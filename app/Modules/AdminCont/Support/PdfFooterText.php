<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Support;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;

/**
 * SCRUM-857/858 — texto del pie de página real (`page_text()` de dompdf, único mecanismo con
 * paginación real) compartido por `AccountStatementPdfService` y `PaymentReceiptPdfService` — antes
 * de esto, `payment-receipt-pdf.blade.php` traía un texto propio hardcodeado ("Atlantic -
 * Panama - Documento generado por AtlanticERP", rebote de SCRUM-858) en vez de leer los datos reales de
 * la empresa como ya hacía `quote-pdf.blade.php`.
 *
 * `AdminContFiscalSettings` está vacío en dev/test/prod (nadie llenó la pantalla, exclusiva de
 * Mark) — sin fallback, `forFiscal()`/`companyName()` devolvían "" y el pie/encabezado salían en
 * blanco en vez de mostrar la marca real. `DEFAULT_COMPANY_NAME` cubre ese hueco de datos hasta
 * que se cargue la info fiscal real; nunca vuelve a mostrar "AtlanticERP".
 */
final class PdfFooterText
{
    public const DEFAULT_COMPANY_NAME = 'Atlantic';

    /**
     * Pre-QA (SCRUM-857/858, 2026-09-09) — mismo string literal ya hardcodeado en
     * `quote-pdf.blade.php`/`service-quote-pdf.blade.php` (aprobado, en producción). Mientras
     * `AdminContFiscalSettings` siga vacío (nombre_comercial Y direccion_fiscal, el estado real
     * hoy en dev/test/prod), el footer de Estado de Cuenta/Recibo de Cobro mostraba solo
     * "Atlantic" — sin dirección ni sitio web — incumpliendo el criterio literal del ticket
     * ("el pie de página muestra: ATLANTIC USA CORP - Calle 57 Este...") y la consistencia
     * visual pedida contra cotizaciones. No es un dato de negocio nuevo: es el mismo texto público
     * ya aprobado y usado en los otros 2 documentos, solo replicado acá como fallback hasta que
     * Mark cargue los datos fiscales reales (que sí toman prioridad, ver abajo).
     */
    /** El sitio web NO tiene columna propia en fiscal_settings — es una constante de marca, igual
     *  que en quote-pdf.blade.php. Se agrega siempre al pie (con datos fiscales o con el fallback)
     *  para que el footer sea idéntico en ambos casos (SCRUM-858 AC #4). */
    public const DEFAULT_WEBSITE = 'www.atlantic.com.pa';

    public const DEFAULT_FOOTER = 'ATLANTIC USA CORP - Calle 57 Este, Times Square Plaza - Obarrio, Panama - '.self::DEFAULT_WEBSITE;

    public static function forFiscal(AdminContFiscalSettings $fiscal): string
    {
        if (trim($fiscal->nombre_comercial) === '' && trim($fiscal->direccion_fiscal) === '') {
            return self::escapeForDoubleQuotedPhpString(self::DEFAULT_FOOTER);
        }

        $name = self::companyName($fiscal);
        $text = trim($fiscal->direccion_fiscal) === ''
            ? $name
            : "{$name} - {$fiscal->direccion_fiscal}";
        $text .= ' - '.self::DEFAULT_WEBSITE;

        // Este valor se interpola dentro de un string PHP DOBLE-COMILLADO
        // (`$companyText = "..."`) en el bloque <script type="text/php"> de dompdf — sin escapar,
        // una comilla doble en el nombre/dirección (dato editable por un admin) deja el pie de
        // página mal formado o ausente. Interpolar este valor en la vista con {!! !!}, nunca
        // {{ }} — Blade escaparía la comilla ya escapada acá una segunda vez.
        //
        // Pre-QA (2026-09-09) — encontrado en vivo probando justo esto (comilla simple en el
        // nombre de empresa): `addslashes()` escapa TANTO comillas simples como dobles, pero acá
        // el string destino usa comillas DOBLES — un apóstrofe no necesita escape en ese contexto,
        // y `\'` no es una secuencia de escape reconocida dentro de un string PHP doble-comillado
        // (a diferencia de un string simple-comillado), así que quedaba como backslash literal
        // VISIBLE en el PDF ("Corp\'s" en vez de "Corp's"). Escapar solo backslash y comilla
        // doble — lo único que ese contexto realmente necesita.
        return self::escapeForDoubleQuotedPhpString($text);
    }

    public static function companyName(AdminContFiscalSettings $fiscal): string
    {
        return trim($fiscal->nombre_comercial) !== '' ? $fiscal->nombre_comercial : self::DEFAULT_COMPANY_NAME;
    }

    /** Escapa únicamente lo que un string PHP `"..."` necesita: backslash y comilla doble. */
    private static function escapeForDoubleQuotedPhpString(string $text): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $text);
    }
}
