<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Observers;

use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\CotizaYaClientGuard;
use App\Modules\VentasDiseno\Support\CotizaYaSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * SCRUM-1120 — el cliente «CotizaYA» es exclusivo del módulo CotizaYA. Esto lo hace cumplir a nivel de modelo, de modo que
 * NINGÚN camino del flujo comercial (API, comandos, seeders) pueda asociarlo: una cotización comercial, una tarjeta de
 * Pipeline, un proyecto o un subcliente nuevo bajo ese Cliente Master. Responde 422 con un mensaje claro (ValidationException).
 *
 * Solo bloquea ASOCIAR: al crear, o cuando cambia el Cliente Master / Subcliente. Guardar un registro que ya lo tenía
 * (las 6 cotizaciones y 2 tarjetas históricas de prod) sin cambiar el cliente sigue funcionando. Las cotizaciones de
 * origen `cotizaya` están exentas: son las únicas que lo usan.
 */
final class CotizaYaClientExclusivityObserver
{
    public function __construct(private readonly CotizaYaClientGuard $guard) {}

    public function saving(Model $model): void
    {
        // Esquema sin migrar todavía (ventana de despliegue): aún no hay cliente CotizaYA que proteger.
        if (! CotizaYaSchema::ready()) {
            return;
        }

        if ($model instanceof Quote) {
            if ($model->isCotizaYa()) {
                return;
            }
            $this->checkMasterAndSub($model);

            return;
        }

        if ($model instanceof PipelineCard) {
            $this->checkMasterAndSub($model);

            return;
        }

        if ($model instanceof SalesProject) {
            if ($this->changed($model, 'sub_client_id') && $this->guard->isSubClient($model->getAttribute('sub_client_id'))) {
                $this->fail('sub_client_id');
            }

            return;
        }

        // Un subcliente nuevo bajo el Cliente Master de CotizaYA, o uno existente reasignado a él (el propio de CotizaYA
        // ya existe y se siembra aparte, antes de que haya ajustes).
        if ($model instanceof SubClient && $this->changed($model, 'master_client_id') && $this->guard->isMasterClient($model->getAttribute('master_client_id'))) {
            $this->fail('master_client_id');
        }
    }

    private function checkMasterAndSub(Model $model): void
    {
        if ($this->changed($model, 'master_client_id') && $this->guard->isMasterClient($model->getAttribute('master_client_id'))) {
            $this->fail('master_client_id');
        }

        if ($this->changed($model, 'sub_client_id') && $this->guard->isSubClient($model->getAttribute('sub_client_id'))) {
            $this->fail('sub_client_id');
        }
    }

    private function changed(Model $model, string $column): bool
    {
        return ! $model->exists || $model->isDirty($column);
    }

    private function fail(string $field): never
    {
        throw ValidationException::withMessages([$field => [CotizaYaClientGuard::MESSAGE]]);
    }
}
