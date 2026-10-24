<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models\Scopes;

use App\Modules\VentasDiseno\Support\CotizaYaSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Pedidos gemelos de ICG — la cotización gemela (`origin = icg_legacy`) existe solo para que el pedido gemelo se
 * pueda facturar con el flujo de siempre. Por defecto NINGUNA consulta sobre `Quote` la devuelve (Cotizaciones,
 * Pipeline, Reportes, Home, Gerencia, comisiones): falla cerrado. Los caminos de facturación que necesitan leerla la
 * piden explícitamente con `Quote::withIcgLegacy()` (o resolviendo `Order::linkedQuote()`).
 * Las consultas SQL directas a la tabla (que no pasan por Eloquent) se filtran a mano por `origin`.
 */
final class IcgLegacyOriginScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Esquema todavía sin la columna `origin` (ventana de despliegue): no se filtra nada, igual que `CotizaYaOriginScope`.
        if (! CotizaYaSchema::ready()) {
            return;
        }

        $builder->where($model->qualifyColumn('origin'), '<>', 'icg_legacy');
    }
}
