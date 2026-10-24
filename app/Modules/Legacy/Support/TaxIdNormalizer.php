<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Support;

/**
 * SCRUM-1137 (Fase 2) — normaliza el identificador fiscal de un cliente de ICG a (RUC, DV).
 * Clase pura: sin DB, sin red, sin estado.
 *
 * Contexto medido en ICG real: `CLIENTES.CIF` es `nvarchar(12)` y trunca el RUC; el RUC completo
 * está en `CLIENTES.NIF20` (`nvarchar(20)`), que en ~1.209 de 6.248 filas trae el dígito
 * verificador pegado en el texto (`155783782-2-2026DV35`, `987695-1-533551 DV09`).
 *
 * Reglas (en el orden en que se aplican):
 *  (a) `NIF20` manda sobre `CIF`; `CIF` solo se usa cuando `NIF20` está vacío.
 *  (b) Se recortan los espacios de los extremos. Vacío o solo espacios cuenta como vacío.
 *  (c) El DV se separa SOLO si el texto termina en el marcador `DV` (sin distinguir mayúsculas)
 *      seguido de 1 a 4 dígitos; el espacio previo al marcador se descarta. Decisión propia: además
 *      se exige que lo anterior al marcador termine en dígito, para no partir un valor como
 *      `ABCDV12` y confundir letras con el marcador.
 *  (d) Una cédula o cualquier valor sin marcador `DV` deja `dv = null`. NUNCA se calcula ni se
 *      inventa un DV.
 *  (e) Si, tras quitar el DV, el valor no es un identificador razonable (solo letras/dígitos
 *      separados por guiones simples, sin espacios internos, sin guion inicial ni final, sin la
 *      palabra RUC al principio o como palabra suelta) -> `estado = revisar`. En ese caso `ruc`
 *      conserva el valor original recortado (con el DV incluido si lo traía) y `dv = null`: no
 *      se corrige nada solo.
 *  (f) `CIF` de exactamente 12 caracteres (ya recortado) sin `NIF20` -> `estado =
 *      truncado_posible` (el límite de la columna es 12, así que puede estar cortado). Decisión
 *      propia: un CIF cortado en 12 puede terminar en guion (`155783782-2-`), lo que por (e) lo
 *      haría `revisar`; para ese solo caso se tolera UN guion final al validar, y el valor se
 *      devuelve sin modificar. Si hay cualquier otra falla (`RUC 1671600-` tiene 12 caracteres,
 *      espacio y la palabra RUC) sigue siendo `revisar`: la revisión manda sobre el truncamiento.
 *  (g) Ambos vacíos -> `origen = ninguno`, `ruc = null`, `dv = null`, `estado = ok` (no hay nada
 *      que revisar; decisión propia, el enum no tiene un valor "vacío").
 *
 * No hay detección de truncamiento en `NIF20` (su límite son 20 caracteres): un `NIF20` de 20
 * caracteres sin marcador DV se acepta como `ok`.
 */
final class TaxIdNormalizer
{
    private const CIF_MAX_LENGTH = 12;

    /** Marcador DV al final: grupo 1 = resto (termina en dígito), grupo 2 = dígitos del DV. */
    private const DV_PATTERN = '/^(.*\d)\s*DV\s*(\d{1,4})$/i';

    /** Identificador razonable: alfanumérico con guiones simples entre grupos. */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/';

    public static function normalize(?string $nif20, ?string $cif): TaxIdNormalization
    {
        $nif20 = self::clean($nif20);
        $cif   = self::clean($cif);

        if ($nif20 !== null) {
            return self::evaluate($nif20, TaxIdNormalization::ORIGEN_NIF20, false);
        }

        if ($cif !== null) {
            return self::evaluate($cif, TaxIdNormalization::ORIGEN_CIF, strlen($cif) === self::CIF_MAX_LENGTH);
        }

        return new TaxIdNormalization(null, null, TaxIdNormalization::ORIGEN_NINGUNO, TaxIdNormalization::ESTADO_OK);
    }

    private static function evaluate(string $value, string $origen, bool $possiblyTruncated): TaxIdNormalization
    {
        $ruc = $value;
        $dv  = null;

        if (preg_match(self::DV_PATTERN, $value, $m) === 1) {
            $ruc = $m[1];
            $dv  = $m[2];
        }

        if (! self::isReasonable($ruc, $possiblyTruncated)) {
            return new TaxIdNormalization($value, null, $origen, TaxIdNormalization::ESTADO_REVISAR);
        }

        return new TaxIdNormalization(
            $ruc,
            $dv,
            $origen,
            $possiblyTruncated ? TaxIdNormalization::ESTADO_TRUNCADO_POSIBLE : TaxIdNormalization::ESTADO_OK,
        );
    }

    private static function isReasonable(string $ruc, bool $allowTrailingHyphen): bool
    {
        if (preg_match('/^RUC/i', $ruc) === 1 || preg_match('/\bRUC\b/i', $ruc) === 1) {
            return false;
        }

        if ($allowTrailingHyphen && str_ends_with($ruc, '-')) {
            $ruc = substr($ruc, 0, -1);
        }

        return preg_match(self::IDENTIFIER_PATTERN, $ruc) === 1;
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
