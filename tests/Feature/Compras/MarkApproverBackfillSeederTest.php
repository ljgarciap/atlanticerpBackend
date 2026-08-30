<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Models\User;
use App\Modules\Compras\Models\ComprasSettings;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\MarkApproverBackfillSeeder;
use Tests\Feature\FeatureTestCase;

/**
 * Pre-QA 2026-07-24 (Bloque B4) — `database/seeders/MarkApproverBackfillSeeder.php` corre DESPUÉS
 * de `CoreUserSeeder`, a diferencia de la migración `2026_07_20_100009_...` que backfillea
 * `mark_approver_user_id` DENTRO de `tenants:artisan migrate` (antes de `db:seed --force` en el
 * pipeline real, ver `ci-backend.yml`) — en un entorno reprovisionado desde cero, Mark todavía no
 * existe cuando esa migración corre, así que su backfill queda en NULL en silencio. Este seeder es
 * la red de seguridad que corrige eso en cualquier entorno.
 */
class MarkApproverBackfillSeederTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_mark_approver_user_id_queda_seteado_tras_el_seeding_normal(): void
    {
        $mark = User::where('email', 'mbekhar@atlantic.com.pa')->firstOrFail();

        $this->assertSame($mark->id, ComprasSettings::current()->mark_approver_user_id);
    }

    public function test_el_seeder_rellena_si_esta_en_null(): void
    {
        $mark = User::where('email', 'mbekhar@atlantic.com.pa')->firstOrFail();
        ComprasSettings::current()->update(['mark_approver_user_id' => null]);

        (new MarkApproverBackfillSeeder)->run();
        // El seeder hace Tenant::forgetCurrent() en su finally — restablecer antes de seguir.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $this->assertSame($mark->id, ComprasSettings::current()->mark_approver_user_id);
    }

    public function test_el_seeder_no_pisa_un_approver_ya_reasignado(): void
    {
        $otroUsuario = User::where('email', '!=', 'mbekhar@atlantic.com.pa')->firstOrFail();
        ComprasSettings::current()->update(['mark_approver_user_id' => $otroUsuario->id]);

        (new MarkApproverBackfillSeeder)->run();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $this->assertSame($otroUsuario->id, ComprasSettings::current()->mark_approver_user_id);
    }
}
