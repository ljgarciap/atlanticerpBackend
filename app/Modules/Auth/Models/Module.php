<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de módulos de negocio, como datos — reemplaza la constante PHP
 * `App\Modules\Auth\Support\ModuleCatalog` como fuente de verdad de qué claves de módulo son
 * válidas (ver docblock de la migración que crea esta tabla). `ModuleCatalog` sigue existiendo
 * como capa de compatibilidad para los call-sites que referencian sus constantes con nombre
 * (`ModuleCatalog::BODEGA`, etc. — módulos con lógica de negocio propia acoplada a su clave).
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property bool $is_system
 * @property bool $is_active
 * @property int $sort_order
 */
class Module extends Model
{
    /** @var list<string> */
    protected $fillable = ['key', 'name', 'is_system', 'is_active', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        return $tenant === null ? 'modules' : "{$tenant->slug}_auth.modules";
    }
}
