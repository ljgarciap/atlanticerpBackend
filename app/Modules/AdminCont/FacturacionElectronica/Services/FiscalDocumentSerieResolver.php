<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;

/**
 * SCRUM-941 "Abierto #2" (ver docblock de la migración `add_serie_fiscal_...`): la serie es
 * configurable en `fiscal_settings.serie_fiscal` (default `'ILU'`, valor real que MSL ya usó para
 * Illumination), nunca hardcodeada en el adapter. Notas de Crédito derivan su propia serie con
 * prefijo `NC-{serie_fiscal}` en el momento del wiring, sin columna separada por tipo de documento
 * — mismo patrón de los ejemplos de MSL ("ILU" / "NC-ILU"). Notas de Débito (`ND-{serie_fiscal}`)
 * no se resuelven acá todavía: AtlanticERP no las modela (ver docblock de `FiscalDocumentReference`).
 */
final class FiscalDocumentSerieResolver
{
    public function paraFactura(): string
    {
        return AdminContFiscalSettings::current()->serie_fiscal;
    }

    public function paraNotaCredito(): string
    {
        return 'NC-'.AdminContFiscalSettings::current()->serie_fiscal;
    }
}
