<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 14b (docs/specs/fase14-parametrizacion.md) — siembra el dominio `payment_method` con las formas de pago de siempre y sus
 * propiedades de comportamiento, sin cambiar ningún valor actual:
 *  - `sin_cuenta_bancaria`: retención de impuestos y ajustes de cuenta (REQ-466 RN4).
 *  - `inmediato`: efectivo y esas dos (REQ-469): se confirman en el momento y entran al arqueo del día.
 *  - `caja_chica`: las 4 que ya ofrecía el pago de reportes de caja chica (transferencia, efectivo, cheque, Yappy).
 * Yappy solo se siembra si el país de la empresa lo ofrece (`config/regions.php`) o si la empresa ya tiene cobros con él: nunca se deja un
 * cobro existente sin su forma de pago. `retencion_impuestos` y `ajuste_cuenta` son de sistema. Aditiva; `down()` borra las entradas.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{string, string, bool, bool, bool, bool}> clave, etiqueta, sin cuenta, inmediato, caja chica, de sistema */
    private const BASE = [
        ['transferencia', 'Transferencia', false, false, true, false],
        ['cheque', 'Cheque', false, false, true, false],
        ['efectivo', 'Efectivo', false, true, true, false],
        ['tarjeta', 'Tarjeta', false, false, false, false],
        ['deposito', 'Depósito bancario', false, false, false, false],
        ['yappy', 'Yappy', false, false, true, false],
        ['link_pago', 'Link de pago', false, false, false, false],
        ['retencion_impuestos', 'Retención de impuestos', true, true, false, true],
        ['ajuste_cuenta', 'Ajustes de cuenta', true, true, false, true],
    ];

    public function up(): void
    {
        $tenant = Tenant::current() ?? throw new \RuntimeException('No hay tenant activo.');
        $local = array_keys((array) config('regions.local_payment_methods.'.($tenant->country ?? 'PA'), []));
        $now = now();

        $order = 0;
        foreach (self::BASE as [$key, $label, $sinCuenta, $inmediato, $cajaChica, $system]) {
            // Una forma propia de ciertos países (Yappy) solo se siembra donde existe o donde ya hay cobros con ella.
            $regional = array_key_exists($key, $this->allRegional());
            if ($regional && ! in_array($key, $local, true) && ! $this->inUse($tenant->slug, $key)) {
                continue;
            }
            DB::table($this->table('catalog_entries'))->insert([
                'domain' => 'payment_method', 'key' => $key, 'label' => $label, 'sort_order' => ++$order, 'is_active' => true, 'is_system' => $system,
                'props' => json_encode(['sin_cuenta_bancaria' => $sinCuenta, 'inmediato' => $inmediato, 'caja_chica' => $cajaChica]),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('catalog_entries'))->where('domain', 'payment_method')->delete();
    }

    /** @return array<string, string> todas las formas regionales conocidas (de cualquier país) */
    private function allRegional(): array
    {
        return array_merge([], ...array_values((array) config('regions.local_payment_methods', [])));
    }

    private function inUse(string $slug, string $key): bool
    {
        $schema = "{$slug}_admin_contab";

        return DB::table("{$schema}.payments")->where('metodo_pago', $key)->exists()
            || DB::table("{$schema}.abonos")->where('metodo_pago', $key)->exists()
            || DB::table("{$schema}.petty_cash_reports")->where('forma_pago', $key)->exists();
    }
};
