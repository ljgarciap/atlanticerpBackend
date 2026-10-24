<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Str;
use Throwable;

/**
 * SCRUM-990 (P3 de la revisión de seguridad) — mensaje de una excepción SIN el SQL ni los bindings que adjunta `QueryException` (en un lote del
 * sincronizador serían miles de parámetros con datos de un tercero; en la limpieza, sentencias con nombres de tablas) ni datos de conexión: clase,
 * código y la primera línea recortada. Para imprimir en consola y en `docker logs`.
 */
trait MensajeSeguroDeExcepcion
{
    private function mensajeSeguro(Throwable $e): string
    {
        $limpio = (string) preg_replace('/\s*\((?:Connection|SQL):.*$/s', '', (string) strtok($e->getMessage(), "\n"));

        return class_basename($e) . ' [' . $e->getCode() . ']: ' . Str::limit($limpio, 200);
    }
}
