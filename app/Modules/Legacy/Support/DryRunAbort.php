<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Support;

use RuntimeException;

/**
 * Señal interna para forzar el rollback de una `DB::transaction()` envolvente cuando un comando
 * corre en modo `--dry-run` — nunca se deja escapar fuera del bloque que la lanza (se captura
 * inmediatamente después). No representa un error real.
 *
 * Agregado 2026-08-26 (hallazgo real en dev: `legacy:fuse-clientes --dry-run` escribía
 * `sub_clients`/`master_clients` reales sin dejar auditoría en `legacy.sync_runs`, ver
 * `memory/project_scrum755_758_tarea6_procedimiento_real.md`) — usada por
 * `LegacyFuseClientesCommand`/`LegacyFuseProveedoresCommand` para que `--dry-run` sea un dry-run
 * real (rollback), no solo "no registra la corrida".
 */
final class DryRunAbort extends RuntimeException
{
}
