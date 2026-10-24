<?php

declare(strict_types=1);

namespace App\Modules\Biblioteca\Exceptions;

/** Archivo subido que no pasa la verificación de finalize (tamaño, tipo o magic bytes). */
final class LibraryRejectedException extends \RuntimeException {}
