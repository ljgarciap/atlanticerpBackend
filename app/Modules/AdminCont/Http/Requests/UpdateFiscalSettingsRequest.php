<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `pac_provider`, `pac_last_sync_at`, `primary_approver_user_id` y `serie_fiscal` a propósito NO
 * están en las reglas — `validated()` solo devuelve las llaves declaradas acá, así que aunque el
 * cliente los mande en el body nunca llegan al `update()` del modelo (RN3 de REQ-555, RN1 de
 * REQ-557; `serie_fiscal` — SCRUM-941, es un identificador registrado ante el PAC, no un valor que
 * Gerencia deba poder cambiar sin coordinar con el proveedor).
 */
class UpdateFiscalSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el gate real es el middleware EnsureIsPrimaryApprover sobre toda la ruta
    }

    /**
     * Pre-QA SCRUM-857/858 (2026-09-09) — el middleware global ConvertEmptyStringsToNull convierte
     * un "" enviado a null antes de que corran estas reglas, así que un intento legítimo de VACIAR
     * un campo de texto ya cargado quedaba como null y era rechazado por `string`. Estos campos son
     * NOT NULL DEFAULT '' en la BD y el código los trata como "" cuando están vacíos — la
     * normalización null → "" se hace en prepareForValidation() abajo.
     */
    protected function prepareForValidation(): void
    {
        $all = $this->all();
        $coerced = [];
        foreach (['razon_social', 'nombre_comercial', 'ruc', 'dv', 'direccion_fiscal', 'telefono_contacto', 'correo_contacto'] as $field) {
            if (array_key_exists($field, $all) && $all[$field] === null) {
                $coerced[$field] = '';
            }
        }
        if ($coerced !== []) {
            $this->merge($coerced);
        }
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
            // SCRUM-550 (rebote 2026-09-10) — contacto de la empresa mostrado en el recibo de
            // cobro (RN1). El formato de email solo se exige cuando el campo no está vacío —
            // 'email' rechaza "" y el campo es NOT NULL DEFAULT '' en BD (nunca null, ver
            // prepareForValidation() arriba), así que 'nullable' no serviría acá.
            'telefono_contacto' => ['sometimes', 'string', 'max:50'],
            'correo_contacto' => [
                'sometimes', 'string', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== '' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $fail('El correo de contacto no tiene un formato válido.');
                    }
                },
            ],
            'regimen_tributario' => ['sometimes', 'in:general,pequeno_contribuyente'],
            // SCRUM-943 — 'apagado' agregado: AtlanticERP genera el documento igual, nunca lo envía al
            // PAC (ver PacProviderFactory/FiscalCertificationService::debeCertificar()).
            'pac_ambiente' => ['sometimes', 'in:apagado,production,testing'],
            'pac_doc_factura_habilitado' => ['sometimes', 'boolean'],
            'pac_doc_nota_credito_habilitado' => ['sometimes', 'boolean'],
            'retencion_proveedores_activa' => ['sometimes', 'boolean'],
            // Batch 4 de Facturación (REQ-450) — plazo de crédito usado por "Antigüedad de
            // cartera" para calcular la fecha de vencimiento de cada factura.
            'dias_credito_factura' => ['sometimes', 'integer', 'min:1', 'max:365'],
            // Batch 11 de Notas Crédito (REQ-482 RN3) — umbral de monto que dispara "pendiente de
            // aprobación de Gerencia", compartido a futuro con Facturación y Comisiones Externas.
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
            // SCRUM-1019 — horas desde la primera emisión (fe_emision_at) tras las cuales un documento no se reenvía al PAC.
            'fe_reintento_max_horas' => ['sometimes', 'integer', 'min:1', 'max:720'],
            // SCRUM-1186 — usuarios que reciben el correo cuando la DGI rechaza un documento en producción (lista vacía = nadie).
            'fe_rechazo_notificar_user_ids' => ['sometimes', 'array', 'list', 'max:20'],
            'fe_rechazo_notificar_user_ids.*' => [
                static function (string $atributo, mixed $valor, \Closure $fallar): void {
                    if (! is_int($valor)) {
                        $fallar('Cada destinatario debe ser el id numérico de un usuario.');
                    }
                },
                'distinct',
                Rule::exists('users', 'id')->where('is_active', true),
            ],
        ];
    }
}
