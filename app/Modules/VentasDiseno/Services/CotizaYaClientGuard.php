<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\VentasDiseno\Models\CotizaYaSettings;

/**
 * SCRUM-1120 — el Cliente Master y el Subcliente «CotizaYA» son EXCLUSIVOS del módulo CotizaYA. Esta es la regla única
 * que el flujo comercial (cotizaciones, tarjetas de Pipeline, proyectos, subclientes) consulta para impedir que se
 * asocie ese cliente. Bloquea ASOCIAR (crear o cambiar el cliente), no editar un registro que ya lo tiene sin
 * cambiarlo: las cotizaciones y tarjetas que ya existen con ese cliente siguen funcionando.
 */
final class CotizaYaClientGuard
{
    public const MESSAGE = 'El cliente Cotización solo se puede usar en CotizaYA, no en el flujo normal de cotización.';

    public function masterClientId(): ?int
    {
        $settings = CotizaYaSettings::current();

        return $settings?->master_client_id;
    }

    public function subClientId(): ?int
    {
        $settings = CotizaYaSettings::current();

        return $settings?->sub_client_id;
    }

    public function isMasterClient(mixed $id): bool
    {
        $master = $this->masterClientId();

        return $master !== null && is_numeric($id) && (int) $id === $master;
    }

    public function isSubClient(mixed $id): bool
    {
        $sub = $this->subClientId();

        return $sub !== null && is_numeric($id) && (int) $id === $sub;
    }

    /**
     * Mensaje de error si alguno de los ids es el cliente de CotizaYA; null si no. `$currentMaster`/`$currentSub` son los
     * valores que el registro ya tiene (en una edición): repetir el mismo valor no es "asociar" y se deja pasar.
     */
    public function violation(mixed $masterId, mixed $subId, mixed $currentMaster = null, mixed $currentSub = null): ?string
    {
        if ($this->isMasterClient($masterId) && ! $this->same($masterId, $currentMaster)) {
            return self::MESSAGE;
        }

        if ($this->isSubClient($subId) && ! $this->same($subId, $currentSub)) {
            return self::MESSAGE;
        }

        return null;
    }

    private function same(mixed $a, mixed $b): bool
    {
        return is_numeric($a) && is_numeric($b) && (int) $a === (int) $b;
    }
}
