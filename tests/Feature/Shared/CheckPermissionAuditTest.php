<?php

namespace Tests\Feature\Shared;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-806 (REQ-631, Epic SCRUM-788) — CheckPermission no logeaba ningún 403, sin rastro de
 * reconocimiento previo a un posible fraude (probar rutas de Bodega/Admin&Cont sin permiso).
 */
class CheckPermissionAuditTest extends FeatureTestCase
{
    private function userId(string $email): int
    {
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            return User::where('email', $email)->firstOrFail()->id;
        } finally {
            Tenant::forgetCurrent();
        }
    }

    public function test_403_por_falta_de_permiso_registra_security_access_denied(): void
    {
        $userId = $this->userId('vendedor@atlantic.test');
        $token  = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        // designer no tiene superadmin.all — ruta gateada permission:superadmin.all.
        $this->getJson('/api/admin/audit/logs', $this->authHeaders($token))
            ->assertStatus(403);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', AuditService::SECURITY_ACCESS_DENIED)
            ->where('user_id', $userId)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($log, 'Debe existir una entrada de audit con action=security.access_denied');
        $this->assertSame('route', $log->entity_type);
        $this->assertSame('api/admin/audit/logs', $log->entity_id);

        $metadata = json_decode((string) $log->metadata, true);
        $this->assertSame(['superadmin.all'], $metadata['required_permissions'] ?? null);
    }

    public function test_acceso_permitido_no_registra_security_access_denied(): void
    {
        $userId = $this->userId('superadmin@atlantic.test');
        $token  = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        // Mismo endpoint que el test de arriba — acá el actor SÍ tiene superadmin.all.
        $this->getJson('/api/admin/audit/logs', $this->authHeaders($token))
            ->assertSuccessful();

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', AuditService::SECURITY_ACCESS_DENIED)
            ->where('user_id', $userId)
            ->first();

        $this->assertNull($log);
    }
}
