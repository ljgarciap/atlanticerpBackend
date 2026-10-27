<?php

declare(strict_types=1);

namespace App\Shared\Catalogs;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Fase 7a — lectura y administración de los catálogos de vocabulario por empresa (`catalog_entries`). El código de negocio pregunta por el
 * COMPORTAMIENTO de una entrada (`prop()`), no por su clave. Sin entrada (clave desconocida) rige el valor por omisión del dominio.
 */
final class CatalogService
{
    /** @return array<string, mixed> */
    public static function domains(): array
    {
        return (array) config('catalogs.domains', []);
    }

    public static function exists(string $domain): bool
    {
        return array_key_exists($domain, self::domains());
    }

    /** @return Collection<int, CatalogEntry> */
    public static function entries(string $domain, bool $onlyActive = true): Collection
    {
        $all = app(CatalogCache::class)->entries($domain);

        return $onlyActive ? $all->filter(static fn (CatalogEntry $e): bool => $e->is_active)->values() : $all;
    }

    /** @return list<string> claves activas del dominio (para reglas `in:`) */
    public static function keys(string $domain): array
    {
        return array_values(array_map(strval(...), self::entries($domain)->pluck('key')->all()));
    }

    /** Propiedad de comportamiento de una entrada; si la entrada o la propiedad no existen, el valor por omisión del dominio. */
    public static function prop(string $domain, ?string $key, string $prop): mixed
    {
        $default = self::domains()[$domain]['props'][$prop]['default'] ?? null;
        if ($key === null) {
            return $default;
        }

        $entry = self::entries($domain, onlyActive: false)->firstWhere('key', $key);

        return $entry?->props[$prop] ?? $default;
    }

    /** @param array{key: string, label: string, sort_order?: int, props?: array<string, mixed>} $data */
    public function create(string $domain, array $data): CatalogEntry
    {
        $rules = self::rulesClass($domain);
        $sort = $data['sort_order'] ?? ($rules !== null ? $rules::defaultSortOrder() : null) ?? ((int) CatalogEntry::query()->where('domain', $domain)->max('sort_order') + 1);
        $props = $this->cleanProps($domain, $data['props'] ?? []);
        if ($rules !== null) {
            $rules::validate($domain, ['sort_order' => $sort, 'props' => $props ?? []], null);
        }

        $entry = CatalogEntry::create([
            'domain' => $domain, 'key' => $data['key'], 'label' => $data['label'], 'sort_order' => $sort,
            'is_active' => true, 'is_system' => false, 'props' => $props,
        ]);
        app(CatalogCache::class)->forget();

        return $entry;
    }

    /** @param array{label?: string, sort_order?: int, is_active?: bool, props?: array<string, mixed>} $data */
    public function update(CatalogEntry $entry, array $data): CatalogEntry
    {
        if (($data['is_active'] ?? true) === false && $entry->is_system) {
            throw new \DomainException('No se puede desactivar una entrada de sistema: el código todavía la usa.');
        }
        if (($data['is_active'] ?? true) === false && $this->inUse($entry)) {
            throw new \DomainException('No se puede desactivar: hay registros que la usan.');
        }

        $this->guardSystemLocks($entry, $data);

        $props = array_key_exists('props', $data) ? $this->cleanProps($entry->domain, $data['props'], $entry) : $entry->props;
        $rules = self::rulesClass($entry->domain);
        if ($rules !== null) {
            $rules::validate($entry->domain, ['sort_order' => $data['sort_order'] ?? $entry->sort_order, 'props' => $props ?? []], $entry);
        }

        if (array_key_exists('label', $data)) {
            $entry->label = $data['label'];
        }
        if (array_key_exists('sort_order', $data)) {
            $entry->sort_order = $data['sort_order'];
        }
        if (array_key_exists('is_active', $data)) {
            $entry->is_active = $data['is_active'];
        }
        if (array_key_exists('props', $data)) {
            $entry->props = $props;
        }
        $entry->save();
        app(CatalogCache::class)->forget();

        return $entry;
    }

    public function delete(CatalogEntry $entry): void
    {
        if ($entry->is_system) {
            throw new \DomainException('No se puede borrar una entrada de sistema: el código todavía la usa.');
        }
        if ($this->inUse($entry)) {
            throw new \DomainException('No se puede borrar: hay registros que la usan. Desactívala en su lugar.');
        }
        $entry->delete();
        app(CatalogCache::class)->forget();
    }

    /**
     * Clase de reglas propias de un dominio (`rules` en config/catalogs.php): `validate($domain, $data, $entry)` lanza
     * ValidationException y `defaultSortOrder()` da el orden de una entrada nueva. Sin ella rige el comportamiento genérico.
     *
     * @return class-string<Contracts\CatalogDomainRules>|null
     */
    private static function rulesClass(string $domain): ?string
    {
        $class = self::domains()[$domain]['rules'] ?? null;

        return is_string($class) && is_subclass_of($class, Contracts\CatalogDomainRules::class) ? $class : null;
    }

    /**
     * Fase 7c — `locked_when_system` del dominio: en una entrada de sistema esas propiedades (y `sort_order`) no se pueden cambiar,
     * porque el código aún depende de ellas (p. ej. la categoría «ganada» de Aprobado o su posición en el flujo lineal).
     *
     * @param  array<string, mixed>  $data
     */
    private function guardSystemLocks(CatalogEntry $entry, array $data): void
    {
        if (! $entry->is_system) {
            return;
        }

        $locked = (array) (self::domains()[$entry->domain]['locked_when_system'] ?? []);
        if (in_array('sort_order', $locked, true) && array_key_exists('sort_order', $data) && (int) $data['sort_order'] !== $entry->sort_order) {
            throw ValidationException::withMessages(['sort_order' => 'El orden de una entrada de sistema no se puede cambiar: el flujo depende de él.']);
        }
        foreach ($locked as $name) {
            if ($name !== 'sort_order' && array_key_exists('props', $data) && ($data['props'][$name] ?? null) !== ($entry->props[$name] ?? null)) {
                throw ValidationException::withMessages(["props.{$name}" => 'Esta propiedad de una entrada de sistema no se puede cambiar.']);
            }
        }
    }

    private function inUse(CatalogEntry $entry): bool
    {
        $usage = self::domains()[$entry->domain]['usage'] ?? null;
        if ($usage === null) {
            return false;
        }
        [$model, $column] = $usage;

        return $model::query()->where($column, $entry->key)->exists();
    }

    /**
     * Solo propiedades declaradas por el dominio, con el tipo correcto; el resto se rechaza.
     *
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>|null
     */
    private function cleanProps(string $domain, array $props, ?CatalogEntry $entry = null): ?array
    {
        $schema = self::domains()[$domain]['props'] ?? [];
        $clean = [];
        foreach ($props as $name => $value) {
            $def = $schema[$name] ?? null;
            $ok = $def !== null && match ($def['type']) {
                'bool' => is_bool($value),
                'enum' => in_array($value, $def['values'], true) && $this->enumValueAllowed($def, $name, $value, $entry),
                'string' => is_string($value) && $value !== '' && mb_strlen($value) <= ($def['max'] ?? 120)
                    && (! isset($def['pattern']) || preg_match($def['pattern'], $value) === 1),
                'int' => is_int($value) && $value >= ($def['min'] ?? PHP_INT_MIN) && $value <= ($def['max'] ?? PHP_INT_MAX),
                'list' => is_array($value) && array_is_list($value) && count($value) <= ($def['max'] ?? 50)
                    && array_filter($value, static fn ($v): bool => ! is_string($v) || $v === '' || mb_strlen($v) > 60) === [],
                default => false,
            };
            if (! $ok) {
                throw ValidationException::withMessages(["props.{$name}" => 'Propiedad o valor no válido para este catálogo.']);
            }
            $clean[$name] = $value;
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * Valores `system_only` de un enum (p. ej. las categorías «ganada»/«perdida» de una etapa): solo los puede tener una entrada de
     * sistema, y solo el que ya tiene. Una entrada propia nunca los obtiene.
     *
     * @param  array<string, mixed>  $def
     */
    private function enumValueAllowed(array $def, string $name, mixed $value, ?CatalogEntry $entry): bool
    {
        if (! in_array($value, (array) ($def['system_only'] ?? []), true)) {
            return true;
        }

        return $entry !== null && $entry->is_system && ($entry->props[$name] ?? null) === $value;
    }
}
