<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Mappers;

use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocument;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentItem;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentReference;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalParty;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalPayment;

/**
 * Traduce FiscalDocument al body JSON de `POST /v2/transform/nuc_json` (Digifact NUC v2.0.8,
 * Panamá). AVISO: este esquema se extrajo por fetch automatizado de la documentación pública de
 * Digifact (`/pa/nuc`, `/pa/nuc/json`), NO verificado campo a campo a mano — cada método mapea
 * una sola sección a propósito, para que corregir un nombre de campo real quede acotado a un
 * método, no a un blob monolítico. Confirmar contra soporte@digifact.com.pa antes de Senior
 * Review — ver docs/architecture/facturacion-electronica-adapter-digifact-notas.md.
 *
 * Mapeo de forma de pago (Payments[].Type) confirmado que NO coincide con el de MSL — nunca
 * reutilizar la misma tabla entre adapters (ver FiscalPayment).
 */
final class NucJsonMapper
{
    private const array METODO_PAGO_A_TYPE = [
        'credito' => '01',
        'efectivo' => '02',
        'tarjeta_credito' => '03',
        'tarjeta_debito' => '04',
        'transferencia' => '08',
        'cheque' => '09',
    ];

    private const array DOC_TYPE = [
        'factura' => '01',
        'nota_credito' => '04',
    ];

    public function __construct(private readonly ?string $branchCode = null)
    {
    }

    /** @return array<string, mixed> */
    public function map(FiscalDocument $documento, string $ambiente): array
    {
        $body = [
            'Version' => '1.00',
            'CountryCode' => 'PA',
            'Header' => $this->mapHeader($documento, $ambiente),
            'Seller' => $this->mapEmisor($documento->emisor),
            'Buyer' => $this->mapReceptor($documento->receptor),
            'Items' => $this->mapItems($documento->items),
            'Totals' => $this->mapTotals($documento->items),
            'Payments' => $this->mapPagos($documento->pagos),
        ];

        $referencia = $this->mapReferencia($documento->referencia);
        if ($referencia !== null) {
            $body['AdditionalDocumentInfo'] = $referencia;
        }

        return $body;
    }

    /** @return array<string, mixed> */
    public function mapHeader(FiscalDocument $documento, string $ambiente): array
    {
        return [
            'DocType' => self::DOC_TYPE[$documento->tipoDocumento]
                ?? throw new \InvalidArgumentException("Sin DocType NUC para: {$documento->tipoDocumento}"),
            'IssuedDateTime' => $documento->fechaEmision->format('c'),
            'AdditionalIssueType' => $ambiente === 'produccion' ? 1 : 2,
        ];
    }

    /** @return array<string, mixed> */
    public function mapEmisor(FiscalParty $emisor): array
    {
        return [
            'TaxID' => $emisor->ruc,
            'TaxIDType' => $emisor->tipo === '02' ? 1 : 2, // 1 natural · 2 jurídico — emisor casi siempre jurídico
            'Name' => $emisor->nombre,
            'BranchInfo' => [
                // Sucursal registrada ante Digifact — sin equivalente hoy en AdminContFiscalSettings,
                // ver docs/architecture/facturacion-electronica-pac-diseno.md punto 3 de "Abierto".
                'Code' => $this->branchCode,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function mapReceptor(FiscalParty $receptor): array
    {
        $taxId = match ($receptor->tipo) {
            '02' => 'CF',
            '04' => 'EXTRANJERO',
            default => $receptor->ruc,
        };

        return [
            'TaxID' => $taxId,
            'Name' => $receptor->nombre,
            'TaxIDAdditionalInfo' => [
                'Info' => [
                    'Value' => [
                        'TipoReceptor' => $receptor->tipo,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param FiscalDocumentItem[] $items
     * @return array<int, array<string, mixed>>
     */
    public function mapItems(array $items): array
    {
        return array_map(static function (FiscalDocumentItem $item): array {
            return [
                'Description' => $item->descripcion,
                'Qty' => $item->cantidad,
                'Price' => $item->precioUnitario,
                'Taxes' => [
                    'Code' => $item->tasaItbmsCode,
                ],
                'Totals' => [
                    'TotalItem' => self::lineTotal($item),
                ],
            ];
        }, $items);
    }

    /**
     * @param FiscalDocumentItem[] $items
     * @return array<string, mixed>
     */
    public function mapTotals(array $items): array
    {
        // Suma los TotalItem YA redondeados por línea (mismo valor que mapItems() emite) — sumar
        // los montos crudos y redondear una sola vez al final puede no coincidir con la suma de
        // los TotalItem individuales que el propio documento ya muestra, y un descuadre de
        // centavos es motivo real de rechazo fiscal (ver GUIA_ILUMINATION.md §6, RECHAZADO por
        // validación de totales).
        $total = array_sum(array_map(self::lineTotal(...), $items));

        return [
            'QtyItems' => count($items),
            'GrandTotal' => [
                'InvoiceTotal' => round($total, 2),
            ],
        ];
    }

    private static function lineTotal(FiscalDocumentItem $item): float
    {
        return round(($item->cantidad * $item->precioUnitario) - $item->descuento, 2);
    }

    /**
     * @param FiscalPayment[] $pagos
     * @return array<int, array<string, mixed>>
     */
    public function mapPagos(array $pagos): array
    {
        return array_map(function (FiscalPayment $pago): array {
            return [
                'Type' => $this->mapMetodoPago($pago->metodoPagoInterno),
                'Amount' => $pago->monto,
            ];
        }, $pagos);
    }

    public function mapMetodoPago(string $metodoPagoInterno): string
    {
        return self::METODO_PAGO_A_TYPE[$metodoPagoInterno] ?? '99';
    }

    /** @return array<string, mixed>|null */
    public function mapReferencia(?FiscalDocumentReference $referencia): ?array
    {
        if ($referencia === null) {
            return null;
        }

        return [
            'H04' => [
                'H041' => [
                    'Info' => [
                        'CUFERef' => $referencia->cufe,
                    ],
                ],
            ],
        ];
    }
}
