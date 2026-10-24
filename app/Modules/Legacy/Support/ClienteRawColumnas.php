<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Support;

/**
 * SCRUM-1137 F1 — lista FIJA de columnas de CLIENTES de ICG que NO se copian a `legacy.clientes_raw`
 * (revisión de seguridad REVIEW-icg-clientes-staging-f1.md, condición 3): datos de tarjeta y credenciales
 * (incluye USUARIO, TIPOTARJETA y TARCADUCIDAD, decisión de Luis tras la Senior Review; 0 filas con dato hoy, pero se excluyen para que un dato futuro nunca llegue a staging) y la foto
 * (binaria). `VERSION` (timestamp) y cualquier columna binaria las excluye ya `IcgTablaExtractor`.
 *
 * Una sola fuente de verdad: la usan el extractor (no se piden a ICG) y los tests (fallan si alguna
 * reaparece en la tabla o en el mapper).
 */
final class ClienteRawColumnas
{
    /** @var list<string> nombres ICG, en mayúscula */
    public const EXCLUIDAS = ['CVC', 'PASS', 'PASSWORDCOMMERCE', 'NUMTARJETA', 'FOTOCLIENTE', 'USUARIO', 'TIPOTARJETA', 'TARCADUCIDAD'];
}
