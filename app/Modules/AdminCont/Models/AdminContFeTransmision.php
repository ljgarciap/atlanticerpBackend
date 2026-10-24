<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-987 — un intento de transmisión al PAC (payload enviado + respuesta). Append-only: es
 * evidencia fiscal, nunca se edita ni se borra desde la app (ver la migración).
 *
 * @property int                        $id
 * @property string                     $documento_tipo
 * @property int                        $documento_id
 * @property string                     $provider
 * @property string|null                $ambiente
 * @property string                     $operacion
 * @property string                     $origen
 * @property string|null                $endpoint
 * @property string|null                $serie
 * @property string|null                $numero_fiscal
 * @property array<string, mixed>|null  $request_payload
 * @property int|null                   $http_status
 * @property array<string, mixed>|null  $response_body
 * @property string                     $resultado
 * @property int|null                   $duracion_ms
 * @property string|null                $request_id
 * @property \Carbon\Carbon             $created_at
 */
class AdminContFeTransmision extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(static fn () => throw new \LogicException('fe_transmisiones es append-only: no se edita.'));
        static::deleting(static fn () => throw new \LogicException('fe_transmisiones es append-only: no se borra.'));
    }

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_body'   => 'array',
            'created_at'      => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'fe_transmisiones';
        }

        return "{$tenant->slug}_admin_contab.fe_transmisiones";
    }
}
