<?php

declare(strict_types=1);

namespace App\Shared\Catalogs\Contracts;

use App\Shared\Catalogs\CatalogEntry;
use Illuminate\Validation\ValidationException;

/**
 * Fase 7c — reglas propias de un dominio del catálogo (se declara su clase en `rules` de config/catalogs.php). Las genéricas
 * (propiedades tipadas, entradas de sistema protegidas) viven en CatalogService; esto es para lo que solo ese dominio sabe.
 */
interface CatalogDomainRules
{
    /** Orden de una entrada nueva sin `sort_order` explícito; null = el genérico (al final). */
    public static function defaultSortOrder(): ?int;

    /**
     * @param  array{sort_order: int, props: array<string, mixed>}  $data  valores resultantes de la entrada
     * @param  CatalogEntry|null  $entry  la entrada que se edita (null al crear)
     *
     * @throws ValidationException
     */
    public static function validate(string $domain, array $data, ?CatalogEntry $entry): void;
}
