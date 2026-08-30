<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Shared\Services\AuditService;
use Illuminate\Support\Str;

/**
 * REQ-053 (Ventas & Diseño — Historial y auditoría de cambios): en vez de threadear
 * un AuditService::log() manual en cada controller/endpoint de escritura (~25 puntos
 * distintos, alto riesgo de dejar alguno afuera y violar el "TODA acción, no solo un
 * subconjunto" del ticket), este trait engancha los eventos created/updated/deleted
 * de Eloquent — cualquier alta/edición/baja del modelo queda registrada
 * automáticamente en audit_logs, sin tocar los controllers. Un cambio de etapa de
 * PipelineCard, por ejemplo, es solo un campo más dentro de "updated" — no necesita
 * una acción propia.
 *
 * created_at/updated_at se excluyen del diff — cambian en cada guardado y no son
 * información de negocio.
 *
 * SCRUM-789/790 (Epic SCRUM-788 — Logs y Telemetría): el prefijo de `auditAction()`
 * antes venía hardcodeado a 'ventas_diseno.' — correcto para los modelos que lo
 * adoptaron primero (REQ-053), pero silenciosamente incorrecto para los 3 modelos de
 * Compras que ya usan este trait (PurchaseOrder/Provider/PurchaseOrderDocument logean
 * hoy como 'ventas_diseno.purchase_order.*' en vez de 'compras.*' — bug preexistente,
 * filas históricas quedan mal etiquetadas, no se corrigen retroactivamente acá). Se
 * deriva ahora del namespace del modelo (App\Modules\{Módulo}\Models\...), que da el
 * mismo resultado para VentasDiseno (Str::snake('VentasDiseno') === 'ventas_diseno')
 * y generaliza sin overrides por modelo para Servicios/Bodega/Compras.
 */
trait LogsAuditTrail
{
    public static function bootLogsAuditTrail(): void
    {
        static::created(function ($model): void {
            app(AuditService::class)->log(
                static::auditAction('created'),
                static::auditEntityType(),
                $model->getKey(),
                null,
                static::auditableAttributes($model->getAttributes()),
            );
        });

        static::updated(function ($model): void {
            $changes = static::auditableAttributes($model->getChanges());
            if ($changes === []) {
                return;
            }

            $original = array_intersect_key($model->getOriginal(), $changes);

            app(AuditService::class)->log(
                static::auditAction('updated'),
                static::auditEntityType(),
                $model->getKey(),
                $original,
                $changes,
            );
        });

        static::deleted(function ($model): void {
            app(AuditService::class)->log(
                static::auditAction('deleted'),
                static::auditEntityType(),
                $model->getKey(),
                static::auditableAttributes($model->getAttributes()),
                null,
            );
        });
    }

    protected static function auditEntityType(): string
    {
        return Str::snake(class_basename(static::class));
    }

    protected static function auditAction(string $verb): string
    {
        return static::auditModule() . '.' . static::auditEntityType() . '.' . $verb;
    }

    /**
     * Segmento de módulo de App\Modules\{Módulo}\Models\{Modelo} → snake_case
     * ('Servicios' → 'servicios', 'VentasDiseno' → 'ventas_diseno').
     */
    protected static function auditModule(): string
    {
        $segments = explode('\\', static::class);

        return Str::snake($segments[2] ?? 'unknown');
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected static function auditableAttributes(array $attributes): array
    {
        unset($attributes['created_at'], $attributes['updated_at']);
        return $attributes;
    }
}
