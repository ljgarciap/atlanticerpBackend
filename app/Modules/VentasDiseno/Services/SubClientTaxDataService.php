<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Exceptions\SubClientTaxDataLockedException;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Support\ReceptorTipo;

/**
 * SCRUM-1002 — edición de razón social, RUC y DV de un SubCliente.
 *
 * El receptor de la factura electrónica, el detalle y el PDF de una factura leen `sub_clients` EN VIVO (no hay
 * snapshot del receptor por factura). Cambiar el RUC de un cliente con documentos ya certificados haría que el PDF
 * dejara de coincidir con el XML fiscal firmado, por eso (decisión D3 de Luis, 2026-09-29) se bloquea con 409 si el
 * cliente tiene facturas o notas de crédito con `fe_estado` `certificado` o `pendiente`. Con documentos en Apagado,
 * rechazados o con error de configuración sí se puede editar. El cambio queda en `audit_logs` (antes/después) vía
 * `LogsAuditTrail` del modelo.
 */
final class SubClientTaxDataService
{
    /** Estados que ya son (o pueden llegar a ser) un documento fiscal real ante la DGI. */
    private const ESTADOS_BLOQUEANTES = ['certificado', 'pendiente'];

    /**
     * @param array{business_name: string, receptor_tipo?: string|null, tax_id?: string|null, tax_id_dv?: string|null} $data
     * @throws SubClientTaxDataLockedException
     */
    public function update(SubClient $subClient, array $data): SubClient
    {
        // SCRUM-1003 — `receptor_tipo` ausente = Contribuyente (igual que el alta); con Consumidor Final no hay RUC ni DV.
        $data = ReceptorTipo::normalizar($data);

        $cambia = $subClient->business_name !== $data['business_name']
            || ($subClient->receptor_tipo ?? ReceptorTipo::CONTRIBUYENTE) !== $data['receptor_tipo']
            || $subClient->tax_id !== $data['tax_id']
            || (string) $subClient->tax_id_dv !== (string) ($data['tax_id_dv'] ?? null);

        if (! $cambia) {
            return $subClient;
        }

        if ($this->tieneDocumentosFiscales($subClient)) {
            throw new SubClientTaxDataLockedException(
                'No se pueden editar la razón social ni el RUC/DV: este cliente ya tiene facturas o notas de crédito '
                .'certificadas (o pendientes de certificación) ante la DGI, y el documento fiscal dejaría de coincidir con sus datos.'
            );
        }

        $subClient->fill([
            'business_name' => $data['business_name'],
            'receptor_tipo' => $data['receptor_tipo'],
            'tax_id'        => $data['tax_id'],
            'tax_id_dv'     => $data['tax_id_dv'] ?? null,
        ])->save();

        return $subClient;
    }

    /**
     * Mismo camino que `FiscalDocumentBuilder::receptorParaInvoice()`: factura -> raíz de familia (Order) -> tarjeta
     * del pipeline -> SubCliente. Las NC cuelgan de su factura de origen.
     */
    private function tieneDocumentosFiscales(SubClient $subClient): bool
    {
        return in_array($subClient->id, $this->conDocumentosFiscales([$subClient->id]), true);
    }

    /**
     * De estos SubClientes, cuáles ya tienen facturas o notas de crédito `certificado`/`pendiente` (es decir, cuáles
     * NO se pueden editar). Por lote: dos consultas para toda la página del directorio, no dos por fila.
     *
     * @param list<int> $subClientIds
     * @return list<int>
     */
    public function conDocumentosFiscales(array $subClientIds): array
    {
        if ($subClientIds === []) {
            return [];
        }

        $facturas = (new AdminContInvoice())->getTable();
        $notas    = (new AdminContCreditNote())->getTable();
        $pedidos  = (new Order())->getTable();
        $tarjetas = (new PipelineCard())->getTable();

        $porFacturas = AdminContInvoice::query()
            ->join("{$pedidos} as o", 'o.id', '=', "{$facturas}.family_root_id")
            ->join("{$tarjetas} as c", 'c.id', '=', 'o.pipeline_card_id')
            ->whereIn('c.sub_client_id', $subClientIds)
            ->whereIn("{$facturas}.fe_estado", self::ESTADOS_BLOQUEANTES)
            ->distinct()
            ->pluck('c.sub_client_id');

        $porNotas = AdminContCreditNote::query()
            ->join("{$facturas} as f", 'f.id', '=', "{$notas}.factura_origen_id")
            ->join("{$pedidos} as o", 'o.id', '=', 'f.family_root_id')
            ->join("{$tarjetas} as c", 'c.id', '=', 'o.pipeline_card_id')
            ->whereIn('c.sub_client_id', $subClientIds)
            ->whereIn("{$notas}.fe_estado", self::ESTADOS_BLOQUEANTES)
            ->distinct()
            ->pluck('c.sub_client_id');

        return array_values(array_unique(array_map('intval', $porFacturas->merge($porNotas)->all())));
    }

    /**
     * Directorio paginado de SubClientes para quien edita datos fiscales fuera de Ventas & Diseño (Contabilidad, desde
     * Admin&Cont). Busca por razón social, RUC o cliente master; ordena por razón social.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, SubClient>
     */
    public function directorio(?string $busqueda, int $perPage, int $page): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = SubClient::query()->with('masterClient:id,name')->orderBy('business_name')->orderBy('id');

        $busqueda = $busqueda !== null ? trim($busqueda) : '';
        if ($busqueda !== '') {
            $patron = '%'.addcslashes($busqueda, '\\%_').'%';
            $query->where(function ($q) use ($patron): void {
                $q->where('business_name', 'ilike', $patron)
                    ->orWhere('tax_id', 'ilike', $patron)
                    ->orWhereHas('masterClient', fn ($m) => $m->where('name', 'ilike', $patron));
            });
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }
}
