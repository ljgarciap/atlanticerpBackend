<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContLocation;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-794 (Epic SCRUM-788 — Logs y Telemetría): LogsAuditTrail aplicado a los modelos de
 * escritura de Admin&Cont. Mismo alcance que Tests\Feature\Bodega\LogsAuditTrailTest — no repite
 * el trait entero, solo fija que created/updated quedan enganchados sin tocar controllers.
 *
 * Nota de naming (no es un bug de este ticket, ver docblock de LogsAuditTrail): el prefijo de
 * `action` se deriva del segmento de namespace `App\Modules\AdminCont\...` → `admin_cont`, NO
 * `admin_contab` (el nombre real del schema Postgres, `{tenant}_admin_contab`). Mismo tipo de
 * divergencia namespace-vs-schema que el trait ya documenta para Compras — no se corrige acá
 * para no desviarse de "generaliza sin overrides por modelo".
 */
class LogsAuditTrailTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function actingForAudit(): void
    {
        $user    = User::where('email', 'gerencia@test.com')->firstOrFail();
        $request = Request::create('/');
        $request->attributes->set('jwt', ['sub' => $user->id]);
        $this->app->instance('request', $request);
    }

    public function test_crear_location_logea_con_prefijo_admin_cont(): void
    {
        $this->actingForAudit();
        $location = AdminContLocation::create(['nombre' => 'Oficina de prueba — audit trail', 'tipo' => 'oficina']);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'admin_cont.admin_cont_location.created')
            ->where('entity_id', (string) $location->id)
            ->first();

        $this->assertNotNull($log, 'Se esperaba una entrada admin_cont.admin_cont_location.created en audit_logs.');
        $this->assertSame('admin_cont_location', $log->entity_type);
    }

    public function test_actualizar_location_logea_diff_sin_timestamps(): void
    {
        $this->actingForAudit();
        $location = AdminContLocation::create(['nombre' => 'Oficina de prueba — audit trail', 'tipo' => 'oficina']);

        $this->actingForAudit();
        $location->update(['direccion' => 'Calle 50, Panamá']);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'admin_cont.admin_cont_location.updated')
            ->where('entity_id', (string) $location->id)
            ->first();

        $this->assertNotNull($log, 'Se esperaba una entrada admin_cont.admin_cont_location.updated en audit_logs.');
        $newValues = json_decode($log->new_values, true);
        $this->assertArrayHasKey('direccion', $newValues);
        $this->assertArrayNotHasKey('updated_at', $newValues);
    }
}
