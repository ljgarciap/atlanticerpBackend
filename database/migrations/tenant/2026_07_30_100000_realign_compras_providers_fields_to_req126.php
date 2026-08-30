<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-189/190/192 (REQ-126/127/129) — el form de Proveedor implementado en sprint2 divergió del
 * mockup real `2G__Compras_Proveedores.html` (hallazgo de Daniela Amaya, 2026-07-30, autorizado
 * por Luis el mismo día): faltan whatsapp/dirección/país en Contacto y IBAN/SWIFT/Beneficiario en
 * Datos bancarios; `bank_account_type` y `notes` no existen en el mockup.
 *
 * `bank_account_number` se reusa tal cual como "Cuenta / IBAN" del mockup (mismo dato, ninguna
 * orden real distingue cuenta local de IBAN) — no se agrega una columna redundante para eso.
 *
 * `notes` se elimina recién después de mover su único uso real (ZonaLibreProviderSeeder, que lo
 * usaba como placeholder de dirección antes de que este campo existiera) a la columna `address`
 * nueva — ver ese seeder. `bank_account_type` no tiene ningún otro consumidor (confirmado por
 * grep antes de esta migración), se elimina sin reemplazo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('providers'), function (Blueprint $table): void {
            $table->string('whatsapp')->nullable()->after('contact_name');
            $table->string('address')->nullable()->after('email');
            $table->string('country')->nullable()->after('address');
            $table->string('bank_swift')->nullable()->after('bank_account_number');
            $table->string('bank_beneficiary')->nullable()->after('bank_swift');
        });

        // Único uso real de `notes` en Provider (ver ZonaLibreProviderSeeder) — se preserva el
        // dato migrándolo a `address` antes de eliminar la columna, en vez de perderlo.
        $rows = DB::table($this->table('providers'))->whereNotNull('notes')->where('notes', '!=', '')->get(['id', 'notes', 'address']);
        foreach ($rows as $row) {
            if ($row->address === null) {
                DB::table($this->table('providers'))->where('id', $row->id)->update(['address' => $row->notes]);
            }
        }

        Schema::table($this->table('providers'), function (Blueprint $table): void {
            $table->dropColumn(['bank_account_type', 'notes']);
        });
    }

    public function down(): void
    {
        Schema::table($this->table('providers'), function (Blueprint $table): void {
            $table->string('bank_account_type')->nullable();
            $table->text('notes')->nullable();
            $table->dropColumn(['whatsapp', 'address', 'country', 'bank_swift', 'bank_beneficiary']);
        });
    }
};
