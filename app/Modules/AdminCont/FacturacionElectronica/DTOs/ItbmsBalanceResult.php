<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\DTOs;

/**
 * SCRUM-1185 — resultado de `ItbmsLineBalancer::balancear()`. Todo en centavos enteros.
 */
final readonly class ItbmsBalanceResult
{
    /**
     * @param list<int> $netos          subtotal (base) de cada línea, ya balanceado, en centavos — mismo orden que la entrada
     * @param list<int> $itbms          ITBMS de cada línea con el redondeo aritmético de la DGI (empate hacia arriba), en centavos
     * @param list<int> $modificadas    índices de las líneas cuyo neto cambió respecto a la entrada
     * @param float     $deltaInicial   (suma del ITBMS redondeado por línea − ITBMS exacto) ANTES de balancear, en centavos
     * @param float     $deltaFinal     ídem DESPUÉS de balancear, en centavos
     */
    public function __construct(
        public array $netos,
        public array $itbms,
        public array $modificadas,
        public float $deltaInicial,
        public float $deltaFinal,
        public int $pares,
        public bool $dentroDelLimite,
    ) {
    }

    /** ¿Se movió algún centavo entre líneas? */
    public function cambiado(): bool
    {
        return $this->modificadas !== [];
    }

    /** Total del documento (neto + ITBMS por línea) en centavos: lo que el PAC firma como `dVTot`. */
    public function totalCentavos(): int
    {
        return array_sum($this->netos) + array_sum($this->itbms);
    }
}
