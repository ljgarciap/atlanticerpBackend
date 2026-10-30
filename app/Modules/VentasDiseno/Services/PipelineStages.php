<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Catalogs\CatalogService;

/**
 * Fase 7c — fachada de las etapas del pipeline de Ventas & Diseño (dominio `pipeline_stage` del catálogo). El código pregunta por la
 * CATEGORÍA de una etapa («¿está ganada?», «¿está abierta?») y por su posición en el flujo lineal, no por su clave: una etapa propia
 * de la empresa (siempre `abierta`) se intercala entre Lead y Aprobado sin que la lógica tenga que conocerla.
 *
 * Las 6 claves de sistema se mantienen como constantes porque son las ANCLAS del flujo: las compuertas de transición (contacto para salir
 * de Lead, cotización real para entrar a Cotización, m² para Propuesta, firma y comprobante para Aprobado) cuelgan de ellas.
 */
final class PipelineStages
{
    public const DOMAIN = 'pipeline_stage';

    public const LEAD = 'lead';

    public const DESIGN = 'design';

    public const QUOTE = 'quote';

    public const PROPOSAL = 'proposal';

    public const APPROVED = 'approved';

    public const LOST = 'lost';

    public const ABIERTA = 'abierta';

    public const GANADA = 'ganada';

    public const PERDIDA = 'perdida';

    /** @return list<string> claves activas, en el orden del catálogo (incluye la perdida) */
    public static function keys(): array
    {
        return CatalogService::keys(self::DOMAIN);
    }

    /** @return list<string> flujo lineal: las etapas activas que no son «perdida», en orden */
    public static function linear(): array
    {
        return self::keysWhere(static fn (CatalogEntry $e): bool => self::categoryOf($e) !== self::PERDIDA);
    }

    /** @return list<string> etapas activas de la categoría dada */
    public static function byCategory(string $categoria): array
    {
        return self::keysWhere(static fn (CatalogEntry $e): bool => self::categoryOf($e) === $categoria);
    }

    /** @return list<string> etapas «abiertas» (en curso): lo que antes era `['lead', 'design', 'quote', 'proposal']` */
    public static function open(): array
    {
        return self::byCategory(self::ABIERTA);
    }

    /** @return list<string> etapas «ganadas» (hoy solo Aprobado) */
    public static function won(): array
    {
        return self::byCategory(self::GANADA);
    }

    /** @return list<string> etapas «perdidas» (hoy solo Perdido) */
    public static function lost(): array
    {
        return self::byCategory(self::PERDIDA);
    }

    public static function categoria(?string $stage): string
    {
        return (string) CatalogService::prop(self::DOMAIN, $stage, 'categoria');
    }

    public static function isWon(?string $stage): bool
    {
        return self::categoria($stage) === self::GANADA;
    }

    public static function isLost(?string $stage): bool
    {
        return self::categoria($stage) === self::PERDIDA;
    }

    /** Posición en el flujo lineal; null si la etapa no está en él (perdida o desconocida). */
    public static function index(?string $stage): ?int
    {
        $i = array_search($stage, self::linear(), true);

        return $i === false ? null : $i;
    }

    /** ¿`$stage` está en la misma posición o después de `$anchor` en el flujo lineal? (p. ej. «ya llegó a Propuesta») */
    public static function isAtOrAfter(?string $stage, string $anchor): bool
    {
        $i = self::index($stage);
        $a = self::index($anchor);

        return $i !== null && $a !== null && $i >= $a;
    }

    /** ¿`$stage` está antes de `$anchor` en el flujo lineal? (p. ej. «todavía no se cotizó») */
    public static function isBefore(?string $stage, string $anchor): bool
    {
        $i = self::index($stage);
        $a = self::index($anchor);

        return $i !== null && $a !== null && $i < $a;
    }

    /**
     * Etapas abiertas que están en la posición de `$anchor` o después (p. ej. las «propuestas pendientes de aprobar»: Propuesta más las
     * etapas propias intercaladas entre Propuesta y Aprobado). La ganada y la perdida no son abiertas, así que no entran.
     *
     * @return list<string>
     */
    public static function openAtOrAfter(string $anchor): array
    {
        return array_values(array_filter(self::open(), static fn (string $k): bool => self::isAtOrAfter($k, $anchor)));
    }

    /**
     * La última etapa ancla (de las dadas) que está en la posición de `$stage` o antes; null si ninguna. Una etapa propia hereda así la
     * probabilidad del forecast de la etapa de sistema que la precede.
     *
     * @param  list<string>  $anchors
     */
    public static function anchorOf(?string $stage, array $anchors): ?string
    {
        $i = self::index($stage);
        $best = null;
        $bestIdx = -1;
        foreach ($anchors as $anchor) {
            $a = self::index($anchor);
            if ($i !== null && $a !== null && $a <= $i && $a > $bestIdx) {
                $best = $anchor;
                $bestIdx = $a;
            }
        }

        return $best;
    }

    /** Etiqueta de la etapa, también de una desactivada; sin entrada, la propia clave. */
    public static function label(string $stage): string
    {
        return self::labels()[$stage] ?? $stage;
    }

    /**
     * Etiquetas de TODAS las etapas (también las desactivadas), clave => etiqueta. Para respuestas transmitidas (CSV) cuyo cierre corre
     * cuando el contexto del tenant ya se olvidó: se resuelve antes de transmitir, no dentro del cierre.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return CatalogService::labels(self::DOMAIN, onlyActive: false);
    }

    /**
     * @param  callable(CatalogEntry): bool  $filter
     * @return list<string>
     */
    private static function keysWhere(callable $filter): array
    {
        return array_values(array_map(
            static fn (CatalogEntry $e): string => $e->key,
            array_filter(CatalogService::entries(self::DOMAIN)->all(), $filter),
        ));
    }

    private static function categoryOf(CatalogEntry $e): string
    {
        return (string) ($e->props['categoria'] ?? self::ABIERTA);
    }
}
