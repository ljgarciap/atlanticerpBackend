<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

use App\Modules\AdminCont\FacturacionElectronica\Mappers\MslJsonMapper;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use Throwable;

/**
 * SCRUM-943 (pedido de Luis, 2026-09-25) — en `pac_ambiente: apagado` (o cualquier otro momento)
 * nunca se llama al PAC de verdad, pero Gerencia/el equipo quiere poder validar que la ESTRUCTURA del
 * documento que se armaría es correcta, sin certificar nada. Reusa `FiscalDocumentBuilder`
 * (mismo ensamblado que usaría la certificación real) + `MslJsonMapper` (mismo shape exacto que
 * `MslPacAdapter` enviaría) — nunca una segunda copia de ninguna de las 2 lógicas.
 *
 * A propósito NO gateado por `pac_ambiente`/`pac_provider` — sirve igual para revisar la
 * estructura en sandbox/producción si hiciera falta depurar algo, no solo en apagado. El ambiente
 * mostrado en el JSON refleja el que de verdad aplicaría según `pac_ambiente` (`apagado` →
 * 'sin envío', `testing` → 'pruebas', `production` → 'produccion') — solo se LEE el interruptor,
 * nunca se resuelven credenciales ni se arma el adapter.
 *
 * Errores de ensamblado (ej. NC cuya factura de origen todavía no tiene CUFE — esperable en
 * `apagado`, donde ninguna factura se certifica nunca) se devuelven como parte del resultado, no
 * como excepción — es información de diagnóstico tan válida como el payload mismo.
 */
final class FiscalDocumentPreviewService
{
    public function __construct(
        private readonly FiscalDocumentBuilder $builder,
        private readonly MslJsonMapper $payloadMapper,
    ) {
    }

    /** @return array{ok: bool, payload?: array<string, mixed>, error?: string} */
    public function paraFactura(AdminContInvoice $invoice): array
    {
        return $this->intentar(fn () => $this->builder->paraFactura($invoice));
    }

    /** @return array{ok: bool, payload?: array<string, mixed>, error?: string} */
    public function paraNotaCredito(AdminContCreditNote $creditNote): array
    {
        return $this->intentar(fn () => $this->builder->paraNotaCredito($creditNote));
    }

    /** @return array{ok: bool, payload?: array<string, mixed>, error?: string} */
    private function intentar(\Closure $armar): array
    {
        try {
            $documento = $armar();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'payload' => $this->payloadMapper->map($documento, $this->ambienteMostrado())];
    }

    private function ambienteMostrado(): string
    {
        $settings = AdminContFiscalSettings::current();
        if ($settings->sinFacturacionElectronica()) {
            return 'sin envío';
        }

        return $settings->pac_ambiente === 'production' ? 'produccion' : 'pruebas';
    }
}
