<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MensajeSeguroDeExcepcion;
use App\Modules\Legacy\Contracts\ProductoDescatalogadoExtractorContract;
use App\Modules\Legacy\Data\ExtractedProductos;
use App\Modules\Legacy\Data\ProductoCostoRow;
use App\Modules\Legacy\Data\ProductoPrecioRow;
use App\Modules\Legacy\Data\ProductoRow;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Services\DescatalogadoEquivalenciaService;
use App\Modules\Legacy\Services\DescatalogadoVerificador;
use App\Modules\Legacy\Services\ProductoStagingLoader;
use App\Modules\Legacy\Support\DryRunAbort;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\MemoryLimit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-1136 F5 (RF1 + RF2 + RF4 + RF5) — carga ÚNICA e idempotente de los artículos descatalogados de ICG con stock distinto de cero en algún almacén en
 * `legacy.productos` (con `descatalogado = true`), `legacy.producto_precios` y `legacy.producto_costos`, reutilizando el staging de activos
 * (`ProductoStagingLoader`) y las mismas reglas de mapeo. Después resuelve la equivalencia por referencia con los activos ya presentes (RF2).
 *
 * NO escribe en `catalog_products` (eso es `legacy:crear-descatalogados-inactivos`) ni en `bodega.*`, y no toca el flujo de activos (`legacy:sync-productos` y
 * los incrementales no cambian). Solo lee de ICG.
 *
 * Aplica por defecto con opt-out `--dry-run` (igual que `legacy:sync-almacenes` de F1): correr SIEMPRE `--dry-run` primero y revisar los pares descatalogado -> activo.
 * `--dry-run` es un dry-run REAL: todo corre dentro de una transacción que se revierte (el staging no queda escrito) y se informa lo que se habría hecho.
 */
final class LegacySyncProductosDescatalogadosCommand extends Command
{
    use MensajeSeguroDeExcepcion;

    /** @var string */
    protected $signature = 'legacy:sync-productos-descatalogados
        {--tenant=atlantic : Slug del tenant}
        {--dry-run : Extrae y calcula todo dentro de una transacción que se revierte: no escribe nada}';

    /** @var string */
    protected $description = 'SCRUM-1136 F5 — stagea los artículos descatalogados de ICG con stock en legacy.productos/precios/costos y resuelve su equivalencia por referencia con los activos';

    public function __construct(
        private readonly ProductoDescatalogadoExtractorContract $extractor,
        private readonly ProductoStagingLoader $stagingLoader,
        private readonly DescatalogadoEquivalenciaService $equivalencias,
        private readonly DescatalogadoVerificador $verificador,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        MemoryLimit::atLeast('512M');

        $slug   = (string) $this->option('tenant');
        $dryRun = (bool) $this->option('dry-run');

        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$slug}' no existe.");

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();
        try {
            return $this->ejecutar($dryRun);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function ejecutar(bool $dryRun): int
    {
        try {
            $extracted = $this->extractor->extract();
        } catch (Throwable $e) {
            $this->error('Error al extraer de ICG: ' . $this->mensajeSeguro($e));

            return self::FAILURE;
        }

        // Defensa: este comando es SOLO para descatalogados. Un activo en el extracto indicaría un filtro roto; abortar antes de escribir.
        $activos = array_filter($extracted->productos, static fn (ProductoRow $p): bool => ! $p->descatalogado);
        if ($activos !== []) {
            $this->error(count($activos) . ' artículos del extracto NO son descatalogados (filtro de extracción roto): se aborta sin escribir.');

            return self::FAILURE;
        }

        // Senior Review H2: un icg_codarticulo que YA está en staging como ACTIVO nunca se convierte en descatalogado desde acá (sacaría de golpe un producto vivo del
        // sync de stock y del backfill). Se omite, se reporta y cuenta como conflicto.
        $conflictosActivos = Producto::query()->where('descatalogado', false)->whereIn('icg_codarticulo', $extracted->icgCodarticulos())->pluck('icg_codarticulo')->map(fn ($c): int => (int) $c)->all();
        if ($conflictosActivos !== []) {
            $extracted = new ExtractedProductos(
                array_values(array_filter($extracted->productos, static fn (ProductoRow $r): bool => ! in_array($r->icgCodarticulo, $conflictosActivos, true))),
                array_values(array_filter($extracted->precios, static fn (ProductoPrecioRow $r): bool => ! in_array($r->icgCodarticulo, $conflictosActivos, true))),
                array_values(array_filter($extracted->costos, static fn (ProductoCostoRow $r): bool => ! in_array($r->icgCodarticulo, $conflictosActivos, true))),
            );
        }

        $codigos = $extracted->icgCodarticulos();
        $res = ['conflictos_activos' => $conflictosActivos];

        try {
            try {
                DB::transaction(function () use ($extracted, $codigos, $dryRun, &$res): void {
                    $existentes = Producto::query()->whereIn('icg_codarticulo', $codigos)->count();

                    $res['staging']       = $this->stagingLoader->load($extracted);
                    $res['nuevos']        = count($codigos) - $existentes;
                    $res['existentes']    = $existentes;
                    $res['equivalencias'] = $this->equivalencias->calcular($codigos);
                    $res['vinculadas']    = $this->equivalencias->aplicar($res['equivalencias']['vinculos']);
                    $res['resumen']       = $this->verificador->resumen(count($codigos));

                    if ($dryRun) {
                        throw new DryRunAbort();
                    }
                });
            } catch (DryRunAbort) {
                // Rollback intencional del dry-run: el staging no queda escrito.
            }
        } catch (Throwable $e) {
            $this->error('Error al stagear los descatalogados (ROLLBACK, no se escribió nada): ' . $this->mensajeSeguro($e));

            return self::FAILURE;
        }

        $this->reportar($res, count($codigos), $dryRun);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $res */
    private function reportar(array $res, int $extraidos, bool $dryRun): void
    {
        $this->info('legacy:sync-productos-descatalogados — ' . ($dryRun ? 'DRY-RUN (nada se escribió)' : 'APLICADO'));
        $this->table(['Etapa', 'Conteo'], [
            ['Extraídos de ICG (DESCATALOGADO=T con stock <> 0)', $extraidos],
            ['  nuevos en legacy.productos', $res['nuevos']],
            ['  ya existentes (se actualizan sin duplicar)', $res['existentes']],
            ['Staged — productos', $res['staging']['productos']],
            ['Staged — precios', $res['staging']['precios']],
            ['Staged — costos', $res['staging']['costos']],
            ['Equivalentes a un activo (vínculo)', count($res['equivalencias']['vinculos'])],
            ['  filas que cambiaron al vincular', $res['vinculadas']],
            ['Equivalencia ambigua (varios activos con la misma referencia): revisión manual', count($res['equivalencias']['ambiguos'])],
            ['Equivalencia en conflicto (el descatalogado ya tiene catálogo propio): revisión manual', count($res['equivalencias']['conflictos'])],
            ['Conflicto: el código ya está en staging como ACTIVO (se OMITE, no se convierte)', count($res['conflictos_activos'])],
        ]);

        if ($res['conflictos_activos'] !== []) {
            $this->warn('Omitidos por ser ACTIVOS en staging (icg_codarticulo): ' . implode(', ', $res['conflictos_activos']));
        }
        if ($res['equivalencias']['pares'] !== []) {
            $this->line('<comment>Pares descatalogado -> activo (revisar a mano antes de usarlos)</comment>');
            $this->table(
                ['descatalogado', 'referencia', 'activo', 'catalog_product_id del activo'],
                array_map(static fn (array $p): array => [$p['icg_codarticulo'], $p['referencia'], $p['equivalente'], $p['catalog_product_id'] ?? 'sin catálogo'], $res['equivalencias']['pares']),
            );
        }
        foreach (['ambiguos' => 'ambigua', 'conflictos' => 'en conflicto'] as $k => $txt) {
            if ($res['equivalencias'][$k] !== []) {
                $this->warn("Equivalencia {$txt} (icg_codarticulo): " . implode(', ', $res['equivalencias'][$k]));
            }
        }

        $this->imprimirResumen($res['resumen']);

        if ($dryRun) {
            $this->comment('Modo --dry-run: se revirtió la transacción, el staging NO quedó escrito. Re-ejecuta sin --dry-run para cargar.');
        }
    }

    /** @param array<string, mixed> $r */
    private function imprimirResumen(array $r): void
    {
        $this->newLine();
        $this->line('<comment>Verificación (RF5) — descatalogados con stock según legacy.stock_almacen</comment>');
        $this->table(['Medida', 'Valor'], [
            ['Esperados (extraídos de ICG)', $r['esperados'] ?? 'n/d'],
            ['Staged (descatalogados con stock en staging)', $r['staged']],
            ['Con equivalente activo', $r['con_equivalente']],
            ['Creados inactivos en catálogo', $r['creados_inactivos']],
            ['Ligados a un catálogo activo (no equivalentes)', $r['ligados_otros']],
            ['Pendientes de crear inactivos', $r['pendientes']],
            ['Sin referencia (revisión manual)', $r['sin_referencia']],
            ['Artículos con stock en staging sin fila en legacy.productos', $r['sin_staging']],
        ]);

        if ($r['stock_por_almacen'] !== []) {
            $this->table(
                ['Almacén', 'Artículos', 'Σ stock'],
                array_map(static fn (array $a): array => [$a['codalmacen'], $a['articulos'], $a['stock']], $r['stock_por_almacen']),
            );
        }

        if ($r['sin_referencia_codigos'] !== []) {
            $this->warn('Sin REFPROVEEDOR: NO se crearán en catálogo, revisar a mano:');
            $this->table(['icg_codarticulo', 'descripcion'], array_map(static fn (array $a): array => [$a['icg_codarticulo'], $a['descripcion']], $r['sin_referencia_codigos']));
        }
    }
}
