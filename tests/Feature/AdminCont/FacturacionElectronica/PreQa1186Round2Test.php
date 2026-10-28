<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Models\User;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalCertificationService;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalTotalAligner;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Models\NotificationSend;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

final class PreQa1186Round2Test extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
        config([
            'services.msl_fe.sandbox'    => ['base_url' => 'https://sandbox.msl.test', 'api_key' => 'sandbox-key'],
            'services.msl_fe.production' => ['base_url' => 'https://prod.msl.test', 'api_key' => 'prod-secret-key-123'],
        ]);
        Queue::fake();
    }

    public function test_api_valores_raros(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
        $this->reTenant();
        $id = (int) User::where('email', 'vendedor@atlantic.test')->value('id');
        $inactivo = User::where('email', 'like', '%@atlantic.test')->whereNotIn('email', ['superadmin@atlantic.test','vendedor@atlantic.test'])->first();
        $inactivo->forceFill(['is_active' => false])->save();
        $casos = [
            'string num' => [(string) $id], 'float' => [(float) $id + 0.0], 'float1.5' => [1.5], 'null elem' => [null], 'anidado' => [[$id]],
            'objeto' => ['a' => $id], 'negativo' => [-1], 'cero' => [0], 'true' => [true], 'inactivo' => [$inactivo->id],
            '21 ids' => range(1, 21), 'escalar' => 5, 'string' => 'x', 'null' => null, 'dup' => [$id, $id], 'exp' => ['1e1'],
            'ok' => [$id], 'vacio' => [],
        ];
        $res = [];
        foreach ($casos as $nombre => $valor) {
            $r = $this->withHeaders($this->authHeaders($token))->putJson('/api/admin-contab/fiscal-settings', ['fe_rechazo_notificar_user_ids' => $valor]);
            $this->reTenant();
            $res[] = "$nombre=".$r->status();
        }
        fwrite(STDERR, "\nAPI ".implode(' ', $res)."\n");
        $this->reTenant();
        fwrite(STDERR, 'GUARDADO FINAL '.json_encode(AdminContFiscalSettings::current()->fe_rechazo_notificar_user_ids)."\n");
        // el rol designer no
        $t2 = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $r = $this->withHeaders($this->authHeaders($t2))->putJson('/api/admin-contab/fiscal-settings', ['fe_rechazo_notificar_user_ids' => [$id]]);
        fwrite(STDERR, 'DESIGNER '.$r->status()."\n");
        $this->assertSame(403, $r->status());
    }

    public function test_migracion_idempotente_y_down(): void
    {
        $this->reTenant();
        $archivo = base_path('database/migrations/tenant/2026_10_21_200000_add_fe_rechazo_notificar_user_ids_to_fiscal_settings.php');
        $tabla = (new AdminContFiscalSettings())->getTable();
        AdminContFiscalSettings::current()->forceFill(['fe_rechazo_notificar_user_ids' => [7]])->save();
        $m = require $archivo;
        $m->up();    // 2ª corrida con lista no vacía: no pisa
        $this->reTenant();
        $this->assertSame([7], AdminContFiscalSettings::current()->fe_rechazo_notificar_user_ids);
        $m->down();
        $this->assertFalse(Schema::hasColumn($tabla, 'fe_rechazo_notificar_user_ids'));
        $m->up();
        $this->assertTrue(Schema::hasColumn($tabla, 'fe_rechazo_notificar_user_ids'));
        $fila = DB::table($tabla)->first();
        fwrite(STDERR, "\nMIGRACION tras down/up: ".$fila->fe_rechazo_notificar_user_ids."\n");
        $m->up();    // tercera vez, sin error
        $this->assertTrue(true);
    }

    public function test_texto_nc_y_correo_renderizado_y_alineador_que_lanza(): void
    {
        $this->app['env'] = 'production';
        $dest = (int) User::where('email', 'vendedor@atlantic.test')->value('id');
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => 'production', 'fe_rechazo_notificar_user_ids' => [$dest]])->save();
        ['root' => $root] = $this->makePedido([['desc' => 'Panel XL', 'qty' => 9, 'price' => 100.00]], [[9]]);
        Http::fake(['prod.msl.test/*' => Http::sequence()
            ->push(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE01-x', 'qr' => 'https://qr', 'xmlFirmado' => base64_encode('<x/>')], 200)
            ->push(['ok' => false, 'estado' => 'RECHAZADO', 'error' => 'Falla RUC 8-123-4567 DV 12 x-api-key: prod-secret-key-123', 'detalles' => ['Falla RUC 8-123-4567 DV 12']], 422)]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $this->reTenant();
        $f = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
        $this->assertSame('certificado', $f->fe_estado);
        $nota = AdminContCreditNote::create([
            'numero' => 'NC-R2-'.self::uniqueTestNumber(), 'master_client_id' => $f->master_client_id ?? 1, 'cliente' => $f->cliente,
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'factura_origen_id' => $f->id, 'monto' => 100, 'subtotal' => 93.46, 'itbms' => 6.54,
            'motivo' => 'x', 'estado' => AdminContCreditNote::ESTADO_APLICADA, 'registrado_por' => $this->ownerId(), 'fecha' => now()->toDateString(),
        ]);
        app(FiscalCertificationService::class)->certificarNotaCredito($nota);
        $a = NotificationSend::query()->where('source', NotificationSend::SOURCE_FE_DOCUMENTO_RECHAZADO)->latest('id')->first();
        $this->assertNotNull($a);
        fwrite(STDERR, "\nNC-ASUNTO ".$a->content['subject']."\nNC-CUERPO\n".$a->content['body']."\nCTA ".$a->content['cta_url']."\n");
        $html = (new NotificationMail($a->content['subject'], $a->content['body'], $a->content['cta_url']))->render();
        $this->assertStringNotContainsString('prod-secret-key-123', $html);
        $this->assertStringNotContainsString('8-123-4567', $html);
        fwrite(STDERR, 'HTML-cta '.(preg_match('#href="([^"]*notas-credito[^"]*)"#', $html, $m) ? $m[1] : 'SIN LINK')."\n");
    }
}
