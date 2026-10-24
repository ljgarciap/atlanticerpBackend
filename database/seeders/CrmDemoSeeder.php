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
            'torre_pacifica' => [
                'nombre'        => '[DEMO] Torre Pacífica',
                'contacto'      => 'Tecnico Servicios Mendez',
                'email'         => 'c.mendez@grupopacifico.com',
                'celular'       => '6615-2244',
                'developer'     => 'Grupo Pacífico',
                'tipo'          => 'diseno',
                'dialux'        => false,
                'area'          => null,
                'ubicacion'     => 'Punta Pacífica, Panamá',
                'despacho'      => null,
                'etapa'         => 'lead',
                'fecha_entrega' => null,
                'valor'         => 45000,
                'notas'         => 'Edificio residencial de lujo, 42 pisos. Cliente contactó por referido.',
                'created_by'    => $createdBy,
            ],
            'westland_mall' => [
                'nombre'        => '[DEMO] Centro Comercial Westland',
                'contacto'      => 'Ana Rodríguez',
                'email'         => 'ana.rodriguez@westland.com.pa',
                'celular'       => '6623-8811',
                'developer'     => 'Inversiones Westland S.A.',
                'tipo'          => 'cotizacion',
                'dialux'        => false,
                'area'          => 'Retail',
                'ubicacion'     => 'Albrook, Panamá',
                'despacho'      => null,
                'etapa'         => 'lead',
                'fecha_entrega' => $today->copy()->addDays(60)->toDateString(),
                'valor'         => 120000,
                'notas'         => 'Retail 15,000 m². Esperan propuesta antes de fin de mes.',
                'created_by'    => $createdBy,
            ],

            // ── Diseño ────────────────────────────────────────────────────────
            'hotel_decameron' => [
                'nombre'        => '[DEMO] Hotel Decameron Farallon',
                'contacto'      => 'Garantias Servicios Torres',
                'email'         => 'm.torres@decameron.com',
                'celular'       => '6644-9900',
                'developer'     => 'Decameron Hotels',
                'arquitecto'    => 'Arq. Sandra Mora',
                'tipo'          => 'ambos',
                'dialux'        => true,
                'area'          => 'Hotelero',
                'ubicacion'     => 'Farallon, Coclé',
                'despacho'      => 'Depot Antón',
                'etapa'         => 'diseno',
                'fecha_entrega' => $today->copy()->addDays(28)->toDateString(),
                'valor'         => 85000,
                'notas'         => 'Remodelación de zonas comunes + habitaciones. DIALux requerido por norma hotelera.',
                'created_by'    => $createdBy,
            ],
            'clinica_san_fernando' => [
                'nombre'        => '[DEMO] Clínica San Fernando',
                'contacto'      => 'Dra. Laura Sánchez',
                'email'         => 'l.sanchez@clinicasf.com',
                'celular'       => '6671-3355',
                'encargado'     => 'Ing. Tecnico Servicios Garantias Servicios',
                'arquitecto'    => 'Arq. Vendedor Disenador Ríos',
                'tipo'          => 'diseno',
                'dialux'        => true,
                'area'          => 'Salud',
                'ubicacion'     => 'San Francisco, Panamá',
                'despacho'      => 'Deposito Panamá',
                'etapa'         => 'diseno',
                'fecha_entrega' => $today->copy()->addDays(2)->toDateString(), // proximo
                'valor'         => 32000,
                'notas'         => 'Iluminación quirúrgica y hospitalaria. Normativa NFPA 99 aplicable.',
                'created_by'    => $createdBy,
            ],
            'supermercado_el_rey' => [
                'nombre'        => '[DEMO] Supermercado El Rey Bethania',
                'contacto'      => 'Roberto Vargas',
                'email'         => 'r.vargas@elrey.com.pa',
                'celular'       => '6638-7722',
                'encargado'     => 'Javier Núñez',
                'tipo'          => 'diseno',
                'dialux'        => true,
                'area'          => 'Retail',
                'ubicacion'     => 'Bethania, Panamá',
                'despacho'      => 'Depot Bethania',
                'etapa'         => 'diseno',
                'fecha_entrega' => $today->copy()->subDays(5)->toDateString(), // vencido
                'valor'         => 42000,
                'notas'         => 'Remodelación de luminarias en toda la tienda. Fecha apretada.',
                'created_by'    => $createdBy,
            ],

            // ── Cotización ────────────────────────────────────────────────────
            'parque_tocumen' => [
                'nombre'        => '[DEMO] Parque Industrial Tocumen',
                'contacto'      => 'Gerardo Espinosa',
                'email'         => 'g.espinosa@pitsa.com.pa',
                'celular'       => '6601-5577',
                'developer'     => 'PITSA Desarrollos',
                'tipo'          => 'cotizacion',
                'dialux'        => false,
                'area'          => 'Industrial',
                'ubicacion'     => 'Tocumen, Panamá',
                'despacho'      => null,
                'etapa'         => 'cotizacion',
                'fecha_entrega' => $today->copy()->addDays(45)->toDateString(),
                'valor'         => 210000,
                'notas'         => 'Iluminación exterior + naves industriales. 3 fases de entrega.',
                'created_by'    => $createdBy,
            ],
            'universidad_latina' => [
                'nombre'        => '[DEMO] Universidad Latina — Campus Central',
                'contacto'      => 'Ernesto Fonseca',
                'email'         => 'rector@ulatina.ac.pa',
                'celular'       => '6690-1122',
                'encargado'     => 'Arq. Diego Morales',
                'arquitecto'    => 'Arq. Diego Morales',
                'tipo'          => 'ambos',
                'dialux'        => true,
                'area'          => 'Educativo',
                'ubicacion'     => 'La Chorrera, Panamá Oeste',
                'despacho'      => 'Depot La Chorrera',
                'etapa'         => 'cotizacion',
                'fecha_entrega' => $today->copy()->addDays(2)->toDateString(), // urgente
                'valor'         => 68000,
                'notas'         => 'Aulas, biblioteca y áreas comunes. Licitación pública — entrega urgente.',
                'created_by'    => $designerId ?? $createdBy,
            ],

            // ── Propuesta ─────────────────────────────────────────────────────
            'aeropuerto_albrook' => [
                'nombre'        => '[DEMO] Terminal Albrook — Expansión',
                'contacto'      => 'Lic. Mariela Ureña',
                'email'         => 'm.urena@aeropuertospa.gob.pa',
                'celular'       => '6655-0099',
                'encargado'     => 'Ing. Felipe Ríos',
                'tipo'          => 'ambos',
                'dialux'        => true,
                'area'          => 'Espacio Público',
                'ubicacion'     => 'Albrook, Panamá',
                'despacho'      => 'Deposito Panamá',
                'etapa'         => 'propuesta',
                'fecha_entrega' => $today->copy()->addDays(75)->toDateString(),
                'valor'         => 350000,
                'notas'         => 'Expansión del terminal doméstico. Proyecto bandera del cliente.',
                'created_by'    => $createdBy,
            ],
            'residencial_los_altos' => [
                'nombre'        => '[DEMO] Residencial Los Altos — Fase 2',
                'contacto'      => 'María Cedeño',
                'email'         => 'm.cedeno@constructoramc.com',
                'celular'       => '6628-4433',
                'developer'     => 'Constructora MC',
                'tipo'          => 'diseno',
                'dialux'        => false,
                'area'          => 'Residencial',
                'ubicacion'     => 'Las Cumbres, Panamá',
                'despacho'      => null,
                'etapa'         => 'propuesta',
                'fecha_entrega' => $today->copy()->addDays(30)->toDateString(),
                'valor'         => 28000,
                'notas'         => 'Conjunto de 24 casas. Proyecto similar al Fase 1 ya ejecutado.',
                'created_by'    => $designerId ?? $createdBy,
            ],

            // ── Cerrado ───────────────────────────────────────────────────────
            'torre_ff' => [
                'nombre'        => '[DEMO] Torre F&F — Oficinas Premium',
                'contacto'      => 'Lic. Rodrigo Fábrega',
                'email'         => 'r.fabrega@ff-inversiones.com',
                'celular'       => '6612-0011',
                'developer'     => 'F&F Inversiones',
                'arquitecto'    => 'Arq. Luis Benedetti',
                'tipo'          => 'ambos',
                'dialux'        => true,
                'area'          => 'Corporativo',
                'ubicacion'     => 'Costa del Este, Panamá',
                'despacho'      => 'Deposito Panamá',
                'etapa'         => 'cerrado',
                'fecha_entrega' => $today->copy()->subDays(20)->toDateString(),
                'valor'         => 95000,
                'notas'         => 'Proyecto entregado. Cliente muy satisfecho. Potencial referido.',
                'created_by'    => $createdBy,
            ],
            'ministerio_salud' => [
                'nombre'        => '[DEMO] Ministerio de Salud — Edificio Central',
                'contacto'      => 'Ing. Teresa Moreno',
                'email'         => 't.moreno@minsa.gob.pa',
                'celular'       => '6677-3344',
                'encargado'     => 'Depto. Infraestructura MINSA',
                'tipo'          => 'cotizacion',
                'dialux'        => false,
                'area'          => 'Salud',
                'ubicacion'     => 'Ancón, Panamá',
                'despacho'      => 'Deposito Panamá',
                'etapa'         => 'cerrado',
                'fecha_entrega' => $today->copy()->subDays(45)->toDateString(),
                'valor'         => 180000,
                'notas'         => 'Licitación gubernamental. Factura emitida, pendiente cobro segunda cuota.',
                'created_by'    => $createdBy,
            ],

            // ── Perdido ───────────────────────────────────────────────────────
            'plaza_tocumen_mall' => [
                'nombre'        => '[DEMO] Plaza Tocumen Mall',
                'contacto'      => 'Ing. Samuel Quirós',
                'email'         => 's.quiros@plazatocumen.com',
                'celular'       => '6644-2288',
                'developer'     => 'Grupo Atlapa',
                'tipo'          => 'cotizacion',
                'dialux'        => false,
                'area'          => 'Comercial',
                'ubicacion'     => 'Tocumen, Panamá',
                'despacho'      => null,
                'etapa'         => 'perdido',
                'fecha_entrega' => $today->copy()->subDays(10)->toDateString(),
                'valor'         => 450000,
                'notas'         => 'Cliente eligió proveedor importador directo por precio. Diferencia ~18%.',
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
            // westland_mall — 1 actividad reciente
            ['project' => 'westland_mall', 'tipo' => 'llamada',
             'fecha' => $today->copy()->subDays(3)->toDateString(),
             'notas' => 'Llamada inicial con Ana Rodríguez. Confirmó interés en cotización LED para toda la planta.',
             'siguiente' => $today->copy()->addDays(7)->toDateString(), 'user_id' => $uid],

            // hotel_decameron — 2 actividades
            ['project' => 'hotel_decameron', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(14)->toDateString(),
             'notas' => 'Reunión on-site con equipo de construcción. Se tomaron medidas y se acordó DIALux completo.',
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'hotel_decameron', 'tipo' => 'email',
             'fecha' => $today->copy()->subDays(4)->toDateString(),
             'notas' => 'Enviamos primera versión del diseño DIALux al arquitecto. Esperamos comentarios.',
             'siguiente' => $today->copy()->addDays(3)->toDateString(), 'user_id' => $did],

            // clinica_san_fernando — 1 actividad
            ['project' => 'clinica_san_fernando', 'tipo' => 'visita',
             'fecha' => $today->copy()->subDays(7)->toDateString(),
             'notas' => 'Visita técnica para levantamiento. Confirmado uso de luminarias clase hospitalaria Cat. 2.',
             'siguiente' => null, 'user_id' => $did],

            // supermercado_el_rey — 1 actividad (vencido)
            ['project' => 'supermercado_el_rey', 'tipo' => 'llamada',
             'fecha' => $today->copy()->subDays(10)->toDateString(),
             'notas' => 'Cliente recordó urgencia por renovación de tienda. Necesitan avanzar esta semana.',
             'siguiente' => $today->copy()->subDays(5)->toDateString(), 'user_id' => $uid],

            // parque_tocumen — 2 actividades
            ['project' => 'parque_tocumen', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(21)->toDateString(),
             'notas' => 'Reunión en oficinas de PITSA. Confirmaron scope: 5 naves industriales + perímetro.',
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'parque_tocumen', 'tipo' => 'whatsapp',
             'fecha' => $today->copy()->subDays(8)->toDateString(),
             'notas' => 'Cliente preguntó por avance de cotización. Le informamos que estará lista en 5 días.',
             'siguiente' => $today->copy()->addDays(3)->toDateString(), 'user_id' => $uid],

            // universidad_latina — 3 actividades
            ['project' => 'universidad_latina', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(12)->toDateString(),
             'notas' => 'Reunión con rector y jefe de infraestructura. Presentamos portafolio hotelero y hospitalario.',
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'universidad_latina', 'tipo' => 'email',
             'fecha' => $today->copy()->subDays(6)->toDateString(),
             'notas' => 'Enviamos memoria técnica y certificaciones. Solicitan aclaración sobre garantía de lámpara.',
             'siguiente' => null, 'user_id' => $did],
            ['project' => 'universidad_latina', 'tipo' => 'llamada',
             'fecha' => $today->copy()->subDays(1)->toDateString(),
             'notas' => 'Recordatorio: propuesta vence mañana. Cliente pide descuento por volumen.',
             'siguiente' => $today->copy()->addDays(1)->toDateString(), 'user_id' => $uid],

            // aeropuerto_albrook — 3 actividades
            ['project' => 'aeropuerto_albrook', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(30)->toDateString(),
             'notas' => 'Primera reunión con Autoridad Aeronáutica. Confirmaron FAA lighting standards requeridos.',
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'aeropuerto_albrook', 'tipo' => 'visita',
             'fecha' => $today->copy()->subDays(15)->toDateString(),
             'notas' => 'Recorrido por la terminal existente con el jefe de obras. Identificamos 3 áreas críticas.',
             'siguiente' => null, 'user_id' => $did],
            ['project' => 'aeropuerto_albrook', 'tipo' => 'email',
             'fecha' => $today->copy()->subDays(2)->toDateString(),
             'notas' => 'Presentamos propuesta técnica completa con DIALux y certificaciones UL/ETL.',
             'siguiente' => $today->copy()->addDays(5)->toDateString(), 'user_id' => $uid],

            // residencial_los_altos — 2 actividades
            ['project' => 'residencial_los_altos', 'tipo' => 'whatsapp',
             'fecha' => $today->copy()->subDays(18)->toDateString(),
             'notas' => 'María Cedeño confirmó que siguen adelante con Fase 2. Muy contentos con la Fase 1.',
             'siguiente' => null, 'user_id' => $did],
            ['project' => 'residencial_los_altos', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(5)->toDateString(),
             'notas' => 'Reunión técnica para definir especificaciones. Mismas luminarias que Fase 1.',
             'siguiente' => $today->copy()->addDays(7)->toDateString(), 'user_id' => $did],

            // torre_ff — 4 actividades (cerrado)
            ['project' => 'torre_ff', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(90)->toDateString(),
             'notas' => 'Kick-off con equipo de F&F. Alcance confirmado: pisos 3-28, zonas comunes y lobby.',
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'torre_ff', 'tipo' => 'visita',
             'fecha' => $today->copy()->subDays(60)->toDateString(),
             'notas' => 'Inicio de instalación. Sin contratiempos. Equipo de electricistas coordinado.',
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'torre_ff', 'tipo' => 'visita',
             'fecha' => $today->copy()->subDays(30)->toDateString(),
             'notas' => 'Inspección de avance. 70% completado. Sin observaciones.',
             'siguiente' => null, 'user_id' => $did],
            ['project' => 'torre_ff', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(20)->toDateString(),
             'notas' => 'Entrega final y punch list. Cliente firmó acta de recepción. Proyecto cerrado.',
             'siguiente' => null, 'user_id' => $uid],

            // ministerio_salud — 3 actividades (cerrado)
            ['project' => 'ministerio_salud', 'tipo' => 'email',
             'fecha' => $today->copy()->subDays(75)->toDateString(),
             'notas' => 'Enviamos cotización formal al departamento de compras del MINSA.',
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'ministerio_salud', 'tipo' => 'llamada',
             'fecha' => $today->copy()->subDays(50)->toDateString(),
             'notas' => 'Confirmaron adjudicación de la licitación. Orden de compra en proceso.',
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'ministerio_salud', 'tipo' => 'visita',
             'fecha' => $today->copy()->subDays(45)->toDateString(),
             'notas' => 'Entrega completada. Pendiente segunda cuota de pago (30 días según contrato).',
             'siguiente' => null, 'user_id' => $uid],

            // plaza_tocumen_mall — 2 actividades (perdido)
            ['project' => 'plaza_tocumen_mall', 'tipo' => 'reunion',
             'fecha' => $today->copy()->subDays(30)->toDateString(),
             'notas' => 'Presentamos propuesta técnica y económica. Grupo Atlapa evaluó 3 proveedores.',
             'siguiente' => null, 'user_id' => $uid],
            ['project' => 'plaza_tocumen_mall', 'tipo' => 'llamada',
             'fecha' => $today->copy()->subDays(10)->toDateString(),
             'notas' => 'Ing. Quirós informó que eligieron proveedor importador directo. Diferencia de precio ~18%.',
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
            // universidad_latina — urgente, cotización vence mañana
            ['project' => 'universidad_latina', 'tipo' => 'llamada',
             'fecha' => $today->copy()->addDays(1)->toDateString(),
             'nota' => 'Confirmar propuesta final con rector. Licitación cierra mañana. Revisar si aplica descuento por volumen.',
             'completado' => false],

            // aeropuerto_albrook — seguimiento propuesta
            ['project' => 'aeropuerto_albrook', 'tipo' => 'email',
             'fecha' => $today->copy()->addDays(5)->toDateString(),
             'nota' => 'Hacer seguimiento si no tienen respuesta a la propuesta técnica enviada.',
             'completado' => false],

            // westland_mall — llamada de seguimiento
            ['project' => 'westland_mall', 'tipo' => 'llamada',
             'fecha' => $today->copy()->addDays(7)->toDateString(),
             'nota' => 'Llamar a Ana Rodríguez para confirmar avance de cotización y resolver dudas técnicas.',
             'completado' => false],

            // clinica_san_fernando — URGENTE, entrega en 2 días
            ['project' => 'clinica_san_fernando', 'tipo' => 'reunion',
             'fecha' => $today->copy()->addDays(2)->toDateString(),
             'nota' => 'Presentar diseño final al cliente. Fecha de entrega apretada — tener DIALux listo.',
             'completado' => false],

            // ministerio_salud — cobro segunda cuota
            ['project' => 'ministerio_salud', 'tipo' => 'llamada',
             'fecha' => $today->copy()->addDays(3)->toDateString(),
             'nota' => 'Confirmar estado de segunda cuota de pago con departamento de finanzas del MINSA.',
             'completado' => false],

            // supermercado_el_rey — VENCIDO (el proyecto ya está vencido)
            ['project' => 'supermercado_el_rey', 'tipo' => 'llamada',
             'fecha' => $today->copy()->subDays(3)->toDateString(),
             'nota' => 'Llamar urgente al cliente — fecha de entrega ya venció. Coordinar próximos pasos.',
             'completado' => false],

            // torre_ff — completado (para mostrar uno en estado completado)
            ['project' => 'torre_ff', 'tipo' => 'email',
             'fecha' => $today->copy()->subDays(19)->toDateString(),
             'nota' => 'Enviar acta de recepción firmada y manual de mantenimiento al cliente.',
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
            'torre_pacifica'        => [$designerId],
            'westland_mall'         => [$managerId, $designerId],
            'hotel_decameron'       => [$designerId],
            'clinica_san_fernando'  => [$designerId],
            'supermercado_el_rey'   => [$designerId],
            'parque_tocumen'        => [$managerId],
            'universidad_latina'    => [$managerId, $designerId],
            'aeropuerto_albrook'    => [$managerId, $designerId],
            'residencial_los_altos' => [$designerId],
            'torre_ff'              => [$managerId, $designerId],
            'ministerio_salud'      => [$managerId],
            // plaza_tocumen_mall: sin asignados (perdido)
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

    /** @param list<string> $roles */
    private function userIdByRole(string $slug, array $roles): ?int
    {
        $table = "{$slug}_auth.user_roles";

        foreach ($roles as $role) {
            $userId = DB::table($table)->where('role', $role)->value('user_id');
            if ($userId !== null) {
                return (int) $userId;
            }
        }

        return null;
    }
}
