<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Console\Commands\Concerns\MensajeSeguroDeExcepcion;
use App\Modules\Legacy\Contracts\DescatalogadoStockIcgExtractorContract;
use App\Modules\Legacy\Services\DescatalogadoVerificador;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * SCRUM-1136 F5 (RF5) — verificación de SOLO LECTURA de los artículos descatalogados de ICG con stock: esperados, staged, con equivalente activo, creados inactivos, sin
 * referencia, no resueltos a catálogo y la suma de stock por almacén de `legacy.stock_almacen` COMPARADA contra ICG (consulta de solo lectura vía el extractor, mismo patrón
 * que `legacy:verify-almacenes`). No escribe nada.
 *
 * Sale con código 1 (incumplimiento) si: la cantidad staged difiere de los esperados (`--esperados`, o el conteo de ICG si no se indica); la suma de stock por almacén
 * difiere de la de ICG; o algún artículo con referencia no resuelve a un `catalog_products` (propio, o el del activo equivalente). Los sin referencia no cuentan (revisión manual).
 * `--sin-icg` omite la consulta a ICG (solo staging); entonces solo aplica `--esperados`.
 */
final class LegacyVerifyDescatalogadosCommand extends Command
{
    use MensajeSeguroDeExcepcion;

    /** @var string */
    protected $signature = 'legacy:verify-descatalogados
        {--tenant=atlantic : Slug del tenant}
        {--esperados= : Cantidad esperada de artículos según ICG (p. ej. 95); si no se indica y hay ICG, se usa el conteo de ICG}
        {--sin-icg : No consulta ICG: solo resumen de staging (y --esperados si se indica)}';

    /** @var string */
    protected $description = 'SCRUM-1136 F5 — verificación de solo lectura de los descatalogados de ICG con stock (staged, equivalentes, creados inactivos, no resueltos, stock por almacén vs ICG)';

    public function __construct(
        private readonly DescatalogadoVerificador $verificador,
        private readonly DescatalogadoStockIcgExtractorContract $icg,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $slug   = (string) $this->option('tenant');
        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$slug}' no existe.");

            return self::FAILURE;
        }

        $esperadosOpt = $this->option('esperados');
        $esperados    = null;
        if ($esperadosOpt !== null && $esperadosOpt !== '') {
            $esperados = filter_var($esperadosOpt, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($esperados === false) {
                $this->error('--esperados debe ser un entero >= 0.');

                return self::FAILURE;
            }
        }

        $icg = null;
        if (! (bool) $this->option('sin-icg')) {
            try {
                $icg = $this->icg->resumen();
            } catch (Throwable $e) {
                $this->error('Error al consultar ICG (solo lectura): ' . $this->mensajeSeguro($e) . ' Usa --sin-icg para verificar solo staging.');

                return self::FAILURE;
            }
            $esperados ??= $icg['articulos'];
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();
        try {
            $r = $this->verificador->resumen($esperados);
        } finally {
            Tenant::forgetCurrent();
        }

        $incumplimientos = [];

        $this->info('legacy:verify-descatalogados — solo lectura');
        $this->table(['Medida', 'Valor'], [
            ['Esperados (ICG)', $r['esperados'] ?? 'n/d'],
            ['Staged (descatalogados con stock en staging)', $r['staged']],
            ['Con equivalente activo', $r['con_equivalente']],
            ['  de ellos, SIN catálogo resuelto (el activo aún no tiene catalog_products)', count($r['equivalentes_sin_catalogo'])],
            ['Creados inactivos en catálogo', $r['creados_inactivos']],
            ['Ligados a un catálogo activo (no equivalentes)', $r['ligados_otros']],
            ['Pendientes de crear inactivos', $r['pendientes']],
            ['Sin referencia (revisión manual)', $r['sin_referencia']],
            ['NO resuelven a catálogo (con referencia)', count($r['sin_resolver'])],
            ['Artículos con stock en staging sin fila en legacy.productos', $r['sin_staging']],
        ]);

        if ($r['equivalentes_sin_catalogo'] !== []) {
            $this->warn('Equivalentes cuyo activo no tiene catálogo (icg_codarticulo): ' . implode(', ', $r['equivalentes_sin_catalogo']));
        }
        if ($r['sin_resolver'] !== []) {
            $incumplimientos[] = count($r['sin_resolver']) . ' artículos con referencia no resuelven a catalog_products (icg_codarticulo: ' . implode(', ', $r['sin_resolver']) . ')';
        }
        foreach ($r['sin_referencia_codigos'] as $a) {
            $this->warn("Sin referencia: {$a['icg_codarticulo']} — {$a['descripcion']}");
        }

        if ($esperados !== null && $esperados !== $r['staged']) {
            $incumplimientos[] = "se esperaban {$esperados} artículos y hay {$r['staged']} staged";
        }

        if ($icg !== null) {
            $filas = DescatalogadoVerificador::compararStock($r['stock_por_almacen'], $icg['por_almacen']);
            $this->table(
                ['Almacén', 'Artículos staging', 'Artículos ICG', 'Σ stock staging', 'Σ stock ICG', 'Diferencia'],
                array_map(static fn (array $f): array => [$f['codalmacen'], $f['articulos_staging'], $f['articulos_icg'], $f['staging'], $f['icg'], $f['diferencia']], $filas),
            );
            foreach ($filas as $f) {
                if (abs($f['diferencia']) > DescatalogadoVerificador::TOLERANCIA_FLOAT || $f['articulos_staging'] !== $f['articulos_icg']) {
                    $incumplimientos[] = "almacén {$f['codalmacen']}: staging {$f['staging']} ({$f['articulos_staging']} art.) vs ICG {$f['icg']} ({$f['articulos_icg']} art.)";
                }
            }
        } else {
            $this->table(['Almacén', 'Artículos', 'Σ stock (staging)'], array_map(static fn (array $a): array => [$a['codalmacen'], $a['articulos'], $a['stock']], $r['stock_por_almacen']));
            $this->comment('--sin-icg: la suma de stock por almacén NO se comparó contra ICG.');
        }

        if ($incumplimientos !== []) {
            foreach ($incumplimientos as $i) {
                $this->error("DIFERENCIA: {$i}");
            }

            return self::FAILURE;
        }

        $this->info('Verificación sin diferencias.');

        return self::SUCCESS;
    }
}
