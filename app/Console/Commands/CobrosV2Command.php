<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AdminCont\Services\CobrosConfigService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-1168 F2 — enciende o apaga el concepto nuevo de cobros (`cobros_v2_activo`). Marcha atrás inmediata: `cobros:v2 off`.
 * Encender solo cuando `cobros:verificar-libro` da cero diferencias.
 */
final class CobrosV2Command extends Command
{
    protected $signature   = 'cobros:v2 {estado : on | off | status}';
    protected $description = 'SCRUM-1168 — enciende, apaga o consulta el concepto nuevo de cobros';

    public function handle(CobrosConfigService $config): int
    {
        $estado = (string) $this->argument('estado');
        if (! in_array($estado, ['on', 'off', 'status'], true)) {
            $this->error('Use: on | off | status');

            return self::INVALID;
        }

        foreach (Tenant::all() as $tenant) {
            Tenant::forgetCurrent();
            $tenant->makeCurrent();

            try {
                if ($estado !== 'status') {
                    $config->setV2Activo($estado === 'on');
                }
                $this->line("[{$tenant->slug}] cobros_v2_activo = ".($config->v2Activo() ? 'ENCENDIDO' : 'apagado'));
            } finally {
                Tenant::forgetCurrent();
            }
        }

        return self::SUCCESS;
    }
}
