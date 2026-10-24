<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\FacturacionElectronica\Services\TaxIdValidationService;
use App\Modules\VentasDiseno\Exceptions\SubClientTaxDataLockedException;
use App\Modules\VentasDiseno\Http\Requests\UpdateSubClientTaxDataRequest;
use App\Modules\VentasDiseno\Http\Requests\ValidateTaxIdRequest;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\SubClientTaxDataService;
use App\Modules\VentasDiseno\Support\ReceptorTipo;
use App\Shared\Http\Pagination;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-1002 — validar un RUC/Cédula contra la DGI (vía MSL) y editar los datos fiscales de un SubCliente.
 * La validación nunca responde 5xx por una falla del PAC (devuelve `disponible: false` + `codigo`) y jamás expone
 * la respuesta cruda ni la API key.
 */
final class ClientTaxDataController extends Controller
{
    public function __construct(
        private readonly TaxIdValidationService $validation,
        private readonly SubClientTaxDataService $taxData,
        private readonly AuditService $audit,
    ) {
    }

    /** POST /api/ventas-diseno/clients/validate-tax-id */
    public function validateTaxId(ValidateTaxIdRequest $request): JsonResponse
    {
        return response()->json($this->validation->validar((string) $request->validated('tax_id')));
    }

    /**
     * GET /api/ventas-diseno/clients/fiscal-directory — directorio de SubClientes con sus datos fiscales para quien los
     * edita (Mark/Felix desde Admin&Cont, o Ventas & Diseño). Paginado, máx. 100 por página ("all" se recorta a 100);
     * `bloqueado` = ya tiene documentos certificados/pendientes y el PATCH lo rechazaría con 409.
     */
    public function directory(Request $request): JsonResponse
    {
        $pagination = Pagination::params($request, [], 'business_name');
        $perPage = min($pagination['per_page'], 100);
        $busqueda = $request->query('search');
        // Mismo tope que el validador de RUC: una búsqueda no necesita más de 100 caracteres.
        $paginador = $this->taxData->directorio(is_string($busqueda) ? mb_substr($busqueda, 0, 100) : null, $perPage, $pagination['page']);

        $bloqueados = array_flip($this->taxData->conDocumentosFiscales(array_values(array_map('intval', $paginador->getCollection()->pluck('id')->all()))));

        return response()->json([
            'data' => $paginador->getCollection()->map(static fn (SubClient $s): array => [
                'id'                 => $s->id,
                'master_client_id'   => $s->master_client_id,
                'master_client_name' => $s->masterClient->name,
                'business_name'      => $s->business_name,
                'receptor_tipo'      => $s->receptor_tipo ?? ReceptorTipo::CONTRIBUYENTE,
                'tax_id'             => $s->tax_id,
                'tax_id_dv'          => $s->tax_id_dv,
                'bloqueado'          => isset($bloqueados[$s->id]),
                // SCRUM-1166 — exención de ITBMS (solo lectura aquí; se cambia desde Admin&Cont con su permiso angosto).
                'itbms_exento'             => $s->itbms_exento,
                'itbms_exencion_documento' => $s->itbms_exencion_documento,
            ])->values(),
            'meta' => Pagination::meta($paginador),
        ]);
    }

    /** PATCH /api/ventas-diseno/clients/sub-clients/{subClientId}/tax-data */
    public function updateTaxData(UpdateSubClientTaxDataRequest $request, int $subClientId): JsonResponse
    {
        $subClient = SubClient::find($subClientId);
        if ($subClient === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{business_name: string, receptor_tipo?: string|null, tax_id?: string|null, tax_id_dv?: string|null} $data */
        $data = $request->validated();

        // SCRUM-1003 — PASAR un cliente a Consumidor Final exige el permiso angosto (Mark/Felix). Editar otros datos de uno que
        // ya lo es (p. ej. la razón social) no cambia el tipo y queda abierto a quien ya edita en Ventas & Diseño.
        if (($data['receptor_tipo'] ?? null) === ReceptorTipo::CONSUMIDOR_FINAL
            && $subClient->receptor_tipo !== ReceptorTipo::CONSUMIDOR_FINAL
            && ! $this->usuario($request)?->hasPermission(ReceptorTipo::PERMISO_CONSUMIDOR_FINAL)) {
            return response()->json(['message' => ReceptorTipo::MENSAJE_SIN_PERMISO], 403);
        }

        // SCRUM-1166 — si el cliente es exento y cambian su RUC, DV o tipo ante la DGI, la exención (y su documento) se conserva pero hay que revisarla:
        // puede ser otra entidad o un Consumidor Final. No se quita sola; se avisa y queda en auditoría.
        $eraExento = $subClient->itbms_exento;
        $antes     = ['tax_id' => $subClient->tax_id, 'tax_id_dv' => $subClient->tax_id_dv, 'receptor_tipo' => $subClient->receptor_tipo ?? ReceptorTipo::CONTRIBUYENTE];

        try {
            $subClient = $this->taxData->update($subClient, $data);
        } catch (SubClientTaxDataLockedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $despues = ['tax_id' => $subClient->tax_id, 'tax_id_dv' => $subClient->tax_id_dv, 'receptor_tipo' => $subClient->receptor_tipo ?? ReceptorTipo::CONTRIBUYENTE];
        $revisar = $eraExento && $antes !== $despues;
        if ($revisar) {
            $this->audit->log(
                action:     AuditService::ADMIN_CONT_SUB_CLIENT_ITBMS_EXENTO_REVISAR,
                entityType: 'sub_client',
                entityId:   $subClient->id,
                oldValues:  $antes,
                newValues:  $despues,
                metadata:   ['documento' => $subClient->itbms_exencion_documento],
            );
        }

        return response()->json([
            'id'               => $subClient->id,
            'master_client_id' => $subClient->master_client_id,
            'business_name'    => $subClient->business_name,
            'receptor_tipo'    => $subClient->receptor_tipo ?? ReceptorTipo::CONTRIBUYENTE,
            'tax_id'           => $subClient->tax_id,
            'tax_id_dv'        => $subClient->tax_id_dv,
            'itbms_exento'     => $subClient->itbms_exento,
            'exencion_a_revisar' => $revisar,
        ]);
    }

    private function usuario(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        return is_array($payload) && isset($payload['sub']) ? User::find((int) $payload['sub']) : null;
    }
}
