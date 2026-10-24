<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\DTOs;

/**
 * Resultado de validar un RUC/Cédula contra el PAC (autocompletar receptor). MSL lo expone como
 * `GET /v1/validar-ruc`; Digifact directo como `GetInfoRuc` — mismo propósito, distinto proveedor
 * detrás del adapter.
 *
 * SCRUM-1002 — `disponible` separa "el PAC respondió que el RUC no existe" (`valido = false`, `disponible = true`)
 * de "no se pudo consultar" (circuito abierto, timeout, API key rechazada, límite de tasa): el segundo caso nunca
 * debe presentarse al usuario como un RUC inválido. `estado` es el estado del contribuyente que reporta la DGI
 * (MSL: p. ej. "I"); su significado exacto está pendiente de confirmar con MSL, por eso solo se muestra, nunca bloquea.
 */
final readonly class FiscalPartyLookupResult
{
    public function __construct(
        public bool $valido,
        public ?string $nombre = null,
        public ?string $dv = null,
        public ?string $tipo = null,
        public ?string $estado = null,
        public bool $disponible = true,
    ) {
    }
}
