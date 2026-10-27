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
        $query = CatalogEntry::query()->where('domain', $domain)->orderBy('sort_order')->orderBy('label');
        if ($onlyActive) {
            $query->where('is_active', true);
        }

        return $query->get();
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

        $entry = CatalogEntry::query()->where('domain', $domain)->where('key', $key)->first();

        return $entry?->props[$prop] ?? $default;
    }

    /** @param array{key: string, label: string, sort_order?: int, props?: array<string, mixed>} $data */
    public function create(string $domain, array $data): CatalogEntry
    {
        return CatalogEntry::create([
            'domain' => $domain, 'key' => $data['key'], 'label' => $data['label'], 'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => true, 'is_system' => false, 'props' => $this->cleanProps($domain, $data['props'] ?? []),
        ]);
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
            $entry->props = $this->cleanProps($entry->domain, $data['props']);
        }
        $entry->save();

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
    private function cleanProps(string $domain, array $props): ?array
    {
        $schema = self::domains()[$domain]['props'] ?? [];
        $clean = [];
        foreach ($props as $name => $value) {
            $def = $schema[$name] ?? null;
            $ok = $def !== null && match ($def['type']) {
                'bool' => is_bool($value),
                'enum' => in_array($value, $def['values'], true),
                default => false,
            };
            if (! $ok) {
                throw ValidationException::withMessages(["props.{$name}" => 'Propiedad o valor no válido para este catálogo.']);
            }
            $clean[$name] = $value;
        }

        return $clean === [] ? null : $clean;
    }
}
