<?php

declare(strict_types=1);

namespace App\Modules\Biblioteca\Exceptions;

/**
 * Otro finalize de ESTE mismo upload_id sigue corriendo (doble clic, reintento, dos pestañas). No es un
 * rechazo del archivo: responde 409 con `code: finalize_in_progress` y el cliente puede reintentar.
 */
final class LibraryFinalizeInProgressException extends \RuntimeException {}
