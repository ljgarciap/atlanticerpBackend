<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Legacy\Services\ArticuloSinCatalogoCreator;
use App\Modules\Legacy\Services\StockBodegaLoader;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * SCRUM-1136 F6 — crea como INACTIVOS en `catalog_products` los artículos ACTIVOS de ICG con stock <> 0 en un almacén confirmado que no resuelven a ningún catálogo
 * (42 de B2 en dev), para poder cargar su stock con `legacy:cargar-stock-bodegas`. Corre ANTES de la carga de stock. No escribe stock.
 *
 * DRY-RUN POR DEFECTO. `--apply` exige `--operador=` (queda en el evento de auditoría). Idempotente: una segunda corrida crea 0.
 */
final class LegacyCrearArticulosSinCatalogoCommand extends Command
{
    private const MAX_IDS_AUDITORIA = 5000;

    /** @var string */
    protected $signature = 'legacy:crear-articulos-sin-catalogo
        {--tenant=atlantic : Slug del tenant}
        {--almacen=* : Código(s) de almacén ICG (vacío = todos los confirmados en legacy.almacen_equivalencias)}
        {--apply : Crea los productos inactivos. Sin esta opción es un dry-run que solo lee}
        {--operador= : Quién ejecuta (queda en el evento de auditoría). OBLIGATORIO con --apply}';

    /** @var string */
    protected $description = 'SCRUM-1136 F6 — crea como INACTIVOS los artículos activos de ICG con stock en un almacén confirmado y sin catálogo (dry-run por defecto)';

    public function __construct(private readonly ArticuloSinCatalogoCreator $creator, private readonly StockBodegaLoader $loader)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && trim((string) $this->option('operador')) === '') {
            $this->error('--operador es obligatorio con --apply: queda en el evento de auditoría.');

            return self::FAILURE;
        }

        $slug = (string) $this->option('tenant');
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
        try {
            $destinos = $this->loader->destinos(array_values(array_map('strval', (array) $this->option('almacen'))));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($destinos === []) {
            $this->warn('No hay equivalencias CONFIRMADAS en legacy.almacen_equivalencias: nada que hacer.');

            return self::SUCCESS;
        }

        $plan = $this->creator->planear(array_keys($destinos));
        $this->info('legacy:crear-articulos-sin-catalogo — ' . ($apply ? 'APLICAR' : 'DRY-RUN') . ' — almacenes: ' . implode(', ', array_keys($destinos)));
        $this->table(['Medida', 'Valor'], [
            ['A CREAR como inactivos (activos de ICG con stock, sin catálogo)', count($plan['crear'])],
            ['  de ellos, sin precio público en ICG (price_full = 0)', count($plan['crear_sin_precio_publico'])],
            ['Sin referencia (revisión manual, NO se crean)', count($plan['sin_referencia'])],
            ['Colisión de referencia (revisión manual, NO se crean)', count($plan['colisiones'])],
            ['Descatalogados sin catálogo (son de F5: legacy:crear-descatalogados-inactivos)', count($plan['descatalogados'])],
            ['Fuera de legacy.productos (sin staging)', count($plan['sin_staging'])],
            ['Excluidos por configuración (no se cargan como stock)', count($plan['excluidos'])],
        ]);
        if ($plan['sin_referencia'] !== []) {
            $this->warn('Sin REFPROVEEDOR — revisar a mano:');
            $this->table(['icg_codarticulo', 'descripcion'], array_map(static fn (array $a): array => [$a['icg_codarticulo'], $a['descripcion']], $plan['sin_referencia']));
        }
        if ($plan['colisiones'] !== []) {
            $this->warn('Colisiones de referencia — revisar a mano:');
            $this->table(['icg_codarticulo', 'reference', 'motivo'], array_map(static fn (array $a): array => [$a['icg_codarticulo'], $a['reference'], $a['motivo']], $plan['colisiones']));
        }

        if (! $apply) {
            $this->comment('Modo dry-run (por defecto): NO se escribió nada. Para aplicar: --apply --operador="<nombre>".');

            return self::SUCCESS;
        }
        if ($plan['crear'] === []) {
            $this->info('Nada que crear.');

            return self::SUCCESS;
        }

        $resultado = $this->creator->crear($plan['crear']);
        $ids = array_map(static fn (array $c): array => [$c['icg_codarticulo'], $c['catalog_product_id']], $resultado['creados']);
        AuditLog::query()->create([
            'user_id'     => null,
            'action'      => AuditService::LEGACY_CREAR_ARTICULOS_SIN_CATALOGO,
            'entity_type' => 'catalog_product',
            'metadata'    => [
                'operador'   => (string) $this->option('operador'),
                'usuario_so' => get_current_user(),
                'host'       => (string) gethostname(),
                'entorno'    => (string) config('app.env'),
                'tenant'     => $slug,
                'almacenes'  => array_keys($destinos),
                'creados'    => count($resultado['creados']),
                'omitidos'   => count($resultado['omitidos']),
                'errores'    => count($resultado['errores']),
                // [icg_codarticulo, catalog_product_id] de lo creado: permite revertir con seguridad.
                'ids_creados'          => array_slice($ids, 0, self::MAX_IDS_AUDITORIA),
                'ids_creados_truncado' => count($ids) > self::MAX_IDS_AUDITORIA,
            ],
        ]);

        $this->newLine();
        $this->info('Aplicado: ' . count($resultado['creados']) . ' productos inactivos creados, ' . count($resultado['omitidos']) . ' omitidos, ' . count($resultado['errores']) . ' errores.');
        foreach ([...$resultado['omitidos'], ...$resultado['errores']] as $x) {
            $this->line("  - {$x['icg_codarticulo']}: {$x['motivo']}");
        }

        return $resultado['errores'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
