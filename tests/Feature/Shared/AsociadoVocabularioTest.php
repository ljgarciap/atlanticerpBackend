<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Fase 17c — la entidad que antes se llamaba «Arquitecto» se muestra como «Asociado». Ningún texto del backend (mensaje, concepto, etiqueta) la
 * nombra «arquitecto». Quedan como excepciones, con dueño, los IDENTIFICADORES de la API que el renombre «solo lo visible» no toca, y el CRM,
 * que conserva su vocabulario propio (Fase 7c). El tipo «Arquitecto» es un dato del catálogo por empresa (no vive en el código). La lista solo
 * puede encogerse: el test falla si una excepción ya no se usa.
 */
final class AsociadoVocabularioTest extends TestCase
{
    /** @var array<string, string> identificador de API → por qué se conserva */
    private const IDENTIFICADORES = [
        'arquitectos'                  => 'clave del resumen de comisiones externas (contrato de la API)',
        'pendiente_factura_arquitecto' => 'valor del estado de la comisión (contrato de la API)',
    ];

    /** @return list<array{string, int, string}> */
    private function textosConArquitecto(): array
    {
        $base = dirname(__DIR__, 3).'/app';
        $hallazgos = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS)) as $archivo) {
            $ruta = (string) $archivo;
            if (! str_ends_with($ruta, '.php') || str_contains($ruta, '/Modules/CRM/') || str_contains($ruta, '/Console/')) {
                continue;
            }
            foreach ((array) file($ruta) as $n => $linea) {
                if (preg_match('/^\s*(\/\/|\*|\/\*|#)/', (string) $linea) === 1) {
                    continue;
                }
                if (preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"/', (string) $linea, $m) > 0) {
                    foreach (array_merge($m[1], $m[2]) as $texto) {
                        if ($texto !== '' && stripos($texto, 'arquitect') !== false) {
                            $hallazgos[] = [str_replace($base.'/', '', $ruta), $n + 1, $texto];
                        }
                    }
                }
            }
        }

        return $hallazgos;
    }

    public function test_ningun_texto_nombra_arquitecto_fuera_de_los_identificadores_de_la_api(): void
    {
        $fuera = array_values(array_filter(
            $this->textosConArquitecto(),
            static fn (array $h): bool => ! array_key_exists($h[2], self::IDENTIFICADORES),
        ));

        $this->assertSame([], array_map(static fn (array $h): string => "{$h[0]}:{$h[1]} «{$h[2]}»", $fuera));
    }

    public function test_cada_excepcion_sigue_en_uso(): void
    {
        $usados = array_unique(array_map(static fn (array $h): string => $h[2], $this->textosConArquitecto()));

        $this->assertSame([], array_values(array_diff(array_keys(self::IDENTIFICADORES), $usados)));
    }
}
