<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\AbonoStateException;
use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\Bodega\Models\Order as BodegaOrder;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-902 (Registrar Abono) + SCRUM-903 (Saldo a Favor de Clientes). Reemplaza a
 * `CreditBalanceService` (retirado) como fuente de verdad de saldo a favor — ver docblock de la
 * migración de drop de `customer_credit_balances` para el porqué del reemplazo completo.
 *
 * Lógica de integración (ticket 902 sección 4, LEER ANTES de tocar este archivo):
 *  - Un abono `por_confirmar` nunca es visible/usable en ningún lado — todo método de consulta
 *    filtra `estado=confirmado` salvo `find()`/`update()`/`confirm()` (que operan sobre un abono
 *    puntual por id, antes o después de confirmarse).
 *  - Nivel Factura/Proyecto: aplicación OBLIGATORIA y bloqueada en Cobros (`abonoObligatorioParaFactura()`).
 *  - Nivel Cliente: aplicación OPCIONAL con tope, consumida FIFO junto con Notas de Crédito
 *    `resultado=saldo_favor` (`saldoClienteDisponible()`/`aplicarSaldoCliente()`).
 *  - Excedente (4.5): cuando un abono obligatorio es mayor a la factura que cubre, la diferencia
 *    se extrae como un abono nuevo, ya confirmado, a nivel Cliente master (`registrarUsoObligatorio()`).
 */
final class AbonoService
{
    public function __construct(
        private readonly AdminContAbonoAttachmentService $attachments,
        private readonly AdminContAbonoFolioService $folios,
        // SCRUM-1168 F1 — libro de movimientos (modo sombra).
        private readonly PaymentLedger $ledger,
        private readonly ClientBalanceService $clientBalance,
    ) {}

    /**
     * @param array{search?: ?string, master_client_id?: ?int, sales_project_id?: ?int, invoice_id?: ?int, estado?: ?string, metodo_pago?: ?string, desde?: ?string, hasta?: ?string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $query = AdminContAbono::query()->orderByDesc('fecha_registro');

        if (($filters['master_client_id'] ?? null) !== null) {
            $query->where('master_client_id', $filters['master_client_id']);
        }
        if (($filters['sales_project_id'] ?? null) !== null) {
            $query->where('sales_project_id', $filters['sales_project_id']);
        }
        if (($filters['invoice_id'] ?? null) !== null) {
            $query->where('invoice_id', $filters['invoice_id']);
        }
        if (($filters['estado'] ?? null) !== null) {
            $query->where('estado', $filters['estado']);
        }
        // SCRUM-948 — mismos criterios que Historial de cobros (HistorialCobrosPanel.tsx): método
        // de pago exacto + rango de fechas inclusive sobre fecha_registro.
        if (($filters['metodo_pago'] ?? null) !== null) {
            $query->where('metodo_pago', $filters['metodo_pago']);
        }
        if (($filters['desde'] ?? null) !== null) {
            $query->whereDate('fecha_registro', '>=', $filters['desde']);
        }
        if (($filters['hasta'] ?? null) !== null) {
            $query->whereDate('fecha_registro', '<=', $filters['hasta']);
        }

        $rows = $query->get();

        $rows = $this->hydrateNames($rows);

        if (($filters['search'] ?? null) !== null && trim((string) $filters['search']) !== '') {
            $needle = mb_strtolower(trim((string) $filters['search']));
            $rows = array_values(array_filter($rows, function (array $r) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', [
                    $r['cliente'], $r['sub_cliente'] ?? '', $r['proyecto'] ?? '', $r['factura'] ?? '', $r['referencia'],
                ]));

                return str_contains($haystack, $needle);
            }));
        }

        return $rows;
    }

    /**
     * SCRUM-955 — modelo crudo (no el array aplanado de `find()`) para el Recibo de Abono en PDF,
     * que necesita las relaciones reales (`cuentaBancaria`/`invoice`) — mismo criterio que
     * `PaymentService::find()`.
     */
    public function findModel(int $id): ?AdminContAbono
    {
        return AdminContAbono::query()->with(['cuentaBancaria', 'invoice'])->find($id);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        /** @var AdminContAbono|null $abono */
        $abono = AdminContAbono::query()->find($id);
        if ($abono === null) {
            return null;
        }

        return $this->hydrateNames(collect([$abono]))[0];
    }

    /**
     * @param \Illuminate\Support\Collection<int, AdminContAbono> $abonos
     * @return array<int, array<string, mixed>>
     */
    private function hydrateNames($abonos): array
    {
        $abonos = collect($abonos);
        $clientNames = MasterClient::whereIn('id', $abonos->pluck('master_client_id')->unique())->pluck('name', 'id');
        $subClientNames = SubClient::whereIn('id', $abonos->pluck('sub_client_id')->filter()->unique())->pluck('business_name', 'id');
        $projectNames = SalesProject::whereIn('id', $abonos->pluck('sales_project_id')->filter()->unique())->pluck('name', 'id');
        $invoiceNumbers = AdminContInvoice::whereIn('id', $abonos->pluck('invoice_id')->filter()->unique())->pluck('numero', 'id');

        return $abonos->map(fn (AdminContAbono $a): array => [
            'id'                => $a->id,
            'numero_recibo'     => $a->numero_recibo,
            'fecha_registro'    => $a->fecha_registro->toIso8601String(),
            'master_client_id'  => $a->master_client_id,
            'cliente'           => $clientNames->get($a->master_client_id) ?? '',
            'sub_client_id'     => $a->sub_client_id,
            'sub_cliente'       => $a->sub_client_id !== null ? ($subClientNames->get($a->sub_client_id) ?? '') : null,
            'sales_project_id'  => $a->sales_project_id,
            'proyecto'          => $a->sales_project_id !== null ? ($projectNames->get($a->sales_project_id) ?? '') : null,
            'invoice_id'        => $a->invoice_id,
            'factura'           => $a->invoice_id !== null ? ($invoiceNumbers->get($a->invoice_id) ?? '') : null,
            'nivel_asociacion'  => $a->nivelAsociacion(),
            'monto_original'    => $a->monto_original,
            'monto_usado'       => $a->monto_usado,
            'monto_disponible'  => $a->montoDisponible(),
            'estado_saldo'      => $a->estadoSaldo(),
            'metodo_pago'       => $a->metodo_pago,
            'cuenta_bancaria_id' => $a->cuenta_bancaria_id,
            'referencia'        => $a->referencia,
            'comprobante_url'   => $a->comprobante_url,
            'estado'            => $a->estado,
            'registrado_por'    => $a->registrado_por,
            'confirmado_por'    => $a->confirmado_por,
            'fecha_confirmacion' => $a->fecha_confirmacion?->toIso8601String(),
            'abono_origen_id'   => $a->abono_origen_id,
        ])->values()->all();
    }

    /**
     * SCRUM-1168 F0 — antes solo se validaba que sub_client/proyecto/factura fueran enteros: un abono podía quedar asociado
     * a la factura o al proyecto de OTRO cliente (y consumirse en sus cobros). Cada referencia debe pertenecer al cliente.
     *
     * @throws AbonoStateException si alguna referencia no pertenece al cliente del abono.
     */
    private function assertPertenencia(int $masterClientId, ?int $subClientId, ?int $salesProjectId, ?int $invoiceId): void
    {
        if ($subClientId !== null
            && ! SubClient::query()->whereKey($subClientId)->where('master_client_id', $masterClientId)->exists()) {
            throw new AbonoStateException('El subcliente indicado no pertenece al cliente del abono.');
        }

        if ($salesProjectId !== null) {
            $project = SalesProject::query()->find($salesProjectId);
            $projectMaster = $project?->sub_client_id !== null
                ? SubClient::query()->whereKey($project->sub_client_id)->value('master_client_id')
                : null;
            if ($project === null || (int) $projectMaster !== $masterClientId
                || ($subClientId !== null && (int) $project->sub_client_id !== $subClientId)) {
                throw new AbonoStateException('El proyecto indicado no pertenece al cliente del abono.');
            }
        }

        if ($invoiceId !== null
            && ! AdminContInvoice::query()->whereKey($invoiceId)->where('master_client_id', $masterClientId)->exists()) {
            throw new AbonoStateException('La factura indicada no pertenece al cliente del abono.');
        }
    }

    /**
     * REQ 902 §2.2 — regla de obligatoriedad condicional (sub_client_id lleno ⇒ sales_project_id
     * obligatorio) ya la valida el Form Request; acá solo se persiste.
     *
     * @param array{master_client_id:int, sub_client_id:?int, sales_project_id:?int, invoice_id:?int,
     *   monto_original:float, metodo_pago:string, cuenta_bancaria_id:int, referencia:string} $data
     */
    public function register(array $data, ?UploadedFile $comprobante, User $actor): AdminContAbono
    {
        // SCRUM-955 punto 3 — RN4 (mismo criterio que PaymentService/StorePaymentRequest): los
        // métodos que no representan un movimiento bancario real nunca persisten una cuenta
        // bancaria, sin importar qué haya llegado en el request. Defensa en profundidad — a
        // diferencia de Payment (que confía 100% en que el frontend ya manda null), acá se
        // refuerza en el servicio porque `cuenta_bancaria_id` recién dejó de ser NOT NULL.
        $sinCuentaBancaria = in_array($data['metodo_pago'], AdminContPayment::METODOS_SIN_CUENTA_BANCARIA, true);

        $this->assertPertenencia(
            (int) $data['master_client_id'], $data['sub_client_id'] ?? null, $data['sales_project_id'] ?? null, $data['invoice_id'] ?? null,
        );

        $abono = DB::transaction(function () use ($data, $actor, $sinCuentaBancaria): AdminContAbono {
            return AdminContAbono::create([
                'numero_recibo'      => $this->folios->next(),
                'master_client_id'   => $data['master_client_id'],
                'sub_client_id'      => $data['sub_client_id'] ?? null,
                'sales_project_id'   => $data['sales_project_id'] ?? null,
                'invoice_id'         => $data['invoice_id'] ?? null,
                'monto_original'     => $data['monto_original'],
                'monto_usado'        => 0,
                'metodo_pago'        => $data['metodo_pago'],
                'cuenta_bancaria_id' => $sinCuentaBancaria ? null : $data['cuenta_bancaria_id'],
                'referencia'         => $data['referencia'],
                'estado'             => AdminContAbono::ESTADO_POR_CONFIRMAR,
                'registrado_por'     => $actor->id,
                'fecha_registro'     => now(),
            ]);
        });

        if ($comprobante !== null) {
            $abono->update(['comprobante_url' => $this->attachments->upload($comprobante, $abono->id)]);
        }

        return $abono;
    }

    /**
     * §2.4 — editar solo permitido mientras `por_confirmar` (RN Escenario 4).
     *
     * @param array{sub_client_id?:?int, sales_project_id?:?int, invoice_id?:?int, monto_original?:float,
     *   metodo_pago?:string, cuenta_bancaria_id?:int, referencia?:string} $data
     * @throws AbonoStateException si el abono ya está confirmado.
     */
    public function update(int $id, array $data, ?UploadedFile $comprobante): AdminContAbono
    {
        /** @var AdminContAbono $abono */
        $abono = AdminContAbono::query()->findOrFail($id);

        if ($abono->estado === AdminContAbono::ESTADO_CONFIRMADO) {
            throw new AbonoStateException('Este abono ya está confirmado y no puede editarse.');
        }

        $update = array_intersect_key($data, array_flip([
            'sub_client_id', 'sales_project_id', 'invoice_id', 'monto_original',
            'metodo_pago', 'cuenta_bancaria_id', 'referencia',
        ]));

        // SCRUM-1168 F0 — lo que quede asociado (editado o ya existente) debe seguir siendo del cliente del abono.
        $this->assertPertenencia(
            (int) $abono->master_client_id,
            array_key_exists('sub_client_id', $update) ? $update['sub_client_id'] : $abono->sub_client_id,
            array_key_exists('sales_project_id', $update) ? $update['sales_project_id'] : $abono->sales_project_id,
            array_key_exists('invoice_id', $update) ? $update['invoice_id'] : $abono->invoice_id,
        );

        // SCRUM-955 punto 3 — mismo criterio de register(): si el método editado queda en uno sin
        // cuenta bancaria real, nunca persistir la que haya llegado.
        $metodoFinal = $update['metodo_pago'] ?? $abono->metodo_pago;
        if (in_array($metodoFinal, AdminContPayment::METODOS_SIN_CUENTA_BANCARIA, true)) {
            $update['cuenta_bancaria_id'] = null;
        }

        $abono->update($update);

        if ($comprobante !== null) {
            $abono->update(['comprobante_url' => $this->attachments->upload($comprobante, $abono->id)]);
        }

        $abono->refresh();

        return $abono;
    }

    /** §2.3 — estado final, ya no editable (RN Escenario 4). */
    public function confirm(int $id, User $actor): AdminContAbono
    {
        // SCRUM-1168 F0 — `lockForUpdate()` fuera de una transacción no bloquea nada: dos confirmaciones simultáneas
        // podían pisarse `confirmado_por`/`fecha_confirmacion`. Ahora lock + chequeo de estado + update van juntos.
        return DB::transaction(function () use ($id, $actor): AdminContAbono {
            /** @var AdminContAbono $abono */
            $abono = AdminContAbono::query()->lockForUpdate()->findOrFail($id);

            if ($abono->estado === AdminContAbono::ESTADO_CONFIRMADO) {
                throw new AbonoStateException('Este abono ya está confirmado.');
            }

            $abono->update([
                'estado'             => AdminContAbono::ESTADO_CONFIRMADO,
                'confirmado_por'     => $actor->id,
                'fecha_confirmacion' => now(),
            ]);

            // SCRUM-1168 F1 — el dinero del abono entra al disponible del cliente en el libro (modo sombra).
            $this->ledger->shadow(fn () => $this->ledger->ingresoDeAbono($abono, \App\Modules\AdminCont\Models\AdminContPaymentMovement::ORIGEN_DIRECTA, $actor->id));

            return $abono;
        });
    }

    /**
     * §4.3 — cuando Facturación genera la factura de un proyecto, cualquier abono confirmado de
     * nivel Proyecto sobre ese `sales_project_id` (sin factura todavía) se re-asocia a la factura
     * recién creada. La factura NO se modifica en este paso (ver `InvoiceService::confirm()`) —
     * desde acá en adelante ese abono se comporta como nivel Factura.
     */
    public function reasociarAlFacturar(int $salesProjectId, AdminContInvoice $invoice): void
    {
        DB::transaction(function () use ($salesProjectId, $invoice): void {
            AdminContAbono::query()
                ->where('sales_project_id', $salesProjectId)
                ->whereNull('invoice_id')
                ->where('estado', AdminContAbono::ESTADO_CONFIRMADO)
                ->lockForUpdate()
                ->get()
                ->each(fn (AdminContAbono $a) => $a->update(['invoice_id' => $invoice->id]));
        });
    }

    /**
     * SCRUM-1168 F0 — lo que un cobro confirmado no pudo aplicar a sus facturas (ya no tenían lugar) queda como saldo a favor del
     * cliente: un abono nuevo, ya `confirmado`, a nivel Cliente master, con el método, cuenta y registrador del cobro y su recibo como
     * referencia. Mismo vehículo que el excedente de §4.5, para que lo vea Saldo a Favor y lo use el siguiente cobro o la compensación.
     */
    public function registrarExcedenteDeCobro(AdminContPayment $payment, float $excedente): AdminContAbono
    {
        $abono = AdminContAbono::create([
            'master_client_id'   => $payment->master_client_id,
            'sub_client_id'      => null,
            'sales_project_id'   => null,
            'invoice_id'         => null,
            'monto_original'     => round($excedente, 2),
            'monto_usado'        => 0,
            'metodo_pago'        => $payment->metodo_pago,
            'cuenta_bancaria_id' => $payment->bank_account_id,
            'referencia'         => "Excedente del cobro {$payment->numero_recibo}",
            'estado'             => AdminContAbono::ESTADO_CONFIRMADO,
            'registrado_por'     => $payment->created_by,
            'confirmado_por'     => $payment->created_by,
            'fecha_registro'     => now(),
            'fecha_confirmacion' => now(),
        ]);

        // SCRUM-1168 F1 — el dinero ya está en el libro (el ingreso del cobro menos lo aplicado): solo se deja la traza (efecto cero).
        $this->ledger->shadow(fn () => $this->ledger->reclasificacion(
            $payment->master_client_id, $excedente, "Excedente del cobro {$payment->numero_recibo} que no cupo en su factura", $payment->id, $abono->id,
        ));

        return $abono;
    }

    /**
     * SCRUM-1168 F4 — al anular un cobro del flujo anterior, el saldo a favor de nivel cliente que había consumido (FIFO, sin rastro de qué abono o nota)
     * vuelve al cliente como un abono nuevo ya confirmado a nivel Cliente, con el recibo del cobro anulado como referencia. El libro ya recoge ese dinero
     * en los contra-movimientos de la anulación: solo se deja la traza (efecto cero).
     */
    public function registrarRestitucionPorAnulacion(AdminContPayment $payment, float $monto): AdminContAbono
    {
        $abono = AdminContAbono::create([
            'master_client_id'   => $payment->master_client_id,
            'sub_client_id'      => null,
            'sales_project_id'   => null,
            'invoice_id'         => null,
            'monto_original'     => round($monto, 2),
            'monto_usado'        => 0,
            'metodo_pago'        => $payment->metodo_pago,
            'cuenta_bancaria_id' => $payment->bank_account_id,
            'referencia'         => "Restitución por anulación del cobro {$payment->numero_recibo}",
            'estado'             => AdminContAbono::ESTADO_CONFIRMADO,
            'registrado_por'     => $payment->created_by,
            'confirmado_por'     => $payment->created_by,
            'fecha_registro'     => now(),
            'fecha_confirmacion' => now(),
        ]);

        $this->ledger->shadow(fn () => $this->ledger->reclasificacion(
            $payment->master_client_id, $monto, "Restitución de saldo por la anulación del cobro {$payment->numero_recibo}", $payment->id, $abono->id,
        ));

        return $abono;
    }

    /**
     * §4.2/4.3 — abono(s) obligatorio(s) ya re-asociados a esta factura, con disponible > 0.
     * Consumidos FIFO por `fecha_registro` si hubiera más de uno (caso raro, no impedido por el
     * ticket). Read-only — no muta nada, usado tanto para el desglose en Cobros como, calculado de
     * nuevo bajo lock, en `PaymentService::register()`/`confirm()`.
     *
     * @return array<int, array{abono_id:int, disponible:float}>
     */
    public function abonosObligatoriosParaFactura(int $invoiceId): array
    {
        return AdminContAbono::query()
            ->where('invoice_id', $invoiceId)
            ->where('estado', AdminContAbono::ESTADO_CONFIRMADO)
            ->orderBy('fecha_registro')
            ->get()
            ->map(fn (AdminContAbono $a) => ['abono_id' => $a->id, 'disponible' => $a->montoDisponible()])
            ->filter(fn (array $r) => $r['disponible'] > 0)
            ->values()
            ->all();
    }

    /**
     * §4.5 — aplica `$montoAplicado` (ya decidido, <= disponible del abono en ese momento) contra
     * la factura, y si el abono tenía más disponible del que hacía falta, extrae la diferencia
     * como un abono NUEVO, ya `confirmado`, a nivel Cliente master (RN: "el abono original queda
     * con monto_usado = monto_original"). Llamado desde el punto donde `PaymentService` de verdad
     * concreta el cobro (inmediato o `confirm()` diferido) — nunca desde el preview.
     */
    public function registrarUsoObligatorio(int $abonoId, float $montoAplicado): void
    {
        DB::transaction(function () use ($abonoId, $montoAplicado): void {
            /** @var AdminContAbono $abono */
            $abono = AdminContAbono::query()->lockForUpdate()->findOrFail($abonoId);
            $disponibleAntes = $abono->montoDisponible();
            // SCRUM-1168 F0 — el abono obligatorio se fijó al registrar el cobro; si entre tanto otro cobro lo consumió, no se
            // vuelve a consumir lo que ya no existe.
            if ($montoAplicado > $disponibleAntes + 0.005) {
                throw new AbonoStateException(
                    "El abono {$abono->numero_recibo} ya no tiene saldo suficiente para este cobro (disponible \$".number_format($disponibleAntes, 2).').'
                );
            }
            $excedente       = round($disponibleAntes - $montoAplicado, 2);

            $abono->update(['monto_usado' => round($abono->monto_usado + $disponibleAntes, 2)]);

            if ($excedente > 0) {
                $nuevo = AdminContAbono::create([
                    'master_client_id'   => $abono->master_client_id,
                    'sub_client_id'      => null,
                    'sales_project_id'   => null,
                    'invoice_id'         => null,
                    'monto_original'     => $excedente,
                    'monto_usado'        => 0,
                    'metodo_pago'        => $abono->metodo_pago,
                    'cuenta_bancaria_id' => $abono->cuenta_bancaria_id,
                    'referencia'         => $abono->referencia,
                    'estado'             => AdminContAbono::ESTADO_CONFIRMADO,
                    'registrado_por'     => $abono->registrado_por,
                    'confirmado_por'     => $abono->confirmado_por,
                    'fecha_registro'     => now(),
                    'fecha_confirmacion' => now(),
                    'abono_origen_id'    => $abono->id,
                ]);

                // SCRUM-1168 F1 — mismo dinero que el abono de origen, ya en el libro: solo se deja la traza (efecto cero).
                $this->ledger->shadow(fn () => $this->ledger->reclasificacion(
                    $nuevo->master_client_id, $excedente, "Excedente del abono {$abono->numero_recibo} al aplicarse a su factura", null, $nuevo->id,
                ));
            }
        });
    }

    /**
     * §4.4/4.6 — saldo disponible a nivel Cliente master: abonos nivel Cliente confirmados +
     * Notas de Crédito `resultado=saldo_favor` `estado=aplicada` (ver `AdminContCreditNote::
     * montoDisponibleSaldoFavor()`). Misma fuente única que consume `Cobros` y muestra `Saldo a
     * Favor de Clientes` — nunca una copia/recálculo aparte (903 §4.1).
     */
    public function saldoClienteDisponible(int $masterClientId, bool $bloquear = false): float
    {
        // SCRUM-1168 F0 — `$bloquear` (solo dentro de una transacción) toma `FOR UPDATE` sobre las filas que forman el saldo,
        // para que quien lo valida y luego lo consume no compita con otro cobro del mismo cliente.
        $abonosQuery = AdminContAbono::query()
            ->where('master_client_id', $masterClientId)
            ->whereNull('sales_project_id')
            ->whereNull('invoice_id')
            ->where('estado', AdminContAbono::ESTADO_CONFIRMADO);
        if ($bloquear) {
            $abonosQuery->orderBy('id')->lockForUpdate();
        }
        $abonos = $abonosQuery
            ->get()
            ->sum(fn (AdminContAbono $a) => $a->montoDisponible());

        $notasQuery = AdminContCreditNote::query()
            ->where('master_client_id', $masterClientId)
            ->where('resultado', AdminContCreditNote::RESULTADO_SALDO_FAVOR)
            ->where('estado', AdminContCreditNote::ESTADO_APLICADA);
        if ($bloquear) {
            $notasQuery->orderBy('id')->lockForUpdate();
        }
        $notas = $notasQuery
            ->get()
            ->sum(fn (AdminContCreditNote $n) => $n->montoDisponibleSaldoFavor());

        return round((float) $abonos + (float) $notas, 2);
    }

    /**
     * SCRUM-1147 — saldo a favor que se podría aplicar a cada factura, para mostrarlo (informativo)
     * junto al total en Facturación. UN solo valor por factura: el del nivel MÁS ESPECÍFICO que
     * tenga saldo disponible — factura (abonos re-asociados a esa factura), si no proyecto (abonos
     * nivel Proyecto sin factura), si no cliente (`saldoClienteDisponible()`: abonos nivel Cliente +
     * Notas de Crédito `saldo_favor`) — así nunca se suman niveles ni se muestran cifras que no
     * corresponden. Todo en 3-4 consultas en lote, nunca una por fila (el listado puede traer
     * cientos de facturas).
     *
     * @param list<array{invoice_id: int, sales_project_id: int|null, master_client_id: int|null}> $targets
     * @return array<int, float> invoice_id => saldo disponible (> 0); las facturas sin saldo no aparecen
     */
    public function saldoFavorPorFactura(array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        $invoiceIds = array_values(array_unique(array_column($targets, 'invoice_id')));
        $projectIds = array_values(array_unique(array_filter(array_column($targets, 'sales_project_id'))));
        $clientIds  = array_values(array_unique(array_filter(array_column($targets, 'master_client_id'))));

        /** @var array<int, float> $porFactura */
        $porFactura = [];
        foreach (AdminContAbono::query()->whereIn('invoice_id', $invoiceIds)->where('estado', AdminContAbono::ESTADO_CONFIRMADO)->get() as $a) {
            $porFactura[(int) $a->invoice_id] = ($porFactura[(int) $a->invoice_id] ?? 0.0) + $a->montoDisponible();
        }

        /** @var array<int, float> $porProyecto */
        $porProyecto = [];
        if ($projectIds !== []) {
            foreach (AdminContAbono::query()->whereIn('sales_project_id', $projectIds)->whereNull('invoice_id')->where('estado', AdminContAbono::ESTADO_CONFIRMADO)->get() as $a) {
                $porProyecto[(int) $a->sales_project_id] = ($porProyecto[(int) $a->sales_project_id] ?? 0.0) + $a->montoDisponible();
            }
        }

        /** @var array<int, float> $porCliente */
        $porCliente = [];
        if ($clientIds !== []) {
            $abonos = AdminContAbono::query()->whereIn('master_client_id', $clientIds)->whereNull('sales_project_id')->whereNull('invoice_id')
                ->where('estado', AdminContAbono::ESTADO_CONFIRMADO)->get();
            foreach ($abonos as $a) {
                $porCliente[(int) $a->master_client_id] = ($porCliente[(int) $a->master_client_id] ?? 0.0) + $a->montoDisponible();
            }
            $notas = AdminContCreditNote::query()->whereIn('master_client_id', $clientIds)
                ->where('resultado', AdminContCreditNote::RESULTADO_SALDO_FAVOR)->where('estado', AdminContCreditNote::ESTADO_APLICADA)->get();
            foreach ($notas as $n) {
                $porCliente[(int) $n->master_client_id] = ($porCliente[(int) $n->master_client_id] ?? 0.0) + $n->montoDisponibleSaldoFavor();
            }
            // SCRUM-1168 F2 — saldo sin aplicar de los cobros del concepto nuevo (libro de movimientos).
            foreach ($this->clientBalance->remanentePorCliente($clientIds) as $clientId => $remanente) {
                $porCliente[$clientId] = ($porCliente[$clientId] ?? 0.0) + $remanente;
            }
        }

        $result = [];
        foreach ($targets as $t) {
            $saldo = round($porFactura[$t['invoice_id']] ?? 0.0, 2);
            if ($saldo <= 0 && $t['sales_project_id'] !== null) {
                $saldo = round($porProyecto[$t['sales_project_id']] ?? 0.0, 2);
            }
            if ($saldo <= 0 && $t['master_client_id'] !== null) {
                $saldo = round($porCliente[$t['master_client_id']] ?? 0.0, 2);
            }
            if ($saldo > 0) {
                $result[$t['invoice_id']] = $saldo;
            }
        }

        return $result;
    }

    /**
     * §4.4 — consume `$monto` (ya validado <= `saldoClienteDisponible()`) en orden FIFO por fecha
     * entre abonos nivel Cliente y Notas de Crédito `saldo_favor` disponibles de este cliente.
     * Transparente al usuario, que solo ve un total (ticket §4.4: "si hay varios registros
     * disponibles, consumirlos en orden, transparente al usuario").
     *
     * @return array{abonos: float, notas: float} cuánto salió de abonos y cuánto de notas de crédito (SCRUM-1168 F1: las notas siguen
     *         fuera del libro de movimientos, el cobro necesita saber esa parte).
     */
    public function aplicarSaldoCliente(int $masterClientId, float $monto): array
    {
        if ($monto <= 0) {
            return ['abonos' => 0.0, 'notas' => 0.0];
        }

        return DB::transaction(function () use ($masterClientId, $monto): array {
            /** @var \Illuminate\Support\Collection<int, AdminContAbono> $abonos */
            $abonos = AdminContAbono::query()
                ->where('master_client_id', $masterClientId)
                ->whereNull('sales_project_id')
                ->whereNull('invoice_id')
                ->where('estado', AdminContAbono::ESTADO_CONFIRMADO)
                ->lockForUpdate()
                ->get();

            /** @var \Illuminate\Support\Collection<int, AdminContCreditNote> $notas */
            $notas = AdminContCreditNote::query()
                ->where('master_client_id', $masterClientId)
                ->where('resultado', AdminContCreditNote::RESULTADO_SALDO_FAVOR)
                ->where('estado', AdminContCreditNote::ESTADO_APLICADA)
                ->lockForUpdate()
                ->get();

            /** @var list<array{tipo: 'abono'|'nota', fecha: \Carbon\Carbon, disponible: float, aplicar: callable(float): void}> $candidatos */
            $candidatos = [];
            foreach ($abonos as $a) {
                $candidatos[] = [
                    'tipo'       => 'abono',
                    'fecha'      => $a->fecha_registro,
                    'disponible' => $a->montoDisponible(),
                    'aplicar'    => fn (float $consumo) => $a->update(['monto_usado' => round($a->monto_usado + $consumo, 2)]),
                ];
            }
            foreach ($notas as $n) {
                $candidatos[] = [
                    'tipo'       => 'nota',
                    'fecha'      => $n->fecha,
                    'disponible' => $n->montoDisponibleSaldoFavor(),
                    'aplicar'    => fn (float $consumo) => $n->update(['monto_usado' => round($n->monto_usado + $consumo, 2)]),
                ];
            }
            usort($candidatos, fn (array $a, array $b) => $a['fecha'] <=> $b['fecha']);

            $restante = $monto;
            $consumido = ['abonos' => 0.0, 'notas' => 0.0];
            foreach ($candidatos as $c) {
                if ($restante <= 0) {
                    break;
                }
                if ($c['disponible'] <= 0) {
                    continue;
                }

                $consumo  = round(min($c['disponible'], $restante), 2);
                $restante = round($restante - $consumo, 2);
                ($c['aplicar'])($consumo);
                $consumido[$c['tipo'] === 'nota' ? 'notas' : 'abonos'] = round($consumido[$c['tipo'] === 'nota' ? 'notas' : 'abonos'] + $consumo, 2);
            }

            // SCRUM-1168 F0 — antes, si el saldo ya no alcanzaba (lo consumió otro cobro) esto terminaba en silencio y el
            // cobro quedaba con un saldo a favor aplicado que nunca se descontó de ningún abono ni nota.
            if ($restante > 0.005) {
                throw new AbonoStateException(
                    'El saldo a favor del cliente ya no alcanza para este cobro (faltan $'.number_format($restante, 2).'); otro cobro lo usó antes.'
                );
            }

            return $consumido;
        });
    }

    /**
     * Cascada del formulario — sub-clientes de un cliente master.
     * @return array<int, array{id:int,name:string}>
     */
    public function subClientsForClient(int $masterClientId): array
    {
        return array_values(SubClient::query()->where('master_client_id', $masterClientId)->orderBy('business_name')
            ->get(['id', 'business_name'])
            ->map(fn (SubClient $s): array => ['id' => $s->id, 'name' => $s->business_name])
            ->all());
    }

    /**
     * Cascada del formulario — proyectos de UN sub-cliente puntual (902 §2.2, distinto del
     * cascada de Estado de Cuenta que agrega todos los sub-clientes del master).
     * @return array<int, array{id:int,name:string}>
     */
    public function projectsForSubClient(int $subClientId): array
    {
        return array_values(SalesProject::query()->where('sub_client_id', $subClientId)->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (SalesProject $p): array => ['id' => $p->id, 'name' => $p->name])
            ->all());
    }

    /**
     * Cascada del formulario — facturas activas de un proyecto.
     * @return array<int, array{id:int,numero:string}>
     */
    public function invoicesForProject(int $salesProjectId): array
    {
        return array_values(AdminContInvoice::query()->where('sales_project_id', $salesProjectId)->whereNull('anulada_at')
            ->orderByDesc('created_at')
            ->get(['id', 'numero'])
            ->map(fn (AdminContInvoice $i): array => ['id' => $i->id, 'numero' => $i->numero])
            ->all());
    }

    /**
     * SCRUM-933 — reemplaza el paso Proyecto→Factura de la cascada de arriba para el buscador
     * cruzado Proyecto↔# Cotización de "Registrar Abono" (ADR-SCRUM933): TODAS las facturas de un
     * sub-cliente de una sola vez, con `sales_project_id`/`proyecto`/`quote_id`/`cotizacion_folio`
     * ya resueltos, mismo shape/criterio que `CreditNoteService::invoicesForClient()` — cotización
     * vía `Order::linkedQuote()`, nunca `AdminContInvoice.quote_id` directo. `AdminContInvoice` no
     * tiene `sub_client_id` propio, se resuelve vía los `SalesProject` de ese sub-cliente. Mismo
     * filtro base que `invoicesForProject()` (`whereNull('anulada_at')`, sin `es_incobrable` — esa
     * cascada nunca lo filtró, no se agrega acá para no cambiar de comportamiento).
     *
     * @return list<array{id: int, numero: string, sales_project_id: ?int, proyecto: ?string, quote_id: ?int, cotizacion_folio: ?string}>
     */
    public function invoicesForSubClient(int $subClientId): array
    {
        $projectIds = SalesProject::query()->where('sub_client_id', $subClientId)->pluck('id');

        $invoices = AdminContInvoice::query()
            ->whereIn('sales_project_id', $projectIds)
            ->whereNull('anulada_at')
            ->get();

        $projectNames = SalesProject::query()
            ->whereIn('id', $invoices->pluck('sales_project_id')->filter()->unique())
            ->pluck('name', 'id');

        $orders = BodegaOrder::query()
            ->whereIn('id', $invoices->pluck('order_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $rows = $invoices
            ->map(function (AdminContInvoice $i) use ($projectNames, $orders): array {
                $quote = $orders->get($i->order_id)?->linkedQuote();
                /** @var string|null $proyecto */
                $proyecto = $i->sales_project_id !== null ? $projectNames->get($i->sales_project_id) : null;

                return [
                    'id'               => $i->id,
                    'numero'           => $i->numero,
                    'monto'            => $i->monto,
                    'sales_project_id' => $i->sales_project_id,
                    'proyecto'         => $proyecto,
                    'quote_id'         => $quote?->id,
                    'cotizacion_folio' => $quote?->folio,
                ];
            })
            ->all();

        return array_values($rows);
    }
}
