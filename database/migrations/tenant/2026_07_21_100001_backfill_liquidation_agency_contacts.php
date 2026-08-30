<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-255 (REQ-192) — cualquier agencia ya creada con el contacto inline viejo
 * (`contact_name`/`phone`/`email`, SCRUM-210) migra a una fila real en
 * `liquidation_agency_contacts`, para que nunca quede una agencia con 0 contactos apenas se activa
 * la regla nueva. Las 3 columnas viejas quedan en la tabla (deprecated, sin drop) — ver nota de
 * alcance en docs/architecture/compras-sustitutos-pagos-agencias-reclamos-reportes.md.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        $agencies = DB::table($this->table('liquidation_agencies'))
            ->whereNotNull('contact_name')
            ->get(['id', 'contact_name', 'phone', 'email']);

        foreach ($agencies as $agency) {
            DB::table($this->table('liquidation_agency_contacts'))->insert([
                'liquidation_agency_id' => $agency->id,
                'name'                  => $agency->contact_name,
                'phone'                 => $agency->phone,
                'email'                 => $agency->email,
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible a propósito — down() de create_..._contacts_table ya vacía la tabla completa.
    }
};
