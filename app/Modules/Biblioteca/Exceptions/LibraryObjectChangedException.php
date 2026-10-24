<?php

declare(strict_types=1);

namespace App\Modules\Biblioteca\Exceptions;

/**
 * El objeto (o la versión exacta que finalize validó) ya no existe o cambió antes de copiarlo:
 * NoSuchVersion, NoSuchKey, PreconditionFailed. No es un fallo de infraestructura — la subida no se
 * puede publicar tal cual y hay que pedir una nueva.
 */
final class LibraryObjectChangedException extends \RuntimeException {}
