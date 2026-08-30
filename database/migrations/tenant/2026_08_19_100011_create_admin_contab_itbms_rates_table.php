<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-635/636 (REQ-558/559) — catálogo de tasas de ITBMS. Las 3 tasas base (RN1 de REQ-558)
 * se siembran acá directo (es_base=true, activa=true desde el inicio) porque son fijas del
 * sistema, nunca se crean por request — `es_base` queda fuera de `$fillable` del modelo a
 * propósito, solo estas 3 filas lo tienen en true.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('itbms_rates'), function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('descripcion', 1000);
            $table->decimal('porcentaje', 5, 2);
            $table->boolean('es_base')->default(false);
            $table->boolean('activa')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table($this->table('itbms_rates'))->insert([
            ['nombre' => '7% — General', 'descripcion' => 'Tasa general de ITBMS.', 'porcentaje' => 7.00, 'es_base' => true, 'activa' => true, 'created_at' => $now, 'updated_at' => $now],
            ['nombre' => '3.5% — Retención de impuesto', 'descripcion' => 'Retención de impuesto (50% del 7% de ITBMS).', 'porcentaje' => 3.50, 'es_base' => true, 'activa' => true, 'created_at' => $now, 'updated_at' => $now],
            ['nombre' => '0% — Exento', 'descripcion' => 'Operaciones exentas de ITBMS.', 'porcentaje' => 0.00, 'es_base' => true, 'activa' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('itbms_rates'));
    }
};
