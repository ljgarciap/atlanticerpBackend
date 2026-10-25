<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Support;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;

/**
 * Fase 4a (docs/specs/fase4-dataset-demo-generico.md, §3.3) — «Instrucciones de pago» de las cotizaciones (Ventas & Diseño y Servicios) leídas de los
 * DATOS de la empresa (perfil fiscal + cuenta corriente activa), no escritas a mano en las plantillas. Antes el cheque a nombre de una razón social y el
 * número de cuenta del cliente anterior estaban en el código y salían en cada PDF. Sin datos cargados no se muestra el bloque (no se inventa nada).
 */
final class QuotePaymentInstructions
{
    /** @return list<string> líneas del bloque; vacío si la empresa no tiene razón social ni cuenta corriente con número completo. */
    public static function lines(): array
    {
        // Sin tenant activo no hay datos de empresa que leer (p. ej. renders de plantilla en tests unitarios).
        if (Tenant::current() === null) {
            return [];
        }

        $lines = [];

        $fiscal = AdminContFiscalSettings::find(1);
        $payee = $fiscal === null ? '' : trim((string) ($fiscal->razon_social !== '' ? $fiscal->razon_social : $fiscal->nombre_comercial));
        if ($payee !== '') {
            $lines[] = "Cheque a nombre de: {$payee}";
        }

        $account = AdminContBankAccount::query()
            ->where('activa', true)
            ->where('tipo_cuenta', AdminContBankAccount::TIPO_CORRIENTE)
            ->whereNotNull('numero_cuenta_completo')
            ->where('numero_cuenta_completo', '!=', '')
            ->orderBy('id')
            ->first();
        if ($account !== null) {
            $lines[] = "Pagos ACH: Cuenta {$account->numero_cuenta_completo}, cuenta corriente, {$account->banco}";
        }

        return $lines;
    }
}
