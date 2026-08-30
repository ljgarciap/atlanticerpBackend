<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-638/639/642 (REQ-561/562/565) — fila única (id=1) de Datos de la Empresa. `razon_social`
 * y `nombre_comercial` NO viven acá — son de solo lectura en esta pantalla (RN1 de REQ-562, RN3 de
 * REQ-561), espejo en vivo de `AdminContFiscalSettings::current()` (Configuración Fiscal, batch
 * anterior) para nunca tener 2 fuentes de verdad del mismo dato. `moneda`/`anio_fiscal` (RN1 de
 * REQ-565) son fijos, nunca editables desde ningún PUT — viven como columna configurable (no
 * constante de código) por la misma regla de "nunca hardcodear umbrales/valores de negocio" del
 * proyecto, aunque hoy no exista ninguna pantalla que los cambie.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('company_profile'), function (Blueprint $table): void {
            $table->id();
            $table->string('logo_key')->nullable();
            $table->string('logo_public_url')->nullable();
            $table->string('descripcion_corta', 500)->default('');
            $table->string('sitio_web')->nullable();
            $table->json('redes_sociales')->nullable();
            $table->string('moneda')->default('USD');
            $table->string('anio_fiscal')->default('Enero-Diciembre');
            $table->string('zona_horaria')->default('America/Panama');
            $table->timestamps();
        });

        DB::table($this->table('company_profile'))->insert([
            'id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('company_profile'));
    }
};
