<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Fase 4b (docs/specs/fase4b-dataset-demo-generico.md §3.3 y §6.1) — la lista única de lo que NO puede aparecer en el repositorio ni en el dato demo:
 * organizaciones, marcas, bancos y lugares del cliente anterior, y el vocabulario de su sector. La usan `DemoDatasetNeutralidadTest` (tablas sembradas)
 * y `NeutralidadDelRepoTest` (código, tests y seeders).
 *
 * `PERMITIDOS` son las EXCEPCIONES TEMPORALES con dueño: términos que son del MODELO (no del dato) y salen cuando su fase termine. La lista solo
 * puede encogerse: el contrato falla si un permitido ya no aparece en ningún archivo.
 */
final class TerminosDelClienteAnterior
{
    /** Organizaciones, marcas, bancos y lugares reales usados como datos de ejemplo. */
    public const ENTIDADES = [
        'decameron', 'westland', 'costa bella', 'torre delta', 'torre pac[ií]fic', 'torre business park', 'hotel riu', 'grupo constructor delta',
        'minsa', 'ministerio de salud', 'ulatina', 'universidad latina', 'supermercado el rey', 'elrey', 'aeropuertospa', 'plazatocumen', 'plaza tocumen',
        'grupopacifico', 'cl[ií]nica san fernando', 'clinicasf', 'pitsa', 'constructoramc', 'ff-inversiones', 'atlapa', 'iluminex', 'lightcorp', 'luxtech',
        'atlantic home', 'signature lighting', 'nordic glow', 'proline comercial', 'banco general', 'banistmo', 'global bank', 'multibank', 'multiplaza',
        'super 99', 'paitilla', 'albrook', 'tocumen', 'bethania', 'costa del este', 'punta pac[ií]fica', 'condado del rey', 'farmacias transporte',
        'automotriz rey', 'ocean view', 'grupo melo', 'gelabert', 'farallon', 'illuminations',
        // Fase 4c — correos demo viejos (el renombre a vendedor@, tecnico@, nivelN@… no debe deshacerse).
        'vendedordisenador\d*@', 'tecnicoservicios\d*@', 'garantiasservicios@', 'ayudantegeneralbodega\d*@', '(?:designer|supervisor|electrician|readonly|multi)@',
    ];

    /** Vocabulario del sector del cliente anterior (iluminación). */
    public const SECTOR = [
        'iluminaci', 'iluminacon', 'luminaria', 'l[aá]mpara', 'lamparas', 'downlight', 'candelabro', 'bombill', '\bLED\b', 'chandelier', 'luminaire',
        '(?<![A-Za-z])LAMP(?![A-Za-z])',
    ];

    /**
     * Excepciones TEMPORALES (patrón => dueño). Son términos del modelo, no del dato:
     * - «catálogo de productos genérico»: las 8 claves de `CatalogProduct::CATEGORIES`, sus etiquetas y el campo `dialux` del CRM.
     * - «almacenes configurables»: `showroom` y los nombres de almacén de la migración que los crea (`Obarrio`).
     *
     * @var array<string, string>
     */
    public const PERMITIDOS = [
        'candelabros_colgantes' => 'catálogo de productos genérico',
        'iluminacion_(?:techo|bano|exterior|empotrada)' => 'catálogo de productos genérico',
        'apliques_pared' => 'catálogo de productos genérico',
        'lamparas_piso_mesa' => 'catálogo de productos genérico',
        '(?<=[\'"])bombillos(?=[\'"])' => 'catálogo de productos genérico',
        '(?<=categories\.)bombillos' => 'catálogo de productos genérico',
        '(?<=category[:=] [\'"])bombillos' => 'catálogo de productos genérico',
        'Candelabros y Colgantes' => 'catálogo de productos genérico',
        '(?<=[\'"])candelabros(?: colgantes)?(?=[\'"])' => 'catálogo de productos genérico',
        'Iluminaci[oó]n de (?:Techo|Ba[ñn]o)' => 'catálogo de productos genérico',
        'Iluminaci[oó]n (?:Exterior|Empotrada)' => 'catálogo de productos genérico',
        'L[aá]mparas de Piso y Mesa' => 'catálogo de productos genérico',
        '(?<=["\'])L[aá]mparas(?=["\'])' => 'catálogo de productos genérico',
        'dialux' => 'catálogo de productos genérico',
        '\w*showrooms?\w*' => 'almacenes configurables',
        'obarrio' => 'almacenes configurables',
    ];

    /**
     * Archivos con identidad o prompts del cliente anterior que corresponden a la Fase 4c (se borran de esta lista al migrarlos).
     * Ruta relativa a la raíz del backend.
     *
     * @var array<string, string>
     */
    public const ARCHIVOS_PENDIENTES_4C = [];

    public static function regexProhibidos(): string
    {
        return '/(?:'.implode('|', [...self::ENTIDADES, ...self::SECTOR]).')/iu';
    }

    /** `\bLED\b` y `LAMP` distinguen mayúsculas (son siglas); el resto no. */
    public static function encuentra(string $texto): ?string
    {
        $limpio = $texto;
        foreach (array_keys(self::PERMITIDOS) as $permitido) {
            $limpio = (string) preg_replace('/'.$permitido.'/iu', '', $limpio);
        }

        foreach (self::ENTIDADES as $e) {
            if (preg_match('/'.$e.'/iu', $limpio, $m) === 1) {
                return $m[0];
            }
        }
        foreach (self::SECTOR as $s) {
            $flag = str_contains($s, 'LED') || str_contains($s, 'LAMP') ? 'u' : 'iu';
            if (preg_match('/'.$s.'/'.$flag, $limpio, $m) === 1) {
                return $m[0];
            }
        }

        return null;
    }
}
