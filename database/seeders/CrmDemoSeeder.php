<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\CRM\Models\Project;
use App\Modules\CRM\Models\ProjectActivity;
use App\Modules\CRM\Models\ProjectReminder;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Database\Seeders\Support\DemoDataset;

class CrmDemoSeeder extends Seeder
{
    // Unique marker so we can detect existing demo data and skip on re-run
    private const DEMO_MARKER = '[DEMO]';

    public function run(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — CrmDemoSeeder skipped.');
            return;
        }

        $tenant->makeCurrent();

        try {
            $this->seed($tenant->slug);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function seed(string $slug): void
    {
        // Demo projects are reconciled (updateOrCreate) on every run so that fixes to
        // this seed data (e.g. SCRUM-30's area category values) actually reach rows
        // created by a previous version of this seeder — a plain existence check let
        // stale attributes (raw m² values in `area`, pre-SCRUM-30) survive every
        // redeploy indefinitely. Activities/reminders/assignees stay create-once,
        // gated by whether demo data existed before this run, to avoid duplicating them.
        $alreadySeeded = Project::where('nombre', 'like', self::DEMO_MARKER . '%')->exists();

        // Resolve user IDs by role — works regardless of which emails are in the DB
        $managerId  = $this->userIdByRole($slug, ['management', 'superadmin']);
        $designerId = $this->userIdByRole($slug, ['designer']);
        $createdBy  = $managerId;

        $today = Carbon::today();

        // ── Projects ─────────────────────────────────────────────────────────
        // (always reconciled — see comment above)

        $projects = $this->createProjects($today, $managerId, $designerId, $createdBy);

        if ($alreadySeeded) {
            $this->command->info('CRM demo projects reconciled — activities/reminders/assignees untouched.');
            return;
        }

        // ── Activities ────────────────────────────────────────────────────────

        $this->createActivities($projects, $today, $managerId, $designerId);

        // ── Reminders ─────────────────────────────────────────────────────────

        $this->createReminders($projects, $today);

        // ── Assignees ─────────────────────────────────────────────────────────

        $this->assignUsers($projects, $slug, $managerId, $designerId);

        $count = count($projects);
        $this->command->info("CRM demo data seeded: {$count} projects.");
    }

    /**
     * @return array<string, Project>
     */
    private function createProjects(Carbon $today, ?int $managerId, ?int $designerId, ?int $createdBy): array
    {
        $data = [
            // ── Lead ──────────────────────────────────────────────────────────
            'proyecto_01' => [
                'nombre'        => DemoDataset::proyectoCrm(1),
                'contacto'      => DemoDataset::contacto(1),
                'email'         => DemoDataset::email(1),
                'celular'       => '6615-2244',
                'developer'     => DemoDataset::desarrolladora(1),
                'tipo'          => 'diseno',
                'requires_technical_study'        => false,
                'area'          => null,
                'ubicacion'     => DemoDataset::ubicacion(1),
                'despacho'      => null,
                'etapa'         => 'lead',
                'fecha_entrega' => null,
                'valor'         => 45000,
                'notas'         => DemoDataset::notas(1),
                'created_by'    => $createdBy,
            ],
            'proyecto_02' => [
                'nombre'        => DemoDataset::proyectoCrm(2),
                'contacto'      => DemoDataset::contacto(2),
                'email'         => DemoDataset::email(2),
                'celular'       => '6623-8811',
                'developer'     => DemoDataset::desarrolladora(2),
                'tipo'          => 'cotizacion',
                'requires_technical_study'        => false,
                'area'          => 'Retail',
                'ubicacion'     => DemoDataset::ubicacion(2),
                'despacho'      => null,
                'etapa'         => 'lead',
                'fecha_entrega' => $today->copy()->addDays(60)->toDateString(),
                'valor'         => 120000,
                'notas'         => DemoDataset::notas(2),
                'created_by'    => $createdBy,
            ],

            // ── Diseño ────────────────────────────────────────────────────────
            'proyecto_03' => [
                'nombre'        => DemoDataset::proyectoCrm(3),
                'contacto'      => DemoDataset::contacto(3),
                'email'         => DemoDataset::email(3),
                'celular'       => '6644-9900',
                'developer'     => DemoDataset::desarrolladora(3),
                'arquitecto'    => DemoDataset::arquitecto(3),
                'tipo'          => 'ambos',
                'requires_technical_study'        => true,
                'area'          => 'Hotelero',
                'ubicacion'     => DemoDataset::ubicacion(3),
                'despacho'      => DemoDataset::despacho(),
                'etapa'         => 'diseno',
                'fecha_entrega' => $today->copy()->addDays(28)->toDateString(),
                'valor'         => 85000,
                'notas'         => DemoDataset::notas(3),
                'created_by'    => $createdBy,
            ],
            'proyecto_04' => [
                'nombre'        => DemoDataset::proyectoCrm(4),
                'contacto'      => DemoDataset::contacto(4),
                'email'         => DemoDataset::email(4),
                'celular'       => '6671-3355',
                'encargado'     => DemoDataset::encargado(4),
                'arquitecto'    => DemoDataset::arquitecto(4),
                'tipo'          => 'diseno',
                'requires_technical_study'        => true,
                'area'          => 'Salud',
                'ubicacion'     => DemoDataset::ubicacion(4),
                'despacho'      => DemoDataset::despacho(),
                'etapa'         => 'diseno',
                'fecha_entrega' => $today->copy()->addDays(2)->toDateString(), // proximo
                'valor'         => 32000,
                'notas'         => DemoDataset::notas(4),
                'created_by'    => $createdBy,
            ],
            'proyecto_05' => [
                'nombre'        => DemoDataset::proyectoCrm(5),
                'contacto'      => DemoDataset::contacto(5),
                'email'         => DemoDataset::email(5),
                'celular'       => '6638-7722',
                'encargado'     => DemoDataset::encargado(5),
                'tipo'          => 'diseno',
                'requires_technical_study'        => true,
                'area'          => 'Retail',
                'ubicacion'     => DemoDataset::ubicacion(5),
                'despacho'      => DemoDataset::despacho(),
                'etapa'         => 'diseno',
                'fecha_entrega' => $today->copy()->subDays(5)->toDateString(), // vencido
                'valor'         => 42000,
                'notas'         => DemoDataset::notas(5),
                'created_by'    => $createdBy,
            ],

            // ── Cotización ────────────────────────────────────────────────────
            'proyecto_06' => [
                'nombre'        => DemoDataset::proyectoCrm(6),
                'contacto'      => DemoDataset::contacto(6),
                'email'         => DemoDataset::email(6),
                'celular'       => '6601-5577',
                'developer'     => DemoDataset::desarrolladora(6),
                'tipo'          => 'cotizacion',
                'requires_technical_study'        => false,
                'area'          => 'Industrial',
                'ubicacion'     => DemoDataset::ubicacion(6),
                'despacho'      => null,
                'etapa'         => 'cotizacion',
                'fecha_entrega' => $today->copy()->addDays(45)->toDateString(),
                'valor'         => 210000,
                'notas'         => DemoDataset::notas(6),
                'created_by'    => $createdBy,
            ],
            'proyecto_07' => [
                'nombre'        => DemoDataset::proyectoCrm(7),
                'contacto'      => DemoDataset::contacto(7),
                'email'         => DemoDataset::email(7),
                'celular'       => '6690-1122',
                'encargado'     => DemoDataset::encargado(7),
                'arquitecto'    => DemoDataset::arquitecto(7),
                'tipo'          => 'ambos',
                'requires_technical_study'        => true,
                'area'          => 'Educativo',
                'ubicacion'     => DemoDataset::ubicacion(7),
                'despacho'      => DemoDataset::despacho(),
                'etapa'         => 'cotizacion',
                'fecha_entrega' => $today->copy()->addDays(2)->toDateString(), // urgente
                'valor'         => 68000,
                'notas'         => DemoDataset::notas(7),
                'created_by'    => $designerId ?? $createdBy,
            ],

            // ── Propuesta ─────────────────────────────────────────────────────
            'proyecto_08' => [
                'nombre'        => DemoDataset::proyectoCrm(8),
                'contacto'      => DemoDataset::contacto(8),
                'email'         => DemoDataset::email(8),
                'celular'       => '6655-0099',
                'encargado'     => DemoDataset::encargado(8),
                'tipo'          => 'ambos',
                'requires_technical_study'        => true,
                'area'          => 'Espacio Público',
                'ubicacion'     => DemoDataset::ubicacion(8),
                'despacho'      => DemoDataset::despacho(),
                'etapa'         => 'propuesta',
                'fecha_entrega' => $today->copy()->addDays(75)->toDateString(),
                'valor'         => 350000,
                'notas'         => DemoDataset::notas(8),
                'created_by'    => $createdBy,
            ],
            'proyecto_09' => [
                'nombre'        => DemoDataset::proyectoCrm(9),
                'contacto'      => DemoDataset::contacto(9),
                'email'         => DemoDataset::email(9),
                'celular'       => '6628-4433',
                'developer'     => DemoDataset::desarrolladora(9),
                'tipo'          => 'diseno',
                'requires_technical_study'        => false,
                'area'          => 'Residencial',
                'ubicacion'     => DemoDataset::ubicacion(9),
                'despacho'      => null,
                'etapa'         => 'propuesta',
                'fecha_entrega' => $today->copy()->addDays(30)->toDateString(),
                'valor'         => 28000,
                'notas'         => DemoDataset::notas(9),
                'created_by'    => $designerId ?? $createdBy,
            ],

            // ── Cerrado ───────────────────────────────────────────────────────
            'proyecto_10' => [
                'nombre'        => DemoDataset::proyectoCrm(10),
                'contacto'      => DemoDataset::contacto(10),
                'email'         => DemoDataset::email(10),
                'celular'       => '6612-0011',
                'developer'     => DemoDataset::desarrolladora(10),
                'arquitecto'    => DemoDataset::arquitecto(10),
                'tipo'          => 'ambos',
                'requires_technical_study'        => true,
                'area'          => 'Corporativo',
                'ubicacion'     => DemoDataset::ubicacion(10),
                'despacho'      => DemoDataset::despacho(),
                'etapa'         => 'cerrado',
                'fecha_entrega' => $today->copy()->subDays(20)->toDateString(),
                'valor'         => 95000,
                'notas'         => DemoDataset::notas(10),
                'created_by'    => $createdBy,
            ],
            'proyecto_11' => [
                'nombre'        => DemoDataset::proyectoCrm(11),
                'contacto'      => DemoDataset::contacto(11),
                'email'         => DemoDataset::email(11),
                'celular'       => '6677-3344',
                'encargado'     => DemoDataset::encargado(11),
                'tipo'          => 'cotizacion',
                'requires_technical_study'        => false,
                'area'          => 'Salud',
                'ubicacion'     => DemoDataset::ubicacion(11),
                'despacho'      => DemoDataset::despacho(),
                'etapa'         => 'cerrado',
                'fecha_entrega' => $today->copy()->subDays(45)->toDateString(),
                'valor'         => 180000,
                'notas'         => DemoDataset::notas(11),
                'created_by'    => $createdBy,
            ],

            // ── Perdido ───────────────────────────────────────────────────────
            'proyecto_12' => [
                'nombre'        => DemoDataset::proyectoCrm(12),
                'contacto'      => DemoDataset::contacto(12),
                'email'         => DemoDataset::email(12),
                'celular'       => '6644-2288',
                'developer'     => DemoDataset::desarrolladora(12),
                'tipo'          => 'cotizacion',
                'requires_technical_study'        => false,
                'area'          => 'Comercial',
                'ubicacion'     => DemoDataset::ubicacion(12),
                'despacho'      => null,
                'etapa'         => 'perdido',
                'fecha_entrega' => $today->copy()->subDays(10)->toDateString(),
                'valor'         => 450000,
                'notas'         => DemoDataset::notas(12),
                'created_by'    => $createdBy,
            ],
        ];

        $created = [];

        foreach ($data as $key => $attrs) {
            /** @var Project $project */
            $project = Project::updateOrCreate(['nombre' => $attrs['nombre']], $attrs);
            $created[$key] = $project;
        }

        return $created;
    }

    /**
     * @param array<string, Project> $projects
     */
    private function createActivities(array $projects, Carbon $today, ?int $managerId, ?int $designerId): void
    {
        $uid = $managerId ?? 1;
        $did = $designerId ?? $uid;

        $acts = [
            // proyecto_02 — 1 actividad reciente
            ['project' => 'proyecto_02', 'tipo' => 'llamada',
             'fecha' => $today->copy()->subDays(3)->toDateString(),
             'notas' => DemoDataset::actividad('llamada', 2),
             'siguiente' => $today->copy()->addDays(7)->toDateString(), 'user_id' => $uid],

            // proyecto_03 — 2 actividades
            ['project' => 'proyecto_03', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(14)->toDateString(),
             'notas' => DemoDataset::actividad('reunion', 3),
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'proyecto_03', 'tipo' => 'email',
             'fecha' => $today->copy()->subDays(4)->toDateString(),
             'notas' => DemoDataset::actividad('email', 3),
             'siguiente' => $today->copy()->addDays(3)->toDateString(), 'user_id' => $did],

            // proyecto_04 — 1 actividad
            ['project' => 'proyecto_04', 'tipo' => 'visita',
             'fecha' => $today->copy()->subDays(7)->toDateString(),
             'notas' => DemoDataset::actividad('visita', 4),
             'siguiente' => null, 'user_id' => $did],

            // proyecto_05 — 1 actividad (vencido)
            ['project' => 'proyecto_05', 'tipo' => 'llamada',
             'fecha' => $today->copy()->subDays(10)->toDateString(),
             'notas' => DemoDataset::actividad('llamada', 5),
             'siguiente' => $today->copy()->subDays(5)->toDateString(), 'user_id' => $uid],

            // proyecto_06 — 2 actividades
            ['project' => 'proyecto_06', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(21)->toDateString(),
             'notas' => DemoDataset::actividad('reunion', 6),
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'proyecto_06', 'tipo' => 'whatsapp',
             'fecha' => $today->copy()->subDays(8)->toDateString(),
             'notas' => DemoDataset::actividad('whatsapp', 6),
             'siguiente' => $today->copy()->addDays(3)->toDateString(), 'user_id' => $uid],

            // proyecto_07 — 3 actividades
            ['project' => 'proyecto_07', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(12)->toDateString(),
             'notas' => DemoDataset::actividad('reunion', 7),
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'proyecto_07', 'tipo' => 'email',
             'fecha' => $today->copy()->subDays(6)->toDateString(),
             'notas' => DemoDataset::actividad('email', 7),
             'siguiente' => null, 'user_id' => $did],
            ['project' => 'proyecto_07', 'tipo' => 'llamada',
             'fecha' => $today->copy()->subDays(1)->toDateString(),
             'notas' => DemoDataset::actividad('llamada', 7),
             'siguiente' => $today->copy()->addDays(1)->toDateString(), 'user_id' => $uid],

            // proyecto_08 — 3 actividades
            ['project' => 'proyecto_08', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(30)->toDateString(),
             'notas' => DemoDataset::actividad('reunion', 8),
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'proyecto_08', 'tipo' => 'visita',
             'fecha' => $today->copy()->subDays(15)->toDateString(),
             'notas' => DemoDataset::actividad('visita', 8),
             'siguiente' => null, 'user_id' => $did],
            ['project' => 'proyecto_08', 'tipo' => 'email',
             'fecha' => $today->copy()->subDays(2)->toDateString(),
             'notas' => DemoDataset::actividad('email', 8),
             'siguiente' => $today->copy()->addDays(5)->toDateString(), 'user_id' => $uid],

            // proyecto_09 — 2 actividades
            ['project' => 'proyecto_09', 'tipo' => 'whatsapp',
             'fecha' => $today->copy()->subDays(18)->toDateString(),
             'notas' => DemoDataset::actividad('whatsapp', 9),
             'siguiente' => null, 'user_id' => $did],
            ['project' => 'proyecto_09', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(5)->toDateString(),
             'notas' => DemoDataset::actividad('reunion', 9),
             'siguiente' => $today->copy()->addDays(7)->toDateString(), 'user_id' => $did],

            // proyecto_10 — 4 actividades (cerrado)
            ['project' => 'proyecto_10', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(90)->toDateString(),
             'notas' => DemoDataset::actividad('reunion', 10),
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'proyecto_10', 'tipo' => 'visita',
             'fecha' => $today->copy()->subDays(60)->toDateString(),
             'notas' => DemoDataset::actividad('visita', 10),
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'proyecto_10', 'tipo' => 'visita',
             'fecha' => $today->copy()->subDays(30)->toDateString(),
             'notas' => DemoDataset::actividad('visita', 10),
             'siguiente' => null, 'user_id' => $did],
            ['project' => 'proyecto_10', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(20)->toDateString(),
             'notas' => DemoDataset::actividad('reunion', 10),
             'siguiente' => null, 'user_id' => $uid],

            // proyecto_11 — 3 actividades (cerrado)
            ['project' => 'proyecto_11', 'tipo' => 'email',
             'fecha' => $today->copy()->subDays(75)->toDateString(),
             'notas' => DemoDataset::actividad('email', 11),
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'proyecto_11', 'tipo' => 'llamada',
             'fecha' => $today->copy()->subDays(50)->toDateString(),
             'notas' => DemoDataset::actividad('llamada', 11),
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'proyecto_11', 'tipo' => 'visita',
             'fecha' => $today->copy()->subDays(45)->toDateString(),
             'notas' => DemoDataset::actividad('visita', 11),
             'siguiente' => null, 'user_id' => $uid],

            // proyecto_12 — 2 actividades (perdido)
            ['project' => 'proyecto_12', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(30)->toDateString(),
             'notas' => DemoDataset::actividad('reunion', 12),
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'proyecto_12', 'tipo' => 'llamada',
             'fecha' => $today->copy()->subDays(10)->toDateString(),
             'notas' => DemoDataset::actividad('llamada', 12),
             'siguiente' => null, 'user_id' => $uid],
        ];

        foreach ($acts as $act) {
            /** @var Project $project */
            $project = $projects[$act['project']];

            ProjectActivity::create([
                'project_id' => $project->id,
                'tipo'       => $act['tipo'],
                'fecha'      => $act['fecha'],
                'notas'      => $act['notas'],
                'siguiente'  => $act['siguiente'],
                'user_id'    => $act['user_id'],
            ]);
        }
    }

    /**
     * @param array<string, Project> $projects
     */
    private function createReminders(array $projects, Carbon $today): void
    {
        $reminders = [
            // proyecto_07 — urgente, cotización vence mañana
            ['project' => 'proyecto_07', 'tipo' => 'llamada',
             'fecha' => $today->copy()->addDays(1)->toDateString(),
             'nota' => DemoDataset::recordatorio('llamada', 7),
             'completado' => false],

            // proyecto_08 — seguimiento propuesta
            ['project' => 'proyecto_08', 'tipo' => 'email',
             'fecha' => $today->copy()->addDays(5)->toDateString(),
             'nota' => DemoDataset::recordatorio('email', 8),
             'completado' => false],

            // proyecto_02 — llamada de seguimiento
            ['project' => 'proyecto_02', 'tipo' => 'llamada',
             'fecha' => $today->copy()->addDays(7)->toDateString(),
             'nota' => DemoDataset::recordatorio('llamada', 2),
             'completado' => false],

            // proyecto_04 — URGENTE, entrega en 2 días
            ['project' => 'proyecto_04', 'tipo' => 'reunion',
             'fecha' => $today->copy()->addDays(2)->toDateString(),
             'nota' => DemoDataset::recordatorio('reunion', 4),
             'completado' => false],

            // proyecto_11 — cobro segunda cuota
            ['project' => 'proyecto_11', 'tipo' => 'llamada',
             'fecha' => $today->copy()->addDays(3)->toDateString(),
             'nota' => DemoDataset::recordatorio('llamada', 11),
             'completado' => false],

            // proyecto_05 — VENCIDO (el proyecto ya está vencido)
            ['project' => 'proyecto_05', 'tipo' => 'llamada',
             'fecha' => $today->copy()->subDays(3)->toDateString(),
             'nota' => DemoDataset::recordatorio('llamada', 5),
             'completado' => false],

            // proyecto_10 — completado (para mostrar uno en estado completado)
            ['project' => 'proyecto_10', 'tipo' => 'email',
             'fecha' => $today->copy()->subDays(19)->toDateString(),
             'nota' => DemoDataset::recordatorio('email', 10),
             'completado' => true],
        ];

        foreach ($reminders as $rem) {
            /** @var Project $project */
            $project = $projects[$rem['project']];

            ProjectReminder::create([
                'project_id' => $project->id,
                'tipo'       => $rem['tipo'],
                'fecha'      => $rem['fecha'],
                'nota'       => $rem['nota'],
                'completado' => $rem['completado'],
            ]);
        }
    }

    /**
     * @param array<string, Project> $projects
     */
    private function assignUsers(array $projects, string $slug, ?int $managerId, ?int $designerId): void
    {
        $pivot = "{$slug}_crm.project_assignees";

        $assignments = [
            'proyecto_01'        => [$designerId],
            'proyecto_02'         => [$managerId, $designerId],
            'proyecto_03'       => [$designerId],
            'proyecto_04'  => [$designerId],
            'proyecto_05'   => [$designerId],
            'proyecto_06'        => [$managerId],
            'proyecto_07'    => [$managerId, $designerId],
            'proyecto_08'    => [$managerId, $designerId],
            'proyecto_09' => [$designerId],
            'proyecto_10'              => [$managerId, $designerId],
            'proyecto_11'      => [$managerId],
            // proyecto_12: sin asignados (perdido)
        ];

        foreach ($assignments as $key => $userIds) {
            if (!isset($projects[$key])) {
                continue;
            }
            $projectId = $projects[$key]->id;
            foreach ($userIds as $userId) {
                if ($userId === null) {
                    continue;
                }
                DB::table($pivot)->insertOrIgnore([
                    'project_id' => $projectId,
                    'user_id'    => $userId,
                ]);
            }
        }
    }

    /**
     * Fase 2: por clave de rol real (`roles.key`), no por el enum legado `user_roles`. `designer` (legado) = `vendedor`.
     *
     * @param list<string> $roles
     */
    private function userIdByRole(string $slug, array $roles): ?int
    {
        $keys = array_map(static fn (string $r): string => $r === 'designer' ? 'vendedor' : $r, $roles);
        $userId = User::query()->withRoleKey($keys)->orderBy('id')->value('id');

        return $userId !== null ? (int) $userId : null;
    }
}
