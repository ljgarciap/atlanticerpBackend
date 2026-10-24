<?php

declare(strict_types=1);

namespace App\Modules\Biblioteca\Exceptions;

/**
 * El almacenamiento falló por una causa ajena al archivo: AccessDenied (IAM), 5xx, throttling, timeouts
 * o error de red. La subida sigue registrada y es reintentable; responde 503.
 */
final class LibraryStorageUnavailableException extends \RuntimeException {}
