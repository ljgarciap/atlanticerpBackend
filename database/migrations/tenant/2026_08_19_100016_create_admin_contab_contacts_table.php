<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SCRUM-641 (REQ-564) — contactos generales por área (texto libre), usados en plantillas de comunicación automática. */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('contacts'), function (Blueprint $table): void {
            $table->id();
            $table->string('area');
            $table->string('email');
            $table->string('telefono', 50);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('contacts'));
    }
};
