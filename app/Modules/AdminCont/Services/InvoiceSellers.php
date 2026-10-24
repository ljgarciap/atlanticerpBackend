<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\VentasDiseno\Models\PipelineCard;
use Illuminate\Support\Collection;

/**
 * SCRUM-1175 — vendedores responsables de un pedido: el `owner` de la tarjeta del Pipeline más los responsables adicionales (`compartido_con`), sin
 * repetir y en ese orden (misma regla de SCRUM-1147 en el detalle de la factura). Se resuelve en lote para que un listado de cientos de facturas no
 * haga una consulta por fila.
 */
final class InvoiceSellers
{
    /**
     * @param iterable<PipelineCard|null> $cards
     * @return Collection<int, User> usuarios por id
     */
    public static function usersFor(iterable $cards): Collection
    {
        $ids = [];
        foreach ($cards as $card) {
            foreach (self::idsOf($card) as $id) {
                $ids[$id] = true;
            }
        }

        return $ids === [] ? new Collection() : User::query()->whereIn('id', array_keys($ids))->get()->keyBy('id');
    }

    /**
     * @param Collection<int, User> $users
     * @return list<string>
     */
    public static function namesFor(?PipelineCard $card, Collection $users): array
    {
        $names = [];
        foreach (self::idsOf($card) as $id) {
            $user = $users->get($id);
            if ($user !== null) {
                $names[] = trim("{$user->first_name} {$user->last_name}");
            }
        }

        return $names;
    }

    /** @return list<int> */
    public static function idsOf(?PipelineCard $card): array
    {
        if ($card === null) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', [$card->owner_id, ...($card->compartido_con ?? [])]))));
    }
}
