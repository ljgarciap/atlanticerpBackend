<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MensajeSeguroDeExcepcion;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoCosto;
use App\Modules\Legacy\Models\Proveedor;
use App\Modules\Legacy\Services\ProductoCostoResolver;
use App\Modules\Legacy\Services\ProductoProveedorResolver;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Vincula los productos ya migrados de ICG con sus proveedores en AtlanticERP (`catalog_products.provider_id` principal + `catalog_product_providers` con todos).
 * El pipeline de ICG nunca llenó esos campos (spec `docs/specs/icg-vinculacion-productos-proveedores.md`, aprobada por Luis 2026-10-14).
 *
 * Selección: productos ACTIVOS, ligados a ICG (`legacy.productos.catalog_product_id`), no descatalogados, con `provider_id IS NULL`, SIN filas en la tabla
 * de varios proveedores y con costos en `producto_costos` de algún proveedor ya enlazado a `compras.providers` (`legacy.proveedores.provider_id`).
 * Nunca pisa un vínculo existente ni borra filas de la pivote. Los proveedores sin enlace se omiten (no se crean). Un producto cuyo `factory_reference`
 * chocaría con otro del mismo proveedor (índice único parcial) se excluye con motivo.
 *
 * DRY-RUN por defecto. `--apply` exige `--operador` y `--esperado=<huella>` (la del dry-run); escribe en UNA transacción, recalcula el plan y aborta si la
 * huella cambió o si algún UPDATE no afecta exactamente una fila. Deja UN evento de auditoría con id → vínculos aplicados. `--revert=<id del evento>`
 * deshace solo los productos cuyo `provider_id` aún vale lo aplicado (no toca los editados después).
 */
final class LegacyVincularProductosProveedoresCommand extends Command
{
    use MensajeSeguroDeExcepcion;

    /** Tope de productos en el evento de auditoría: si el plan lo supera NO se aplica (la reversa quedaría incompleta). */
    private const MAX_PRODUCTOS_AUDITORIA = 20000;

    /** @var string */
    protected $signature = 'legacy:vincular-productos-proveedores
        {--tenant=atlantic : Slug del tenant}
        {--apply : Escribe los vínculos. Sin esta opción es un dry-run que solo lee}
        {--operador= : Quién ejecuta (queda en el evento de auditoría). OBLIGATORIO con --apply y --revert}
        {--esperado= : Huella del dry-run que se va a aplicar. OBLIGATORIA con --apply}
        {--revert= : Id del evento de auditoría legacy.vincular_productos_proveedores cuyos vínculos se revierten}';

    /** @var string */
    protected $description = 'Vincula los productos migrados de ICG con sus proveedores (principal + varios) desde legacy.producto_costos';

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

        if ($plan['vinculos'] === []) {
            $this->info('Nada que aplicar.');

            return self::SUCCESS;
        }
        if (! $apply) {
            $this->comment('Modo dry-run (por defecto): NO se escribió nada. Para aplicar: --apply --operador="<nombre>" --esperado=' . $plan['huella']);

            return self::SUCCESS;
        }
        if (count($plan['vinculos']) > self::MAX_PRODUCTOS_AUDITORIA) {
            $this->error('El plan supera ' . self::MAX_PRODUCTOS_AUDITORIA . ' productos: el evento de auditoría no podría guardarlos todos y la reversa quedaría incompleta.');

            return self::FAILURE;
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
        $this->info("Proveedores vinculados a {$aplicados} productos y verificado.");

        return self::SUCCESS;
    }

    /**
     * @return array{vinculos: array<int, array{principal: int, proveedores: list<int>}>, un_proveedor: int, varios: int, empates: list<int>, parcial_sin_enlace: int, sin_enlace: int, choque: list<int>, con_pivote: int, sin_costo_icg: int, huella: string}
     */
    private function analizar(): array
    {
        $cp = (new CatalogProduct())->getTable();
        $lp = (new Producto())->getTable();
        $pc = (new ProductoCosto())->getTable();
        $pivote = CatalogProduct::providersPivotTable();
        $base = "FROM {$cp} c JOIN {$lp} l ON l.catalog_product_id = c.id
                 WHERE c.is_active = true AND l.descatalogado = false AND c.provider_id IS NULL";
        $sinPivote = "AND NOT EXISTS (SELECT 1 FROM {$pivote} v WHERE v.catalog_product_id = c.id)";

        $candidatos = DB::select(
            "SELECT c.id, c.factory_reference, l.icg_codarticulo {$base} {$sinPivote}
               AND EXISTS (SELECT 1 FROM {$pc} k WHERE k.icg_codarticulo = l.icg_codarticulo)
             ORDER BY c.id"
        );

        $codigos = array_values(array_unique(array_map(static fn ($r): int => (int) $r->icg_codarticulo, $candidatos)));
        /** @var Collection<int|string, Collection<int, ProductoCosto>> $costosPorArticulo */
        $costosPorArticulo = new Collection();
        foreach (array_chunk($codigos, 1000) as $lote) {
            foreach (ProductoCosto::query()->whereIn('icg_codarticulo', $lote)->get()->groupBy('icg_codarticulo') as $cod => $filas) {
                $costosPorArticulo->put($cod, $filas);
            }
        }

        /** @var array<int, int> $providerPorCod */
        $providerPorCod = [];
        foreach (Proveedor::query()->whereNotNull('provider_id')->get(['icg_codproveedor', 'provider_id']) as $p) {
            $providerPorCod[(int) $p->icg_codproveedor] = (int) $p->provider_id;
        }

        $resolver = new ProductoProveedorResolver();
        // Solo los artículos con VARIOS proveedores enlazados y sin el por defecto necesitan la última compra para elegir el principal.
        $varios = $costosPorArticulo
            ->filter(fn (Collection $c): bool => $c->filter(fn (ProductoCosto $k): bool => isset($providerPorCod[(int) $k->codproveedor]))->count() > 1
                && $c->doesntContain(fn (ProductoCosto $k): bool => (int) $k->codproveedor === ProductoCostoResolver::DEFAULT_CODPROVEEDOR))
            ->keys()->map(fn ($k): int => (int) $k)->values()->all();
        $compras = (new ProductoCostoResolver())->ultimasComprasPorProveedor($varios);

        $vinculos = [];
        $unProveedor = 0;
        $variosN = 0;
        $empates = [];
        $parcial = 0;
        $sinEnlace = 0;
        $choque = [];
        /** @var array<string, true> $usadas */
        $usadas = [];
        foreach ($candidatos as $r) {
            $id = (int) $r->id;
            $cod = (int) $r->icg_codarticulo;
            $res = $resolver->resolve($costosPorArticulo->get($cod) ?? new Collection(), $providerPorCod, $compras[$cod] ?? []);
            if ($res === null) {
                $sinEnlace++;

                continue;
            }
            if ($r->factory_reference !== null) {
                // Choque con otro producto ya vinculado, o con otro producto de ESTA misma corrida que quedaría con el mismo proveedor y referencia.
                $clave = $res['principal'] . '|' . $r->factory_reference;
                if (isset($usadas[$clave]) || DB::selectOne(
                    "SELECT 1 AS x FROM {$cp} WHERE provider_id = ? AND factory_reference = ? AND id <> ? LIMIT 1",
                    [$res['principal'], $r->factory_reference, $id]
                ) !== null) {
                    $choque[] = $id;

                    continue;
                }
                $usadas[$clave] = true;
            }
            $vinculos[$id] = ['principal' => $res['principal'], 'proveedores' => $res['proveedores']];
            count($res['proveedores']) === 1 ? $unProveedor++ : $variosN++;
            if ($res['empate']) {
                $empates[] = $id;
            }
            if ($res['sin_enlace'] > 0) {
                $parcial++;
            }
        }

        $huella = md5(implode(';', array_map(
            static fn (int $id, array $v): string => $id . ':' . $v['principal'] . ':' . implode(',', $v['proveedores']),
            array_keys($vinculos),
            $vinculos
        )));

        return [
            'vinculos'           => $vinculos,
            'un_proveedor'       => $unProveedor,
            'varios'             => $variosN,
            'empates'            => $empates,
            'parcial_sin_enlace' => $parcial,
            'sin_enlace'         => $sinEnlace,
            'choque'             => $choque,
            'con_pivote'         => (int) DB::selectOne(
                "SELECT COUNT(*) AS n {$base} AND EXISTS (SELECT 1 FROM {$pivote} v WHERE v.catalog_product_id = c.id)"
            )->n,
            'sin_costo_icg'      => (int) DB::selectOne(
                "SELECT COUNT(*) AS n {$base} {$sinPivote} AND NOT EXISTS (SELECT 1 FROM {$pc} k WHERE k.icg_codarticulo = l.icg_codarticulo)"
            )->n,
            'huella'             => $huella,
        ];
    }

    /** @param array<string, mixed> $plan */
    private function reportar(array $plan): void
    {
        $this->info('legacy:vincular-productos-proveedores — ' . ($this->option('apply') ? 'APLICAR' : 'DRY-RUN'));
        $this->table(['Medida', 'Valor'], [
            ['A VINCULAR (provider_id nulo, con proveedor enlazado)', count($plan['vinculos'])],
            ['  con UN solo proveedor', $plan['un_proveedor']],
            ['  con varios proveedores (todos a la pivote)', $plan['varios']],
            ['  de ellos, principal por EMPATE o sin compras (menor código; revisar con Compras)', count($plan['empates'])],
            ['  con algún proveedor omitido por no estar enlazado a compras.providers', $plan['parcial_sin_enlace']],
            ['No tocados: ningún proveedor del artículo está enlazado', $plan['sin_enlace']],
            ['No tocados: choque de factory_reference con otro producto del proveedor', count($plan['choque'])],
            ['No tocados: ya tienen filas en la tabla de varios proveedores', $plan['con_pivote']],
            ['No tocados: sin ningún costo en ICG (no hay de dónde deducirlo)', $plan['sin_costo_icg']],
            ['Huella del plan', $plan['huella']],
        ]);
        if ($plan['empates'] !== []) {
            $this->line('Ids con principal por empate (revisar con Compras): ' . implode(',', array_slice($plan['empates'], 0, 100)) . (count($plan['empates']) > 100 ? ',…' : ''));
        }
        if ($plan['choque'] !== []) {
            $this->line('Ids con choque de factory_reference: ' . implode(',', array_slice($plan['choque'], 0, 100)) . (count($plan['choque']) > 100 ? ',…' : ''));
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
        $pivote = CatalogProduct::providersPivotTable();
        $aplicados = 0;
        $filasPivote = [];
        foreach ($plan['vinculos'] as $id => $v) {
            $n = DB::affectingStatement("UPDATE {$cp} SET provider_id = ?, updated_at = now() WHERE id = ? AND provider_id IS NULL", [$v['principal'], $id]);
            if ($n !== 1) {
                throw new RuntimeException("el producto {$id} ya tiene proveedor (cambió concurrentemente).");
            }
            $aplicados += $n;
            foreach ($v['proveedores'] as $providerId) {
                $filasPivote[] = ['catalog_product_id' => $id, 'provider_id' => $providerId, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($filasPivote, 1000) as $lote) {
            DB::table($pivote)->insert($lote);
        }
        if ($aplicados !== count($plan['vinculos'])) {
            throw new RuntimeException("aplicados {$aplicados}, esperados " . count($plan['vinculos']) . '.');
        }

        AuditLog::query()->create([
            'user_id'     => null,
            'action'      => AuditService::LEGACY_VINCULAR_PRODUCTOS_PROVEEDORES,
            'entity_type' => 'catalog_product',
            'metadata'    => [
                'operador'          => (string) $this->option('operador'),
                'usuario_so'        => get_current_user(),
                'host'              => (string) gethostname(),
                'entorno'           => (string) config('app.env'),
                'tenant'            => $slug,
                'productos'         => $aplicados,
                'un_proveedor'      => $plan['un_proveedor'],
                'varios'            => $plan['varios'],
                'empates'           => count($plan['empates']),
                'huella'            => $plan['huella'],
                // id → {principal, proveedores}: permite revertir con `--revert=<id de este evento>`.
                'vinculos_aplicados' => $plan['vinculos'],
            ],
        ]);

        return $aplicados;
    }

    private function revertir(int $eventoId, string $slug): int
    {
        $evento = AuditLog::query()->where('id', $eventoId)->where('action', AuditService::LEGACY_VINCULAR_PRODUCTOS_PROVEEDORES)->first();
        if ($evento === null) {
            $this->error("No existe un evento {$eventoId} de " . AuditService::LEGACY_VINCULAR_PRODUCTOS_PROVEEDORES . '.');

            return self::FAILURE;
        }
        /** @var array<int|string, array{principal: int, proveedores: list<int>}> $aplicados */
        $aplicados = (array) (($evento->metadata ?? [])['vinculos_aplicados'] ?? []);
        if ($aplicados === []) {
            $this->error('El evento no guarda vínculos aplicados.');

            return self::FAILURE;
        }

        $cp = (new CatalogProduct())->getTable();
        $pivote = CatalogProduct::providersPivotTable();
        try {
            $revertidos = DB::transaction(function () use ($aplicados, $cp, $pivote, $slug, $eventoId): int {
                $n = 0;
                foreach ($aplicados as $id => $v) {
                    // Solo si el principal aún vale lo aplicado: un producto reasignado a mano después no se toca.
                    $tocados = DB::affectingStatement("UPDATE {$cp} SET provider_id = NULL, updated_at = now() WHERE id = ? AND provider_id = ?", [(int) $id, (int) $v['principal']]);
                    if ($tocados === 1) {
                        DB::table($pivote)->where('catalog_product_id', (int) $id)->whereIn('provider_id', array_map('intval', $v['proveedores']))->delete();
                        $n++;
                    }
                }
                AuditLog::query()->create([
                    'user_id'     => null,
                    'action'      => AuditService::LEGACY_VINCULAR_PRODUCTOS_PROVEEDORES . '.reverted',
                    'entity_type' => 'catalog_product',
                    'metadata'    => ['operador' => (string) $this->option('operador'), 'tenant' => $slug, 'evento_revertido' => $eventoId, 'revertidos' => $n, 'evaluados' => count($aplicados)],
                ]);

                return $n;
            });
        } catch (Throwable $e) {
            $this->error('ROLLBACK: no se revirtió nada. ' . $this->mensajeSeguro($e));

            return self::FAILURE;
        }

        $this->info("Revertidos {$revertidos} de " . count($aplicados) . ' productos (los reasignados después se dejaron intactos).');

        return self::SUCCESS;
    }
}
