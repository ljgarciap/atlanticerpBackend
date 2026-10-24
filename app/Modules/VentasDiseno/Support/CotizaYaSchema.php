<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Support;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1120 — ¿el esquema del tenant ya tiene lo que CotizaYA necesita (`quotes.origin` y `cotizaya_settings`)?
 *
 * El despliegue levanta el código nuevo (`up -d --build`) ANTES de correr las migraciones: en ese intervalo el filtro global
 * de `Quote` y el observer del cliente consultarían una columna/tabla que todavía no existe y cada pantalla de Cotizaciones,
 * Pipeline, Home, Gerencia y Pedidos respondería 500. Mientras el esquema no esté listo, ambos se desactivan solos (no hay
 * ninguna CotizaYA que aislar todavía), y se reactivan sin intervención en cuanto corre la migración.
 *
 * El "sí" se memoriza por tenant para siempre (el esquema no retrocede); el "no" se vuelve a consultar como mucho cada 30 s.
 */
final class CotizaYaSchema
{
    /** @var array<string, bool> */
    private static array $ready = [];

    /** @var array<string, int> */
    private static array $checkedAt = [];

    public static function ready(): bool
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return true;
        }

        $slug = $tenant->slug;
        if (self::$ready[$slug] ?? false) {
            return true;
        }

        if ((self::$checkedAt[$slug] ?? 0) > time() - 30) {
            return false;
        }

        self::$checkedAt[$slug] = time();
        $schema = "{$slug}_ventas_diseno";

        return self::$ready[$slug] = Schema::hasColumn("{$schema}.quotes", 'origin') && Schema::hasTable("{$schema}.cotizaya_settings");
    }

    /** Solo para tests. */
    public static function forget(): void
    {
        self::$ready     = [];
        self::$checkedAt = [];
    }
}
