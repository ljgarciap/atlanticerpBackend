<?php

declare(strict_types=1);

namespace App\Modules\Biblioteca\Services;

/**
 * SCRUM-984 (hallazgo M-3) — escaneo barato de un PDF en busca de contenido activo (JavaScript,
 * /Launch, archivos embebidos, etc.). Lee por bloques con un reader inyectado (Range de S3 o fopen
 * local), así que no carga el archivo entero en memoria. Los nombres PDF escritos con escapes
 * hexadecimales (`/J#61vaScript`) se normalizan antes de comparar. No reemplaza a un antivirus: un
 * nombre dentro de un object stream comprimido no se ve (ver config/biblioteca.php).
 *
 * Falla CERRADO: si no se puede garantizar que el archivo completo se escaneó (error de PCRE, o un
 * archivo más grande que el tope de escaneo) devuelve un centinela y el PDF se rechaza.
 */
class PdfActiveContentScanner
{
    /** El PDF es más grande que `biblioteca.pdf_scan_max_bytes`: no se puede escanear completo. */
    public const TOO_LARGE = '[too-large-to-scan]';

    /** PCRE falló (límite de backtracking, etc.): se trata como sospechoso, nunca como limpio. */
    public const SCAN_ERROR = '[scan-error]';

    /** Bytes del bloque anterior que se arrastran al siguiente para no perder un token partido en el corte. */
    private const OVERLAP = 64;

    /**
     * Fin de un nombre PDF: un byte que NO sea "regular". Delimitadores (ISO 32000 §7.2.3) y espacio en
     * blanco, incluido NUL (`/JavaScript\0` es el mismo nombre) y `{`/`}`. Así `/JS` no coincide con
     * `/JSON`, pero `/JS{`, `/JS}` o `/JS\0` sí. Un lookahead negativo también acepta el fin de los datos.
     */
    private const NAME_END = '(?![^\x00\s\/\[\]<>(){}%])';

    /**
     * @param  callable(int $offset, int $length): string $reader lee $length bytes desde $offset ('' al pasar el final)
     * @return string|null el token encontrado (o un centinela self::TOO_LARGE / self::SCAN_ERROR), o null si el PDF está limpio
     */
    public function scan(callable $reader, int $size): ?string
    {
        $tokens = array_values(array_filter((array) config('biblioteca.pdf_active_content_tokens'), 'is_string'));
        if ($tokens === [] || $size < 1) {
            return null;
        }

        // Un archivo que no se puede escanear completo no se acepta: escanear solo un prefijo dejaba pasar
        // el contenido activo puesto después del tope (pdf_scan_max_bytes < max_pdf_mb).
        if ($size > (int) config('biblioteca.pdf_scan_max_bytes', 100 * 1024 * 1024)) {
            return self::TOO_LARGE;
        }

        $chunk = max(4096, (int) config('biblioteca.pdf_scan_chunk_bytes', 8 * 1024 * 1024));
        $tail  = '';

        for ($offset = 0; $offset < $size; $offset += $chunk) {
            $data = $reader($offset, min($chunk, $size - $offset));
            if ($data === '') {
                break;
            }

            $window = $this->decodeNameEscapes($tail . $data);
            foreach ($tokens as $token) {
                $found = $this->search('#' . preg_quote($token, '#') . self::NAME_END . '#', $window);
                if ($found === false) {
                    return self::SCAN_ERROR;
                }
                if ($found === 1) {
                    return $token;
                }
            }

            $tail = substr($data, -self::OVERLAP);
        }

        return null;
    }

    /** Costura de prueba: `preg_match` devuelve false ante un error de PCRE, y eso se debe tratar como sospechoso. */
    protected function search(string $pattern, string $subject): int|false
    {
        return preg_match($pattern, $subject);
    }

    private function decodeNameEscapes(string $data): string
    {
        return (string) preg_replace_callback(
            '/#([0-9A-Fa-f]{2})/',
            static fn (array $m): string => chr((int) hexdec($m[1])),
            $data,
        );
    }
}
