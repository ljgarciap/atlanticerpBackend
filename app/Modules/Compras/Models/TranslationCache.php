<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-1127 — traducción por texto de origen (sha256 del texto normalizado), compartida entre órdenes. Sin auditoría de cambios a propósito:
 * es una caché derivada, no un dato de negocio.
 *
 * @property int    $id
 * @property string $source_hash
 * @property string $lang
 * @property string $translated
 */
class TranslationCache extends Model
{
    protected $table = 'translation_cache';

    /** @var list<string> */
    protected $fillable = ['source_hash', 'lang', 'translated'];

    public function getTable(): string
    {
        $tenant = Tenant::current();

        return $tenant === null ? 'translation_cache' : "{$tenant->slug}_compras.translation_cache";
    }
}
