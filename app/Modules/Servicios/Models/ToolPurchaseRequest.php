<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 4 — Servicios, Batch 13 parte 1 (REQ-271/REQ-272) — solicitud de compra de herramientas
 * (a Felix/Administración, conceptual, sin integración real). Ver docblock de la migración
 * `..._create_servicios_tool_purchase_requests_table.php`.
 *
 * @property int         $id
 * @property string      $nombre
 * @property int         $cantidad
 * @property string      $estado
 * @property int|null    $source_tool_id
 * @property int|null    $requested_by
 * @property \Carbon\Carbon|null $received_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Tool|null $sourceTool
 * @property-read User|null $requestedByUser
 */
class ToolPurchaseRequest extends Model
{
    use LogsAuditTrail;

    public const ESTADO_SOLICITADO = 'solicitado';

    public const ESTADO_RECIBIDA = 'recibida';

    /** @var list<string> */
    protected $fillable = ['nombre', 'cantidad', 'estado', 'source_tool_id', 'requested_by', 'received_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'tool_purchase_requests';
        }

        return "{$tenant->slug}_servicios.tool_purchase_requests";
    }

    /** @return BelongsTo<Tool, $this> */
    public function sourceTool(): BelongsTo
    {
        return $this->belongsTo(Tool::class, 'source_tool_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
