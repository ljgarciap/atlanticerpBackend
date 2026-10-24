<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

use App\Models\User;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentResult;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFeTransmision;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCRUM-1186 (hotfix) — correo a los usuarios de `fiscal_settings.fe_rechazo_notificar_user_ids` (hoy Gerencia Test 4 y Luis García) cuando la DGI
 * rechaza una factura o una nota de crédito. Hasta ahora un rechazo solo quedaba en `fe_estado`/`fe_detalle` y nadie se enteraba hasta abrir
 * Facturación (26 facturas rechazadas se acumularon entre el 6 y el 21 de octubre de 2026).
 *
 * Decisiones de Luis (2026-10-21):
 *  - SOLO en producción (`pac_ambiente = production`): los rechazos de dev, test y sandbox son parte normal de las pruebas de QA.
 *  - SOLO el estado `rechazado` (HTTP 422 del PAC). `error_configuracion` y `pendiente` no avisan.
 *  - Un correo por transmisión rechazada (dedupe por `source_ref`); un reintento que vuelve a rechazarse es otra transmisión y avisa de nuevo.
 *
 * NUNCA rompe la certificación: cualquier falla se registra en el log y se ignora. El texto del motivo es el de `fe_detalle`, que ya pasó por
 * `FiscalCertificationService::sanitizarDetalle()` (sin API keys); el correo no lleva el RUC del cliente.
 */
final class FeRechazoNotifier
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function avisar(AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento, FiscalDocumentResult $resultado): void
    {
        if ($resultado->estado !== 'rechazado') {
            return;
        }

        try {
            $this->enviar($modelo, $tipoDocumento);
        } catch (Throwable $e) {
            Log::warning('Facturación electrónica: no se pudo enviar el aviso de documento rechazado', [
                'id' => $modelo->id, 'tipo' => $tipoDocumento, 'exception' => $e->getMessage(), 'exception_class' => $e::class,
            ]);
        }
    }

    private function enviar(AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento): void
    {
        $settings = AdminContFiscalSettings::current();
        if ($settings->pac_ambiente !== 'production') {
            return;
        }

        /** @var list<int> $configurados */
        $configurados = array_values(array_filter(array_map('intval', (array) ($settings->fe_rechazo_notificar_user_ids ?? [])), static fn (int $id): bool => $id > 0));
        if ($configurados === []) {
            return;
        }
        /** @var list<int> $destinatarios */
        $destinatarios = User::query()->whereIn('id', $configurados)->where('is_active', true)->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($destinatarios === []) {
            return;
        }

        $transmisiones = AdminContFeTransmision::query()->where('documento_tipo', $tipoDocumento)->where('documento_id', $modelo->id);
        $ultima        = (clone $transmisiones)->orderByDesc('id')->first();
        $sourceRef     = "{$tipoDocumento}:{$modelo->id}:tx:".($ultima->id ?? 0);

        if (NotificationSend::query()->where('source', NotificationSend::SOURCE_FE_DOCUMENTO_RECHAZADO)->where('source_ref', $sourceRef)->exists()) {
            return;   // misma transmisión ya avisada
        }

        $intento = (clone $transmisiones)->where('resultado', 'rechazado')->count();
        $this->notifications->send(
            audience: $destinatarios,
            content: $this->contenido($modelo, $tipoDocumento, $ultima, max(1, $intento), $settings),
            channels: [NotificationSend::CHANNEL_EMAIL],
            source: NotificationSend::SOURCE_FE_DOCUMENTO_RECHAZADO,
            sourceRef: $sourceRef,
        );
    }

    /** Los mensajes de la DGI (p. ej. 9032) pueden citar el RUC o la cédula del cliente: el correo no los lleva. */
    private function sinIdentificaciones(string $texto): string
    {
        // Formatos con letras o espacios (8-NT-2-1234, 8 123 4567, PAS-123456, PA1234567) antes del patrón general con guiones.
        $texto = (string) preg_replace(['/\b\d{1,2}-[A-Z]{1,3}-\d{1,4}-\d{1,6}\b/iu', '/\b\d{1,2}[ ]\d{2,4}[ ]\d{3,6}\b/u', '/\b[A-Z]{1,3}-?\d{5,}\b/u'], '[identificación omitida]', $texto);
        $texto = (string) preg_replace('/\b(?:[A-Z]{1,3}-)?\d{1,4}(?:-\d{1,6}){1,3}(?:\s*DV\s*\d+)?\b/iu', '[identificación omitida]', $texto);

        return (string) preg_replace('/\b\d{7,}\b/u', '[identificación omitida]', $texto);
    }

    /** @return array{subject: string, body: string, cta_url: string} */
    private function contenido(AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento, ?AdminContFeTransmision $ultima, int $intento, AdminContFiscalSettings $settings): array
    {
        $esFactura = $modelo instanceof AdminContInvoice;
        $etiqueta  = $esFactura ? 'factura' : 'nota de crédito';
        $fiscal    = $ultima !== null && $ultima->serie !== null ? "{$ultima->serie}-{$ultima->numero_fiscal}" : (string) $modelo->numero;
        $zona      = (string) config('app.timezone', 'America/Panama');

        $motivo = trim($this->sinIdentificaciones((string) $modelo->fe_detalle));
        $motivo = $motivo !== '' ? mb_substr($motivo, 0, 900) : 'La DGI no devolvió un motivo legible; revisa la transmisión en Facturación.';

        $lineas = [
            "La DGI rechazó la {$etiqueta} {$modelo->numero} (número fiscal {$fiscal}) enviada desde AtlanticERP en PRODUCCIÓN.",
            '',
            "Motivo del rechazo:\n{$motivo}",
            '',
            'Cliente: '.(trim((string) $modelo->cliente) !== '' ? $modelo->cliente : '—'),
            'Monto: B/. '.number_format((float) $modelo->monto, 2),
            "Intento número: {$intento}",
            'Fecha del rechazo: '.now()->setTimezone($zona)->format('d/m/Y H:i')." (hora de Panamá)",
        ];

        $horas = (int) $settings->fe_reintento_max_horas;
        if ($esFactura && $modelo->fe_emision_at !== null && $horas > 0) {
            $lineas[] = 'Ventana de reintento hasta: '.$modelo->fe_emision_at->copy()->addHours($horas)->setTimezone($zona)->format('d/m/Y H:i').' (pasada esa hora la factura requiere acción manual).';
        }
        $lineas[] = '';
        $lineas[] = $esFactura
            ? 'Abre Facturación para revisar el motivo y decidir si se reintenta o se corrige.'
            : 'Abre Notas de crédito para revisar el motivo y decidir si se reintenta o se corrige.';

        return [
            'subject' => "DGI rechazó la {$etiqueta} {$modelo->numero} ({$fiscal})",
            'body'    => implode("\n", $lineas),
            'cta_url' => $esFactura ? '/admin-contab/facturacion' : '/admin-contab/notas-credito',
        ];
    }
}
