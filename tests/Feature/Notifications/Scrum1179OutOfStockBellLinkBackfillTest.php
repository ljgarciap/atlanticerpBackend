<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1179 — los avisos viejos "Pedido X sin stock disponible" sin enlace llevan al pedido en Status de Pedidos;
 * los que ya traen enlace (los nuevos, al producto) y los de otros tipos no se tocan.
 */
class Scrum1179OutOfStockBellLinkBackfillTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function aviso(int $userId, string $subject, ?string $link = null, array $extra = []): int
    {
        return (int) DB::table('atlantic_notifications.notifications')->insertGetId([
            'user_id'  => $userId,
            'content'  => json_encode(['subject' => $subject, 'body' => 'x'] + $extra),
            'link_url' => $link,
        ]);
    }

    private function link(int $id): ?string
    {
        return DB::table('atlantic_notifications.notifications')->where('id', $id)->value('link_url');
    }

    public function test_los_avisos_viejos_sin_stock_reciben_el_enlace_al_pedido_y_los_demas_no_se_tocan(): void
    {
        $uid  = (int) User::where('email', 'lideroperaciones@test.com')->value('id');
        $otro = (int) User::where('email', 'contabilidad@test.com')->value('id');

        $viejo      = $this->aviso($uid, 'Pedido PED-2026-0049 sin stock disponible');
        $viejoOtro  = $this->aviso($otro, 'Pedido PED-2026-0031 sin stock disponible');
        $nuevo      = $this->aviso($uid, 'Pedido PED-2026-0115 sin stock disponible', '/inventario?search=10147F-DL-1', ['cta_url' => '/inventario?search=10147F-DL-1']);
        $soloCta    = $this->aviso($uid, 'Pedido PED-2026-0116 sin stock disponible', null, ['cta_url' => '/inventario?search=ABC']);
        $conEnlace  = $this->aviso($uid, 'Pedido PED-2026-0117 sin stock disponible', '/otro/destino');
        $yaTieneStk = $this->aviso($uid, 'Pedido PED-2026-0080 ya tiene stock disponible');
        $ajeno      = $this->aviso($uid, 'Aviso cualquiera que no es de este emisor');
        $parecido   = $this->aviso($uid, 'Pedido PED-2026-0118 sin stock disponible en la bodega 3');

        $migracion = require base_path('database/migrations/tenant/2026_10_19_210000_backfill_link_of_out_of_stock_bell_notifications.php');
        $migracion->up();
        $migracion->up(); // idempotente

        $this->assertSame('/bodega/pedidos/status?search=PED-2026-0049', $this->link($viejo));
        $this->assertSame('/bodega/pedidos/status?search=PED-2026-0031', $this->link($viejoOtro));
        $this->assertSame('/inventario?search=10147F-DL-1', $this->link($nuevo));
        $this->assertNull($this->link($soloCta));
        $this->assertSame('/otro/destino', $this->link($conEnlace));
        $this->assertNull($this->link($yaTieneStk));
        $this->assertNull($this->link($ajeno));
        $this->assertNull($this->link($parecido));

        $content = json_decode((string) DB::table('atlantic_notifications.notifications')->where('id', $viejo)->value('content'), true);
        $this->assertSame('/bodega/pedidos/status?search=PED-2026-0049', $content['cta_url']);
        $this->assertSame('Pedido PED-2026-0049 sin stock disponible', $content['subject']);
    }
}
