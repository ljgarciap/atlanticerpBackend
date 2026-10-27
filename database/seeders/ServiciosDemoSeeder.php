<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\TicketFolioService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;
use Database\Seeders\Support\DemoDataset;

/**
 * SCRUM-721 — datos de prueba para QA del módulo Servicios/Tickets (SCRUM-279→284/312/359):
 * sin esto, `GET /servicios/tickets` devuelve 0 filas en dev.atlanticerp.ai y no hay forma de validar
 * tabla/tablero/filtros/gate de cierre en vivo.
 *
 * Marcador de idempotencia: `descripcion` con prefijo `[DEMO]` (mismo patrón que
 * `CrmDemoSeeder::DEMO_MARKER` sobre `Project.nombre`) — create-once, no reconciliado en cada
 * corrida (a diferencia de CrmDemoSeeder) porque estos tickets fijan combinaciones exactas de
 * estado/quote_status/inspection_report_status para ejercitar el gate REQ-218, y una
 * reconciliación automática podría pisar un estado que QA movió a mano durante la prueba.
 *
 * Escribe los campos directamente (no vía `TicketService::changeStage()`) a propósito: son datos
 * de fixture, no transiciones reales de usuario — el objetivo es aterrizar tickets YA en un estado
 * dado para poder probar el filtro/tablero/gate desde ese punto, no simular el camino que llevó
 * hasta ahí.
 */
class ServiciosDemoSeeder extends Seeder
{
    private const DEMO_MARKER = '[DEMO]';

    /**
     * Para tests: siembra los tickets demo SIN la guarda de `testing` de run() y con el tenant ya activo (el llamador
     * lo activa). Corre dentro de la transacción del test, así que se revierte sola. Los tests que necesitan tickets
     * de ejemplo lo piden explícitamente en vez de depender de un seed global que `testing` se salta a propósito.
     */
    public function seedParaTests(): void
    {
        $this->seed();
    }

    public function run(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — ServiciosDemoSeeder skipped.');
            return;
        }

        $tenant->makeCurrent();

        try {
            $this->seed();
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function seed(): void
    {
        if (Ticket::where('descripcion', 'like', self::DEMO_MARKER . '%')->exists()) {
            $this->command?->info('Servicios demo tickets already seeded — skipped.');
            return;
        }

        $folio = app(TicketFolioService::class);

        // Nivel 1 (`tecnico`/`tecnico_garantias`, ver
        // 2026_08_02_100004_grant_servicios_edit_to_technician_roles.php) — mismos usuarios reales
        // que consume `TechnicianController::internal()`, no un fixture propio.
        $roleIds = Role::whereIn('key', ['tecnico', 'tecnico_garantias'])->pluck('id');
        $technicianIds = User::whereIn('role_id', $roleIds)
            ->where('is_active', true)
            ->orderBy('first_name')
            ->pluck('id')
            ->all();

        [$carlos, $pedro, $agustin, $miguel] = [
            $technicianIds[0] ?? null, $technicianIds[1] ?? null,
            $technicianIds[2] ?? null, $technicianIds[3] ?? null,
        ];

        $liderServiciosRoleId = Role::where('key', 'lider_servicios')->value('id');
        $createdBy = User::where('role_id', $liderServiciosRoleId)->value('id');

        // [tipo, subtipo, tipo_instalacion, estado, quote_status, inspection_report_status, técnico, cliente]
        $rows = [
            [Ticket::TIPO_INSTALLATION, Ticket::SUBTIPO_INSTALLATION, Ticket::TIPO_INSTALACION_INTERNAL,
                Ticket::ESTADO_REPORTED, Ticket::QUOTE_STATUS_NOT_APPLICABLE, Ticket::INSPECTION_STATUS_NOT_APPLICABLE,
                null, DemoDataset::sitio(1)],
            [Ticket::TIPO_INSTALLATION, Ticket::SUBTIPO_INSPECTION, Ticket::TIPO_INSTALACION_INTERNAL,
                Ticket::ESTADO_SCHEDULED, Ticket::QUOTE_STATUS_PENDING, Ticket::INSPECTION_STATUS_PENDING,
                $carlos, DemoDataset::sitio(2)],
            // GATE CASE — on_site, informe todavía pendiente: intentar mover a "Resuelto" debe
            // bloquear con el mensaje de informe (REQ-218 RN1a/RN4).
            [Ticket::TIPO_INSTALLATION, Ticket::SUBTIPO_INSPECTION, Ticket::TIPO_INSTALACION_INTERNAL,
                Ticket::ESTADO_ON_SITE, Ticket::QUOTE_STATUS_PENDING, Ticket::INSPECTION_STATUS_PENDING,
                $pedro, DemoDataset::sitio(3)],
            [Ticket::TIPO_INSTALLATION, Ticket::SUBTIPO_INSTALLATION, Ticket::TIPO_INSTALACION_SUBCONTRACTED,
                Ticket::ESTADO_RESOLVED, Ticket::QUOTE_STATUS_APPROVED, Ticket::INSPECTION_STATUS_COMPLETED,
                null, DemoDataset::sitio(4)],

            [Ticket::TIPO_WARRANTY, Ticket::SUBTIPO_WARRANTY_GENERIC, Ticket::TIPO_INSTALACION_INTERNAL,
                Ticket::ESTADO_REPORTED, Ticket::QUOTE_STATUS_PENDING, Ticket::INSPECTION_STATUS_PENDING,
                null, DemoDataset::sitio(5)],
            [Ticket::TIPO_WARRANTY, Ticket::SUBTIPO_REPLACEMENT_INSPECTION, Ticket::TIPO_INSTALACION_INTERNAL,
                Ticket::ESTADO_SCHEDULED, Ticket::QUOTE_STATUS_DRAFT, Ticket::INSPECTION_STATUS_PENDING,
                $agustin, DemoDataset::sitio(6)],
            // GATE CASE — informe completo pero cotización solo "Enviada" (no Aprobada): intentar
            // cerrar debe bloquear con el mensaje de cotización (REQ-218 RN1b/RN4).
            [Ticket::TIPO_WARRANTY, Ticket::SUBTIPO_WARRANTY_GENERIC, Ticket::TIPO_INSTALACION_INTERNAL,
                Ticket::ESTADO_ON_SITE, Ticket::QUOTE_STATUS_SENT, Ticket::INSPECTION_STATUS_COMPLETED,
                $miguel, DemoDataset::sitio(7)],
            [Ticket::TIPO_WARRANTY, Ticket::SUBTIPO_REPLACEMENT_INSPECTION, Ticket::TIPO_INSTALACION_INTERNAL,
                Ticket::ESTADO_CLOSED, Ticket::QUOTE_STATUS_APPROVED, Ticket::INSPECTION_STATUS_COMPLETED,
                $carlos, DemoDataset::sitio(8)],

            [Ticket::TIPO_CLAIM, null, Ticket::TIPO_INSTALACION_INTERNAL,
                Ticket::ESTADO_REPORTED, Ticket::QUOTE_STATUS_PENDING, Ticket::INSPECTION_STATUS_NOT_APPLICABLE,
                null, DemoDataset::sitio(9)],
            [Ticket::TIPO_CLAIM, null, Ticket::TIPO_INSTALACION_INTERNAL,
                Ticket::ESTADO_SCHEDULED, Ticket::QUOTE_STATUS_DRAFT, Ticket::INSPECTION_STATUS_NOT_APPLICABLE,
                $pedro, DemoDataset::sitio(10)],
            [Ticket::TIPO_CLAIM, null, Ticket::TIPO_INSTALACION_INTERNAL,
                Ticket::ESTADO_CANCELLED, Ticket::QUOTE_STATUS_REJECTED, Ticket::INSPECTION_STATUS_NOT_APPLICABLE,
                null, DemoDataset::sitio(11)],

            [Ticket::TIPO_RETROFIT, null, Ticket::TIPO_INSTALACION_INTERNAL,
                Ticket::ESTADO_REPORTED, Ticket::QUOTE_STATUS_PENDING, Ticket::INSPECTION_STATUS_PENDING,
                null, DemoDataset::sitio(12)],
            [Ticket::TIPO_RETROFIT, null, Ticket::TIPO_INSTALACION_INTERNAL,
                Ticket::ESTADO_ON_SITE, Ticket::QUOTE_STATUS_PENDING, Ticket::INSPECTION_STATUS_PENDING,
                $agustin, DemoDataset::sitio(13)],
            [Ticket::TIPO_RETROFIT, null, Ticket::TIPO_INSTALACION_INTERNAL,
                Ticket::ESTADO_RESOLVED, Ticket::QUOTE_STATUS_APPROVED, Ticket::INSPECTION_STATUS_COMPLETED,
                $miguel, DemoDataset::sitio(14)],
        ];

        foreach ($rows as [$tipo, $subtipo, $tipoInstalacion, $estado, $quoteStatus, $inspectionStatus, $technicianId, $cliente]) {
            Ticket::create([
                'numero'                    => $folio->next($tipo),
                'tipo'                      => $tipo,
                'subtipo'                   => $subtipo,
                'tipo_instalacion'          => $tipoInstalacion,
                'descripcion'               => self::DEMO_MARKER . " Visita técnica — {$cliente}",
                'cliente'                   => $cliente,
                'contacto'                  => 'Contacto ' . $cliente,
                'telefono'                  => '+507 6000-0000',
                'email'                     => null,
                'direccion'                 => null,
                'requerimientos_especiales' => null,
                'estado'                    => $estado,
                'quote_status'              => $quoteStatus,
                'inspection_report_status'  => $inspectionStatus,
                'internal_technician_id'    => $technicianId,
                'scheduled_at'              => $estado === Ticket::ESTADO_REPORTED ? null : now()->addDays(2),
                'scheduled_ends_at'         => null,
                'created_by'                => $createdBy,
            ]);
        }

        $this->command?->info('Servicios demo tickets seeded: ' . count($rows) . ' tickets.');
    }
}
