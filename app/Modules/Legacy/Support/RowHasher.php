<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Support;

/**
 * SCRUM-838 — hash de las columnas de negocio de una fila extraída de ICG, usado por los
 * comandos incrementales (`legacy:sync-incremental-*`) para detectar si una fila cambió de verdad
 * desde la última carga, sin depender de una ventana de fecha (varias tablas de ICG no tienen
 * columna de modificación real, y las que sí la tienen no cubren actualizaciones tardías de
 * satélites — ver Illumination/migracion-icg-diseno.md, ticket SCRUM-838, punto 2).
 *
 * `ksort()` antes de codificar es lo que hace el hash estable sin importar el orden en que el
 * llamador arme el array — dos filas con las mismas columnas/valores dan el mismo hash siempre,
 * independientemente del orden de las keys.
 */
final class RowHasher
{
    /** @param array<string, scalar|null> $businessColumns */
    public static function hash(array $businessColumns): string
    {
        ksort($businessColumns);

        return hash('sha256', json_encode($businessColumns, JSON_THROW_ON_ERROR));
    }
}
