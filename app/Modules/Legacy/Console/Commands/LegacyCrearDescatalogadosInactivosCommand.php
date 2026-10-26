<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Services\DescatalogadoEquivalenciaService;
use App\Modules\Legacy\Services\DescatalogadoInactivoCreator;
use App\Modules\Legacy\Services\DescatalogadoVerificador;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Console\Command;
use Throwable;

/**
 * SCRUM-1136 F5 (RF2 + RF3 + RF4 + RF5) — sobre los descatalogados de ICG con stock ya staged (`legacy:sync-productos-descatalogados`):
 *  1. fija la equivalencia por referencia con los activos (su `catalog_product_id` pasa a ser el del activo; nunca se duplica `reference`);
 *  2. crea en `ventas_diseno.catalog_products` los que tienen referencia y no tienen equivalente, como productos INACTIVOS (`is_active = false`);
 *  3. lista los que no tienen referencia (no se crean) y las colisiones de referencia, para revisión manual.
 * No escribe stock de ningún tipo (RF6): ni bodega, ni kardex, ni `stock_quantity`.
 *
 * DRY-RUN POR DEFECTO (solo lecturas, informa cuántos crearía y vincularía). `--apply` exige `--operador=` (queda en el evento de auditoría). Idempotente: una
 * segunda corrida con `--apply` crea 0 y vincula 0. Cada producto se crea en su propia transacción.
 */
final class LegacyCrearDescatalogadosInactivosCommand extends Command
{
    /** Máximo de ids creados que se guardan en el evento de auditoría (sirven para revertir). */
    private const MAX_IDS_AUDITORIA = 5000;

    /** @var string */
    protected $signature = 'legacy:crear-descatalogados-inactivos
        {--tenant=atlantic : Slug del tenant}
        {--apply : Escribe los vínculos y crea los productos inactivos. Sin esta opción es un dry-run que solo lee}
        {--operador= : Quién ejecuta (queda en el evento de auditoría). OBLIGATORIO con --apply}';

    /** @var string */
    protected $description = 'SCRUM-1136 F5 — crea como INACTIVOS en catalog_products los descatalogados de ICG con stock (dry-run por defecto)';

    public function __construct(
        private readonly DescatalogadoInactivoCreator $creator,
        private readonly DescatalogadoEquivalenciaService $equivalencias,
        private readonly DescatalogadoVerificador $verificador,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && trim((string) $this->option('operador')) === '') {
            $this->error('--operador es obligatorio con --apply: queda en el evento de auditoría.');

            return self::FAILURE;
        }

        $slug   = (string) $this->option('tenant');
        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$slug}' no existe.");

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();
        try {
            return $this->ejecutar($slug, $apply);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function ejecutar(string $slug, bool $apply): int
    {
        $codigos = $this->verificador->codigosConStock();
        $totalDescatalogados = Producto::query()->where('descatalogado', true)->count();
        if ($codigos === []) {
            $this->warn(
                $totalDescatalogados === 0
                    ? 'No hay descatalogados en legacy.productos: corre antes legacy:sync-productos-descatalogados.'
                    : 'Hay descatalogados staged pero ninguno con stock <> 0 en legacy.stock_almacen (¿falta la carga de F1, legacy:sync-almacenes?).'
            );

            return self::SUCCESS;
        }

        $plan = $this->creator->planear($codigos);
        $this->reportarPlan($plan, $apply);

        if (! $apply) {
            $this->comment('Modo dry-run (por defecto): NO se escribió nada. Para aplicar: --apply --operador="<nombre>".');

            return self::SUCCESS;
        }

        try {
            $vinculadas = $this->equivalencias->aplicar($plan['vinculos']);
        } catch (Throwable) {
            $this->error('No se pudieron guardar las equivalencias (ROLLBACK de ese paso). No se creó ningún producto.');

            return self::FAILURE;
        }

        $resultado = $this->creator->crear($plan['crear']);

        $this->auditar($slug, $plan, $vinculadas, $resultado);

        $this->newLine();
        $this->info('Aplicado: ' . count($resultado['creados']) . " productos inactivos creados, {$vinculadas} equivalencias guardadas, "
            . count($resultado['omitidos']) . ' omitidos, ' . count($resultado['errores']) . ' errores.');
        foreach ([...$resultado['omitidos'], ...$resultado['errores']] as $x) {
            $this->line("  - {$x['icg_codarticulo']}: {$x['motivo']}");
        }

        $this->imprimirVerificacion();

        return $resultado['errores'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $plan */
    private function reportarPlan(array $plan, bool $apply): void
    {
        $this->info('legacy:crear-descatalogados-inactivos — ' . ($apply ? 'APLICAR' : 'DRY-RUN'));
        $this->table(['Medida', 'Valor'], [
            ['Descatalogados con stock en staging', $plan['total']],
            ['Con equivalente activo (se vinculan, NO se crean)', count($plan['con_equivalente'])],
            ['  de ellos, equivalencias por guardar ahora', count($plan['vinculos'])],
            ['Equivalencia ambigua o en conflicto (revisión manual, NO se crean)', count($plan['revision_manual'])],
            ['Sin referencia (revisión manual, NO se crean)', count($plan['sin_referencia'])],
            ['Ya ligados a un producto de catálogo (idempotencia)', count($plan['ya_ligados'])],
            ['Referencia ya existente en catálogo o repetida (NO se crean)', count($plan['colisiones'])],
            ['A CREAR como inactivos', count($plan['crear'])],
            ['  de ellos, sin precio público en ICG (price_full = 0)', count($plan['crear_sin_precio_publico'])],
        ]);

        if ($plan['sin_referencia'] !== []) {
            $this->warn('Sin REFPROVEEDOR — revisar a mano:');
            $this->table(['icg_codarticulo', 'descripcion'], array_map(static fn (array $a): array => [$a['icg_codarticulo'], $a['descripcion']], $plan['sin_referencia']));
        }
        if ($plan['colisiones'] !== []) {
            $this->warn('Colisiones de referencia — revisar a mano:');
            $this->table(['icg_codarticulo', 'reference', 'motivo'], array_map(static fn (array $a): array => [$a['icg_codarticulo'], $a['reference'], $a['motivo']], $plan['colisiones']));
        }
        if ($plan['pares'] !== []) {
            $this->line('<comment>Pares descatalogado -> activo a vincular</comment>');
            $this->table(
                ['descatalogado', 'referencia', 'activo', 'catalog_product_id del activo'],
                array_map(static fn (array $p): array => [$p['icg_codarticulo'], $p['referencia'], $p['equivalente'], $p['catalog_product_id'] ?? 'sin catálogo'], $plan['pares']),
            );
        }
        if ($plan['revision_manual'] !== []) {
            $this->warn('Equivalencia ambigua/en conflicto — icg_codarticulo: ' . implode(', ', $plan['revision_manual']));
        }
    }

    /**
     * @param array<string, mixed> $plan
     * @param array{creados: list<array{icg_codarticulo: int, catalog_product_id: int, reference: string}>, omitidos: list<mixed>, errores: list<mixed>} $resultado
     */
    private function auditar(string $slug, array $plan, int $vinculadas, array $resultado): void
    {
        $ids = array_map(static fn (array $c): array => [$c['icg_codarticulo'], $c['catalog_product_id']], $resultado['creados']);

        AuditLog::query()->create([
            'user_id'     => null,
            'action'      => AuditService::LEGACY_CREAR_DESCATALOGADOS_INACTIVOS,
            'entity_type' => 'catalog_product',
            'metadata'    => [
                'operador'        => (string) $this->option('operador'),
                'usuario_so'      => get_current_user(),
                'host'            => (string) gethostname(),
                'entorno'         => (string) config('app.env'),
                'tenant'          => $slug,
                'creados'         => count($resultado['creados']),
                'omitidos'        => count($resultado['omitidos']),
                'errores'         => count($resultado['errores']),
                'equivalencias'   => $vinculadas,
                'sin_referencia'  => count($plan['sin_referencia']),
                'colisiones'      => count($plan['colisiones']),
                // [icg_codarticulo, catalog_product_id] de lo creado: permite revertir con seguridad.
                'ids_creados'     => array_slice($ids, 0, self::MAX_IDS_AUDITORIA),
                'ids_creados_truncado' => count($ids) > self::MAX_IDS_AUDITORIA,
            ],
        ]);
    }

    private function imprimirVerificacion(): void
    {
        $r = $this->verificador->resumen();
        $this->newLine();
        $this->line('<comment>Verificación (RF5) — descatalogados con stock según legacy.stock_almacen</comment>');
        $this->table(['Medida', 'Valor'], [
            ['Staged', $r['staged']],
            ['Con equivalente activo', $r['con_equivalente']],
            ['Creados inactivos en catálogo', $r['creados_inactivos']],
            ['Ligados a un catálogo activo (no equivalentes)', $r['ligados_otros']],
            ['Pendientes de crear', $r['pendientes']],
            ['Sin referencia (revisión manual)', $r['sin_referencia']],
        ]);
        if ($r['stock_por_almacen'] !== []) {
            $this->table(['Almacén', 'Artículos', 'Σ stock'], array_map(static fn (array $a): array => [$a['codalmacen'], $a['articulos'], $a['stock']], $r['stock_por_almacen']));
        }
    }
}
