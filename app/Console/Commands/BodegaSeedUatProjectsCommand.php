<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Auth\Support\RoleKeys;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\PipelineService;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * SCRUM-748 — "Data de prueba: 40 proyectos aprobados en TEST" (Ventas & Diseño → Bodega).
 *
 * Comando one-shot (no forma parte de `DatabaseSeeder` ni de la corrida automática de CI/CD —
 * decisión técnica del Arquitecto, ver `docs/architecture/scrum742-743-746-747-748-diseno.md`
 * sección SCRUM-748). Corrido manualmente contra `test.atlanticerp.ai` por DevOps.
 *
 * Crea N proyectos de Ventas & Diseño válidos (con todos los campos obligatorios de la etapa
 * "proposal" completos — contacto, fecha de entrega, comprobante de aprobación), distribuidos
 * round-robin entre los vendedores REALES del roster (`BusinessRoleUserSeeder`,
 * `role_key=vendedor` — nunca cuentas demo `*@atlantic.test`, regla dura del
 * proyecto), y los transiciona a "approved" a través del mecanismo REAL
 * (`PipelineService::changeStage()`, el mismo que dispara `PATCH .../pipeline/{id}/stage`) y luego
 * ejecuta la aprobación explícita `PipelineService::passToOrder()` (SCRUM-1128: entrar a "approved"
 * ya no genera el pedido por sí solo), que crea el `Order` correspondiente en Bodega — este
 * comando nunca inserta un `Order` a mano.
 *
 * Idempotencia: marcador `[UAT-BODEGA]` como prefijo de `SalesProject.name` (mismo criterio que
 * `[DEMO]`/`[QA-VD]` en otros seeders de este proyecto). Si ya hay N' < --count proyectos
 * marcados, el comando hace "top-up" — solo crea los que faltan para llegar a --count, nunca
 * duplica los ya existentes.
 *
 * Verificación post-corrida (RN de integridad del ticket — "sin duplicados, sin huérfanos:
 * aprobado en Ventas sin reflejo en Bodega"): por cada `PipelineCard` marcado y aprobado, confirma
 * que existe su `Order` en Bodega. Un huérfano se reporta explícitamente en el output en vez de
 * asumir éxito solo porque no hubo un error visible.
 */
final class BodegaSeedUatProjectsCommand extends Command
{
    private const MARKER = '[UAT-BODEGA]';

    /** @var string */
    protected $signature = 'bodega:seed-uat-projects
        {--tenant=atlantic : Slug del tenant a sembrar}
        {--count=40 : Cantidad total de proyectos aprobados marcados [UAT-BODEGA] que debe haber al terminar (top-up, no duplica)}
        {--force : Requerido para correr en app()->environment(\'production\') — nunca por accidente}';

    /** @var string */
    protected $description = 'SCRUM-748 — siembra N proyectos aprobados de Ventas & Diseño (distribuidos entre vendedores reales) para que Bodega reciba sus Order vía el flujo real';

    public function __construct(private readonly PipelineService $pipelineService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (app()->environment('production') && ! (bool) $this->option('force')) {
            $this->error('Este comando siembra data de prueba — requiere --force explícito para correr en production.');

            return self::FAILURE;
        }

        $tenantSlug  = (string) $this->option('tenant');
        $targetCount = (int) $this->option('count');

        if ($targetCount < 1) {
            $this->error('--count debe ser >= 1.');

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();

        // Regla dura del proyecto (feedback_testing_uses_real_users_not_demo.md): nunca cuentas
        // demo, aunque compartan role_key por conveniencia de fixtures — ej.
        // designer@atlantic.test (AuthUserSeeder) tiene role_key=vendedor en
        // local/dev/testing para no inflar el roster de FeatureTestCase, pero no es un vendedor
        // real. Exclusión explícita en vez de confiar en que qa (target real de este comando)
        // nunca corre AuthUserSeeder — así el comando es correcto en cualquier entorno.
        /** @var Collection<int, User> $vendedores */
        $vendedores = User::whereHas('role', fn ($q) => $q->where('key', RoleKeys::VENDEDOR))
            ->where('email', 'not like', '%@atlantic.test')
            ->orderBy('id')
            ->get();

        if ($vendedores->isEmpty()) {
            $this->error("No hay usuarios con role_key='".RoleKeys::VENDEDOR."' en el tenant '{$tenantSlug}' — abortando (¿BusinessRoleUserSeeder no corrió?).");

            return self::FAILURE;
        }

        $existingCards = $this->markedCards();
        $existingCount = $existingCards->count();

        if ($existingCount >= $targetCount) {
            $this->info("Ya hay {$existingCount} proyectos marcados ".self::MARKER." (>= --count={$targetCount}) — nada que crear.");

            return $this->verifyAndReport($existingCards);
        }

        $toCreate = $targetCount - $existingCount;
        $this->info(
            "Hay {$existingCount} proyectos marcados ".self::MARKER." — creando {$toCreate} más para "
            ."llegar a {$targetCount}, repartidos round-robin entre {$vendedores->count()} vendedores reales."
        );

        $created = 0;
        for ($i = 0; $i < $toCreate; $i++) {
            $globalIndex = $existingCount + $i;
            $vendedor    = $vendedores->get($globalIndex % $vendedores->count());
            if ($vendedor === null) {
                // No debería pasar — el índice siempre es < $vendedores->count() por el módulo.
                throw new \RuntimeException('Índice de vendedor fuera de rango al repartir round-robin.');
            }

            $this->createApprovedCard($globalIndex, $vendedor);
            $created++;
        }

        $this->info("{$created} proyectos creados y transicionados a \"approved\".");

        return $this->verifyAndReport($this->markedCards());
    }

    private function createApprovedCard(int $globalIndex, User $vendedor): PipelineCard
    {
        $seq = $globalIndex + 1;

        $master = MasterClient::create([
            'name' => sprintf('%s Cliente Master %d', self::MARKER, $seq),
        ]);

        $sub = SubClient::create([
            'master_client_id' => $master->id,
            'business_name'    => sprintf('%s Subcliente %d', self::MARKER, $seq),
            'tax_id'           => sprintf('155-%04d-1-2026', $seq),
            'delivery_address' => 'Calle 50, Ciudad de Panamá',
        ]);

        $project = SalesProject::create([
            'sub_client_id' => $sub->id,
            'name'          => sprintf('%s Proyecto %d', self::MARKER, $seq),
        ]);

        $card = PipelineCard::create([
            'sales_project_id' => $project->id,
            'stage'            => 'proposal',
            'master_client_id' => $master->id,
            'sub_client_id'    => $sub->id,
            'owner_id'         => $vendedor->id,
            'stage_changed_at' => Carbon::now(),
        ]);

        $card->contacts()->create([
            'name'  => sprintf('Contacto UAT %d', $seq),
            'role'  => 'client',
            'phone' => '6000-1234',
        ]);
        $card->deliveryDates()->create(['date' => Carbon::now()->addMonth()->toDateString()]);
        $card->files()->create([
            'type'        => 'approval_proof',
            'file_name'   => 'aprobacion-uat.pdf',
            'storage_key' => sprintf('uat-bodega/%d/aprobacion.pdf', $seq),
            'size_bytes'  => 100,
            'mime_type'   => 'application/pdf',
            'uploaded_by' => $vendedor->id,
        ]);

        // Trigger real: cambio de etapa (PATCH .../pipeline/{id}/stage) + aprobación explícita
        // "Pasar a Pedido" (POST .../pipeline/{id}/pass-to-order, SCRUM-1128), que genera el pedido.
        $card = $this->pipelineService->changeStage($card, 'approved');

        return $this->pipelineService->passToOrder($card, $vendedor);
    }

    /** @return Collection<int, PipelineCard> */
    private function markedCards(): Collection
    {
        return PipelineCard::whereHas(
            'salesProject',
            fn ($q) => $q->where('name', 'like', self::MARKER.'%'),
        )->get();
    }

    /** @param Collection<int, PipelineCard> $cards */
    private function verifyAndReport(Collection $cards): int
    {
        $approved    = $cards->where('stage', 'approved');
        $notApproved = $cards->where('stage', '!=', 'approved');

        $orphans = [];
        foreach ($approved as $card) {
            $exists = Order::where('pipeline_card_id', $card->id)->exists();
            if (! $exists) {
                $orphans[] = $card->id;
            }
        }

        $withOrder = $approved->count() - count($orphans);
        $this->info(
            'Total marcados '.self::MARKER.": {$cards->count()} — aprobados: {$approved->count()}, "
            ."con Order en Bodega: {$withOrder}."
        );

        $byVendor = $approved->groupBy('owner_id')->map->count();
        $this->line('Distribución por vendedor (owner_id => cantidad): '.$byVendor->map(
            fn (int $count, int $ownerId): string => "{$ownerId}={$count}"
        )->implode(', '));

        if ($notApproved->isNotEmpty()) {
            $this->warn(
                'PipelineCard marcados que NO quedaron en "approved" (revisar manualmente): '
                .$notApproved->pluck('id')->implode(', ')
            );
        }

        if ($orphans !== []) {
            $this->error(
                'PipelineCard aprobados SIN Order en Bodega (huérfanos, revisar logs de '
                .'bodega.order.creation-failed): '.implode(', ', $orphans)
            );

            return self::FAILURE;
        }

        $this->info('Verificación OK — todos los proyectos aprobados marcados tienen su Order en Bodega.');

        return self::SUCCESS;
    }
}
