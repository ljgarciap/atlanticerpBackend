<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models\Scopes;

use App\Modules\VentasDiseno\Support\CotizaYaContext;
use App\Modules\VentasDiseno\Support\CotizaYaSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * SCRUM-1120 — aislamiento de CotizaYA: salvo dentro del módulo CotizaYA (`CotizaYaContext`), ninguna consulta sobre
 * `Quote` devuelve cotizaciones de venta chica. Así Cotizaciones, Pipeline, Pedidos, Reportes, Home, Gerencia,
 * Bodega, Facturación y las comisiones no las cuentan, sin tener que tocar cada uno de esos consumidores.
 * Las consultas SQL directas a la tabla (que no pasan por Eloquent) se filtran a mano por `origin`.
 */
final class CotizaYaOriginScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Contexto de CotizaYA, o esquema todavía sin migrar (ventana de despliegue): no se filtra nada.
        if (CotizaYaContext::isActive() || ! CotizaYaSchema::ready()) {
            return;
        }

        $builder->where($model->qualifyColumn('origin'), '<>', 'cotizaya');
    }
}
