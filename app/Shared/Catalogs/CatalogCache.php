<?php

declare(strict_types=1);

namespace App\Shared\Catalogs;

use App\Modules\Auth\Services\PreferencesService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Collection;

/**
 * Memo de las entradas de un catálogo, acotada a la petición (se registra `scoped` en AppServiceProvider: se descarta entre peticiones y
 * entre jobs de la cola, así que un worker nunca ve un catálogo viejo). Sin ella, preguntar por el comportamiento de una entrada dentro de
 * un bucle (una fila de la lista de cotizaciones, una tarjeta del forecast) costaba una consulta por pregunta. La clave incluye el tenant
 * activo, y toda escritura de CatalogService la invalida (`forget()`).
 */
final class CatalogCache
{
    /** @var array<string, Collection<int, CatalogEntry>> */
    private array $memo = [];

    /** @return Collection<int, CatalogEntry> todas las entradas del dominio (también inactivas), en su orden */
    public function entries(string $domain): Collection
    {
        $key = (Tenant::current()->slug ?? '-').':'.$domain;

        return $this->memo[$key] ??= CatalogEntry::query()->where('domain', $domain)->orderBy('sort_order')->orderBy('label')->get();
    }

    /** @var array<string, string> idioma por defecto de la empresa, por tenant */
    private array $defaultLanguage = [];

    /** Fase 14i — idioma por defecto de la empresa (ajuste de sistema `default_language`), memoizado por petición. */
    public function defaultLanguage(): string
    {
        $key = Tenant::current()->slug ?? '-';

        return $this->defaultLanguage[$key] ??= (string) (app(PreferencesService::class)->getSystemSettings()['default_language'] ?? 'es');
    }

    public function forget(): void
    {
        $this->memo = [];
        $this->defaultLanguage = [];
    }
}
