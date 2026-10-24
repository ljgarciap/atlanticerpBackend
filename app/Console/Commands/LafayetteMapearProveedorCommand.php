<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MensajeSeguroDeExcepcion;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Compras\Models\Provider;
use App\Modules\Integrations\Lafayette\Models\LafayetteProducto;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use App\Shared\Support\MemoryLimit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * SCRUM-1151 — mapea los productos de Lafayette Lighting (espejo `bodega.lafayette_productos`) con los productos YA existentes de
 * AtlanticERP y asocia el proveedor BK INTERPRISES S.A. a los que coinciden de forma confiable. Spec:
 * `docs/specs/scrum-1151-mapeo-lafayette-bk-interprises.md` (decisiones de Luis, 2026-10-15).
 *
 * Reglas (acordadas): se compara la referencia de Lafayette con la referencia PÚBLICA de AtlanticERP (`catalog_products.reference`), ignorando
 * mayúsculas y espacios en los extremos. Solo la coincidencia exacta 1 a 1 se asocia sola; lo que únicamente calza al normalizar
 * (sin guiones/espacios) o es ambiguo queda «pendiente_revision»; sin referencia equivalente es «sin_coincidencia». Se consideran productos
 * activos E inactivos. Si el producto no tiene proveedor principal, BK pasa a ser el principal (y entra a la tabla de varios proveedores,
 * como hace la vinculación de ICG); si ya tiene otro principal, BK se AGREGA solo a la tabla de varios proveedores y el principal no cambia.
 *
 * Nunca crea productos, nunca toca reference/description/categoría/costo/foto, nunca borra ni reemplaza un proveedor existente, y no
 * escribe en el espejo de Lafayette ni usa la base externa (solo lee `lafayette_productos` y `catalog_products`).
 *
 * DRY-RUN por defecto (solo lee y escribe el CSV de resultado). `--apply` exige `--operador` y `--esperado=<huella del dry-run>`; escribe en
 * UNA transacción, recalcula el plan y aborta si la huella cambió. Deja UN evento de auditoría (id → asociación aplicada); `--revert=<id>`
 * deshace solo lo que sigue valiendo lo aplicado.
 */
final class LafayetteMapearProveedorCommand extends Command
{
    use MensajeSeguroDeExcepcion;

    public const PROVEEDOR = 'BK INTERPRISES S.A.';

    private const ESTADO_ASOCIADO = 'asociado';
    private const ESTADO_PENDIENTE = 'pendiente_revision';
    private const ESTADO_SIN_COINCIDENCIA = 'sin_coincidencia';

    /** @var string */
    protected $signature = 'lafayette:mapear-proveedor
        {--tenant=atlantic : Slug del tenant}
        {--apply : Escribe las asociaciones. Sin esta opción es un dry-run que solo lee}
        {--operador= : Quién ejecuta (queda en el evento de auditoría). OBLIGATORIO con --apply y --revert}
        {--esperado= : Huella del dry-run que se va a aplicar. OBLIGATORIA con --apply}
        {--revert= : Id del evento de auditoría lafayette.mapear_proveedor cuyas asociaciones se revierten}';

    /** @var string */
    protected $description = 'SCRUM-1151 — asocia BK INTERPRISES S.A. a los productos de AtlanticERP que coinciden con Lafayette (dry-run por defecto)';

    public function handle(): int
    {
        // ~26 mil filas de Lafayette + el catálogo de AtlanticERP en memoria: mismo resguardo que `lafayette:sync-productos`.
        MemoryLimit::atLeast('512M');

        $slug   = (string) $this->option('tenant');
        $apply  = (bool) $this->option('apply');
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
        $proveedorId = $this->proveedorId();
        if ($proveedorId === null) {
            return self::FAILURE;
        }

        $plan = $this->analizar($proveedorId);
        $this->reportar($plan);
        $this->line('CSV del resultado: ' . $this->escribirCsv($plan, $apply ? 'apply' : 'dry-run'));

        if ($plan['asociaciones'] === []) {
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
            $aplicados = DB::transaction(fn (): int => $this->aplicar($plan, $proveedorId, $slug));
        } catch (Throwable $e) {
            $this->error('ROLLBACK: no se escribió nada. ' . $this->mensajeSeguro($e));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(self::PROVEEDOR . " asociado a {$aplicados} productos y verificado.");

        return self::SUCCESS;
    }

    /** El proveedor debe existir y ser único: este comando nunca lo crea (lo siembra `ZonaLibreProviderSeeder`). */
    private function proveedorId(): ?int
    {
        $ids = Provider::query()->where('name', self::PROVEEDOR)->pluck('id');
        if ($ids->count() !== 1) {
            $this->error('Se esperaba exactamente UN proveedor "' . self::PROVEEDOR . '" y hay ' . $ids->count() . '. No se hace nada.');

            return null;
        }

        return (int) $ids->first();
    }

    private static function claveExacta(string $referencia): string
    {
        return mb_strtolower(trim($referencia));
    }

    /** Solo para COMPARAR (nunca se escribe): sin ningún carácter que no sea letra o dígito, en mayúsculas. */
    private static function claveNormalizada(string $referencia): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($referencia));
    }

    /**
     * @return array{
     *   filas: list<array{lafayette_id: string, referencia: string, estado: string, motivo: string, catalog_product_id: ?int, reference_atlanticerp: ?string, proveedor_previo: ?int}>,
     *   asociaciones: array<int, string>,
     *   asociados: int, ya_asociados: int, adicionales: int, principales: int, pendientes: int, sin_coincidencia: int, huella: string
     * }
     */
    private function analizar(int $proveedorId): array
    {
        $cp     = (new CatalogProduct())->getTable();
        $pivote = CatalogProduct::providersPivotTable();

        /** @var array<string, list<int>> $porExacta */
        $porExacta = [];
        /** @var array<string, list<int>> $porNormalizada */
        $porNormalizada = [];
        /** @var array<int, object{reference: string, factory_reference: ?string, provider_id: ?int}> $productos */
        $productos = [];
        foreach (DB::select("SELECT id, reference, factory_reference, provider_id FROM {$cp} WHERE reference IS NOT NULL AND trim(reference) <> '' ORDER BY id") as $r) {
            $id = (int) $r->id;
            $productos[$id] = (object) ['reference' => (string) $r->reference, 'factory_reference' => $r->factory_reference !== null ? (string) $r->factory_reference : null, 'provider_id' => $r->provider_id !== null ? (int) $r->provider_id : null];
            $porExacta[self::claveExacta((string) $r->reference)][] = $id;
            $porNormalizada[self::claveNormalizada((string) $r->reference)][] = $id;
        }

        /** @var array<int, true> $yaConBk productos que ya tienen a BK (principal o en la tabla de varios proveedores) */
        $yaConBk = [];
        foreach (DB::select("SELECT id FROM {$cp} WHERE provider_id = ?", [$proveedorId]) as $r) {
            $yaConBk[(int) $r->id] = true;
        }
        foreach (DB::select("SELECT catalog_product_id FROM {$pivote} WHERE provider_id = ?", [$proveedorId]) as $r) {
            $yaConBk[(int) $r->catalog_product_id] = true;
        }
        /** @var array<string, int> $factoryDeBk factory_reference → producto que ya está bajo BK con esa referencia de fábrica */
        $factoryDeBk = [];
        foreach ($yaConBk as $id => $_) {
            $fr = $productos[$id]->factory_reference ?? null;
            if ($fr !== null && $fr !== '') {
                $factoryDeBk[$fr] = $id;
            }
        }

        // Filas de Lafayette: solo las que siguen en la fuente. Agrupadas por referencia exacta para detectar duplicados.
        /** @var array<string, list<array{lafayette_id: string, referencia: string}>> $laf */
        $laf = [];
        /** @var list<array{lafayette_id: string, referencia: string}> $sinReferencia */
        $sinReferencia = [];
        foreach (LafayetteProducto::query()->whereNull('fuera_de_fuente_at')->orderBy('id')->get(['lafayette_id', 'referencia']) as $row) {
            $ref = trim((string) $row->referencia);
            if ($ref === '') {
                $sinReferencia[] = ['lafayette_id' => (string) $row->lafayette_id, 'referencia' => ''];

                continue;
            }
            $laf[self::claveExacta($ref)][] = ['lafayette_id' => (string) $row->lafayette_id, 'referencia' => $ref];
        }

        $filas = [];
        $asociaciones = [];
        $contadores = ['asociados' => 0, 'ya_asociados' => 0, 'adicionales' => 0, 'principales' => 0, 'pendientes' => 0, 'sin_coincidencia' => 0];
        /** @var array<string, true> $factoryUsadas */
        $factoryUsadas = [];

        $fila = function (array $f, string $estado, string $motivo, ?int $productoId = null, ?int $previo = null) use (&$filas, $productos): void {
            $filas[] = [
                'lafayette_id' => $f['lafayette_id'], 'referencia' => $f['referencia'], 'estado' => $estado, 'motivo' => $motivo,
                'catalog_product_id' => $productoId, 'reference_atlanticerp' => $productoId !== null ? ($productos[$productoId]->reference ?? null) : null,
                'proveedor_previo' => $previo,
            ];
        };

        foreach ($sinReferencia as $f) {
            $fila($f, self::ESTADO_SIN_COINCIDENCIA, 'sin referencia en Lafayette');
            $contadores['sin_coincidencia']++;
        }

        foreach ($laf as $clave => $grupo) {
            if (count($grupo) > 1) {
                foreach ($grupo as $f) {
                    $fila($f, self::ESTADO_PENDIENTE, 'varias filas de Lafayette con la misma referencia');
                    $contadores['pendientes']++;
                }

                continue;
            }

            $f = $grupo[0];
            $exactos = $porExacta[$clave] ?? [];
            $normalizados = $porNormalizada[self::claveNormalizada($f['referencia'])] ?? [];

            if ($exactos === []) {
                if ($normalizados === []) {
                    $fila($f, self::ESTADO_SIN_COINCIDENCIA, 'ninguna referencia equivalente en AtlanticERP');
                    $contadores['sin_coincidencia']++;
                } else {
                    $fila($f, self::ESTADO_PENDIENTE, 'solo coincide al normalizar la referencia (AtlanticERP: ' . implode(', ', array_map(static fn (int $i): string => $productos[$i]->reference, array_slice($normalizados, 0, 5))) . ')', $normalizados[0]);
                    $contadores['pendientes']++;
                }

                continue;
            }
            if (count($exactos) > 1) {
                $fila($f, self::ESTADO_PENDIENTE, 'varios productos de AtlanticERP con esa referencia (ids ' . implode(', ', array_slice($exactos, 0, 5)) . ')', $exactos[0]);
                $contadores['pendientes']++;

                continue;
            }

            $id = $exactos[0];
            if (count(array_diff($normalizados, [$id])) > 0) {
                $fila($f, self::ESTADO_PENDIENTE, 'ambigua: otra referencia de AtlanticERP se normaliza igual', $id, $productos[$id]->provider_id);
                $contadores['pendientes']++;

                continue;
            }
            if (isset($yaConBk[$id])) {
                $fila($f, self::ESTADO_ASOCIADO, 'ya estaba asociado a ' . self::PROVEEDOR . ' (sin cambios)', $id, $productos[$id]->provider_id);
                $contadores['asociados']++;
                $contadores['ya_asociados']++;

                continue;
            }
            $fr = $productos[$id]->factory_reference;
            if ($fr !== null && $fr !== '' && (isset($factoryDeBk[$fr]) || isset($factoryUsadas[$fr]))) {
                $fila($f, self::ESTADO_PENDIENTE, "choque de factory_reference '{$fr}' con otro producto ya bajo " . self::PROVEEDOR, $id, $productos[$id]->provider_id);
                $contadores['pendientes']++;

                continue;
            }
            if ($fr !== null && $fr !== '') {
                $factoryUsadas[$fr] = true;
            }

            $previo = $productos[$id]->provider_id;
            $tipo = $previo === null ? 'principal' : 'adicional';
            $asociaciones[$id] = $tipo;
            $tipo === 'principal' ? $contadores['principales']++ : $contadores['adicionales']++;
            $contadores['asociados']++;
            $fila($f, self::ESTADO_ASOCIADO, $tipo === 'principal' ? 'coincidencia exacta 1 a 1; sin proveedor previo (BK queda como principal)' : 'coincidencia exacta 1 a 1; ya tenía otro proveedor principal (BK se agrega como adicional)', $id, $previo);
        }

        ksort($asociaciones);
        $huella = md5(implode(';', array_map(static fn (int $id, string $t): string => "{$id}:{$t}", array_keys($asociaciones), $asociaciones)));

        return ['filas' => $filas, 'asociaciones' => $asociaciones, 'huella' => $huella] + $contadores;
    }

    /** @param array<string, mixed> $plan */
    private function reportar(array $plan): void
    {
        $this->info('lafayette:mapear-proveedor — ' . ($this->option('apply') ? 'APLICAR' : 'DRY-RUN') . ' — proveedor ' . self::PROVEEDOR);
        $this->table(['Medida', 'Valor'], [
            ['ASOCIADOS (coincidencia exacta 1 a 1)', $plan['asociados']],
            ['  nuevos: BK queda como proveedor PRINCIPAL (el producto no tenía)', $plan['principales']],
            ['  nuevos: BK se AGREGA como adicional (ya tenía otro principal)', $plan['adicionales']],
            ['  ya estaban asociados (sin cambios)', $plan['ya_asociados']],
            ['PENDIENTES DE REVISIÓN (ambiguas / solo normalizadas / choques)', $plan['pendientes']],
            ['SIN COINCIDENCIA (no se toca)', $plan['sin_coincidencia']],
            ['Huella del plan', $plan['huella']],
        ]);
    }

    /** @param array<string, mixed> $plan */
    private function escribirCsv(array $plan, string $modo): string
    {
        $dir = storage_path('app/lafayette-mapeo');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $ruta = $dir . '/' . now()->format('Ymd-His') . "-{$modo}.csv";
        $f = fopen($ruta, 'wb');
        if ($f === false) {
            throw new RuntimeException("No se pudo escribir {$ruta}");
        }
        fputcsv($f, ['lafayette_id', 'referencia', 'estado', 'motivo', 'catalog_product_id', 'reference_atlanticerp', 'proveedor_previo_id'], ',', '"', '\\');
        // La referencia viene de una fuente externa: una celda que empiece con = + - @ se abre en Excel como fórmula (inyección de CSV).
        $seguro = static fn (mixed $v): mixed => is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'".$v : $v;
        /** @var list<array<string, mixed>> $filas */
        $filas = $plan['filas'];
        foreach ($filas as $r) {
            fputcsv($f, array_map($seguro, [$r['lafayette_id'], $r['referencia'], $r['estado'], $r['motivo'], $r['catalog_product_id'], $r['reference_atlanticerp'], $r['proveedor_previo']]), ',', '"', '\\');
        }
        fclose($f);

        return $ruta;
    }

    /** @param array<string, mixed> $plan */
    private function aplicar(array $plan, int $proveedorId, string $slug): int
    {
        // El plan se recalcula dentro de la transacción: si algo cambió desde el dry-run, se aborta.
        $actual = $this->analizar($proveedorId);
        if ($actual['huella'] !== $plan['huella']) {
            throw new RuntimeException('la selección cambió entre el análisis y la escritura (huella distinta): repite el dry-run.');
        }

        $cp     = (new CatalogProduct())->getTable();
        $pivote = CatalogProduct::providersPivotTable();
        $aplicados = 0;
        /** @var array<int, string> $asociaciones */
        $asociaciones = $plan['asociaciones'];
        foreach ($asociaciones as $id => $tipo) {
            if ($tipo === 'principal') {
                $n = DB::affectingStatement("UPDATE {$cp} SET provider_id = ?, updated_at = now() WHERE id = ? AND provider_id IS NULL", [$proveedorId, $id]);
                if ($n !== 1) {
                    throw new RuntimeException("el producto {$id} ya tiene proveedor principal (cambió concurrentemente).");
                }
            }
            $creada = DB::table($pivote)->insertOrIgnore([['catalog_product_id' => $id, 'provider_id' => $proveedorId, 'created_at' => now(), 'updated_at' => now()]]);
            if ($creada !== 1 && $tipo === 'principal') {
                throw new RuntimeException("el producto {$id} ya tenía la fila de proveedor en la tabla de varios proveedores.");
            }
            $aplicados++;
        }
        if ($aplicados !== count($asociaciones)) {
            throw new RuntimeException("aplicados {$aplicados}, esperados " . count($asociaciones) . '.');
        }

        AuditLog::query()->create([
            'user_id'     => null,
            'action'      => AuditService::LAFAYETTE_MAPEAR_PROVEEDOR,
            'entity_type' => 'catalog_product',
            'metadata'    => [
                'operador'      => (string) $this->option('operador'),
                'usuario_so'    => get_current_user(),
                'host'          => (string) gethostname(),
                'entorno'       => (string) config('app.env'),
                'tenant'        => $slug,
                'proveedor_id'  => $proveedorId,
                'productos'     => $aplicados,
                'principales'   => $plan['principales'],
                'adicionales'   => $plan['adicionales'],
                'pendientes'    => $plan['pendientes'],
                'huella'        => $plan['huella'],
                // id → 'principal' | 'adicional': permite revertir con `--revert=<id de este evento>`.
                'asociaciones_aplicadas' => $asociaciones,
            ],
        ]);

        return $aplicados;
    }

    private function revertir(int $eventoId, string $slug): int
    {
        $evento = AuditLog::query()->where('id', $eventoId)->where('action', AuditService::LAFAYETTE_MAPEAR_PROVEEDOR)->first();
        if ($evento === null) {
            $this->error("No existe un evento {$eventoId} de " . AuditService::LAFAYETTE_MAPEAR_PROVEEDOR . '.');

            return self::FAILURE;
        }
        $meta = (array) ($evento->metadata ?? []);
        /** @var array<int|string, string> $aplicadas */
        $aplicadas = (array) ($meta['asociaciones_aplicadas'] ?? []);
        $proveedorId = (int) ($meta['proveedor_id'] ?? 0);
        if ($aplicadas === [] || $proveedorId === 0) {
            $this->error('El evento no guarda asociaciones aplicadas.');

            return self::FAILURE;
        }

        $cp     = (new CatalogProduct())->getTable();
        $pivote = CatalogProduct::providersPivotTable();
        try {
            $revertidos = DB::transaction(function () use ($aplicadas, $proveedorId, $cp, $pivote, $slug, $eventoId): int {
                $n = 0;
                foreach ($aplicadas as $id => $tipo) {
                    if ($tipo === 'principal') {
                        // Solo si el principal aún vale BK: un producto reasignado a mano después no se toca.
                        if (DB::affectingStatement("UPDATE {$cp} SET provider_id = NULL, updated_at = now() WHERE id = ? AND provider_id = ?", [(int) $id, $proveedorId]) === 1) {
                            DB::table($pivote)->where('catalog_product_id', (int) $id)->where('provider_id', $proveedorId)->delete();
                            $n++;
                        }

                        continue;
                    }
                    // Adicional: el principal nunca se tocó; se quita solo la fila de BK en la tabla de varios proveedores.
                    $n += DB::table($pivote)->where('catalog_product_id', (int) $id)->where('provider_id', $proveedorId)->delete();
                }
                AuditLog::query()->create([
                    'user_id'     => null,
                    'action'      => AuditService::LAFAYETTE_MAPEAR_PROVEEDOR . '.reverted',
                    'entity_type' => 'catalog_product',
                    'metadata'    => ['operador' => (string) $this->option('operador'), 'tenant' => $slug, 'evento_revertido' => $eventoId, 'revertidos' => $n, 'evaluados' => count($aplicadas)],
                ]);

                return $n;
            });
        } catch (Throwable $e) {
            $this->error('ROLLBACK: no se revirtió nada. ' . $this->mensajeSeguro($e));

            return self::FAILURE;
        }

        $this->info("Revertidas {$revertidos} de " . count($aplicadas) . ' asociaciones (las reasignadas después se dejaron intactas).');

        return self::SUCCESS;
    }
}
