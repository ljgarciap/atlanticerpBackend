<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContPayment;
use App\Shared\Catalogs\CatalogService;

/**
 * Fase 14b — fachada de las formas de pago (dominio `payment_method` del catálogo). La lógica pregunta por el COMPORTAMIENTO de una forma
 * (`sin_cuenta_bancaria`, `inmediato`, `caja_chica`), no por su clave; las únicas claves que el código todavía nombra son las de sistema
 * (`retencion_impuestos`, `ajuste_cuenta`), que no se borran ni cambian de propiedades. Una forma desactivada deja de ofrecerse pero se
 * sigue resolviendo (etiqueta y comportamiento) para los cobros ya registrados con ella.
 */
final class PaymentMethods
{
    public const DOMAIN = 'payment_method';

    /** @return list<string> claves activas (para reglas `in:` de un cobro o abono nuevo) */
    public static function keys(): array
    {
        return CatalogService::keys(self::DOMAIN);
    }

    /** @return list<string> claves activas que también se ofrecen al pagar un reporte de caja chica */
    public static function cajaChicaKeys(): array
    {
        return self::withProp(onlyActive: true, prop: 'caja_chica');
    }

    /** ¿Nunca representa dinero entrando a una cuenta bancaria real? (no pide cuenta) */
    public static function sinCuentaBancaria(?string $key): bool
    {
        return CatalogService::prop(self::DOMAIN, $key, 'sin_cuenta_bancaria') === true;
    }

    /** ¿Se confirma en el momento (entra al arqueo del día del cobro) en vez de esperar confirmación bancaria? */
    public static function inmediato(?string $key): bool
    {
        return CatalogService::prop(self::DOMAIN, $key, 'inmediato') === true;
    }

    /** @return list<string> claves (también las desactivadas) cuyo cobro entra al arqueo del día en que se registra */
    public static function inmediatos(): array
    {
        return self::withProp(onlyActive: false, prop: 'inmediato');
    }

    /** Etiqueta de cualquier clave guardada, aun si la forma se desactivó; sin entrada, la de siempre o la clave. */
    public static function label(string $key): string
    {
        $entry = CatalogService::entries(self::DOMAIN, onlyActive: false)->firstWhere('key', $key);

        return $entry !== null ? CatalogService::labelOf($entry) : (AdminContPayment::METODO_LABELS[$key] ?? $key);
    }

    /** @return array<string, string> clave => etiqueta de todas las formas (para tablas y PDF que muestran cobros históricos) */
    public static function labels(): array
    {
        return CatalogService::labels(self::DOMAIN, onlyActive: false);
    }

    /** @return list<string> */
    private static function withProp(bool $onlyActive, string $prop): array
    {
        return array_values(array_map(strval(...), CatalogService::entries(self::DOMAIN, $onlyActive)
            ->filter(static fn ($e): bool => ($e->props[$prop] ?? false) === true)
            ->pluck('key')->all()));
    }
}
