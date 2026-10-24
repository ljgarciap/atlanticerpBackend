<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Support;

/**
 * SCRUM-1156 — decide qué PDF lleva una factura: `fiscal` (certificada ante la DGI, con QR y clave de acceso) o `sin_validez`
 * (el PDF de siempre más el banner «Sin validez fiscal» con su motivo). Clase PURA: sin I/O ni acceso a la BD, para que cada
 * rama se pruebe sola. Ver docs/architecture/scrum1156-pdf-factura-diseno.md §4 y §5.
 *
 * Regla central (RN1/RN2): la variante `fiscal` exige TODO a la vez — estado `certificado`, `cufe` y `qr_content` presentes,
 * enlace `https` sin credenciales a un host permitido, y que el `cufe` guardado viaje en el propio enlace (`chFE`). Cualquier
 * fallo cae a `sin_validez`: un documento nunca se pinta como certificado con datos incompletos o de otro documento
 * (el conocimiento FE ya registró un CUFE ajeno asignado por reconciliación entre ambientes, reglas 9 y 21).
 */
final class InvoicePdfVariant
{
    public const FISCAL      = 'fiscal';
    public const SIN_VALIDEZ = 'sin_validez';

    public const MOTIVO_PENDIENTE        = 'pendiente';
    public const MOTIVO_RECHAZADA        = 'rechazada';
    public const MOTIVO_EN_ESPERA_NC     = 'en_espera_nc';
    public const MOTIVO_NO_APLICADA      = 'no_aplicada';
    public const MOTIVO_LEGADA           = 'legada';
    public const MOTIVO_DATOS_INCOMPLETOS = 'datos_incompletos';
    /** Lo pone `InvoicePdfService`: el detalle es de un pedido que todavía no tiene factura emitida. */
    public const MOTIVO_SIN_EMITIR       = 'sin_emitir';

    /** Parámetro del enlace de consulta de la DGI que lleva el CUFE (`chFE`). */
    private const CUFE_PARAM = 'chFE';

    /**
     * @param list<string> $allowedHosts hosts DGI aceptados en `qr_content` (ver `config/fiscal-pdf.php`)
     */
    public static function resolve(
        ?string $feEstado,
        ?string $cufe,
        ?string $qrContent,
        bool $gdAvailable,
        array $allowedHosts,
    ): InvoicePdfVariantResult {
        if ($feEstado !== 'certificado') {
            return self::sinValidez(self::motivoSinCertificar($feEstado));
        }

        $cufe = $cufe === null ? '' : trim($cufe);
        $url  = $gdAvailable && $cufe !== '' ? self::validatedUrl($qrContent, $cufe, $allowedHosts) : null;

        if ($url === null) {
            return self::sinValidez(self::MOTIVO_DATOS_INCOMPLETOS);
        }

        return new InvoicePdfVariantResult(self::FISCAL, null, $url, $cufe);
    }

    /**
     * ¿El documento se emitió en un ambiente de pruebas? (RN9). `produccion` (el valor que guarda `fe_transmisiones.ambiente`;
     * se acepta también `production`, el de `pac_ambiente`) es el único valor declarado que NO lo es; ambiente ausente
     * se deduce del host del enlace (producción solo si el host está en `$productionHosts`) y, ante cualquier duda, es pruebas
     * — nunca se pinta como producción lo que no se puede demostrar.
     *
     * @param list<string> $productionHosts
     */
    public static function isTestEnvironment(?string $ambiente, ?string $qrContent, array $productionHosts): bool
    {
        $ambienteDeclarado = $ambiente !== null && trim($ambiente) !== '';
        $ambienteEsProduccion = ! $ambienteDeclarado || in_array(trim((string) $ambiente), ['produccion', 'production'], true);

        $host = self::hostOf($qrContent);
        $hostEsProduccion = $host !== null && in_array($host, self::lower($productionHosts), true);

        // Producción solo si TODO lo que se sabe lo dice: el ambiente guardado (si lo hay) Y el host del enlace. Si discrepan
        // (ej. ambiente `produccion` con un enlace del host de pruebas), manda la duda: nunca se pinta como producción lo que no
        // se puede demostrar.
        return ! ($ambienteEsProduccion && $hostEsProduccion);
    }

    private static function motivoSinCertificar(?string $feEstado): string
    {
        return match ($feEstado) {
            'pendiente'                       => self::MOTIVO_PENDIENTE,
            'rechazado'                       => self::MOTIVO_RECHAZADA,
            'en_espera_nc'                    => self::MOTIVO_EN_ESPERA_NC,
            'error_configuracion', 'no_aplica' => self::MOTIVO_NO_APLICADA,
            null, ''                          => self::MOTIVO_LEGADA,
            default                           => self::MOTIVO_DATOS_INCOMPLETOS,
        };
    }

    private static function sinValidez(string $motivo): InvoicePdfVariantResult
    {
        return new InvoicePdfVariantResult(self::SIN_VALIDEZ, $motivo, null, null);
    }

    /**
     * Enlace de consulta imprimible (esquema + host [+ puerto no estándar] + ruta, sin query ni fragmento) o null si
     * `qr_content` no cumple las reglas.
     *
     * @param list<string> $allowedHosts
     */
    private static function validatedUrl(?string $qrContent, string $cufe, array $allowedHosts): ?string
    {
        $qrContent = $qrContent === null ? '' : trim($qrContent);
        if ($qrContent === '') {
            return null;
        }

        // Espacios, caracteres de control y barras invertidas se interpretan distinto en un navegador y en `parse_url`: el
        // validador y quien escanea el QR podrían ver hosts diferentes. El PAC nunca los emite; si aparecen, no es el enlace del PAC.
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $qrContent) === 1) {
            return null;
        }

        $parts = parse_url($qrContent);
        if ($parts === false) {
            return null;
        }

        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
        if (($parts['scheme'] ?? '') !== 'https'
            || $host === ''
            || isset($parts['user']) || isset($parts['pass'])
            || ! in_array($host, self::lower($allowedHosts), true)
        ) {
            return null;
        }

        // `chFE` repetido es ambiguo: `parse_str` se queda con el último valor, un consumidor del enlace podría tomar el primero.
        $claves = array_map('strtolower', array_map(static fn (string $par): string => explode('=', $par, 2)[0], explode('&', $parts['query'] ?? '')));
        if (count(array_keys($claves, strtolower(self::CUFE_PARAM), true)) !== 1) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        $chfe = $query[self::CUFE_PARAM] ?? null;
        if (! is_string($chfe) || ! hash_equals($cufe, $chfe)) {
            return null;
        }

        $port = isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '';

        return 'https://'.$host.$port.($parts['path'] ?? '');
    }

    private static function hostOf(?string $qrContent): ?string
    {
        if ($qrContent === null || trim($qrContent) === '') {
            return null;
        }

        $parts = parse_url(trim($qrContent));

        return $parts !== false && isset($parts['host']) ? strtolower($parts['host']) : null;
    }

    /**
     * @param list<string> $hosts
     * @return list<string>
     */
    private static function lower(array $hosts): array
    {
        return array_map('strtolower', $hosts);
    }
}
