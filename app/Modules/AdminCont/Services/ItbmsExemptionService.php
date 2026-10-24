<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\ItbmsExemptionException;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1166 — marcar o quitar la exención de ITBMS de un cliente fiscal (sub-cliente). Solo lo hace quien tiene el permiso angosto
 * `admin_contab.edit.client_itbms_exemption`, y marcar exige el documento que la respalda (número de resolución o ley).
 *
 * El cambio alcanza a lo NUEVO y a los BORRADORES (decisión de Luis, 2026-10-19):
 *  - las cotizaciones sin confirmar del cliente toman la marca nueva, y si su tarjeta del Pipeline ya había tomado su monto, ese monto se recalcula;
 *  - las cotizaciones confirmadas y las facturas emitidas no se tocan. Una factura ya emitida que estuviera mal se corrige con la nota de crédito de
 *    corrección que ya existe.
 */
final class ItbmsExemptionService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @return array{sub_client: SubClient, borradores_actualizados: int, confirmadas_sin_facturar: int}
     * @throws ItbmsExemptionException
     */
    public function marcar(int $subClientId, string $documento, User $actor): array
    {
        $documento = trim($documento);
        if ($documento === '') {
            throw new ItbmsExemptionException('Indica el documento que respalda la exención (número de resolución o ley).');
        }

        // Si Configuración Fiscal desactivó la tasa «0% — Exento», no se marca a nadie: cotizar y facturar fallarían después.
        try {
            ItbmsRateResolver::exemptRate();
        } catch (\DomainException $e) {
            throw new ItbmsExemptionException($e->getMessage(), 0, $e);
        }

        return $this->aplicar($subClientId, true, $documento, $actor);
    }

    /**
     * @return array{sub_client: SubClient, borradores_actualizados: int, confirmadas_sin_facturar: int}
     * @throws ItbmsExemptionException
     */
    public function quitar(int $subClientId, User $actor): array
    {
        return $this->aplicar($subClientId, false, null, $actor);
    }

    /** @return array{sub_client: SubClient, borradores_actualizados: int, confirmadas_sin_facturar: int} */
    private function aplicar(int $subClientId, bool $exento, ?string $documento, User $actor): array
    {
        return DB::transaction(function () use ($subClientId, $exento, $documento, $actor): array {
            /** @var SubClient|null $cliente */
            $cliente = SubClient::query()->whereKey($subClientId)->lockForUpdate()->first();
            if ($cliente === null) {
                throw new ItbmsExemptionException('Cliente no encontrado.');
            }

            $antes = ['itbms_exento' => $cliente->itbms_exento, 'documento' => $cliente->itbms_exencion_documento];

            $cliente->forceFill([
                'itbms_exento'             => $exento,
                'itbms_exencion_documento' => $exento ? $documento : null,
                'itbms_exento_at'          => now(),
                'itbms_exento_by'          => $actor->id,
            ])->save();

            $borradores = $this->actualizarBorradores($cliente, $exento);

            $this->audit->log(
                action:     $exento ? AuditService::ADMIN_CONT_SUB_CLIENT_ITBMS_EXENTO : AuditService::ADMIN_CONT_SUB_CLIENT_ITBMS_EXENTO_QUITADO,
                entityType: 'sub_client',
                entityId:   $cliente->id,
                oldValues:  $antes,
                newValues:  ['itbms_exento' => $exento, 'documento' => $cliente->itbms_exencion_documento],
                metadata:   ['borradores_actualizados' => $borradores, 'cliente' => $cliente->business_name],
            );

            return ['sub_client' => $cliente, 'borradores_actualizados' => $borradores, 'confirmadas_sin_facturar' => $this->confirmadasSinFacturar($cliente)];
        });
    }

    /**
     * Cotizaciones ya confirmadas del cliente que todavía no tienen factura vigente: conservan su marca anterior (lo nuevo y los borradores es la regla), así
     * que se facturarán como estaban. Se avisa a quien marca para que, si no corresponde, las corrija antes de facturar o con la nota de crédito de corrección.
     */
    private function confirmadasSinFacturar(SubClient $cliente): int
    {
        $confirmadas = Quote::query()->where('sub_client_id', $cliente->id)->whereNotNull('confirmed_at')->pluck('id');
        if ($confirmadas->isEmpty()) {
            return 0;
        }

        $facturadas = AdminContInvoice::query()->whereIn('quote_id', $confirmadas)->whereNull('anulada_at')->pluck('quote_id')->unique();

        return $confirmadas->diff($facturadas)->count();
    }

    /**
     * Las cotizaciones sin confirmar del cliente toman la marca nueva. Si la tarjeta de su proyecto ya tenía el monto de ESA cotización (coincide con el
     * total anterior), se recalcula con el nuevo; una tarjeta con otro monto (p. ej. el de otra versión confirmada) no se pisa.
     */
    private function actualizarBorradores(SubClient $cliente, bool $exento): int
    {
        $cotizaciones = Quote::query()
            ->with('parts.items')
            ->where('sub_client_id', $cliente->id)
            ->whereNull('confirmed_at')
            ->where('itbms_exento', '!=', $exento)
            ->get();

        foreach ($cotizaciones as $cotizacion) {
            $totalAntes = $cotizacion->grandTotal();

            // La marca no se toca vía fillable: el `saving` de Quote solo la recalcula al crear o al cambiar de cliente.
            $cotizacion->forceFill(['itbms_exento' => $exento])->save();

            if ($cotizacion->sales_project_id === null) {
                continue;
            }

            PipelineCard::query()
                ->where('sales_project_id', $cotizacion->sales_project_id)
                ->get()
                ->each(function (PipelineCard $card) use ($totalAntes, $cotizacion): void {
                    if (abs((float) $card->amount - $totalAntes) < 0.005) {
                        $card->update(['amount' => $cotizacion->grandTotal()]);
                    }
                });
        }

        return $cotizaciones->count();
    }
}
