<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Support;

/**
 * SCRUM-1120 — interruptor por request que dice "estamos dentro del módulo CotizaYA". Fuera de él, el global scope
 * `CotizaYaOriginScope` esconde las cotizaciones `origin = cotizaya` de TODA consulta; dentro, las deja ver para que
 * los controladores de partidas/ítems/contactos (que hacen `Quote::find($id)`) las encuentren. Lo activa únicamente
 * el middleware `cotizaya.context` de las rutas de CotizaYA y lo apaga siempre al terminar la request.
 *
 * Contador (no booleano) para tolerar anidamiento; los jobs en cola y los comandos nunca lo activan, así que
 * por defecto nunca ven una CotizaYA.
 */
final class CotizaYaContext
{
    private static int $depth = 0;

    public static function enter(): void
    {
        self::$depth++;
    }

    public static function leave(): void
    {
        self::$depth = max(0, self::$depth - 1);
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }

    /** Ejecuta $callback con el contexto activo (para el módulo CotizaYA y los tests). */
    public static function run(callable $callback): mixed
    {
        self::enter();
        try {
            return $callback();
        } finally {
            self::leave();
        }
    }
}
