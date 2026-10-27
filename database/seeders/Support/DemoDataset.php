<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

/**
 * Fase 4b (docs/specs/fase4b-dataset-demo-generico.md) — ÚNICA fuente de los textos del dataset demo. Los seeders demo conservan su lógica, sus conteos,
 * sus estados y sus relaciones; solo el CONTENIDO sale de aquí, y es neutro: sin organizaciones, personas ni dominios reales y sin vocabulario de
 * ningún sector. Todo se genera por índice para que la forma del dataset no dependa de los nombres.
 *
 * `NeutralidadDelRepoTest` falla si algún seeder vuelve a escribir un nombre real fuera de este archivo.
 */
final class DemoDataset
{
    /** Prefijo que identifica un dato demo (lo usan los filtros y la limpieza de datos de prueba). */
    public const MARKER = '[DEMO]';

    // ── CRM ──────────────────────────────────────────────────────────────────────────────────────────────────
    public static function proyectoCrm(int $n): string
    {
        return sprintf('%s Proyecto %02d', self::MARKER, $n);
    }

    public static function contacto(int $n): string
    {
        return sprintf('Contacto Demo %02d', $n);
    }

    public static function email(int $n): string
    {
        return sprintf('contacto%02d@demo.test', $n);
    }

    public static function desarrolladora(int $n): string
    {
        return sprintf('Desarrolladora Demo %02d', $n);
    }

    public static function arquitecto(int $n): string
    {
        return sprintf('Arq. Demo %02d', $n);
    }

    public static function encargado(int $n): string
    {
        return sprintf('Encargado Demo %02d', $n);
    }

    public static function ubicacion(int $n): string
    {
        return sprintf('Ubicación demo %02d', $n);
    }

    public static function despacho(): string
    {
        return 'Depósito Demo';
    }

    public static function notas(int $n): string
    {
        return sprintf('Notas de demostración del proyecto %02d.', $n);
    }

    public static function actividad(string $tipo, int $n): string
    {
        return sprintf('Actividad demo (%s) del proyecto %02d.', $tipo, $n);
    }

    public static function recordatorio(string $tipo, int $n): string
    {
        return sprintf('Recordatorio demo (%s) del proyecto %02d.', $tipo, $n);
    }

    // ── Ventas & Diseño ──────────────────────────────────────────────────────────────────────────────────────
    public static function clienteMaestro(string $letra): string
    {
        return sprintf('%s Cliente Maestro %s', self::MARKER, $letra);
    }

    public static function subcliente(string $letra): string
    {
        return sprintf('%s Cliente %s1 S.A.', self::MARKER, $letra);
    }

    public static function proyectoVenta(string $nombre): string
    {
        return sprintf('%s Proyecto %s', self::MARKER, $nombre);
    }

    // ── Servicios ────────────────────────────────────────────────────────────────────────────────────────────
    public static function sitio(int $n): string
    {
        return sprintf('%s Sitio %02d', self::MARKER, $n);
    }

    // ── Compras ──────────────────────────────────────────────────────────────────────────────────────────────
    public static function proveedor(string $letra): string
    {
        return sprintf('%s Proveedor %s', self::MARKER, $letra);
    }

    // ── Catálogo ─────────────────────────────────────────────────────────────────────────────────────────────
    public static function marca(string $letra): string
    {
        return sprintf('Marca Demo %s', $letra);
    }

    public static function productoRef(int $n): string
    {
        return sprintf('DEMO-%03d', $n);
    }

    public static function productoDescripcion(int $n): string
    {
        return sprintf('Producto demo %02d', $n);
    }

    public static function familia(int $n): string
    {
        return sprintf('Familia demo %02d', $n);
    }
}
