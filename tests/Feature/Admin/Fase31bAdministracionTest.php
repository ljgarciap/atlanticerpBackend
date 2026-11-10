<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Auth\Models\Department;
use App\Modules\Auth\Support\GlobalSettingsRegistry;
use App\Modules\CRM\Models\NotificationRule;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** Fase 31b (docs/specs/fase31-ventas-administracion.md) — perfil de empresa para Gerencia, departamentos, reglas de notificación y ajustes de texto. */
final class Fase31bAdministracionTest extends FeatureTestCase
{
    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    /** @return array<string, string> */
    private function superadmin(): array
    {
        $h = $this->authHeaders($this->loginAs());
        $this->tenant();

        return $h;
    }

    public function test_el_perfil_de_empresa_lo_leen_gerencia_y_no_un_vendedor(): void
    {
        $gerencia = $this->authHeaders($this->loginAs('gerencia@test.com', 'gerencia@test.com'));
        $this->withHeaders($gerencia)->getJson('/api/admin-contab/company-profile')->assertOk();
        $this->withHeaders($gerencia)->putJson('/api/admin-contab/company-profile', ['direccion' => 'Calle 1'])->assertOk();

        $vendedor = $this->authHeaders($this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer'));
        $this->withHeaders($vendedor)->getJson('/api/admin-contab/company-profile')->assertStatus(403);

        // La configuración fiscal sigue siendo solo del aprobador primario.
        $this->withHeaders($gerencia)->getJson('/api/admin-contab/fiscal-settings')->assertStatus(403);
    }

    public function test_desactivar_un_departamento_y_reasignar_sus_usuarios(): void
    {
        $h = $this->superadmin();
        $a = Department::create(['name' => 'Depto A F31b', 'is_active' => true]);
        $b = Department::create(['name' => 'Depto B F31b', 'is_active' => true]);
        $u = User::where('email', 'vendedor@atlantic.test')->firstOrFail();
        $u->department_id = $a->id;
        $u->save();

        $this->withHeaders($h)->patchJson("/api/departments/{$a->id}/status", ['is_active' => false])->assertOk();

        // El selector oculta el desactivado… salvo que se pida conservar el propio.
        $ids = fn (string $q) => collect($this->withHeaders($h)->getJson("/api/departments?all=1{$q}")->assertOk()->json())->pluck('id')->all();
        $this->assertNotContains($a->id, $ids(''));
        $this->assertContains($a->id, $ids("&incluir={$a->id}"));

        $this->withHeaders($h)->postJson("/api/departments/{$a->id}/reassign", ['to_department_id' => $a->id])->assertStatus(422);
        $this->withHeaders($h)->postJson("/api/departments/{$a->id}/reassign", ['to_department_id' => 999999])->assertStatus(422);
        $this->withHeaders($h)->postJson("/api/departments/{$a->id}/reassign", ['to_department_id' => $b->id])
            ->assertOk()->assertJsonPath('movidos', 1);

        $this->tenant();
        $this->assertSame($b->id, $u->refresh()->department_id);
    }

    public function test_reasignar_a_nadie_deja_a_los_usuarios_sin_departamento(): void
    {
        $h = $this->superadmin();
        $a = Department::create(['name' => 'Depto C F31b', 'is_active' => true]);
        $u = User::where('email', 'vendedor@atlantic.test')->firstOrFail();
        $u->department_id = $a->id;
        $u->save();

        $this->withHeaders($h)->postJson("/api/departments/{$a->id}/reassign", [])->assertOk()->assertJsonPath('movidos', 1);
        $this->tenant();
        $this->assertNull($u->refresh()->department_id);
    }

    public function test_reglas_de_notificacion_de_compras_bodega_y_servicios(): void
    {
        $h = $this->superadmin();
        $registro = $this->withHeaders($h)->getJson('/api/admin/notification-rules')->assertOk()->json('registry');
        $this->assertSame('compras', $registro['modules']['PurchaseOrder']);
        $this->assertSame('bodega', $registro['modules']['Order']);
        $this->assertSame('servicios', $registro['modules']['Ticket']);
        $this->assertSame(['created', 'updated'], $registro['events']['Ticket']);

        $base = ['trigger_type' => 'model_event', 'channels' => ['in_app'], 'recipient_type' => 'role', 'recipient_value' => ['lider_servicios']];
        $this->withHeaders($h)->postJson('/api/admin/notification-rules', $base + ['name' => 'Ticket nuevo', 'trigger_model' => 'Ticket', 'trigger_event' => 'created'])->assertCreated();
        $this->withHeaders($h)->postJson('/api/admin/notification-rules', $base + ['name' => 'Estado', 'trigger_model' => 'Ticket', 'trigger_event' => 'updated', 'field' => 'estado', 'operator' => 'changed'])->assertCreated();

        // Evento fuera de la lista corta del modelo, campo no permitido y «asignados» fuera de CRM → 422.
        $this->withHeaders($h)->postJson('/api/admin/notification-rules', $base + ['name' => 'X', 'trigger_model' => 'InspectionReport', 'trigger_event' => 'created'])->assertStatus(422);
        $this->withHeaders($h)->postJson('/api/admin/notification-rules', $base + ['name' => 'X', 'trigger_model' => 'Ticket', 'trigger_event' => 'created', 'field' => 'descripcion', 'operator' => 'changed'])->assertStatus(422);
        $this->withHeaders($h)->postJson('/api/admin/notification-rules', ['recipient_type' => 'project_assignees', 'recipient_value' => []] + $base + ['name' => 'X', 'trigger_model' => 'Ticket', 'trigger_event' => 'created'])->assertStatus(422);
    }

    public function test_una_regla_de_servicios_avisa_al_crear_un_ticket(): void
    {
        $this->tenant();
        $lider = User::where('email', 'liderservicios@test.com')->firstOrFail();
        $rule = NotificationRule::create([
            'name' => 'Ticket nuevo', 'trigger_type' => 'model_event', 'trigger_model' => 'Ticket', 'trigger_event' => 'created',
            'channels' => ['in_app'], 'recipient_type' => 'user', 'recipient_value' => [$lider->id], 'is_active' => true,
        ]);

        $h = $this->authHeaders($this->loginAs('liderservicios@test.com', 'liderservicios@test.com'));
        $this->tenant();
        $otro = User::where('email', 'gerencia@test.com')->firstOrFail();
        $rule->update(['recipient_value' => [$otro->id]]);

        $this->tenant();
        $u = uniqid();
        $master = MasterClient::create(['name' => "Cliente {$u}"]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => "Cliente {$u} SA", 'tax_id' => $u]);
        $proyecto = SalesProject::create(['sub_client_id' => $sub->id, 'name' => "Proyecto {$u}"]);

        $this->withHeaders($h)->postJson('/api/servicios/tickets', [
            'tipo' => Ticket::TIPO_CLAIM, 'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion' => 'Prueba F31b', 'sales_project_id' => $proyecto->id,
        ])->assertStatus(201);

        $this->tenant();
        $this->assertDatabaseHas(
            'notification_sends',
            ['source_ref' => "notification_rule:{$rule->id}"],
            (new \App\Modules\Notifications\Models\NotificationSend())->getConnectionName(),
        );
    }

    public function test_ajustes_globales_de_texto(): void
    {
        $h = $this->superadmin();
        $fila = collect($this->withHeaders($h)->getJson('/api/admin/global-settings')->assertOk()->json('data'))->firstWhere('key', 'support_contact');
        $this->assertSame('text', $fila['type']);
        $this->assertSame('', $fila['value']);

        $this->withHeaders($h)->putJson('/api/admin/global-settings/support_contact', ['value' => '  soporte@example.com  '])
            ->assertOk()->assertJsonPath('value', 'soporte@example.com');
        $this->tenant();
        $this->assertSame('soporte@example.com', GlobalSettingsRegistry::text('support_contact'));

        $this->withHeaders($h)->putJson('/api/admin/global-settings/support_contact', ['value' => str_repeat('x', 201)])->assertStatus(422);
        // Un ajuste entero sigue exigiendo entero.
        $this->withHeaders($h)->putJson('/api/admin/global-settings/documents.max_size_mb', ['value' => 'abc'])->assertStatus(422);
    }
}
