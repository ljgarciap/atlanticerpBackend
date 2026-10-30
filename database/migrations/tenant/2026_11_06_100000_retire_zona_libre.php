<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 14f-2 (docs/specs/fase14f-retiro-zona-libre.md) — retira del esquema todo lo que quedó de Zona Libre tras 14f-1 (el código).
 *
 * Borra: las tablas de solicitudes (`bodega_zona_libre_request_lines`, `bodega_zona_libre_requests`, `bodega_zona_libre_folio_counters`),
 * la columna `settings.zona_libre_provider_id`, el permiso `compras.zona-libre.approve` (catálogo, niveles de seguridad y permisos
 * adicionales por usuario) y las reglas de aprobación de Gerencia del tipo `zona_libre`. Convierte las órdenes de modalidad `zona_libre` a
 * `directo` y la categoría de proveedor `zona_libre` a una entrada `internacional` (se crea si no existe; mismo origen internacional).
 * NO toca `bodega.read` del Líder de Compras (se le dio para esa pantalla, pero la tiene para el resto de Bodega: quitarla sería un cambio de acceso aparte).
 *
 * Seguridad de datos (IRREVERSIBLE: `down()` no restaura nada):
 *  - En cualquier entorno aborta si queda una solicitud `pendiente` (decisión 4: cerrarlas antes).
 *  - Fuera de local/dev/testing aborta también si las tablas de solicitudes tienen alguna fila: exportar antes y respaldo obligatorio.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    /** Entornos donde los datos de estas tablas son de demostración y se pueden descartar. */
    private const ENTORNOS_DESCARTABLES = ['local', 'dev', 'testing'];

    private const PERMISO = 'compras.zona-libre.approve';

    public function up(): void
    {
        $slug = (string) Tenant::current()?->slug;
        $compras = "{$slug}_compras";
        $auth = "{$slug}_auth";

        $this->verificarSolicitudes($compras);

        DB::transaction(function () use ($slug, $compras, $auth): void {
            // Órdenes: la modalidad desaparece como concepto; las que la tenían pasan a la única vigente.
            DB::statement("UPDATE {$compras}.purchase_orders SET modality = 'directo' WHERE modality = 'zona_libre'");

            DB::statement("DROP TABLE IF EXISTS {$compras}.bodega_zona_libre_request_lines");
            DB::statement("DROP TABLE IF EXISTS {$compras}.bodega_zona_libre_requests");
            DB::statement("DROP TABLE IF EXISTS {$compras}.bodega_zona_libre_folio_counters");
            DB::statement("ALTER TABLE {$compras}.settings DROP COLUMN IF EXISTS zona_libre_provider_id");

            // Permiso: catálogo, escape hatch de los niveles y permisos adicionales de cada usuario (jsonb: `-` quita el elemento de texto).
            DB::table("{$auth}.permission_catalog")->where('key', self::PERMISO)->delete();
            DB::statement("UPDATE {$auth}.security_levels SET permissions = permissions - '".self::PERMISO."' WHERE jsonb_exists(permissions, '".self::PERMISO."')");
            DB::statement("UPDATE {$auth}.users SET extra_permissions = extra_permissions - '".self::PERMISO."' WHERE jsonb_exists(extra_permissions, '".self::PERMISO."')");

            // Reglas de aprobación de Gerencia del tipo que ya no existe (y sus aprobadores, por la FK en cascada).
            DB::statement("DELETE FROM {$slug}_gerencia.reglas_aprobacion WHERE tipo = 'zona_libre'");

            $this->migrarCategoriaDeProveedor($compras, $auth);
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Fase 14f-2 es irreversible: las solicitudes, el permiso y la categoría de Zona Libre no se restauran. Restaura el respaldo previo.');
    }

    private function verificarSolicitudes(string $compras): void
    {
        $existe = DB::table('information_schema.tables')->where('table_schema', $compras)->where('table_name', 'bodega_zona_libre_requests')->exists();
        if (! $existe) {
            return;
        }

        $pendientes = (int) DB::table("{$compras}.bodega_zona_libre_requests")->where('status', 'pendiente')->count();
        if ($pendientes > 0) {
            throw new \RuntimeException("Hay {$pendientes} solicitud(es) de Zona Libre pendiente(s): ciérralas (aprobar o rechazar) antes de retirar el flujo.");
        }

        if (! app()->environment(self::ENTORNOS_DESCARTABLES)) {
            $total = (int) DB::table("{$compras}.bodega_zona_libre_requests")->count();
            if ($total > 0) {
                throw new \RuntimeException("Las solicitudes de Zona Libre tienen {$total} fila(s): exporta el historial y haz un respaldo antes de migrar este entorno.");
            }
        }
    }

    /** La categoría `zona_libre` pasa a `internacional` (mismo origen); la entrada vieja se borra. */
    private function migrarCategoriaDeProveedor(string $compras, string $auth): void
    {
        $entradas = DB::table("{$auth}.catalog_entries")->where('domain', 'provider_category');
        if (! (clone $entradas)->where('key', 'zona_libre')->exists()) {
            return;
        }

        if (! (clone $entradas)->where('key', 'internacional')->exists()) {
            DB::table("{$auth}.catalog_entries")->insert([
                'domain' => 'provider_category', 'key' => 'internacional', 'label' => 'Internacional',
                'sort_order' => (int) (clone $entradas)->max('sort_order') + 1, 'is_active' => true, 'is_system' => false,
                'props' => json_encode(['origen' => 'internacional', 'valida_identificacion' => false]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table("{$compras}.providers")->where('category', 'zona_libre')->update(['category' => 'internacional']);
        DB::table("{$auth}.catalog_entries")->where('domain', 'provider_category')->where('key', 'zona_libre')->delete();
    }
};
