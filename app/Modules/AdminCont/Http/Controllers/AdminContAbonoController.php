<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Services\PaymentMethods;
use App\Models\User;
use App\Modules\AdminCont\Exceptions\AbonoStateException;
use App\Modules\AdminCont\Http\Requests\StoreAbonoRequest;
use App\Modules\AdminCont\Http\Requests\UpdateAbonoRequest;
use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Services\AbonoReceiptPdfService;
use App\Modules\AdminCont\Services\AbonoService;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** SCRUM-902 — Registrar Abono (4O). */
class AdminContAbonoController
{
    public function __construct(
        private readonly AbonoService $abonos,
        private readonly AbonoReceiptPdfService $receiptPdf,
        private readonly \App\Modules\AdminCont\Services\CobrosConfigService $cobrosConfig,
    ) {}

    /**
     * GET /api/admin-contab/abonos?search=&master_client_id=&sales_project_id=&invoice_id=&estado=&metodo_pago=&desde=&hasta=&page=&per_page=
     * — §2.1. SCRUM-947 — paginación estándar (Pagination::ofArray(), el listado ya se arma
     * como array en el servicio, no una query Eloquent pura — ver AbonoService::list()).
     * metodo_pago/desde/hasta agregados en SCRUM-948 para igualar los criterios de búsqueda de
     * Historial de cobros.
     */
    public function index(Request $request): JsonResponse
    {
        $rows = $this->abonos->list([
            'search'           => $request->filled('search') ? $request->string('search')->toString() : null,
            'master_client_id' => $request->filled('master_client_id') ? (int) $request->integer('master_client_id') : null,
            'sales_project_id' => $request->filled('sales_project_id') ? (int) $request->integer('sales_project_id') : null,
            'invoice_id'       => $request->filled('invoice_id') ? (int) $request->integer('invoice_id') : null,
            'estado'           => $request->filled('estado') ? $request->string('estado')->toString() : null,
            'metodo_pago'      => $request->filled('metodo_pago') ? $request->string('metodo_pago')->toString() : null,
            'desde'            => $request->filled('desde') ? $request->string('desde')->toString() : null,
            'hasta'            => $request->filled('hasta') ? $request->string('hasta')->toString() : null,
        ]);

        $pagination = Pagination::params($request, [], 'fecha_registro', 'desc');
        $paginator  = Pagination::ofArray(array_values($rows), $pagination['per_page'], $pagination['page']);

        return response()->json(['data' => $paginator->items(), 'meta' => Pagination::meta($paginator)]);
    }

    /** GET /api/admin-contab/abonos/{id} — §2.4 detalle. */
    public function show(int $id): JsonResponse
    {
        $abono = $this->abonos->find($id);

        return $abono === null ? response()->json(['message' => 'Not found'], 404) : response()->json($abono);
    }

    /** POST /api/admin-contab/abonos — §2.2 registro. */
    public function store(StoreAbonoRequest $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        // SCRUM-1168 F2 — con el concepto nuevo encendido ya no hay abonos: el dinero que entra sin factura es un cobro.
        if ($this->cobrosConfig->v2Activo()) {
            return response()->json(['message' => 'Los abonos se registran ahora como cobros (con o sin factura) desde Cobros.'], 422);
        }

        // SCRUM-1168 F0 — sub_client/proyecto/factura de otro cliente → 422 (antes no se validaba la pertenencia).
        try {
            $abono = $this->abonos->register([
                'master_client_id'   => (int) $request->validated('master_client_id'),
                'sub_client_id'      => $request->validated('sub_client_id') !== null ? (int) $request->validated('sub_client_id') : null,
                'sales_project_id'   => $request->validated('sales_project_id') !== null ? (int) $request->validated('sales_project_id') : null,
                'invoice_id'         => $request->validated('invoice_id') !== null ? (int) $request->validated('invoice_id') : null,
                'monto_original'     => (float) $request->validated('monto_original'),
                'metodo_pago'        => (string) $request->validated('metodo_pago'),
                'cuenta_bancaria_id' => (int) $request->validated('cuenta_bancaria_id'),
                'referencia'         => (string) $request->validated('referencia'),
            ], $request->file('comprobante'), $actor);
        } catch (AbonoStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->abonos->find($abono->id), 201);
    }

    /** PUT /api/admin-contab/abonos/{id} — §2.4, solo si `por_confirmar`. */
    public function update(int $id, UpdateAbonoRequest $request): JsonResponse
    {
        try {
            $abono = $this->abonos->update($id, [
                'sub_client_id'      => $request->validated('sub_client_id') !== null ? (int) $request->validated('sub_client_id') : null,
                'sales_project_id'   => $request->validated('sales_project_id') !== null ? (int) $request->validated('sales_project_id') : null,
                'invoice_id'         => $request->validated('invoice_id') !== null ? (int) $request->validated('invoice_id') : null,
                'monto_original'     => (float) $request->validated('monto_original'),
                'metodo_pago'        => (string) $request->validated('metodo_pago'),
                'cuenta_bancaria_id' => (int) $request->validated('cuenta_bancaria_id'),
                'referencia'         => (string) $request->validated('referencia'),
            ], $request->file('comprobante'));
        } catch (AbonoStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->abonos->find($abono->id));
    }

    /** GET /api/admin-contab/abonos/{id}/pdf — SCRUM-955 punto 2, recibo formal. */
    public function pdf(int $id): \Illuminate\Http\Response
    {
        $abono = $this->abonos->findModel($id);
        if ($abono === null) {
            abort(404);
        }

        return $this->receiptPdf->generate($this->presentReceiptDetail($abono))->stream("recibo-abono-{$abono->numero_recibo}.pdf");
    }

    /** @return array<string, mixed> */
    private function presentReceiptDetail(AdminContAbono $abono): array
    {
        $creator = $abono->registrado_por !== null ? User::find($abono->registrado_por) : null;
        $cliente = MasterClient::find($abono->master_client_id);

        // SCRUM-955 punto 5 (nivel de asociación) — el recibo debe dejar claro a qué se aplica el
        // abono, mismo vocabulario que `AdminContAbono::nivelAsociacion()`.
        $invoice  = $abono->invoice;
        $concepto = match ($abono->nivelAsociacion()) {
            AdminContAbono::NIVEL_FACTURA  => 'Factura '.($invoice !== null ? $invoice->numero : '—'),
            AdminContAbono::NIVEL_PROYECTO => 'Saldo a favor de proyecto',
            default                        => 'Saldo a favor de cliente',
        };

        return [
            'numero_recibo'      => $abono->numero_recibo,
            'fecha_registro'     => $abono->fecha_registro->toIso8601String(),
            'estado'             => $abono->estado,
            'cliente'            => $cliente?->name,
            'concepto'           => $concepto,
            'metodo_pago_label'  => PaymentMethods::label($abono->metodo_pago),
            'bank_account'       => $abono->cuentaBancaria?->label(),
            'referencia'         => $abono->referencia,
            'registrado_por'     => $creator !== null ? trim("{$creator->first_name} {$creator->last_name}") : null,
            'monto_original'     => $abono->monto_original,
        ];
    }

    /** POST /api/admin-contab/abonos/{id}/confirm — §2.3. */
    public function confirm(int $id, Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        try {
            $abono = $this->abonos->confirm($id, $actor);
        } catch (AbonoStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->abonos->find($abono->id));
    }

    /** GET /api/admin-contab/abonos/cascada/sub-clientes?master_client_id= */
    public function subClientes(Request $request): JsonResponse
    {
        $request->validate(['master_client_id' => ['required', 'integer']]);

        return response()->json(['data' => $this->abonos->subClientsForClient((int) $request->integer('master_client_id'))]);
    }

    /** GET /api/admin-contab/abonos/cascada/proyectos?sub_client_id= */
    public function proyectos(Request $request): JsonResponse
    {
        $request->validate(['sub_client_id' => ['required', 'integer']]);

        return response()->json(['data' => $this->abonos->projectsForSubClient((int) $request->integer('sub_client_id'))]);
    }

    /** GET /api/admin-contab/abonos/cascada/facturas?sales_project_id= */
    public function facturas(Request $request): JsonResponse
    {
        $request->validate(['sales_project_id' => ['required', 'integer']]);

        return response()->json(['data' => $this->abonos->invoicesForProject((int) $request->integer('sales_project_id'))]);
    }

    /**
     * GET /api/admin-contab/abonos/cascada/facturas-por-subcliente?sub_client_id=
     *
     * SCRUM-933 — reemplaza, para el buscador cruzado Proyecto↔# Cotización de "Registrar Abono",
     * los pasos `proyectos()`+`facturas()` de arriba (que siguen existiendo intactos para otros
     * consumidores) con una sola lista de todas las facturas del sub-cliente ya enriquecida.
     */
    public function facturasPorSubcliente(Request $request): JsonResponse
    {
        $request->validate(['sub_client_id' => ['required', 'integer']]);

        return response()->json(['data' => $this->abonos->invoicesForSubClient((int) $request->integer('sub_client_id'))]);
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }
}
