<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-300 (rebote QA 2026-08-13) — `ServiciosSettingsService::textSetting()` graba el default
 * en `servicios_settings` la primera vez que se lee (no es un fallback sin estado), así que el
 * valor de EJEMPLO del mockup (5A__Servicios_Tickets.html) que se sembró por error ya quedó
 * persistido en cada entorno donde se abrió Cotización de Servicio al menos una vez desde el
 * Batch 12 (2026-08-12). Corregir solo la constante en `ServiciosSettingsService` no alcanza —
 * esta migración corrige el dato ya guardado.
 *
 * Solo pisa el valor si sigue siendo EXACTAMENTE el texto viejo (mockup) — si Gerencia ya lo
 * editó a mano por su cuenta desde entonces, esa edición real gana y esta migración no la toca.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    private const KEY = 'condiciones_cotizacion_servicios';

    private const OLD_VALUE =
        "50% de anticipo para agendar, 50% contra entrega\n"
        ."Cotización válida por 15 días\n"
        ."Garantía de mano de obra e instalación: 90 días desde la fecha de entrega\n"
        ."Los productos suministrados mantienen la garantía de fábrica correspondiente\n"
        ."Precios sujetos a cambio sin previo aviso pasada la fecha de validez";

    private const NEW_VALUE =
        "Cotización válida por 10 días a partir de la fecha de emisión.\n"
        ."Forma de pago: 100% de pago completo antes de generar la instalación.\n"
        ."Garantía de mano de obra e instalación: 30 días desde la fecha de entrega.\n"
        ."Los productos suministrados mantienen la garantía de fábrica correspondiente (no la garantía del servicio).\n"
        ."Precios sujetos a cambio sin previo aviso una vez vencida la fecha de validez de la cotización.";

    public function up(): void
    {
        DB::table($this->table('servicios_settings'))
            ->where('key', self::KEY)
            ->where('value', self::OLD_VALUE)
            ->update(['value' => self::NEW_VALUE]);
    }

    public function down(): void
    {
        DB::table($this->table('servicios_settings'))
            ->where('key', self::KEY)
            ->where('value', self::NEW_VALUE)
            ->update(['value' => self::OLD_VALUE]);
    }
};
