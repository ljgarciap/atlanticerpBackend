<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1167 (rebote) — los avisos de la campana ya creados sin enlace reciben el destino de su emisor.
 * SCRUM-1157 (rebote) — a Lider Operaciones se le quita la fila de visibilidad de Ventas & Diseño, sin tocar nada más.
 */
class Scrum1167Scrum1157DataFixesTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** Carga una migración histórica por su nombre o, si termina en `*`, por su prefijo (los nombres históricos no se tocan). */
    private function migration(string $file): object
    {
        $path = base_path("database/migrations/tenant/{$file}");
        if (str_contains($file, '*')) {
            $found = glob($path) ?: [];
            $this->assertCount(1, $found, "Migración histórica no encontrada: {$file}");
            $path = $found[0];
        }

        return require $path;
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

    public function test_los_avisos_viejos_sin_enlace_reciben_el_destino_de_su_emisor_y_los_demas_no_se_tocan(): void
    {
        $uid = (int) User::where('email', 'lideroperaciones@test.com')->value('id');

        $zona      = $this->aviso($uid, 'Recordatorio: solicitud Zona Libre #3 pendiente de aprobar');
        $stock     = $this->aviso($uid, 'Pedido PED-2026-0080 ya tiene stock disponible');
        $asistente = $this->aviso($uid, 'Pedido PED-2026-0081 sin asistente asignado');
        $facturar  = $this->aviso($uid, 'Pedido PED-2026-0082 listo para facturar');
        $propuesta = $this->aviso($uid, 'Recordatorio: propuestas vencidas pendientes de seguimiento');
        $conEnlace = $this->aviso($uid, 'Recordatorio: solicitud Zona Libre #9 pendiente de aprobar', '/otro/destino');
        $otro      = $this->aviso($uid, 'Aviso cualquiera que no es de estos emisores');

        $migracion = $this->migration('2026_10_18_110000_backfill_link_of_existing_bell_notifications.php');
        $migracion->up();
        $migracion->up(); // idempotente

        $this->assertSame('/bodega/ordenes-zona-libre', $this->link($zona));
        $this->assertSame('/bodega/pedidos/status?search=PED-2026-0080', $this->link($stock));
        $this->assertSame('/bodega/pedidos/status?search=PED-2026-0081', $this->link($asistente));
        $this->assertSame('/admin-contab/facturacion', $this->link($facturar));
        $this->assertSame('/ventas-diseno/pipeline', $this->link($propuesta));
        $this->assertSame('/otro/destino', $this->link($conEnlace));
        $this->assertNull($this->link($otro));

        $content = json_decode((string) DB::table('atlantic_notifications.notifications')->where('id', $zona)->value('content'), true);
        $this->assertSame('/bodega/ordenes-zona-libre', $content['cta_url']);
        $this->assertSame('Recordatorio: solicitud Zona Libre #3 pendiente de aprobar', $content['subject']);
    }

    public function test_a_lideroperaciones_se_le_quita_solo_la_fila_de_ventas_y_diseno(): void
    {
        $lideroperaciones = (int) User::where('email', 'lideroperaciones@test.com')->value('id');
        $otra   = (int) User::where('email', 'contabilidad@test.com')->value('id');
        $t      = 'atlantic_auth.user_module_visibility';
        DB::table($t)->whereIn('user_id', [$lideroperaciones, $otra])->delete();

        foreach ([[$lideroperaciones, 'ventas_diseno'], [$lideroperaciones, 'compras'], [$otra, 'ventas_diseno']] as [$uid, $modulo]) {
            DB::table($t)->insert(['user_id' => $uid, 'module' => $modulo, 'can_view' => 2, 'can_view_team' => true]);
        }

        $migracion = $this->migration('2026_10_18_120000_remove_ventas_diseno_visibility_from_*.php');
        $migracion->up();
        $migracion->up(); // idempotente

        $this->assertFalse(DB::table($t)->where('user_id', $lideroperaciones)->where('module', 'ventas_diseno')->exists());
        $this->assertTrue(DB::table($t)->where('user_id', $lideroperaciones)->where('module', 'compras')->exists());
        $this->assertTrue(DB::table($t)->where('user_id', $otra)->where('module', 'ventas_diseno')->exists());
    }
}
