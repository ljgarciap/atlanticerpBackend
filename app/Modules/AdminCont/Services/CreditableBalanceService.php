<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;

/**
 * SCRUM-1116 — fuente ÚNICA del "valor acreditable restante" (VAR) de una factura: cuánto valor de la factura todavía
 * se puede acreditar con notas de crédito.
 *
 *     VAR = factura.monto − Σ monto de las notas de la factura (de TODOS los tipos) en estado aplicada o pendiente
 *
 * No es el "saldo por cobrar" (`monto − saldo_aplicado`): `saldo_aplicado` suma los COBROS confirmados además de las
 * notas ya aplicadas, y una anulación completa de una factura cobrada a medias debe seguir siendo por el total (se
 * devuelve lo cobrado, REQ-484). Los cobros por eso NO entran acá.
 *
 * Qué notas cuentan (decisión de Luis 2026-10-06, D1): las `aplicada` y también las `pendiente_aprobacion`, que
 * reservan valor mientras Mark decide (si se rechaza, la nota deja de contar y el valor se libera sola). Las
 * `rechazada` nunca cuentan. Cuentan las notas de cualquier tipo (descuento, devolución, anulación y las de
 * "Corrección de datos"), no solo la última.
 *
 * Esta clase solo CALCULA; las reglas que la usan (anulación por el VAR, invariante de acumulado, bloqueo de la
 * corrección, revalidación al aprobar) viven en `CreditNoteService` / `RegisterCreditNoteRequest`.
 */
final class CreditableBalanceService
{
    /** @var list<string> estados que reservan valor de la factura */
    public const ESTADOS_QUE_CUENTAN = [
        AdminContCreditNote::ESTADO_APLICADA,
        AdminContCreditNote::ESTADO_PENDIENTE_APROBACION,
    ];

    /**
     * Suma de las notas previas de una factura que reservan valor, sin redondeo extra. Con `$excluirNotaId` se deja
     * fuera una nota (la que se está aprobando: no debe contarse contra sí misma).
     */
    public function notasPrevias(int $facturaId, ?int $excluirNotaId = null): float
    {
        $suma = AdminContCreditNote::query()
            ->where('factura_origen_id', $facturaId)
            ->whereIn('estado', self::ESTADOS_QUE_CUENTAN)
            ->when($excluirNotaId !== null, static fn ($q) => $q->where('id', '<>', $excluirNotaId))
            ->sum('monto');

        return round((float) $suma, 2);
    }

    /**
     * VAR de una factura. Nunca negativo: una factura que YA excede su valor por datos históricos (el defecto que
     * corrige SCRUM-1116) queda en 0, es decir, sin nada más que acreditar.
     */
    public function valorAcreditableRestante(AdminContInvoice $factura, ?int $excluirNotaId = null): float
    {
        return round(max(0.0, (float) $factura->monto - $this->notasPrevias((int) $factura->id, $excluirNotaId)), 2);
    }

    /** Importe con dos decimales para los mensajes al usuario ("USD 1,000.00"). */
    private static function usd(float $valor): string
    {
        return 'USD ' . number_format($valor, 2, '.', ',');
    }

    /** RN3 — una anulación completa sobre una factura que ya no tiene nada por acreditar. */
    public function mensajeSinSaldo(string $numeroFactura): string
    {
        return "La factura {$numeroFactura} ya está completamente acreditada por notas previas: no queda valor que anular.";
    }

    /** RN4 — el monto pedido más las notas previas superaría el valor de la factura. */
    public function mensajeExceso(string $numeroFactura, float $monto, float $acreditable, float $notasPrevias, float $montoFactura): string
    {
        return 'El monto ' . self::usd($monto) . " supera el valor que aún se puede acreditar de la factura {$numeroFactura} ("
            . self::usd($acreditable) . '): la factura es de ' . self::usd($montoFactura) . ' y ya tiene ' . self::usd($notasPrevias)
            . ' en notas de crédito o devoluciones (aplicadas o pendientes de aprobación).';
    }

    /**
     * Versión en lote para listados: DOS consultas para N facturas (sin N+1). Devuelve, por cada id de factura que
     * existe, su monto, el total de notas previas y el VAR; los ids inexistentes se omiten.
     *
     * @param list<int> $facturaIds
     * @return array<int, array{monto: float, notas_previas: float, acreditable: float}>
     */
    public function valoresAcreditables(array $facturaIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $facturaIds)));
        if ($ids === []) {
            return [];
        }

        $montos = AdminContInvoice::query()->whereIn('id', $ids)->pluck('monto', 'id');

        $sumas = AdminContCreditNote::query()
            ->whereIn('factura_origen_id', $ids)
            ->whereIn('estado', self::ESTADOS_QUE_CUENTAN)
            ->selectRaw('factura_origen_id, SUM(monto) AS total')
            ->groupBy('factura_origen_id')
            ->pluck('total', 'factura_origen_id');

        $out = [];
        foreach ($montos as $id => $monto) {
            $previas = round((float) ($sumas[$id] ?? 0.0), 2);
            $out[(int) $id] = [
                'monto'         => round((float) $monto, 2),
                'notas_previas' => $previas,
                'acreditable'   => round(max(0.0, (float) $monto - $previas), 2),
            ];
        }

        return $out;
    }
}
