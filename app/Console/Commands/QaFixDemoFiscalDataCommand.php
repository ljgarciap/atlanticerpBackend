<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\SubClientTaxDataService;
use App\Modules\VentasDiseno\Support\SandboxFiscalFixtures;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-1117 — la guarda de RUC al facturar bloquea cualquier cliente que la DGI no reconozca. Los sub-clientes que
 * `QaTicketsBatch20260725Seeder` sembró en dev/test con RUC inventados (`155-9001-1-2026`, `155-9002-1-2026`,
 * `155-4090-1-2026`) no se actualizan solos (el seeder solo crea), así que este comando los lleva a los datos del sandbox
 * de MSL (`SandboxFiscalFixtures`). Solo toca esos 3 RUC exactos, solo en entornos que no son producción, respeta el candado 409 de documentos fiscales, y por defecto
 * solo muestra qué cambiaría: hay que pasar `--apply` para escribir.
 */
final class QaFixDemoFiscalDataCommand extends Command
{
    protected $signature = 'qa:fix-demo-fiscal-data {--apply : Escribe los cambios (sin esto solo muestra lo que cambiaría)}';

    protected $description = 'SCRUM-1117 — pasa los sub-clientes demo de QA a los RUC/DV/razón social del sandbox de MSL (dry-run por defecto)';

    /** RUC inventados que sembró QaTicketsBatch20260725Seeder -> fixture del sandbox al que pasan. */
    private const MAPA = [
        '155-9001-1-2026' => SandboxFiscalFixtures::ATLANTIC,
        '155-9002-1-2026' => SandboxFiscalFixtures::DIGIFACT,
        '155-4090-1-2026' => SandboxFiscalFixtures::ATLANTIC,
    ];

    public function handle(SubClientTaxDataService $taxData): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando no corre en producción.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->first();
        if ($tenant === null) {
            $this->error('No hay tenant configurado.');

            return self::FAILURE;
        }
        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        $aplicar = (bool) $this->option('apply');
        $filas = SubClient::query()->whereIn('tax_id', array_keys(self::MAPA))->orderBy('id')->get();

        if ($filas->isEmpty()) {
            $this->info('No hay sub-clientes demo de QA por corregir.');

            return self::SUCCESS;
        }

        // Mismo candado que la edición desde la app: un demo que ya tiene facturas/NC certificadas o pendientes no se reescribe.
        $bloqueados = array_flip($taxData->conDocumentosFiscales(array_values($filas->pluck('id')->map(fn ($id) => (int) $id)->all())));

        foreach ($filas as $sub) {
            if (isset($bloqueados[$sub->id])) {
                $this->warn("#{$sub->id} \"{$sub->business_name}\" se omite: ya tiene documentos fiscales certificados o pendientes.");
                continue;
            }
            $nuevo = self::MAPA[$sub->tax_id];
            $this->line(sprintf('#%d "%s" [%s] -> "%s" [%s DV %s]', $sub->id, $sub->business_name, $sub->tax_id, $nuevo['business_name'], $nuevo['tax_id'], $nuevo['tax_id_dv']));

            if ($aplicar) {
                $sub->forceFill($nuevo)->save(); // LogsAuditTrail deja el antes/después en audit_logs
            }
        }

        $this->info($aplicar ? (count($filas) - count($bloqueados)).' sub-cliente(s) corregido(s).' : 'Dry-run: nada se escribió. Repita con --apply para aplicar.');

        return self::SUCCESS;
    }
}
