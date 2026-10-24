<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Exceptions;

/** SCRUM-1002 — el SubCliente ya tiene documentos fiscales certificados/pendientes: sus datos fiscales no se editan (409). */
final class SubClientTaxDataLockedException extends \RuntimeException
{
}
