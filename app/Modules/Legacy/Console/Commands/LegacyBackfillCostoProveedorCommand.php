<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Console\Commands\Concerns\MensajeSeguroDeExcepcion;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoCosto;
use App\Modules\Legacy\Services\ProductoCostoResolver;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Backfill de `catalog_products.cost` para productos ya migrados de ICG cuyo costo no está con el proveedor por defecto (1001): el mapper
 * viejo solo leía 1001 y dejaba `cost` en null aunque ICG tuviera costo con otro proveedor (caso A691-451471527K, MARSET). Aplica la misma regla que
 * `ProductoMapper` vía `ProductoCostoResolver` (un solo proveedor → ese; varios → el de la compra más reciente; empate o sin compras → se deja vacío).
 *
 * Selección: productos ACTIVOS, ligados a ICG (`legacy.productos.catalog_product_id`), no descatalogados, con `cost IS NULL` y con costo en
 * `producto_costos` pero SIN fila del proveedor 1001. Nunca pisa un costo existente (ni uno editado a mano en Inventario): el UPDATE exige `cost IS NULL`.
 * Los productos con costo 1001 y `cost` nulo (otra causa: sync desfasado o costo borrado) NO se tocan; el dry-run los cuenta aparte.
 *
 * DRY-RUN por defecto. `--apply` exige `--operador` y `--esperado=<huella>` (la del dry-run); escribe en UNA transacción, recalcula el plan y aborta si
 * la huella cambió o si algún UPDATE no afecta exactamente una fila. Deja un evento de auditoría con id → costo aplicado. `--revert=<id del evento>`
 * deja de nuevo en null los costos aplicados por esa corrida que aún valgan lo aplicado (no toca los editados después).
 */
final class LegacyBackfillCostoProveedorCommand extends Command
{
    use MensajeSeguroDeExcepcion;

    /** Tope de pares id→costo que se guardan en el evento de auditoría (sirven para revertir). */
    private const MAX_IDS_AUDITORIA = 5000;

    /** @var string */
    protected $signature = 'legacy:backfill-costo-proveedor
        {--tenant=atlantic : Slug del tenant}
        {--apply : Escribe los costos. Sin esta opción es un dry-run que solo lee}
        {--operador= : Quién ejecuta (queda en el evento de auditoría). OBLIGATORIO con --apply y --revert}
        {--esperado= : Huella del dry-run que se va a aplicar. OBLIGATORIA con --apply}
        {--revert= : Id del evento de auditoría legacy.backfill_costo_proveedor cuyos costos se revierten}';

    /** @var string */
    protected $description = 'Rellena catalog_products.cost desde legacy.producto_costos cuando el costo no está con el proveedor por defecto (1001)';

    public function handle(): int
    {
        $slug = (string) $this->option('tenant');
        $apply = (bool) $this->option('apply');
        $revert = $this->option('revert');

        if (($apply || $revert !== null) && trim((string) $this->option('operador')) === '') {
            $this->error('--operador es obligatorio con --apply y --revert: queda en el evento de auditoría.');

            return self::FAILURE;
        }
        if ($apply && $revert !== null) {
            $this->error('--apply y --revert son excluyentes.');

            return self::FAILURE;
        }
        if ($apply && trim((string) $this->option('esperado')) === '') {
            $this->error('--esperado=<huella> es obligatorio con --apply: corre primero el dry-run y copia su huella.');

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$slug}' no existe.");

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();
        try {
            if ($revert !== null) {
                return $this->revertir((int) $revert, $slug);
            }

            return $this->ejecutar($slug, $apply);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function ejecutar(string $slug, bool $apply): int
    {
        $plan = $this->analizar();
        $this->reportar($plan);

        if ($plan['costos'] === []) {
            $this->info('Nada que aplicar.');

            return self::SUCCESS;
        }
        if (! $apply) {
            $this->comment('Modo dry-run (por defecto): NO se escribió nada. Para aplicar: --apply --operador="<nombre>" --esperado=' . $plan['huella']);

            return self::SUCCESS;
        }
        if (! hash_equals($plan['huella'], trim((string) $this->option('esperado')))) {
            $this->error("La huella actual ({$plan['huella']}) no coincide con --esperado: la selección cambió desde el dry-run. Repítelo.");

            return self::FAILURE;
        }

        try {
            $aplicados = DB::transaction(fn (): int => $this->aplicar($plan, $slug));
        } catch (Throwable $e) {
            $this->error('ROLLBACK: no se escribió nada. ' . $this->mensajeSeguro($e));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Costo aplicado a {$aplicados} productos y verificado.");

        return self::SUCCESS;
    }

    /**
     * @return array{costos: array<int, float>, un_proveedor: int, varios_por_compra: int, sin_resolver: int, ids_sin_resolver: list<int>, con_1001_nulo: int, sin_costo_icg: int, huella: string}
     */
    private function analizar(): array
    {
        $cp = (new CatalogProduct())->getTable();
        $lp = (new Producto())->getTable();
        $pc = (new ProductoCosto())->getTable();
        $base = "FROM {$cp} c JOIN {$lp} l ON l.catalog_product_id = c.id
                 WHERE c.is_active = true AND l.descatalogado = false AND c.cost IS NULL";

        $candidatos = DB::select(
            "SELECT c.id, l.icg_codarticulo {$base}
               AND EXISTS (SELECT 1 FROM {$pc} k WHERE k.icg_codarticulo = l.icg_codarticulo)
               AND NOT EXISTS (SELECT 1 FROM {$pc} k WHERE k.icg_codarticulo = l.icg_codarticulo AND k.codproveedor = " . ProductoCostoResolver::DEFAULT_CODPROVEEDOR . ')
             ORDER BY c.id'
        );

        $codigos = array_values(array_unique(array_map(static fn ($r): int => (int) $r->icg_codarticulo, $candidatos)));
        /** @var Collection<int|string, Collection<int, ProductoCosto>> $costosPorArticulo */
        $costosPorArticulo = new Collection();
        foreach (array_chunk($codigos, 1000) as $lote) {
            foreach (ProductoCosto::query()->whereIn('icg_codarticulo', $lote)->get()->groupBy('icg_codarticulo') as $cod => $filas) {
                $costosPorArticulo->put($cod, $filas);
            }
        }

        $resolver = new ProductoCostoResolver();
        $varios = $costosPorArticulo->filter(fn (Collection $c): bool => $c->count() > 1)->keys()->map(fn ($k): int => (int) $k)->values()->all();
        $compras = $resolver->ultimasComprasPorProveedor($varios);

        $costos = [];
        $unProveedor = 0;
        $variosPorCompra = 0;
        $idsSinResolver = [];
        foreach ($candidatos as $r) {
            $id = (int) $r->id;
            $cod = (int) $r->icg_codarticulo;
            $filas = $costosPorArticulo->get($cod) ?? new Collection();
            $costo = $resolver->resolve($filas, $compras[$cod] ?? []);
            if ($costo === null) {
                $idsSinResolver[] = $id;

                continue;
            }
            $costos[$id] = round($costo, 2);
            $filas->count() === 1 ? $unProveedor++ : $variosPorCompra++;
        }

        $huella = md5(implode(',', array_map(static fn (int $id, float $c): string => $id . ':' . number_format($c, 2, '.', ''), array_keys($costos), $costos)));

        return [
            'costos'            => $costos,
            'un_proveedor'      => $unProveedor,
            'varios_por_compra' => $variosPorCompra,
            'sin_resolver'      => count($idsSinResolver),
            'ids_sin_resolver'  => $idsSinResolver,
            'con_1001_nulo'     => (int) DB::selectOne(
                "SELECT COUNT(*) AS n {$base} AND EXISTS (SELECT 1 FROM {$pc} k WHERE k.icg_codarticulo = l.icg_codarticulo AND k.codproveedor = " . ProductoCostoResolver::DEFAULT_CODPROVEEDOR . ')'
            )->n,
            'sin_costo_icg'     => (int) DB::selectOne(
                "SELECT COUNT(*) AS n {$base} AND NOT EXISTS (SELECT 1 FROM {$pc} k WHERE k.icg_codarticulo = l.icg_codarticulo)"
            )->n,
            'huella'            => $huella,
        ];
    }

    /** @param array<string, mixed> $plan */
    private function reportar(array $plan): void
    {
        $this->info('legacy:backfill-costo-proveedor — ' . ($this->option('apply') ? 'APLICAR' : 'DRY-RUN'));
        $this->table(['Medida', 'Valor'], [
            ['A RELLENAR (cost nulo, sin costo 1001, resoluble)', count($plan['costos'])],
            ['  por UN solo proveedor', $plan['un_proveedor']],
            ['  por varios proveedores → compra más reciente', $plan['varios_por_compra']],
            ['Sin resolver (varios proveedores sin compras o con empate) — se dejan vacíos', $plan['sin_resolver']],
            ['No tocados: costo 1001 en ICG pero cost nulo (investigar aparte)', $plan['con_1001_nulo']],
            ['No tocados: sin ningún costo en ICG', $plan['sin_costo_icg']],
            ['Σ costos a aplicar', number_format((float) array_sum($plan['costos']), 2, '.', '')],
            ['Huella del plan', $plan['huella']],
        ]);
        if ($plan['ids_sin_resolver'] !== []) {
            $this->line('Ids sin resolver (revisar con Compras): ' . implode(',', array_slice($plan['ids_sin_resolver'], 0, 100)) . (count($plan['ids_sin_resolver']) > 100 ? ',…' : ''));
        }
    }

    /** @param array<string, mixed> $plan */
    private function aplicar(array $plan, string $slug): int
    {
        // El plan se recalcula dentro de la transacción: si algo cambió desde el dry-run, se aborta.
        $actual = $this->analizar();
        if ($actual['huella'] !== $plan['huella']) {
            throw new RuntimeException('la selección cambió entre el análisis y la escritura (huella distinta): repite el dry-run.');
        }

        $cp = (new CatalogProduct())->getTable();
        $aplicados = 0;
        foreach ($plan['costos'] as $id => $costo) {
            $n = DB::affectingStatement("UPDATE {$cp} SET cost = ?, updated_at = now() WHERE id = ? AND cost IS NULL", [$costo, $id]);
            if ($n !== 1) {
                throw new RuntimeException("el producto {$id} ya no tiene cost nulo (cambió concurrentemente).");
            }
            $aplicados += $n;
        }
        if ($aplicados !== count($plan['costos'])) {
            throw new RuntimeException("aplicados {$aplicados}, esperados " . count($plan['costos']) . '.');
        }

        AuditLog::query()->create([
            'user_id'     => null,
            'action'      => AuditService::LEGACY_BACKFILL_COSTO_PROVEEDOR,
            'entity_type' => 'catalog_product',
            'metadata'    => [
                'operador'            => (string) $this->option('operador'),
                'usuario_so'          => get_current_user(),
                'host'                => (string) gethostname(),
                'entorno'             => (string) config('app.env'),
                'tenant'              => $slug,
                'productos'           => $aplicados,
                'un_proveedor'        => $plan['un_proveedor'],
                'varios_por_compra'   => $plan['varios_por_compra'],
                'sin_resolver'        => $plan['sin_resolver'],
                'huella'              => $plan['huella'],
                // id → costo aplicado: permite revertir con `--revert=<id de este evento>`.
                'costos_aplicados'    => array_slice($plan['costos'], 0, self::MAX_IDS_AUDITORIA, true),
                'costos_truncado'     => count($plan['costos']) > self::MAX_IDS_AUDITORIA,
            ],
        ]);

        return $aplicados;
    }

    private function revertir(int $eventoId, string $slug): int
    {
        $evento = AuditLog::query()->where('id', $eventoId)->where('action', AuditService::LEGACY_BACKFILL_COSTO_PROVEEDOR)->first();
        if ($evento === null) {
            $this->error("No existe un evento {$eventoId} de {$this->auditAction()}.");

            return self::FAILURE;
        }
        /** @var array<int|string, float|int|string> $aplicados */
        $aplicados = (array) (($evento->metadata ?? [])['costos_aplicados'] ?? []);
        if ($aplicados === []) {
            $this->error('El evento no guarda costos aplicados.');

            return self::FAILURE;
        }

        $cp = (new CatalogProduct())->getTable();
        try {
            $revertidos = DB::transaction(function () use ($aplicados, $cp, $slug, $eventoId): int {
                $n = 0;
                foreach ($aplicados as $id => $costo) {
                    // Solo si aún vale lo aplicado: un costo editado después a mano en Inventario no se toca.
                    $n += DB::affectingStatement("UPDATE {$cp} SET cost = NULL, updated_at = now() WHERE id = ? AND cost = ?", [(int) $id, (float) $costo]);
                }
                AuditLog::query()->create([
                    'user_id'     => null,
                    'action'      => AuditService::LEGACY_BACKFILL_COSTO_PROVEEDOR . '.reverted',
                    'entity_type' => 'catalog_product',
                    'metadata'    => ['operador' => (string) $this->option('operador'), 'tenant' => $slug, 'evento_revertido' => $eventoId, 'revertidos' => $n, 'evaluados' => count($aplicados)],
                ]);

                return $n;
            });
        } catch (Throwable $e) {
            $this->error('ROLLBACK: no se revirtió nada. ' . $this->mensajeSeguro($e));

            return self::FAILURE;
        }

        $this->info("Revertidos {$revertidos} de " . count($aplicados) . ' productos (los editados después se dejaron intactos).');

        return self::SUCCESS;
    }

    private function auditAction(): string
    {
        return AuditService::LEGACY_BACKFILL_COSTO_PROVEEDOR;
    }
}
