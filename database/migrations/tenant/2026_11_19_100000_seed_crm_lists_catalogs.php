<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 25 (docs/specs/fase25-crm-listas-y-enlaces.md) — las listas fijas del CRM pasan a catálogos de la empresa:
 * `crm_project_type`, `crm_activity_type`, `crm_reminder_type` y `crm_document_category`.
 *
 *  - Quita los CHECK que fijaban los valores en la base (`projects.tipo`, `project_activities.tipo`, `project_documents.categoria`, y
 *    `contacts.tipo`, que la Fase 14c dejó puesto aunque el catálogo ya admitía roles propios). La validación vive en la aplicación.
 *  - Siembra los valores históricos con las MISMAS claves (ningún dato cambia) y cualquier otro valor ya guardado, para que nada quede huérfano.
 *    Si la entrada ya existe no se pisa.
 *
 * `down()` borra las entradas; los CHECK solo se restauran si todos los valores guardados son los originales.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    /** @var array<string, array{table: string, column: string, check: ?string, values: array<string, array{string, string}>}> */
    private const LISTAS = [
        'crm_project_type' => [
            'table' => 'projects', 'column' => 'tipo', 'check' => 'projects_tipo_check',
            'values' => ['diseno' => ['Diseño', 'Design'], 'cotizacion' => ['Cotización', 'Quote'], 'ambos' => ['Ambos', 'Both']],
        ],
        'crm_activity_type' => [
            'table' => 'project_activities', 'column' => 'tipo', 'check' => 'project_activities_tipo_check',
            'values' => ['llamada' => ['Llamada', 'Call'], 'email' => ['Email', 'Email'], 'reunion' => ['Reunión', 'Meeting'], 'whatsapp' => ['WhatsApp', 'WhatsApp'], 'visita' => ['Visita', 'Visit'], 'otro' => ['Otro', 'Other']],
        ],
        'crm_reminder_type' => [
            'table' => 'project_reminders', 'column' => 'tipo', 'check' => null,
            'values' => ['llamada' => ['Llamada', 'Call'], 'email' => ['Email', 'Email'], 'reunion' => ['Reunión', 'Meeting'], 'whatsapp' => ['WhatsApp', 'WhatsApp'], 'visita' => ['Visita', 'Visit'], 'cotizacion' => ['Cotización', 'Quote'], 'diseno' => ['Diseño', 'Design'], 'otro' => ['Otro', 'Other']],
        ],
        'crm_document_category' => [
            'table' => 'project_documents', 'column' => 'categoria', 'check' => 'project_documents_categoria_check',
            'values' => ['cotizacion' => ['Cotización', 'Quote'], 'diseno' => ['Diseño', 'Design'], 'presentacion' => ['Presentación', 'Presentation'], 'plano' => ['Plano', 'Plan'], 'render' => ['Render', 'Render'], 'ficha' => ['Ficha', 'Data sheet'], 'modelo_3d' => ['Modelo 3D', '3D model'], 'otro' => ['Otro', 'Other']],
        ],
    ];

    public function up(): void
    {
        DB::statement(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS contacts_tipo_check', $this->table('contacts')));

        $entradas = Tenant::current()?->slug.'_auth.catalog_entries'; // el catálogo vive en el schema `auth`
        $now = now();
        foreach (self::LISTAS as $dominio => $lista) {
            if ($lista['check'] !== null) {
                DB::statement(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', $this->table($lista['table']), $lista['check']));
            }

            $orden = 0;
            foreach ($lista['values'] as $clave => [$etiqueta, $ingles]) {
                $orden += 10;
                if (DB::table($entradas)->where('domain', $dominio)->where('key', $clave)->exists()) {
                    continue;
                }
                DB::table($entradas)->insert([
                    'domain' => $dominio, 'key' => $clave, 'label' => $etiqueta, 'labels' => json_encode(['en' => $ingles], JSON_UNESCAPED_UNICODE),
                    'sort_order' => $orden, 'is_active' => true, 'is_system' => false, 'props' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $enUso = DB::table($this->table($lista['table']))->whereNotNull($lista['column'])->where($lista['column'], '!=', '')
                ->whereNotIn($lista['column'], array_keys($lista['values']))->distinct()->orderBy($lista['column'])->pluck($lista['column']);
            foreach ($enUso as $clave) {
                $orden += 10;
                DB::table($entradas)->insert([
                    'domain' => $dominio, 'key' => $clave, 'label' => ucfirst(str_replace('_', ' ', (string) $clave)), 'sort_order' => $orden,
                    'is_active' => true, 'is_system' => false, 'props' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $entradas = Tenant::current()?->slug.'_auth.catalog_entries';
        DB::table($entradas)->whereIn('domain', array_keys(self::LISTAS))->delete();

        foreach (self::LISTAS as $lista) {
            if ($lista['check'] === null) {
                continue;
            }
            $fuera = DB::table($this->table($lista['table']))->whereNotIn($lista['column'], array_keys($lista['values']))->exists();
            if (! $fuera) {
                $valores = "'".implode("','", array_keys($lista['values']))."'";
                DB::statement(sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s IN (%s))', $this->table($lista['table']), $lista['check'], $lista['column'], $valores));
            }
        }
    }
};
