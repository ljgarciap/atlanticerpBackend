<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Models\User;
use App\Modules\Bodega\Models\Warehouse;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-790 (Epic SCRUM-788 — Logs y Telemetría): LogsAuditTrail aplicado a los modelos de
 * escritura de Bodega. Mismo alcance que Tests\Feature\Servicios\LogsAuditTrailTest — no repite
 * el trait entero, solo fija que el `action` logeado usa el prefijo `bodega.` y que
 * created/updated quedan enganchados sin tocar controllers.
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
        $user    = User::where('email', 'daniela@atlantic.com.pa')->firstOrFail();
        $request = Request::create('/');
        $request->attributes->set('jwt', ['sub' => $user->id]);
        $this->app->instance('request', $request);
    }

    public function test_crear_bodega_logea_con_prefijo_bodega(): void
    {
        $this->actingForAudit();
        $warehouse = Warehouse::create(['name' => 'Bodega de prueba — audit trail']);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'bodega.warehouse.created')
            ->where('entity_id', (string) $warehouse->id)
            ->first();

        $this->assertNotNull($log, 'Se esperaba una entrada bodega.warehouse.created en audit_logs.');
        $this->assertSame('warehouse', $log->entity_type);
    }

    public function test_actualizar_bodega_logea_diff_sin_timestamps(): void
    {
        $this->actingForAudit();
        $warehouse = Warehouse::create(['name' => 'Bodega de prueba — audit trail']);

        $this->actingForAudit();
        $warehouse->update(['responsable' => 'Nuevo responsable']);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'bodega.warehouse.updated')
            ->where('entity_id', (string) $warehouse->id)
            ->first();

        $this->assertNotNull($log, 'Se esperaba una entrada bodega.warehouse.updated en audit_logs.');
        $newValues = json_decode($log->new_values, true);
        $this->assertArrayHasKey('responsable', $newValues);
        $this->assertArrayNotHasKey('updated_at', $newValues);
    }
}
