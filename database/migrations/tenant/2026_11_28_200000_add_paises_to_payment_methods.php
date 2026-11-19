<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 34b (docs/specs/fase34-vocabularios-por-empresa.md) — la forma de pago `yappy` solo se ofrece en Panamá: se le asigna la propiedad
 * `paises = ['PA']` (el resto de formas queda sin la propiedad = todos los países). Lo ya cobrado con ella se sigue mostrando; solo deja de
 * ofrecerse para cobros nuevos en una empresa de otro país. Aditiva; `down()` quita la propiedad.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        $this->setPaises(['PA']);
    }

    public function down(): void
    {
        $this->setPaises(null);
    }

    /** @param list<string>|null $paises */
    private function setPaises(?array $paises): void
    {
        $table = $this->table('catalog_entries');
        $entry = DB::table($table)->where('domain', 'payment_method')->where('key', 'yappy')->first();
        if ($entry === null) {
            return;
        }
        $props = json_decode((string) $entry->props, true) ?: [];
        if ($paises === null) {
            unset($props['paises']);
        } else {
            $props['paises'] = $paises;
        }
        DB::table($table)->where('id', $entry->id)->update(['props' => json_encode($props), 'updated_at' => now()]);
    }
};
