<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-072/SCRUM-146: Meta (+ Meta Plus) configurada a mano por Gerencia, por
 * vendedor — no un promedio automático. Una fila por usuario (unique en la
 * migración); "Meta de equipo" (REQ-076) se calcula sumando las filas de todos
 * los vendedores del equipo, no es un valor propio.
 *
 * @property int    $id
 * @property int    $user_id
 * @property float  $goal_amount
 * @property float|null $goal_plus_amount
 * @property User   $user
 */
class SalesGoal extends Model
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'goal_amount', 'goal_plus_amount'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'goal_amount'      => 'float',
            'goal_plus_amount' => 'float',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'sales_goals';
        }

        return "{$tenant->slug}_ventas_diseno.sales_goals";
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
