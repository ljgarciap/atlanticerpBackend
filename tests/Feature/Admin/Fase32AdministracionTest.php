<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Auth\Models\Department;
use App\Modules\CRM\Models\NotificationRule;
use App\Modules\CRM\Notifications\NotificationRuleCache;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** Fase 32 (docs/specs/fase32-cierre-abiertos.md) — administración: contacto de soporte visible sin sesión. */
final class Fase32AdministracionTest extends FeatureTestCase
{
    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    public function test_el_contacto_de_soporte_se_ve_sin_sesion_y_solo_ese_dato(): void
    {
        // Sin configurar: vacío (la pantalla de ingreso no muestra nada).
        $this->getJson('/api/auth/support-contact')->assertOk()->assertExactJson(['data' => ['support_contact' => '']]);

        $h = $this->authHeaders($this->loginAs());
        $this->tenant();
        $this->withHeaders($h)->putJson('/api/admin/global-settings/support_contact', ['value' => 'soporte@example.com'])->assertOk();

        $this->getJson('/api/auth/support-contact')->assertOk()->assertExactJson(['data' => ['support_contact' => 'soporte@example.com']]);
    }

    private function regla(string $nombre, string $modelo = 'Ticket', string $evento = 'created'): NotificationRule
    {
        return NotificationRule::create([
            'name' => $nombre, 'trigger_type' => 'model_event', 'trigger_model' => $modelo, 'trigger_event' => $evento,
            'channels' => ['in_app'], 'recipient_type' => 'role', 'recipient_value' => ['lider_servicios'], 'is_active' => true,
        ]);
    }

    public function test_las_reglas_activas_se_leen_una_vez_por_peticion_y_se_refrescan_al_cambiar(): void
    {
        $this->tenant();
        $this->regla('Ticket nuevo F32');
        $this->regla('Orden actualizada F32', 'PurchaseOrder', 'updated');
        $cache = new NotificationRuleCache;

        DB::connection()->enableQueryLog();
        $this->assertCount(1, $cache->activeFor('Ticket', 'created'));
        $this->assertCount(1, $cache->activeFor('PurchaseOrder', 'updated'));
        $this->assertCount(0, $cache->activeFor('Order', 'updated'));
        $consultas = collect(DB::connection()->getQueryLog())->filter(fn ($q) => str_contains((string) $q['query'], 'notification_rules'))->count();
        DB::connection()->disableQueryLog();
        $this->assertSame(1, $consultas, 'Tres preguntas, una sola lectura de reglas.');

        // Guardar una regla invalida el memo registrado en el contenedor (el que usa el listener).
        $compartido = app(NotificationRuleCache::class);
        $this->assertCount(1, $compartido->activeFor('Ticket', 'created'));
        $this->regla('Otro ticket nuevo F32');
        $this->assertCount(2, $compartido->activeFor('Ticket', 'created'));
    }

    public function test_una_regla_desactivada_no_se_ofrece(): void
    {
        $this->tenant();
        $r = $this->regla('Apagada F32');
        $r->update(['is_active' => false]);
        $this->assertCount(0, app(NotificationRuleCache::class)->activeFor('Ticket', 'created')->where('id', $r->id));
    }

    public function test_reasignar_un_departamento_deja_una_entrada_de_auditoria_por_usuario(): void
    {
        $h = $this->authHeaders($this->loginAs());
        $this->tenant();
        $a = Department::create(['name' => 'Depto A F32', 'is_active' => true]);
        $b = Department::create(['name' => 'Depto B F32', 'is_active' => true]);
        $u1 = User::where('email', 'vendedor@atlantic.test')->firstOrFail();
        $u2 = User::where('email', 'liderservicios@test.com')->firstOrFail();
        foreach ([$u1, $u2] as $u) {
            $u->department_id = $a->id;
            $u->save();
        }

        $this->withHeaders($h)->postJson("/api/departments/{$a->id}/reassign", ['to_department_id' => $b->id])->assertOk()->assertJsonPath('movidos', 2);

        $this->tenant();
        foreach ([$u1, $u2] as $u) {
            $log = AuditLog::where('action', 'auth.user.department_reassigned')->where('entity_id', (string) $u->id)->latest('id')->first();
            $this->assertNotNull($log, "Falta la entrada de auditoría del usuario {$u->id}.");
            $this->assertSame($a->id, $log->old_values['department_id']);
            $this->assertSame($b->id, $log->new_values['department_id']);
        }
        $this->assertSame(2, AuditLog::where('action', 'auth.user.department_reassigned')->count(), 'Una entrada por usuario, no una por operación.');
    }
}
