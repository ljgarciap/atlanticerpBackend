<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1025 — el apellido de Anelhys "Annie" Nuñez quedó sembrado como "Nuez" (sin la ñ) en
 * `BusinessRoleUserSeeder`, y en pantalla ("Diseñador a cargo") se leía "Vendedor Disenador Test 6".
 *
 * Por qué hace falta esta migración además de corregir el seeder: `BusinessRoleUserSeeder` es
 * create-only-if-not-exists y NUNCA actualiza a un usuario que ya existe (mismo criterio que
 * `CoreUserSeeder`, ver la lección de las contraseñas, 2026-07-01). Corregir el seeder arregla solo
 * los entornos que se creen desde cero; dev, test y producción ya tienen la fila con "Nuez".
 *
 * El nombre se lee siempre en vivo de `users` (no hay copias en cotizaciones ni tarjetas), así que
 * corregir esta fila corrige todas las vistas a la vez.
 *
 * Solo toca la fila si todavía dice EXACTAMENTE "Nuez": si alguien ya la corrigió o la editó a mano
 * desde Usuarios, no se pisa. Idempotente.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const EMAIL = 'vendedordisenador6@test.com';

    public function up(): void
    {
        DB::table($this->table('users'))
            ->where('email', self::EMAIL)
            ->where('last_name', 'Nuez')
            ->update(['last_name' => 'Nuñez', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Revierte solo lo que esta migración cambió (no toca un apellido editado después).
        DB::table($this->table('users'))
            ->where('email', self::EMAIL)
            ->where('last_name', 'Nuñez')
            ->update(['last_name' => 'Nuez', 'updated_at' => now()]);
    }
};
