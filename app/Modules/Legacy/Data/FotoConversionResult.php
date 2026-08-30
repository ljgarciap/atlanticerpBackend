<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * Resultado de `App\Modules\Legacy\Services\ProductoFotoConverter::convert()` para UN BMP —
 * `hash` (SHA-256 del BMP ORIGINAL, antes de decodificar) se calcula SIEMPRE, incluso en un
 * fallo, porque el dedup (`ProductoFotoHash`) y el reporte de `--dry-run` lo necesitan de
 * cualquier forma. `success = false` es un resultado esperado y tolerado (BMP corrupto/con header
 * no estándar) — nunca una excepción, ver docblock de `ProductoFotoConverter`.
 */
final readonly class FotoConversionResult
{
    private function __construct(
        public bool $success,
        public ?string $jpegBytes,
        public ?int $width,
        public ?int $height,
        public string $hash,
        public ?string $errorReason,
    ) {
    }

    public static function success(string $jpegBytes, int $width, int $height, string $hash): self
    {
        return new self(true, $jpegBytes, $width, $height, $hash, null);
    }

    public static function failure(string $hash, string $reason): self
    {
        return new self(false, null, null, null, $hash, $reason);
    }
}
