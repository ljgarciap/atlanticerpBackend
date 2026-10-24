<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\InternalTechnician;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Models\Tool;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1015 — Editar y Eliminar (soft delete) técnicos internos. El técnico eliminado deja de
 * aparecer en el listado y de ser asignable, pero su historial (tickets, comisiones, herramientas
 * pasadas) queda intacto.
 */
class InternalTechnicianEditDeleteTest extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    private const TECNICO_SERVICIOS = 'tecnicoservicios@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array<string, string> */
    private function lider(): array
    {
        return $this->authHeaders($this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios'));
    }

    private function nuevoTecnico(string $nombre = 'Tecnico Temporal'): InternalTechnician
    {
        return InternalTechnician::create([
            'nombre' => $nombre, 'telefono' => '6000-0000', 'email' => 'temp@example.com',
            'especialidad' => InternalTechnician::ESPECIALIDAD_GENERAL, 'color' => '#10B981',
        ]);
    }

    /** El middleware de la API limpia el tenant actual al terminar cada request — se restaura antes de consultar modelos. */
    private function trashed(int $id): ?InternalTechnician
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return InternalTechnician::withTrashed()->find($id);
    }

    public function test_editar_actualiza_los_datos_y_se_refleja_en_el_listado(): void
    {
        $t = $this->nuevoTecnico();

        $this->withHeaders($this->lider())
            ->putJson("/api/servicios/internal-technicians/{$t->id}", [
                'nombre' => 'Nombre Corregido', 'telefono' => '6999-1111',
                'email' => 'nuevo@example.com', 'especialidad' => InternalTechnician::ESPECIALIDAD_WARRANTY,
            ])
            ->assertStatus(200)
            ->assertJsonPath('nombre', 'Nombre Corregido')
            ->assertJsonPath('especialidad', InternalTechnician::ESPECIALIDAD_WARRANTY);

        $row = collect($this->withHeaders($this->lider())->getJson('/api/servicios/internal-technicians')->json('data'))
            ->firstWhere('id', $t->id);
        $this->assertSame('Nombre Corregido', $row['nombre']);
        $this->assertSame('6999-1111', $row['telefono']);
        $this->assertSame('nuevo@example.com', $row['email']);
    }

    public function test_editar_sin_nombre_retorna_422(): void
    {
        $t = $this->nuevoTecnico();

        $this->withHeaders($this->lider())
            ->putJson("/api/servicios/internal-technicians/{$t->id}", [
                'nombre' => '', 'especialidad' => InternalTechnician::ESPECIALIDAD_GENERAL,
            ])
            ->assertStatus(422);
    }

    public function test_editar_y_eliminar_exigen_rol_lider_o_superadmin(): void
    {
        $t = $this->nuevoTecnico();
        $headers = $this->authHeaders($this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios'));

        $this->withHeaders($headers)
            ->putJson("/api/servicios/internal-technicians/{$t->id}", [
                'nombre' => 'X', 'especialidad' => InternalTechnician::ESPECIALIDAD_GENERAL,
            ])->assertStatus(403);
        $this->withHeaders($headers)->deleteJson("/api/servicios/internal-technicians/{$t->id}")->assertStatus(403);
    }

    public function test_editar_o_eliminar_inexistente_retorna_404(): void
    {
        $this->withHeaders($this->lider())
            ->putJson('/api/servicios/internal-technicians/99999999', [
                'nombre' => 'X', 'especialidad' => InternalTechnician::ESPECIALIDAD_GENERAL,
            ])->assertStatus(404);
        $this->withHeaders($this->lider())->deleteJson('/api/servicios/internal-technicians/99999999')->assertStatus(404);
    }

    public function test_eliminar_quita_al_tecnico_del_listado_pero_conserva_el_registro(): void
    {
        $t = $this->nuevoTecnico();

        $this->withHeaders($this->lider())->deleteJson("/api/servicios/internal-technicians/{$t->id}")->assertStatus(204);

        $ids = collect($this->withHeaders($this->lider())->getJson('/api/servicios/internal-technicians')->json('data'))->pluck('id');
        $this->assertNotContains($t->id, $ids);
        $this->assertNotNull($this->trashed($t->id), 'Soft delete: el registro debe seguir existiendo.');

        $agendaIds = collect($this->withHeaders($this->lider())->getJson('/api/servicios/internal-technicians/agenda')->json('data'))->pluck('id');
        $this->assertNotContains($t->id, $agendaIds);
    }

    public function test_tecnico_eliminado_con_usuario_deja_de_ser_asignable_pero_su_historial_sigue(): void
    {
        /** @var InternalTechnician $carlos */
        $carlos = InternalTechnician::where('nombre', 'Tecnico Servicios Test')->firstOrFail();
        $userId = (int) $carlos->user_id;
        $ticket = Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(), 'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL, 'descripcion' => 'Histórico',
            'cliente' => 'Cliente', 'estado' => Ticket::ESTADO_CLOSED, 'quote_status' => Ticket::QUOTE_STATUS_PENDING,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING, 'internal_technician_id' => $userId,
        ]);

        $before = collect($this->withHeaders($this->lider())->getJson('/api/servicios/technicians/internal')->json('data'))->pluck('id');
        $this->assertContains($userId, $before);

        $this->withHeaders($this->lider())->deleteJson("/api/servicios/internal-technicians/{$carlos->id}")->assertStatus(204);

        $after = collect($this->withHeaders($this->lider())->getJson('/api/servicios/technicians/internal')->json('data'))->pluck('id');
        $this->assertNotContains($userId, $after);
        $this->assertSame($userId, (int) (function () use ($ticket) { Tenant::where('slug', 'atlantic')->first()?->makeCurrent(); return Ticket::find($ticket->id)?->internal_technician_id; })(), 'El historial del ticket no se toca.');
    }

    public function test_no_se_puede_eliminar_con_servicios_pendientes(): void
    {
        /** @var InternalTechnician $carlos */
        $carlos = InternalTechnician::where('nombre', 'Tecnico Servicios Test')->firstOrFail();
        Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(), 'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL, 'descripcion' => 'Pendiente',
            'cliente' => 'Cliente', 'estado' => Ticket::ESTADO_SCHEDULED, 'quote_status' => Ticket::QUOTE_STATUS_PENDING,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING, 'internal_technician_id' => $carlos->user_id,
        ]);

        $this->withHeaders($this->lider())->deleteJson("/api/servicios/internal-technicians/{$carlos->id}")->assertStatus(422);
        $this->assertNull($this->trashed($carlos->id)?->deleted_at);
    }

    public function test_no_se_puede_eliminar_con_herramientas_asignadas(): void
    {
        $t = $this->nuevoTecnico();
        Tool::create([
            'nombre' => 'Taladro', 'codigo_unico' => 'TAL-'.static::uniqueTestNumber(),
            'estado' => Tool::ESTADO_GOOD, 'assigned_to_technician_id' => $t->id,
        ]);

        $this->withHeaders($this->lider())->deleteJson("/api/servicios/internal-technicians/{$t->id}")->assertStatus(422);
        $this->assertNull($this->trashed($t->id)?->deleted_at);
    }

    /** Cada deploy corre db:seed: un técnico eliminado no debe reaparecer ni romper el seeder (unique de user_id). */
    public function test_el_seeder_no_resucita_ni_duplica_a_un_tecnico_eliminado(): void
    {
        /** @var InternalTechnician $carlos */
        $carlos = InternalTechnician::where('nombre', 'Tecnico Servicios Test')->firstOrFail();
        $this->withHeaders($this->lider())->deleteJson("/api/servicios/internal-technicians/{$carlos->id}")->assertStatus(204);

        $this->seed(\Database\Seeders\InternalTechnicianSeeder::class);

        $this->assertNotNull($this->trashed($carlos->id)?->deleted_at, 'Sigue eliminado tras re-sembrar.');
        $this->assertSame(1, InternalTechnician::withTrashed()->where('user_id', $carlos->user_id)->count());
        $ids = collect($this->withHeaders($this->lider())->getJson('/api/servicios/internal-technicians')->json('data'))->pluck('id');
        $this->assertNotContains($carlos->id, $ids);
    }

    /** Pre-QA: un técnico eliminado no puede recibir herramientas (Rule::exists ignoraba deleted_at). */
    public function test_no_se_puede_asignar_una_herramienta_a_un_tecnico_eliminado(): void
    {
        $t = $this->nuevoTecnico();
        $tool = Tool::create([
            'nombre' => 'Sierra', 'codigo_unico' => 'SIE-'.static::uniqueTestNumber(),
            'estado' => Tool::ESTADO_GOOD, 'assigned_to_technician_id' => null,
        ]);
        $this->withHeaders($this->lider())->deleteJson("/api/servicios/internal-technicians/{$t->id}")->assertStatus(204);

        $this->withHeaders($this->lider())
            ->patchJson("/api/servicios/tools/{$tool->id}/asignacion", ['assigned_to_technician_id' => $t->id])
            ->assertStatus(422);
    }
}
