<?php

declare(strict_types=1);

namespace App\Shared\Catalogs;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Fase 7a — una entrada de un catálogo de vocabulario de la empresa (ver la migración que crea la tabla).
 *
 * @property int $id
 * @property string $domain
 * @property string $key
 * @property string $label
 * @property array<string, string>|null $labels
 * @property int $sort_order
 * @property bool $is_active
 * @property bool $is_system
 * @property array<string, mixed>|null $props
 */
class CatalogEntry extends Model
{
    /** @var list<string> */
    protected $fillable = ['domain', 'key', 'label', 'labels', 'sort_order', 'is_active', 'is_system', 'props'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_system' => 'boolean', 'sort_order' => 'integer', 'props' => 'array', 'labels' => 'array'];
    }

    /** Fase 14i — la etiqueta en un idioma; sin traducción (o sin idioma) cae a la base. */
    public function labelFor(?string $language): string
    {
        $translated = $language !== null ? ($this->labels[$language] ?? null) : null;

        return is_string($translated) && $translated !== '' ? $translated : $this->label;
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        return $tenant === null ? 'catalog_entries' : "{$tenant->slug}_auth.catalog_entries";
    }
}
