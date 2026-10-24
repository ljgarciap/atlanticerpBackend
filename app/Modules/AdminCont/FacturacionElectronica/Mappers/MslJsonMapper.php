<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Mappers;

use App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl\MslPaymentMethodMapper;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocument;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalParty;

/**
 * SCRUM-943 — traduce un `FiscalDocument` canónico al JSON exacto que MSL espera (`pagos[].tipo`
 * ya traducido vía `MslPaymentMethodMapper`, mismo criterio que `NucJsonMapper` para Digifact).
 * Extraído de `MslPacAdapter` (antes privado ahí) para que el mismo mapeo sirva tanto para
 * certificar de verdad como para previsualizar el payload sin enviarlo (modo `apagado`, ver
 * `FiscalDocumentPreviewService`) — una sola fuente de verdad del shape exacto, nunca 2 copias
 * que puedan divergir.
 */
final class MslJsonMapper
{
    public function __construct(private readonly MslPaymentMethodMapper $paymentMethodMapper = new MslPaymentMethodMapper())
    {
    }

    /**
     * SCRUM-1188 — MSL rechaza con 3010 («Falló la validación de XML contra su esquema») un documento cuyo código de ítem lleva un apóstrofo entre
     * letras (`AEREO'_SVHYATT`, F-0008 de producción): verificado en el sandbox de MSL, el mismo código sin el apóstrofo certifica. El resto de
     * caracteres especiales probados (& < > \" * ˚ º acentos, etc.) no dan problema. No hay forma de saber qué otras combinaciones con apóstrofo
     * fallan, así que se quitan siempre, en el código y en la descripción (ambos son texto informativo del documento).
     */
    private function sinApostrofos(string $texto): string
    {
        return str_replace(["'", "\u{2019}", "\u{2018}"], '', $texto);
    }

    /** @return array<string, mixed> */
    public function map(FiscalDocument $documento, string $ambiente): array
    {
        $payload = [
            'ambiente' => $ambiente,
            'serie' => $documento->serie,
            'numero' => $documento->numero,
            'fechaEmision' => $documento->fechaEmision->format(DATE_ATOM),
            'receptor' => $this->mapParty($documento->receptor),
            'items' => array_map(
                fn ($item) => [
                    'codigo' => $this->sinApostrofos($item->codigo),
                    'descripcion' => $this->sinApostrofos($item->descripcion),
                    'cantidad' => $item->cantidad,
                    'precioUnitario' => $item->precioUnitario,
                    'descuento' => $item->descuento,
                    'tasaITBMS' => $item->tasaItbmsCode,
                ],
                $documento->items,
            ),
            'pagos' => array_map(
                function ($pago) {
                    $mapeo = $this->paymentMethodMapper->map($pago->metodoPagoInterno, $pago->descripcion ?? $pago->metodoPagoInterno);

                    return array_filter([
                        'tipo' => $mapeo['tipo'],
                        'monto' => $pago->monto,
                        'descripcion' => $mapeo['descripcion'],
                    ], static fn ($v) => $v !== null);
                },
                $documento->pagos,
            ),
        ];

        if ($documento->referencia !== null) {
            $payload['referencia'] = [
                'cufe' => $documento->referencia->cufe,
                'fecha' => $documento->referencia->fecha->format(DATE_ATOM),
                'razon' => $documento->referencia->razon,
            ];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function mapParty(FiscalParty $party): array
    {
        // SCRUM-1019 — Consumidor Final (`02`) nunca envía RUC/DV, y un RUC/DV vacío ("") tampoco se manda a ninguno.
        $sinIdentificacion = $party->tipo === '02';

        return array_filter([
            'tipo' => $party->tipo,
            'ruc' => $sinIdentificacion || trim((string) $party->ruc) === '' ? null : $party->ruc,
            'dv' => $sinIdentificacion || trim((string) $party->dv) === '' ? null : $party->dv,
            'nombre' => $party->nombre,
            'email' => $party->email,
            'direccion' => $party->direccion,
            'pais' => $party->pais,
        ], static fn ($v) => $v !== null);
    }
}
