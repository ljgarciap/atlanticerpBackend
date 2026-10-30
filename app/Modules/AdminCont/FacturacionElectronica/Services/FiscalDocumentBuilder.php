<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

use App\Modules\AdminCont\Services\PaymentMethods;
use App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl\MslPaymentMethodMapper;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocument;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentItem;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentReference;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalParty;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalPayment;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Services\ItbmsRateResolver;
use App\Modules\Bodega\Models\CustomerReturn;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\ReceptorFiscalSnapshot;
use App\Modules\VentasDiseno\Support\ReceptorTipo;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

/**
 * SCRUM-943 — ensambla el `FiscalDocument` completo (emisor/receptor/ítems/pagos/referencia) de
 * una factura o una Nota de Crédito, para certificar ante cualquier PAC. Los ítems de una factura
 * los sigue armando `FiscalDocumentItemsBuilder` (SCRUM-929) sin cambios — este builder agrega lo
 * que faltaba: emisor, receptor, pagos, y (para NC) un ítem sintético + la referencia a la factura
 * original.
 */
final class FiscalDocumentBuilder
{
    public function __construct(
        private readonly FiscalDocumentItemsBuilder $itemsBuilder,
        private readonly FiscalDocumentSerieResolver $serieResolver,
        private readonly ItbmsCodeMapper $itbmsCodeMapper,
    ) {
    }

    public function paraFactura(AdminContInvoice $invoice): FiscalDocument
    {
        $receptor = $this->receptorParaInvoice($invoice);
        [$items, $totalFiscalCentavos] = $this->itemsBuilder->buildConTotal($invoice);

        return new FiscalDocument(
            tipoDocumento: 'factura',
            serie: $this->serieResolver->paraFactura(),
            numero: $this->numeroFiscal($invoice->numero),
            fechaEmision: new DateTimeImmutable(($invoice->fe_emision_at ?? $invoice->created_at)->toIso8601String()),
            emisor: $this->emisor(),
            receptor: $receptor,
            items: $items,
            pagos: $this->pagosParaInvoice($invoice, $totalFiscalCentavos, count($items)),
        );
    }

    /**
     * SCRUM-943 — a diferencia de una factura, `credit_notes` no tiene líneas propias en AtlanticERP
     * (solo `subtotal`/`itbms` agregados, sin tabla de ítems) — arma un único ítem sintético con el
     * total de la nota, a la MISMA tasa de ITBMS de la factura que corrige (`facturaOrigen`), nunca
     * una tasa propia que AtlanticERP no rastrea para una NC. Sin `facturaOrigen->itbmsRate` (facturas
     * legadas sin tasa asignada) cae al mismo default que `FiscalDocumentItemsBuilder::fromQuote()`
     * — mismo criterio ya usado en el resto de esta capa, no uno nuevo.
     *
     * Requiere que `facturaOrigen` ya tenga `cufe` (haya sido certificada de verdad) — una NC no
     * puede referenciar una factura que el PAC nunca certificó. Ese caso es responsabilidad del
     * caller (tarea 2: no ofrecer "generar NC" para una factura sin certificar todavía), no algo
     * que este builder deba adivinar.
     */
    public function paraNotaCredito(AdminContCreditNote $creditNote): FiscalDocument
    {
        $facturaOrigen = $creditNote->facturaOrigen;
        if ($facturaOrigen === null) {
            throw new \RuntimeException("Nota de crédito {$creditNote->numero} sin factura de origen (FK huérfana).");
        }
        if ($facturaOrigen->cufe === null) {
            throw new \RuntimeException(
                "Nota de crédito {$creditNote->numero}: la factura de origen {$facturaOrigen->numero} ".
                'no tiene CUFE (nunca fue certificada ante el PAC) — no se puede referenciar.'
            );
        }

        // SCRUM-1019 — referencia.fecha = fecha de emisión del documento ORIGINAL tal como se le mandó al PAC (`fe_emision_at`,
        // que es la `fechaEmision` enviada); sin ella (preview/legado) cae a `created_at`. La DGI rechaza si no coincide (Ref H041).
        $fechaReferencia = new DateTimeImmutable(($facturaOrigen->fe_emision_at ?? $facturaOrigen->created_at)->toIso8601String());

        return new FiscalDocument(
            tipoDocumento: 'nota_credito',
            serie: $this->serieResolver->paraNotaCredito(),
            numero: $this->numeroFiscal($creditNote->numero),
            fechaEmision: $this->fechaEmisionNotaCredito($creditNote, $fechaReferencia),
            emisor: $this->emisor(),
            receptor: $this->receptorParaInvoice($facturaOrigen),
            items: $this->itemsParaNotaCredito($creditNote, $facturaOrigen),
            pagos: $this->pagosParaNotaCredito($creditNote, $facturaOrigen),
            referencia: new FiscalDocumentReference(
                cufe: $facturaOrigen->cufe,
                fecha: $fechaReferencia,
                razon: $creditNote->motivo,
            ),
        );
    }

    /**
     * SCRUM-1008 — la DGI rechaza (9026 `FE_ValidacionFechaReferenciada`, "Verifique la fecha
     * referenciada") una NC cuya fecha de emisión NO es posterior a la fecha-hora de la factura que
     * referencia. Antes se enviaba `credit_notes.fecha`, que es una fecha SIN hora (queda a las
     * 00:00 del día de la NC) mientras `referencia.fecha` es el `created_at` de la factura CON hora:
     * cualquier NC emitida el mismo día que su factura quedaba "antes" de ella y se rechazaba (Pre-QA
     * de SCRUM-958: NC-0009 rechazada el mismo día que F-0016, NC-0011 certificada al día siguiente).
     *
     * Se usa el instante de registro de la nota (`created_at`) — el mismo criterio que la factura
     * (`created_at`) y coherente con `fecha`, que se fija con `now()` al registrarla. La salvaguarda
     * `max(…, referencia + 1 s)` cubre relojes desfasados o una factura creada en el mismo segundo:
     * la emisión nunca es anterior ni igual a la referencia.
     *
     * SCRUM-1019 — el instante de emisión se fija en el primer envío (`fe_emision_at`, lo persiste
     * `FiscalCertificationService`) y los reintentos lo reutilizan; `created_at` solo se usa como respaldo
     * cuando aún no se fijó (preview, que no persiste nada). Nunca `now()`: rompería el determinismo de
     * `fe-preview` y movería la fecha en cada reintento.
     */
    private function fechaEmisionNotaCredito(AdminContCreditNote $creditNote, DateTimeImmutable $fechaReferencia): DateTimeImmutable
    {
        $emision = new DateTimeImmutable(($creditNote->fe_emision_at ?? $creditNote->created_at)->toIso8601String());
        $minimo  = $fechaReferencia->modify('+1 second');

        if ($emision >= $minimo) {
            return $emision;
        }

        // Rara vez actúa (reloj desfasado o NC en el mismo segundo que la factura): que quede rastro.
        Log::warning('Facturación electrónica: la fecha de emisión de la NC no era posterior a la factura referenciada; se ajustó a referencia + 1 s', [
            'nota_credito_id' => $creditNote->id,
            'emision_original' => $emision->format('c'),
            'referencia' => $fechaReferencia->format('c'),
        ]);

        return $minimo;
    }

    /**
     * Ítems fiscales de la NC — lo que la DGI espera ver, no un resumen:
     *  - devolución de mercancía ligada a una devolución de Bodega: las líneas realmente devueltas (referencia,
     *    descripción, cantidad recibida y precio de cotización sin ITBMS), a la tasa de la factura de origen. Si no
     *    cuadran con el subtotal de la nota se lanza (queda `error_configuracion`): MSL lo rechazaría igual;
     *  - anulación completa/corrección: las líneas de la factura de origen, siempre que sumen el subtotal de la nota
     *    (una anulación sobre una factura ya acreditada en parte acredita solo el restante: ahí cae al respaldo);
     *  - cualquier otro caso (descuento comercial, sin devolución, pedidos sin precio snapshot): ítem sintético.
     *
     * @return list<FiscalDocumentItem>
     */
    private function itemsParaNotaCredito(AdminContCreditNote $creditNote, AdminContInvoice $facturaOrigen): array
    {
        if ($creditNote->tipo === AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA && $creditNote->devolucion_bodega_id !== null) {
            $items = $this->itemsDeDevolucion($creditNote, $facturaOrigen);
            if ($items !== null) {
                return $items;
            }
        }

        if ($creditNote->tipo === AdminContCreditNote::TIPO_ANULACION_COMPLETA) {
            try {
                $items = $this->itemsBuilder->build($facturaOrigen);
            } catch (\RuntimeException) {
                $items = [];
            }
            // Corrección: acredita la factura ORIGINAL completa (ver pagosParaNotaCredito), así que sus líneas siempre
            // aplican; la anulación normal solo si suman el subtotal de la nota.
            $esCorreccion = $creditNote->subtipo_anulacion === AdminContCreditNote::SUBTIPO_CORRECCION;
            if ($items !== [] && ($esCorreccion || $this->cuadra($items, (float) $creditNote->subtotal))) {
                return $items;
            }
        }

        return [$this->itemSinteticoParaNotaCredito($creditNote, $facturaOrigen)];
    }

    /**
     * @return list<FiscalDocumentItem>|null null si la devolución no existe, no tiene líneas o alguna no tiene precio
     */
    private function itemsDeDevolucion(AdminContCreditNote $creditNote, AdminContInvoice $facturaOrigen): ?array
    {
        $devolucion = CustomerReturn::with('lines')->find($creditNote->devolucion_bodega_id);
        if ($devolucion === null || $devolucion->lines->isEmpty() || $devolucion->lines->contains(fn ($l) => $l->unit_price === null)) {
            return null;
        }

        $rate = $facturaOrigen->itbmsRate ?? ItbmsRateResolver::defaultRate();
        $code = $this->itbmsCodeMapper->map($rate->porcentaje);

        $items = [];
        foreach ($devolucion->lines as $line) {
            $cantidad = (int) ($line->qty_received ?? $line->qty_requested);
            if ($cantidad <= 0) {
                continue;
            }
            $items[] = new FiscalDocumentItem(
                codigo: $line->reference_snapshot ?? "LINEA-{$line->id}",
                descripcion: $line->description_snapshot ?? $creditNote->motivo,
                cantidad: $cantidad,
                precioUnitario: (float) $line->unit_price,
                tasaItbmsCode: $code,
                descuento: 0.0,
            );
        }

        if ($items === [] || ! $this->cuadra($items, (float) $creditNote->subtotal)) {
            throw new \RuntimeException(
                "Nota de crédito {$creditNote->numero}: las líneas de la devolución {$devolucion->return_number} no suman el subtotal de la nota "
                .'(' . number_format((float) $creditNote->subtotal, 2) . '). Revisa el monto de la nota contra lo devuelto antes de reintentar.'
            );
        }

        return $items;
    }

    /** @param list<FiscalDocumentItem> $items */
    private function cuadra(array $items, float $subtotal): bool
    {
        $suma = 0.0;
        foreach ($items as $item) {
            $suma += $item->cantidad * $item->precioUnitario - $item->descuento;
        }

        return abs(round($suma, 2) - round($subtotal, 2)) <= 0.01;
    }

    private function itemSinteticoParaNotaCredito(AdminContCreditNote $creditNote, AdminContInvoice $facturaOrigen): FiscalDocumentItem
    {
        $rate = $facturaOrigen->itbmsRate ?? ItbmsRateResolver::defaultRate();

        return new FiscalDocumentItem(
            codigo: $creditNote->numero,
            descripcion: $creditNote->motivo,
            cantidad: 1,
            precioUnitario: $creditNote->subtotal,
            tasaItbmsCode: $this->itbmsCodeMapper->map($rate->porcentaje),
            descuento: 0.0,
        );
    }

    /**
     * SCRUM-943 — a diferencia de una factura, acá `pagos[]` no describe un cobro sino cómo se
     * liquida el valor de la nota (`resultado`, ver AdminContCreditNote): `aplicado_saldo`/
     * `saldo_favor` no mueven dinero real todavía (reducen deuda o quedan disponibles a futuro) —
     * mismo criterio que una factura a crédito, se declara con `METODO_INTERNO_CREDITO`. `devuelto`
     * SÍ es dinero real saliendo — vía `cuenta_bancaria_salida_id` (transferencia/depósito) cuando
     * existe, o efectivo si no hay cuenta bancaria asociada.
     *
     * @return list<FiscalPayment>
     */
    private function pagosParaNotaCredito(AdminContCreditNote $creditNote, AdminContInvoice $facturaOrigen): array
    {
        $metodo = match (true) {
            $creditNote->resultado === AdminContCreditNote::RESULTADO_DEVUELTO && $creditNote->cuenta_bancaria_salida_id !== null => 'transferencia',
            $creditNote->resultado === AdminContCreditNote::RESULTADO_DEVUELTO => 'efectivo',
            default => MslPaymentMethodMapper::METODO_INTERNO_CREDITO, // aplicado_saldo | saldo_favor | null
        };

        // SCRUM-1009 — la NC de anulación de una "Corrección de datos" acredita el total de la factura ORIGINAL.
        // `credit_notes.monto` guarda el total del REEMPLAZO (con la tasa corregida), que en una corrección de
        // ITBMS difiere del original: usarlo dejaba `pagos[].monto` ≠ ítem (que sí usa la tasa original) y la
        // DGI rechaza el descuadre. Las columnas de AtlanticERP no cambian, solo el documento fiscal.
        $monto = $creditNote->subtipo_anulacion === AdminContCreditNote::SUBTIPO_CORRECCION
            ? (float) $facturaOrigen->monto
            : $creditNote->monto;

        return [new FiscalPayment(metodoPagoInterno: $metodo, monto: $monto)];
    }

    /**
     * SCRUM-987 — el folio interno (`AdminContInvoiceFolioService`/`AdminContCreditNoteFolioService`,
     * `sprintf('F-%04d', ...)`/`sprintf('NC-%04d', ...)`) es el único `numero` que AtlanticERP persiste —
     * no existe una columna numérica separada. La DGI rechaza cualquier `numero` no puramente
     * numérico ("9026 - Falló la validación de algunos escenarios"), confirmado empíricamente el
     * 2026-09-28 con una llamada real y aislada a MSL sandbox (mismo receptor, único campo variado):
     * "F-0013" rechazado, "9001" certificado con CUFE real. Ni la guía del proveedor
     * (`msl-fe/GUIA_ILUMINATION.md`) ni su OpenAPI documentan esta restricción explícitamente.
     * El folio `F-XXXX`/`NC-XXXX` se sigue usando tal cual en toda la UI/PDF interna — esto solo
     * afecta el `numero` que sale hacia el PAC. Público a propósito: `FiscalCertificationService`
     * también lo necesita para el `numeroFiscal` de `PollMslDocumentEstadoJob` (el polling debe
     * consultar a MSL por el mismo numero que de verdad se certificó, nunca por el folio interno).
     *
     * A propósito NO se recortan los ceros a la izquierda (ej. "F-0013" -> "0013", no "13"): la
     * prueba aislada del 2026-09-28 solo confirmó que letras/guión rompen la validación de la DGI,
     * nunca se probó si un cero a la izquierda también lo haría — cambiar solo lo que se confirmó
     * roto, no una transformación extra sin evidencia.
     */
    public function numeroFiscal(string $folio): string
    {
        return preg_replace('/\D/', '', $folio) ?: '0';
    }

    private function emisor(): FiscalParty
    {
        $settings = AdminContFiscalSettings::current();

        return new FiscalParty(
            tipo: '01',
            ruc: $settings->ruc,
            dv: $settings->dv,
            nombre: $settings->razon_social,
            email: $settings->correo_contacto !== '' ? $settings->correo_contacto : null,
            direccion: $settings->direccion_fiscal !== '' ? $settings->direccion_fiscal : null,
        );
    }

    /**
     * SCRUM-1140 — si la factura congeló su receptor al crearse (`receptor_*`: razón social validada por la DGI, o Consumidor
     * Final por decisión de quien factura) eso es lo que viaja, también en recertificaciones y en las notas de crédito, que
     * toman el receptor de la factura de origen. Sin receptor congelado (facturas anteriores o creadas sin la guarda, como el
     * reemplazo de una "Corrección de datos") se lee el cliente en vivo, como siempre. `email`/`direccion` no se congelan.
     */
    private function receptorParaInvoice(AdminContInvoice $invoice): FiscalParty
    {
        $subClient = Order::find($invoice->family_root_id)?->fiscalSubClient();
        $congelado = ReceptorFiscalSnapshot::desdeFactura($invoice);

        if ($congelado !== null) {
            return $congelado->toFiscalParty($subClient?->contacts->first()?->email, $subClient?->delivery_address);
        }

        return $this->receptorDeSubClient($subClient);
    }

    /**
     * SCRUM-1003 — tipo de receptor según `sub_clients.receptor_tipo` (selector explícito por SubCliente, decisión de Luis
     * 2026-09-29): `02` ⇒ Consumidor Final (`CONSUMIDOR FINAL`, sin RUC ni DV, aunque el cliente tenga un `tax_id`
     * guardado); NULL (filas anteriores a la captura) o `01` ⇒ Contribuyente con su RUC/DV. Un subcliente sin `tax_id` sigue
     * saliendo como Consumidor Final (no hay identificación que mandar). Gobierno (`03`) y Extranjero (`04`) siguen fuera de
     * alcance: `ReceptorTipo` solo admite `01`/`02`.
     */
    private function receptorDeSubClient(?SubClient $subClient): FiscalParty
    {
        if ($subClient === null || $subClient->receptor_tipo === ReceptorTipo::CONSUMIDOR_FINAL || trim($subClient->tax_id) === '') {
            return FiscalParty::consumidorFinal();
        }

        return new FiscalParty(
            tipo: $subClient->receptor_tipo ?? ReceptorTipo::CONTRIBUYENTE,
            ruc: $subClient->tax_id,
            dv: $subClient->tax_id_dv,
            nombre: $subClient->business_name,
            email: $subClient->contacts->first()?->email,
            direccion: $subClient->delivery_address,
        );
    }

    /**
     * SCRUM-943 — MSL exige `pagos[]` cubriendo el 100% del total (`/referencia` §4: "la suma de
     * los pagos debe corresponder al total del documento"). La mayoría de facturas de Atlantic
     * se emiten a crédito (`dias_credito_factura`) sin ningún `AdminContPayment` vinculado todavía:
     * el saldo sin cobrar se declara con `MslPaymentMethodMapper::METODO_INTERNO_CREDITO` (→ tipo
     * `01`, "crédito/pago a plazo") y cada cobro real ya recibido (`AdminContPaymentInvoice`) se
     * declara con su propio método real — nunca se inventa que ya se cobró lo que no se cobró.
     *
     * @return list<FiscalPayment>
     */
    private function pagosParaInvoice(AdminContInvoice $invoice, int $totalFiscalCentavos, int $lineas): array
    {
        // SCRUM-1168 F4 — un cobro anulado ya no es un pago de esta factura.
        $aplicados = AdminContPaymentInvoice::query()
            ->where('invoice_id', $invoice->id)
            ->whereHas('payment', fn ($q) => $q->where('estado', '!=', AdminContPayment::ESTADO_ANULADO))
            ->with('payment')
            ->orderBy('id')
            ->get();

        $pagos         = [];
        $totalAplicado = 0.0;
        foreach ($aplicados as $aplicado) {
            $payment = $aplicado->payment;
            if ($payment === null) {
                continue; // payment_id es NOT NULL en BD — defensivo, no debería pasar nunca.
            }
            $metodo = $payment->metodo_pago;

            $pagos[] = new FiscalPayment(
                metodoPagoInterno: $metodo,
                monto: $aplicado->monto_aplicado,
                descripcion: PaymentMethods::label($metodo),
            );
            $totalAplicado += $aplicado->monto_aplicado;
        }

        // SCRUM-1185 — los pagos deben cuadrar con el total del documento fiscal (neto + ITBMS por línea, ya balanceado), que puede diferir del
        // `monto` de AtlanticERP en unos centavos cuando la factura no se pudo alinear (tiene cobros, abonos o notas). Si la diferencia es solo de
        // centavos (mismo margen que `FiscalTotalAligner`, en centavos enteros) se usa el total fiscal; si no, se conserva el `monto` como siempre.
        $montoCentavos = (int) round((float) $invoice->monto * 100);
        $margen        = FiscalTotalAligner::margenCentavos($lineas);
        $objetivo      = abs($totalFiscalCentavos - $montoCentavos) <= $margen ? $totalFiscalCentavos : $montoCentavos;

        $saldoCentavos = $objetivo - (int) round($totalAplicado * 100);
        if ($saldoCentavos > 0) {
            $pagos[] = new FiscalPayment(
                metodoPagoInterno: MslPaymentMethodMapper::METODO_INTERNO_CREDITO,
                monto: $saldoCentavos / 100,
            );
        } elseif ($saldoCentavos < 0 && $saldoCentavos >= -$margen && $pagos !== []) {
            // Cobrada por el monto de AtlanticERP, unos centavos por encima del total fiscal: el último pago absorbe la diferencia.
            $ultimo   = array_pop($pagos);
            $absorbido = (int) round($ultimo->monto * 100) + $saldoCentavos;
            if ($absorbido > 0) {
                $pagos[] = new FiscalPayment(
                    metodoPagoInterno: $ultimo->metodoPagoInterno,
                    monto: $absorbido / 100,
                    descripcion: $ultimo->descripcion,
                );
            } else {
                // El último pago queda en 0 o negativo: se descarta y el resto de la diferencia se descuenta del anterior.
                $resto = $absorbido;
                while ($resto < 0 && $pagos !== []) {
                    $previo = array_pop($pagos);
                    $nuevo  = (int) round($previo->monto * 100) + $resto;
                    if ($nuevo > 0) {
                        $pagos[] = new FiscalPayment(metodoPagoInterno: $previo->metodoPagoInterno, monto: $nuevo / 100, descripcion: $previo->descripcion);
                        $resto = 0;
                    } else {
                        $resto = $nuevo;
                    }
                }
            }
        }

        return $pagos;
    }
}
