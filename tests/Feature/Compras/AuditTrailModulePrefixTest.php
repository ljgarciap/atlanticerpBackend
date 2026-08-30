<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Models\User;
use App\Modules\Compras\Models\Provider;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-789/790 (Epic SCRUM-788 — Logs y Telemetría) — regresión del fix en
 * App\Shared\Support\LogsAuditTrail::auditModule(): antes de esta epic, `auditAction()`
 * tenía 'ventas_diseno.' hardcodeado, así que Provider (y el resto de Compras que ya usa el
 * trait) logeaba con la acción equivocada (`ventas_diseno.provider.created` en vez de
 * `compras.provider.created`). Filas históricas quedan mal etiquetadas a propósito (no se
 * corrigen retroactivamente) — este test solo fija el comportamiento correcto hacia adelante.
 */
class AuditTrailModulePrefixTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function actingForAudit(): void
    {
        $user    = User::where('email', 'daniela@illuminations.com.pa')->firstOrFail();
        $request = Request::create('/');
        $request->attributes->set('jwt', ['sub' => $user->id]);
        $this->app->instance('request', $request);
    }

    public function test_provider_logea_con_prefijo_compras_no_ventas_diseno(): void
    {
        $this->actingForAudit();
        $provider = Provider::create(['name' => 'LightCorp Audit Test', 'currency' => 'USD']);

        $correcto = DB::table('illuminations_auth.audit_logs')
            ->where('action', 'compras.provider.created')
            ->where('entity_id', (string) $provider->id)
            ->exists();
        $viejoBug = DB::table('illuminations_auth.audit_logs')
            ->where('action', 'ventas_diseno.provider.created')
            ->where('entity_id', (string) $provider->id)
            ->exists();

        $this->assertTrue($correcto, 'Se esperaba compras.provider.created en audit_logs.');
        $this->assertFalse($viejoBug, 'El prefijo viejo (ventas_diseno.*) no debe volver a aparecer para modelos de Compras.');
    }
}
