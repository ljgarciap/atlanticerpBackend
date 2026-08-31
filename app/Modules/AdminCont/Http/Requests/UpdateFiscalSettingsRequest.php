<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `pac_provider`, `pac_last_sync_at` y `primary_approver_user_id` a propósito NO están en las
 * reglas — `validated()` solo devuelve las llaves declaradas acá, así que aunque el cliente los
 * mande en el body nunca llegan al `update()` del modelo (RN3 de REQ-555, RN1 de REQ-557).
 */
class UpdateFiscalSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el gate real es el middleware EnsureIsPrimaryApprover sobre toda la ruta
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'razon_social' => ['sometimes', 'string', 'max:255'],
            'nombre_comercial' => ['sometimes', 'string', 'max:255'],
            'ruc' => ['sometimes', 'string', 'max:50'],
            'dv' => ['sometimes', 'string', 'max:10'],
            'direccion_fiscal' => ['sometimes', 'string', 'max:500'],
            'regimen_tributario' => ['sometimes', 'in:general,pequeno_contribuyente'],
            'pac_ambiente' => ['sometimes', 'in:production,testing'],
            'pac_doc_factura_habilitado' => ['sometimes', 'boolean'],
            'pac_doc_nota_credito_habilitado' => ['sometimes', 'boolean'],
            'retencion_proveedores_activa' => ['sometimes', 'boolean'],
            // Batch 4 de Facturación (REQ-450) — plazo de crédito usado por "Antigüedad de
            // cartera" para calcular la fecha de vencimiento de cada factura.
            'dias_credito_factura' => ['sometimes', 'integer', 'min:1', 'max:365'],
            // Batch 11 de Notas Crédito (REQ-482 RN3) — umbral de monto que dispara "pendiente de
            // aprobación de Mark", compartido a futuro con Facturación y Comisiones Externas.
            'primary_approval_threshold' => ['sometimes', 'numeric', 'min:0'],
            // Batch 18 de Arqueo de Caja (REQ-520 RN1) — ver ADR-SCRUM597-601, reconciliación
            // punto 1: valor manual hasta que Batch 20 (Caja Chica) lo reemplace por un saldo
            // calculado desde un ledger real.
            'petty_cash_balance' => ['sometimes', 'numeric', 'min:0'],
            // Batch 21 de Caja Chica (REQ-542) — Senior Review hallazgo #5: umbral de "cuántos
            // rechazos antes de pasar permanentemente a Rechazados", nunca hardcodeado en el código.
            'petty_cash_max_intentos_rechazo' => ['sometimes', 'integer', 'min:1'],
            // Batch 19 de Arqueo de Caja (REQ-527) — ver ADR-SCRUM602-606-batch19-arqueo-caja-parte2.md,
            // reconciliación punto 4: piso desde el cual el bloqueo secuencial exige cierre.
            'arqueo_tracking_start_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
