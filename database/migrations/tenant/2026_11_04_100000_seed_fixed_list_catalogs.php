<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 14c (docs/specs/fase14-parametrizacion.md) — siembra los cuatro dominios que eran listas fijas del código, con los mismos valores y
 * orden de siempre (cero cambio de comportamiento). Las entradas con significado para la lógica son de SISTEMA y no se borran:
 *  - `contact_role`: las 4 son columnas de «proyecto» que importa el directorio.
 *  - `adjustment_reason.OTRO_ESPECIFICAR` y `return_reason.otra`: piden texto libre.
 *  - `technician_specialty`: `general` y `warranty` (la lógica de tickets de garantía pregunta por `warranty`).
 * Aditiva: no toca las tablas de origen. `down()` borra las entradas de los cuatro dominios.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var array<string, list<array{string, string, bool}>> clave, etiqueta, de sistema */
    private const ENTRIES = [
        'contact_role' => [
            ['developer', 'Desarrollador', true], ['arquitecto', 'Arquitecto', true], ['encargado', 'Encargado', true], ['contacto', 'Contacto', true],
        ],
        'adjustment_reason' => [
            ['CONTEO_FISICO_NO_COINCIDE', 'Conteo físico no coincide con el sistema', false],
            ['PRODUCTO_DANADO_ROTO', 'Producto dañado/roto', false],
            ['PRODUCTO_VENCIDO_OBSOLETO', 'Producto vencido/obsoleto', false],
            ['ERROR_DE_REGISTRO', 'Error de registro (entrada o salida mal digitada)', false],
            ['ROBO_O_PERDIDA', 'Robo o pérdida', false],
            ['OTRO_ESPECIFICAR', 'Otro (especificar)', true],
        ],
        'return_reason' => [
            ['danado_defectuoso', 'Producto dañado / defectuoso', false],
            ['no_corresponde', 'No corresponde a lo pedido', false],
            ['cliente_cambio_opinion', 'Cliente cambió de opinión', false],
            ['excedente', 'Excedente — sobró material', false],
            ['error_instalacion', 'Error en la instalación / medidas', false],
            ['otra', 'Otro (especificar)', true],
        ],
        'technician_specialty' => [
            ['general', 'General', true], ['warranty', 'Garantías', true],
        ],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::ENTRIES as $domain => $entries) {
            foreach ($entries as $i => [$key, $label, $system]) {
                DB::table($this->table('catalog_entries'))->insert([
                    'domain' => $domain, 'key' => $key, 'label' => $label, 'sort_order' => $i + 1, 'is_active' => true, 'is_system' => $system,
                    'props' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table($this->table('catalog_entries'))->whereIn('domain', array_keys(self::ENTRIES))->delete();
    }
};
