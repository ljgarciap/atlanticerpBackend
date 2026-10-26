<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Modules\Legacy\Services\ClienteMatcher;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-1137 — informe previo de SOLO LECTURA del matching de clientes con el RUC completo: cuántos `legacy.clientes` cambiarían
 * de `match_status` o de `sub_client_id` si el Matcher corriera hoy. No escribe nada ni toca ICG. Imprime conteos y códigos de
 * cliente de ICG, nunca RUC ni nombres. Se corre después de que `legacy.clientes.ruc` esté poblado (sync de clientes).
 */
final class LegacyPreviewClientesMatchCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:preview-clientes-match
        {--tenant=atlantic : Slug del tenant a procesar}
        {--muestra=20 : Cuántos clientes que cambian se listan (solo código de ICG y estados)}';

    /** @var string */
    protected $description = 'SCRUM-1137 — informe de solo lectura: qué cambiaría en match_status/sub_client_id con el RUC completo';

    public function __construct(private readonly ClienteMatcher $matcher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $slug   = (string) $this->option('tenant');
        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$slug}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();
        try {
            $r = $this->matcher->preview(max(0, (int) $this->option('muestra')));
        } finally {
            Tenant::forgetCurrent();
        }

        $this->info('legacy:preview-clientes-match — solo lectura, no se escribió nada');
        $this->line("Clientes evaluados (sin los ya promovidos): {$r['total']}");

        $this->table(['Antes -> Después', 'Clientes'], array_map(static fn (string $k, int $n): array => [$k, $n], array_keys($r['transiciones']), $r['transiciones']));
        $this->table(['Resuelto por', 'Clientes'], array_map(static fn (string $k, int $n): array => [$k, $n], array_keys($r['via']), $r['via']));
        $this->line("Clientes que cambiarían de sub_client ya vinculado: {$r['cambian_de_sub_client']}");
        $this->line("Clientes cuyo RUC contradice al CIF (se ignora el RUC y se conserva el CIF): {$r['conflictos_ruc_cif']}");

        if ($r['muestra'] !== []) {
            $this->line('Muestra de cambios (código ICG, estados y sub_client; sin datos personales):');
            $this->table(
                ['ICG', 'Antes', 'Después', 'Vía', 'sub_client antes', 'sub_client después'],
                array_map(static fn (array $m): array => [$m['icg_codcliente'], $m['antes'], $m['despues'], $m['via'] ?? '-', $m['sub_client_antes'] ?? '-', $m['sub_client_despues'] ?? '-'], $r['muestra']),
            );
        }

        return self::SUCCESS;
    }
}
