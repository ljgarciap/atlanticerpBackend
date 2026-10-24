<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\FacturacionElectronica\Services\ReceptorFiscalGuard;
use App\Modules\AdminCont\FacturacionElectronica\Services\TaxIdValidationService;
use App\Modules\AdminCont\Http\Requests\CorrectInvoiceReceptorRequest;
use App\Modules\VentasDiseno\Exceptions\SubClientTaxDataLockedException;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\SubClientTaxDataService;
use App\Modules\VentasDiseno\Support\ReceptorTipo;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Guarda de RUC al facturar (docs/specs/guarda-validacion-ruc-al-facturar.md): corregir el receptor desde la vista previa.
 * Los datos candidatos se validan contra la DGI ANTES de guardarse — un RUC que no coincide nunca llega a `sub_clients`.
 * El guardado reutiliza `SubClientTaxDataService` (mismo candado 409 por documentos certificados/pendientes y mismo
 * rastro en `audit_logs` que la edición desde Clientes).
 */
final class AdminContInvoiceReceptorController extends Controller
{
    public const PERMISO = 'admin_contab.edit.receptor_fiscal';

    /** SCRUM-1140 — facturar a Consumidor Final por decisión de quien factura (va a quien factura, ver SpecialPermissionSeeder). */
    public const PERMISO_CONSUMIDOR_FINAL = 'admin_contab.edit.receptor_consumidor_final';

    public function __construct(
        private readonly ReceptorFiscalGuard $guard,
        private readonly SubClientTaxDataService $taxData,
        private readonly AuditService $audit,
    ) {
    }

    /** PATCH /api/admin-contab/invoices/receptors/{subClientId} */
    public function update(CorrectInvoiceReceptorRequest $request, int $subClientId): JsonResponse
    {
        $subClient = SubClient::find($subClientId);
        if ($subClient === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{business_name: string, tax_id: string, tax_id_dv?: string|null} $data */
        $data = $request->validated();

        if ($this->taxData->conDocumentosFiscales([$subClient->id]) !== []) {
            return response()->json(['message' => $this->mensajeBloqueo()], 409);
        }

        $evaluacion = $this->guard->evaluarDatos($subClient, ReceptorTipo::CONTRIBUYENTE, $data['tax_id'], $data['tax_id_dv'] ?? null, $data['business_name']);

        // SCRUM-1140: la razón social distinta ya no bloquea la FACTURA, pero este endpoint GUARDA datos en el cliente: sigue
        // exigiendo que la razón social coincida con la de la DGI (el frontend manda la que devolvió la DGI), para que
        // `sub_clients` nunca reciba un nombre arbitrario por esta vía.
        // Salvo que sea EXACTAMENTE la razón social que devolvió la DGI (texto externo ya validado), el nombre pasa por la
        // regex estricta del formulario.
        $nombreDgi = is_string($evaluacion['nombre_dgi'] ?? null) ? $evaluacion['nombre_dgi'] : null;
        if ($data['business_name'] !== $nombreDgi && preg_match(CorrectInvoiceReceptorRequest::NOMBRE_REGEX, $data['business_name']) !== 1) {
            return response()->json([
                'message' => 'La razón social solo admite letras, números, espacios y . , & \' ( ) / # - +.',
                'errors'  => ['business_name' => ['La razón social solo admite letras, números, espacios y . , & \' ( ) / # - +.']],
            ], 422);
        }

        if ($evaluacion['bloquea'] === true || $evaluacion['estado'] === ReceptorFiscalGuard::NOMBRE_DISTINTO) {
            return response()->json(['message' => $evaluacion['mensaje'], 'receptor' => $evaluacion], 422);
        }

        $nombreAnterior = (string) $subClient->business_name;

        try {
            $this->taxData->update($subClient, [
                'business_name' => $data['business_name'],
                'receptor_tipo' => ReceptorTipo::CONTRIBUYENTE,
                'tax_id'        => $data['tax_id'],
                'tax_id_dv'     => $data['tax_id_dv'] ?? null,
            ]);
        } catch (SubClientTaxDataLockedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        // SCRUM-1140 — "Actualizar cliente": rastro propio cuando el cliente adopta la razón social de la DGI.
        if ($nombreAnterior !== $data['business_name']) {
            $this->audit->log(
                action:     AuditService::ADMIN_CONT_SUB_CLIENT_RAZON_SOCIAL_ACTUALIZADA,
                entityType: 'sub_client',
                entityId:   $subClient->id,
                oldValues:  ['business_name' => $nombreAnterior],
                newValues:  ['business_name' => $data['business_name']],
                metadata:   ['origen' => 'vista_previa_factura', 'documento' => TaxIdValidationService::enmascarar($data['tax_id'])],
            );
        }

        return response()->json(['receptor' => $evaluacion]);
    }

    private function mensajeBloqueo(): string
    {
        return 'No se pueden editar la razón social ni el RUC/DV: este cliente ya tiene facturas o notas de crédito '
            .'certificadas (o pendientes de certificación) ante la DGI.';
    }
}
