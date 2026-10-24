<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\DTOs;

/**
 * Resultado de certificar (o consultar) un documento ante el PAC — mismo shape sin importar el
 * proveedor detrás. `estado` normaliza los 3 estados que ambos esquemas comparten
 * (certificado/rechazado/pendiente), aunque cada proveedor use su propio vocabulario interno
 * (MSL: CERTIFICADO/RECHAZADO/PENDIENTE ; Digifact/NUC: RECIBIDO_EXITOSAMENTE/EN_COLA_DE_ENVIO/
 * ERROR_AL_ENVIAR) — esa traducción vive en cada adapter.
 *
 * `error_configuracion` (agregado — Senior Review 2026-09-17, finding #5) es un 4to estado para
 * cuando el proveedor nunca llegó a evaluar el documento por un problema nuestro (API key
 * inválida, ambiente no habilitado, request malformado) — nunca un rechazo fiscal real de la DGI.
 * Confundirlo con `rechazado` le mostraría a un usuario "tu factura fue rechazada" cuando el
 * problema real es que AtlanticERP está mal configurado. Hoy solo lo produce `MslPacAdapter`.
 *
 * `errorTransporte` distingue, dentro de `pendiente`, un "PENDIENTE"/202 real del proveedor (el
 * documento se encoló y hay algo que esperar) de una falla nuestra para siquiera contactarlo
 * (circuito abierto / timeout de transporte, ver `serviceUnavailableResult()`) — Senior Review
 * 2026-09-17, finding #6: pollear el estado de un documento que nunca llegó a enviarse no tiene
 * sentido, necesita una señal distinta a la de un encolado real.
 *
 * @param string[] $errores
 * @param array<string, mixed> $rawResponse
 */
final readonly class FiscalDocumentResult
{
    public function __construct(
        public string $estado, // certificado | rechazado | pendiente | error_configuracion
        public string $pacProvider,
        public ?string $cufe = null,
        public ?string $qr = null,
        public ?string $xmlFirmadoBase64 = null,
        /** @var string[] */
        public array $errores = [],
        /** @var array<string, mixed> */
        public array $rawResponse = [],
        public bool $errorTransporte = false,
        // SCRUM-987 — evidencia de la transmisión (la completa el adapter; los que no la conocen la
        // dejan en null): request enviado, HTTP recibido, duración, endpoint y ambiente.
        /** @var array<string, mixed>|null */
        public ?array $requestPayload = null,
        public ?int $httpStatus = null,
        public ?int $duracionMs = null,
        public ?string $endpoint = null,
        public ?string $ambiente = null,
    ) {
    }

    public function certificado(): bool
    {
        return $this->estado === 'certificado';
    }

    public function errorConfiguracion(): bool
    {
        return $this->estado === 'error_configuracion';
    }
}
