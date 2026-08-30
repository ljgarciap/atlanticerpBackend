<?php

declare(strict_types=1);

namespace App\Modules\CRM\Notifications;

use Illuminate\Database\Eloquent\Model;

/**
 * Aísla todo el manejo de tipos `mixed` de evaluar una condición dinámica (campo/operador/
 * valor arbitrarios) en un único punto — ver nota técnica en docs/specs/notifications.md
 * § "Nota de implementación — PHPStan Level 8".
 */
final class ConditionEvaluator
{
    public static function matches(Model $model, ?string $field, ?string $operator, mixed $value): bool
    {
        if ($field === null || $operator === null) {
            return true; // regla solo por evento (ej. "cualquier documento subido"), sin condición de campo
        }

        $current = $model->getAttribute($field);

        return match ($operator) {
            'changed'    => $model->wasChanged($field),
            'changed_to' => $model->wasChanged($field) && self::looseEquals($current, $value),
            'equals'     => self::looseEquals($current, $value),
            'gt'         => self::compareNumeric($current, $value, fn (float $a, float $b): bool => $a > $b),
            'lt'         => self::compareNumeric($current, $value, fn (float $a, float $b): bool => $a < $b),
            'gte'        => self::compareNumeric($current, $value, fn (float $a, float $b): bool => $a >= $b),
            'lte'        => self::compareNumeric($current, $value, fn (float $a, float $b): bool => $a <= $b),
            default      => false,
        };
    }

    private static function looseEquals(mixed $a, mixed $b): bool
    {
        return (string) $a === (string) $b;
    }

    /** @param callable(float, float): bool $compare */
    private static function compareNumeric(mixed $a, mixed $b, callable $compare): bool
    {
        if (! is_numeric($a) || ! is_numeric($b)) {
            return false;
        }

        return $compare((float) $a, (float) $b);
    }
}
