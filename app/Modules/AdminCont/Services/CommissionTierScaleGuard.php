<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Fase 28 (grupo 2) — coherencia de una escala de tramos (comisión interna y de cartera).
 *
 * El tramo que se aplica a un monto se elige por `monto_minimo` (el mayor que no lo supere), así que un
 * solape o un hueco cambia importes sin que nadie lo note. Al guardar:
 *  - BLOQUEA (422): dos tramos que se solapan, o un `orden` repetido (solo si `$validarOrden`).
 *  - AVISA (no bloquea): huecos entre tramos, que el primero no arranque en 0, o un tramo sin tope que
 *    no sea el último. Un hueco no se bloquea porque mover un límite exige editar dos tramos seguidos y
 *    el estado intermedio siempre tendría uno.
 * La continuidad esperada es `siguiente.monto_minimo = anterior.monto_maximo + 0,01`.
 */
final class CommissionTierScaleGuard
{
    private const EPS = 0.005;

    /**
     * @param  Collection<int, \App\Modules\AdminCont\Models\CommissionTier>|Collection<int, \App\Modules\AdminCont\Models\CarteraCommissionTier>  $existentes
     * @param  array<string, mixed>  $candidato
     * @return list<string> avisos (huecos), vacío si la escala queda continua
     *
     * @throws ValidationException
     */
    public function check(Collection $existentes, array $candidato, ?int $ignorarId = null, bool $validarOrden = false): array
    {
        /** @var Collection<int, \App\Modules\AdminCont\Models\CommissionTier|\App\Modules\AdminCont\Models\CarteraCommissionTier> $otros */
        $otros = $existentes->reject(fn ($t) => $ignorarId !== null && (int) $t->id === $ignorarId)->values();

        $nuevo = [
            'min' => (float) ($candidato['monto_minimo'] ?? 0),
            'max' => isset($candidato['monto_maximo']) ? (float) $candidato['monto_maximo'] : null,
        ];

        // Un PUT parcial puede dejar el tramo invertido (mínimo mayor que el máximo): nunca coincidiría con ningún monto.
        if ($nuevo['max'] !== null && $nuevo['max'] <= $nuevo['min']) {
            throw ValidationException::withMessages(['monto_maximo' => 'El monto máximo debe ser mayor que el mínimo.']);
        }

        foreach ($otros as $t) {
            $min = (float) $t->monto_minimo;
            $max = $t->monto_maximo !== null ? (float) $t->monto_maximo : null;
            $solapa = ($max === null || $nuevo['min'] <= $max + self::EPS) && ($nuevo['max'] === null || $min <= $nuevo['max'] + self::EPS);
            if ($solapa) {
                throw ValidationException::withMessages([
                    'monto_minimo' => sprintf('El tramo se solapa con el de %s a %s.', number_format($min, 2, '.', ''), $max === null ? 'sin tope' : number_format($max, 2, '.', '')),
                ]);
            }
        }

        if ($validarOrden && isset($candidato['orden']) && $otros->contains(fn ($t) => (int) $t->orden === (int) $candidato['orden'])) {
            throw ValidationException::withMessages(['orden' => 'Ya existe un tramo con ese orden.']);
        }

        return $this->avisos($otros, $nuevo);
    }

    /**
     * @param  Collection<int, \App\Modules\AdminCont\Models\CommissionTier|\App\Modules\AdminCont\Models\CarteraCommissionTier>  $otros
     * @param  array{min: float, max: float|null}  $nuevo
     * @return list<string>
     */
    private function avisos(Collection $otros, array $nuevo): array
    {
        /** @var list<array{min: float, max: float|null}> $escala */
        $escala = $otros->map(fn ($t) => [
            'min' => (float) $t->monto_minimo,
            'max' => $t->monto_maximo !== null ? (float) $t->monto_maximo : null,
        ])->push($nuevo)->sortBy('min')->values()->all();

        $avisos = [];
        if ($escala[0]['min'] > self::EPS) {
            $avisos[] = 'El primer tramo no arranca en 0: los montos menores no reciben porcentaje.';
        }
        for ($i = 0, $n = count($escala); $i < $n - 1; $i++) {
            $actual = $escala[$i];
            $sig    = $escala[$i + 1];
            if ($actual['max'] === null) {
                $avisos[] = 'Hay un tramo sin tope que no es el último.';

                continue;
            }
            if (abs($sig['min'] - ($actual['max'] + 0.01)) > self::EPS) {
                $avisos[] = sprintf('Hueco entre %s y %s: el siguiente tramo debería empezar en %s.', number_format($actual['max'], 2, '.', ''), number_format($sig['min'], 2, '.', ''), number_format($actual['max'] + 0.01, 2, '.', ''));
            }
        }

        return $avisos;
    }
}
