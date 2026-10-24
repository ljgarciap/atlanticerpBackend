<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\VentasDiseno\Models\CotizaYaSettings;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Support\ReceptorTipo;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * SCRUM-1120 — deja listo el Cliente Master y el Subcliente «Cotización» (antes «CotizaYA») que usa todo el módulo CotizaYA y registra sus
 * ids en `cotizaya_settings` (los ids difieren por entorno, nunca se hardcodean).
 *
 * Idempotente y de solo-crear: en producción el cliente «CotizaYA» ya existe (creado a mano el 2026-10-07) y se
 * reutiliza tal cual, buscándolo por nombre sin distinguir mayúsculas — no se modifica, no se duplica. En dev/test/
 * instalaciones nuevas se crea con la misma forma (walk-in, consumidor final). Nunca pisa una fila de ajustes ya
 * existente. Se salta en `testing`: los tests crean su propio fixture (el cliente aparecería en conteos de clientes).
 */
class CotizaYaClientSeeder extends Seeder
{
    public const CLIENT_NAME = 'Cotización';

    public function run(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $tenant = Tenant::where('slug', 'atlantic')->first();
        if ($tenant === null) {
            $this->command?->warn('Tenant "atlantic" not found — CotizaYaClientSeeder skipped.');
            return;
        }

        $tenant->makeCurrent();

        try {
            self::ensureClient();
        } finally {
            Tenant::forgetCurrent();
        }
    }

    /**
     * Cliente + subcliente + ajustes del tenant actual. Público para que los tests armen el mismo fixture.
     */
    public static function ensureClient(): CotizaYaSettings
    {
        $existing = CotizaYaSettings::current();
        if ($existing !== null) {
            return $existing;
        }

        $master = MasterClient::query()
            ->whereRaw('lower(name) in (?, ?)', [mb_strtolower(self::CLIENT_NAME), 'cotizaya']) // «CotizaYA» = nombre anterior
            ->orderBy('id')
            ->first()
            ?? MasterClient::create(['name' => self::CLIENT_NAME, 'default_price_type' => 'public']);

        $sub = SubClient::query()->where('master_client_id', $master->id)->orderBy('id')->first()
            ?? SubClient::create([
                'master_client_id' => $master->id,
                'business_name'    => self::CLIENT_NAME,
                'tax_id'           => '', // columna NOT NULL; en prod el subcliente CotizaYA la tiene vacía (consumidor final)
                'receptor_tipo'    => ReceptorTipo::CONSUMIDOR_FINAL,
                'delivery_address' => 'NoAplica',
                'category'         => 'a_walkin',
            ]);

        return CotizaYaSettings::create(['master_client_id' => $master->id, 'sub_client_id' => $sub->id]);
    }
}
