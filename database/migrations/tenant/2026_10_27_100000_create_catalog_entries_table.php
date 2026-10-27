<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 7a (docs/specs/fase7-catalogos.md §6) — catálogos de vocabulario como DATOS por empresa. Una sola tabla con una fila por entrada
 * de cada dominio (`provider_category`, `architect_type`…): clave estable que guardan las demás tablas, etiqueta editable, orden, y
 * `props` con el COMPORTAMIENTO que el código consulta (p. ej. el origen local/internacional de una categoría de proveedor).
 *
 * Aditiva: se siembran EXACTAMENTE los valores que antes eran constantes PHP (`Provider::CATEGORIES`, `Architect::TYPES`) con las
 * propiedades que hoy tenían implícitas, así que con los datos por defecto nada cambia. `is_system = true` = el código aún referencia
 * esa clave: se puede renombrar la etiqueta y reordenar, pero no borrar.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var array<string, list<array{0: string, 1: string, 2: array<string, mixed>}>> */
    private array $seed = [
        'provider_category' => [
            ['locales', 'Locales', ['origen' => 'local', 'valida_identificacion' => true]],
            ['zona_libre', 'Zona Libre', ['origen' => 'internacional', 'valida_identificacion' => false]],
            ['china', 'China', ['origen' => 'internacional', 'valida_identificacion' => false]],
            ['europa', 'Europa', ['origen' => 'internacional', 'valida_identificacion' => false]],
            ['online', 'Online', ['origen' => 'internacional', 'valida_identificacion' => false]],
            ['latinoamerica', 'Latinoamérica', ['origen' => 'internacional', 'valida_identificacion' => false]],
        ],
        'architect_type' => [
            ['architect', 'Arquitecto', []],
            ['designer', 'Diseñador', []],
            ['contractor', 'Contratista', []],
            ['engineer', 'Ingeniero', []],
            ['consultant', 'Consultor', []],
            ['other', 'Otro', []],
        ],
    ];

    public function up(): void
    {
        Schema::create($this->table('catalog_entries'), function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 40);
            $table->string('key', 60);
            $table->string('label', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->jsonb('props')->nullable();
            $table->timestamps();

            $table->unique(['domain', 'key']);
        });

        $now = now();
        foreach ($this->seed as $domain => $entries) {
            foreach ($entries as $i => [$key, $label, $props]) {
                DB::table($this->table('catalog_entries'))->insert([
                    'domain' => $domain, 'key' => $key, 'label' => $label, 'sort_order' => $i, 'is_active' => true, 'is_system' => true,
                    'props' => $props === [] ? null : json_encode($props), 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('catalog_entries'));
    }
};
