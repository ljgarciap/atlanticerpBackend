<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\Bodega\Models\Order;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\ClienteRucCorreccion;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\ClienteRucCorrector;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1137 F3 — `legacy:fix-clientes-ruc`: corrige `sub_clients.tax_id`/`tax_id_dv` truncados. Cubre las 3 precondiciones, conflictos, dry-run, apply, idempotencia,
 * reversa, casos raros (que quedan intactos) y que la consola nunca muestre RUC completos ni nombres.
 */
final class LegacyFixClientesRucCommandTest extends FeatureTestCase
{
    private const RUC_LARGO = '155716554-2-2021';
    private const CIF_12    = '155716554-2-';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tenant();
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, mixed> $opts */
    private function fix(array $opts = []): string
    {
        $code = Artisan::call('legacy:fix-clientes-ruc', ['--tenant' => 'atlantic'] + $opts);
        $out  = Artisan::output();
        $this->tenant();
        $this->lastExit = $code;

        return $out;
    }

    private int $lastExit = 0;

    private function huella(): string
    {
        $c = new ClienteRucCorrector();

        return $c->huella($c->plan());
    }

    /** @param array<string, mixed> $extra */
    private function aplicar(array $extra = []): string
    {
        return $this->fix($extra + ['--apply' => true, '--operador' => 'luis', '--esperado' => $this->huella(), '--yes' => true]);
    }

    /** Factura (y opcionalmente nota de crédito) con el estado FE dado, colgando del sub_client por el camino pedido -> tarjeta. */
    private function conDocumento(SubClient $sub, ?string $estadoFactura, ?string $estadoNota = null): void
    {
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto '.uniqid()]);
        $ownerId = (int) DB::table('atlantic_auth.users')->where('email', 'contabilidad@test.com')->value('id');
        $card    = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $sub->master_client_id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => 100, 'stage_changed_at' => Carbon::now(),
        ]);
        $order = Order::create([
            'order_number' => 'PED-2026-'.random_int(1000, 999999), 'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_POR_DESPACHAR, 'customer_name' => $sub->business_name, 'pipeline_card_id' => $card->id,
        ]);
        $quote = Quote::create([
            'owner_id' => $ownerId, 'master_client_id' => $sub->master_client_id, 'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id, 'folio' => 'COT-2026-'.static::uniqueTestNumber(), 'generated_at' => Carbon::now(),
        ]);
        $factura = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => $order->id, 'quote_id' => $quote->id,
            'itbms_rate_id' => AdminContItbmsRate::where('nombre', '7% — General')->firstOrFail()->id, 'sales_project_id' => $project->id,
            'master_client_id' => $sub->master_client_id, 'cliente' => $sub->business_name, 'monto' => 100, 'saldo_aplicado' => 0.0,
        ]);
        $factura->forceFill(['fe_estado' => $estadoFactura])->save();
        if ($estadoNota !== null) {
            $nota = AdminContCreditNote::create([
                'numero' => 'NC-'.static::uniqueTestNumber(), 'master_client_id' => $sub->master_client_id, 'cliente' => $sub->business_name,
                'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'factura_origen_id' => $factura->id, 'monto' => 10, 'subtotal' => 9.35, 'itbms' => 0.65,
                'motivo' => 'Descuento', 'estado' => AdminContCreditNote::ESTADO_APLICADA, 'registrado_por' => 1, 'fecha' => now()->toDateString(),
            ]);
            $nota->forceFill(['fe_estado' => $estadoNota])->save();
        }
    }

    public static function estadosBloqueantes(): array
    {
        return ['certificado' => ['certificado'], 'pendiente' => ['pendiente']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('estadosBloqueantes')]
    public function test_un_sub_client_con_factura_certificada_o_pendiente_se_omite(string $estado): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->cliente(1050, $sub, self::CIF_12, self::RUC_LARGO, '35');
        $this->conDocumento($sub, $estado);

        $this->aplicar();

        $this->assertSame(self::CIF_12, $sub->fresh()->tax_id);
        $this->assertSame(0, ClienteRucCorreccion::query()->count());
        $this->assertStringContainsString('tiene_documentos_fiscales', Storage::disk('local')->get($this->csvDeLaCorrida()));
    }

    public function test_un_sub_client_con_solo_una_nota_de_credito_certificada_se_omite(): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->cliente(1051, $sub, self::CIF_12, self::RUC_LARGO, '35');
        $this->conDocumento($sub, 'rechazado', 'certificado');

        $this->aplicar();

        $this->assertSame(self::CIF_12, $sub->fresh()->tax_id);
    }

    public function test_documentos_en_estados_no_bloqueantes_no_impiden_la_correccion(): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->cliente(1052, $sub, self::CIF_12, self::RUC_LARGO, '35');
        $this->conDocumento($sub, 'rechazado');

        $this->aplicar();

        $this->assertSame(self::RUC_LARGO, $sub->fresh()->tax_id);
    }

    public function test_si_gana_un_documento_fiscal_entre_el_plan_y_el_apply_se_omite_dentro_de_la_transaccion(): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->cliente(1053, $sub, self::CIF_12, self::RUC_LARGO, '35');

        $c    = new ClienteRucCorrector();
        $plan = $c->plan();
        $this->conDocumento($sub, 'certificado');   // se emite una factura después del plan
        $res = $c->aplicar($plan, 'luis', 'testing', (string) \Illuminate\Support\Str::uuid());

        $this->assertSame(0, $res['corregidos']);
        $this->assertSame(self::CIF_12, $sub->fresh()->tax_id);
    }

    public function test_revert_de_un_sub_client_que_gano_documentos_fiscales_se_omite(): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->cliente(1054, $sub, self::CIF_12, self::RUC_LARGO, '35');
        $this->aplicar();
        $runId = ClienteRucCorreccion::query()->firstOrFail()->run_id;
        $this->conDocumento($sub, 'certificado');

        $out = $this->fix(['--revert' => $runId, '--operador' => 'luis']);

        $this->assertSame(self::RUC_LARGO, $sub->fresh()->tax_id);
        $this->assertNull(ClienteRucCorreccion::query()->firstOrFail()->revertida_at);
        $this->assertStringContainsString('tiene_documentos_fiscales', $out);
    }

    public function test_un_cif_de_menos_de_12_caracteres_no_esta_truncado_y_se_omite(): void
    {
        $sub = $this->sub('8-123-456');
        $this->cliente(1055, $sub, '8-123-456', '8-123-4567', null);

        $this->aplicar();

        $this->assertSame('8-123-456', $sub->fresh()->tax_id);
        $this->assertStringContainsString('cif_no_truncado', Storage::disk('local')->get($this->csvDeLaCorrida()));
    }

    public function test_dv_distinto_se_omite_y_dv_igual_corrige(): void
    {
        $distinto = $this->sub(self::CIF_12, 'Dv distinto', '77');
        $this->cliente(1056, $distinto, self::CIF_12, self::RUC_LARGO, '35');
        $igual = $this->sub('987695-1-533', 'Dv igual', '09');
        $this->cliente(1057, $igual, '987695-1-533', '987695-1-533551', '09');

        $this->aplicar();

        $this->assertSame(self::CIF_12, $distinto->fresh()->tax_id);
        $this->assertSame('77', $distinto->fresh()->tax_id_dv);
        $this->assertSame('987695-1-533551', $igual->fresh()->tax_id);
        $this->assertStringContainsString('dv_distinto', Storage::disk('local')->get($this->csvDeLaCorrida()));
    }

    public function test_interrupcion_a_mitad_deja_lo_aplicado_revertible_con_el_run_id_impreso(): void
    {
        $a = $this->sub(self::CIF_12, 'A');
        $b = $this->sub('987695-1-533', 'B');
        $this->cliente(1060, $a, self::CIF_12, self::RUC_LARGO, '35');
        $this->cliente(1061, $b, '987695-1-533', '987695-1-533551', '09');

        $n = 0;
        ClienteRucCorrector::$despuesDeGuardar = static function () use (&$n): void {
            if (++$n === 2) {
                throw new \RuntimeException('corte simulado');
            }
        };
        try {
            $out = $this->aplicar();
        } finally {
            ClienteRucCorrector::$despuesDeGuardar = null;
        }

        $this->assertSame(1, $this->lastExit);
        $this->assertSame(self::RUC_LARGO, $a->fresh()->tax_id);
        $this->assertSame('987695-1-533', $b->fresh()->tax_id);
        $runId = ClienteRucCorreccion::query()->firstOrFail()->run_id;
        $this->assertStringContainsString("run_id: {$runId}", $out);
        $run = SyncRun::query()->where('entidad', 'clientes_ruc_fix')->firstOrFail();
        $this->assertSame(SyncRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(1, $run->cargados);
        $this->assertSame(1, AuditLog::query()->where('action', 'legacy.fix_clientes_ruc')->where('metadata->run_id', $runId)->count());

        $this->fix(['--revert' => $runId, '--operador' => 'luis']);
        $this->assertSame(self::CIF_12, $a->fresh()->tax_id);
    }

    public function test_el_csv_neutraliza_formulas(): void
    {
        $s1 = $this->sub('=1+1', 'F1');
        $this->cliente(1070, $s1, '=1+1', '=1+1', null, Cliente::MATCH_MATCHED, ['ruc_estado' => 'revisar']);
        $s2 = $this->sub('@x', 'F2');
        $this->cliente(1071, $s2, '-5-2', '-5-2', null, Cliente::MATCH_MATCHED, ['ruc_estado' => 'revisar']);

        $this->fix();

        $csv = Storage::disk('local')->get('legacy/ruc-correcciones-dry.csv');
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString("'@x", $csv);
        $this->assertStringContainsString("'-5-2", $csv);
        $this->assertStringNotContainsString(',=1+1', $csv);
        $this->assertStringNotContainsString(',-5-2', $csv);
        $this->assertStringNotContainsString(',@x', $csv);
    }

    public function test_el_dry_run_imprime_cantidad_y_huella_y_apply_exige_la_huella_y_la_frase(): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->cliente(1080, $sub, self::CIF_12, self::RUC_LARGO, '35');

        $out = $this->fix();
        $this->assertMatchesRegularExpression('/A corregir: 1\. Huella del plan: ([0-9a-f]{12})/', $out);
        preg_match('/Huella del plan: ([0-9a-f]{12})/', $out, $m);
        $huella = $m[1];

        // sin huella
        $this->fix(['--apply' => true, '--operador' => 'luis', '--yes' => true]);
        $this->assertSame(1, $this->lastExit);
        // huella distinta: aborta sin escribir
        $this->fix(['--apply' => true, '--operador' => 'luis', '--yes' => true, '--esperado' => 'deadbeef']);
        $this->assertSame(1, $this->lastExit);
        // frase incorrecta
        $this->fix(['--apply' => true, '--operador' => 'luis', '--esperado' => $huella, '--confirmar' => 'APLICAR prod atlantic']);
        $this->assertSame(1, $this->lastExit);
        $this->assertSame(self::CIF_12, $sub->fresh()->tax_id);
        $this->assertSame(0, SyncRun::query()->where('entidad', 'clientes_ruc_fix')->count());

        // frase exacta, sin --yes
        $this->fix(['--apply' => true, '--operador' => 'luis', '--esperado' => $huella, '--confirmar' => 'APLICAR testing atlantic']);
        $this->assertSame(0, $this->lastExit);
        $this->assertSame(self::RUC_LARGO, $sub->fresh()->tax_id);
    }

    public function test_si_el_plan_cambia_despues_de_la_revision_apply_aborta(): void
    {
        $a = $this->sub(self::CIF_12);
        $this->cliente(1081, $a, self::CIF_12, self::RUC_LARGO, '35');
        $huella = $this->huella();
        $b = $this->sub('987695-1-533', 'B');
        $this->cliente(1082, $b, '987695-1-533', '987695-1-533551', '09');   // aparece algo nuevo tras la revisión

        $this->fix(['--apply' => true, '--operador' => 'luis', '--yes' => true, '--esperado' => $huella]);

        $this->assertSame(1, $this->lastExit);
        $this->assertSame(self::CIF_12, $a->fresh()->tax_id);
    }

    private function sub(string $taxId, string $nombre = 'Empresa Reservada SA', ?string $dv = null): SubClient
    {
        $master = MasterClient::query()->create(['name' => $nombre]);

        return SubClient::query()->create(['master_client_id' => $master->id, 'business_name' => $nombre, 'tax_id' => $taxId, 'tax_id_dv' => $dv]);
    }

    /** @param array<string, mixed> $extra */
    private function cliente(int $codigo, ?SubClient $sub, ?string $cif, ?string $ruc, ?string $dv = null, string $status = Cliente::MATCH_MATCHED, array $extra = []): Cliente
    {
        return Cliente::query()->create($extra + [
            'icg_codcliente'   => $codigo,
            'nombre_comercial' => 'Nombre Comercial '.$codigo,
            'razon_social'     => 'Razon Social Privada '.$codigo,
            'cif'              => $cif,
            'nif20'            => $ruc,
            'ruc'              => $ruc,
            'ruc_dv'           => $dv,
            'ruc_origen'       => 'nif20',
            'ruc_estado'       => 'ok',
            'sub_client_id'    => $sub?->id,
            'match_status'     => $status,
            'bloqueado'        => false,
        ]);
    }

    public function test_apply_corrige_tax_id_y_dv_audita_y_registra_la_reversa(): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->cliente(1001, $sub, self::CIF_12, self::RUC_LARGO, '35');

        $out = $this->aplicar();
        $this->assertSame(0, $this->lastExit, $out);

        $sub->refresh();
        $this->assertSame(self::RUC_LARGO, $sub->tax_id);
        $this->assertSame('35', $sub->tax_id_dv);

        $corr = ClienteRucCorreccion::query()->firstOrFail();
        $this->assertSame(self::CIF_12, $corr->tax_id_anterior);
        $this->assertNull($corr->tax_id_dv_anterior);
        $this->assertSame(self::RUC_LARGO, $corr->tax_id_nuevo);
        $this->assertSame('35', $corr->tax_id_dv_nuevo);
        $this->assertSame('luis', $corr->operador);
        $this->assertNull($corr->revertida_at);

        $audit = AuditLog::query()->where('action', 'ventas_diseno.sub_client.updated')->where('entity_id', (string) $sub->id)->latest('id')->firstOrFail();
        $this->assertSame(self::CIF_12, $audit->old_values['tax_id']);
        $this->assertSame(self::RUC_LARGO, $audit->new_values['tax_id']);
        $this->assertSame('35', $audit->new_values['tax_id_dv']);

        $run = SyncRun::query()->where('entidad', 'clientes_ruc_fix')->firstOrFail();
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->cargados);
        $this->assertStringContainsString($corr->run_id, $out);
    }

    public function test_sin_apply_no_escribe_ni_registra_nada_pero_deja_el_csv(): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->cliente(1002, $sub, self::CIF_12, self::RUC_LARGO, '35');

        $out = $this->fix();
        $this->assertSame(0, $this->lastExit);
        $out2 = $this->fix(['--dry-run' => true]);
        $this->assertSame(0, $this->lastExit);

        $this->assertSame(self::CIF_12, $sub->fresh()->tax_id);
        $this->assertNull($sub->fresh()->tax_id_dv);
        $this->assertSame(0, ClienteRucCorreccion::query()->count());
        $this->assertSame(0, SyncRun::query()->where('entidad', 'clientes_ruc_fix')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'ventas_diseno.sub_client.updated')->count());
        $this->assertStringContainsString('dry-run', $out.$out2);

        Storage::disk('local')->assertExists('legacy/ruc-correcciones-dry.csv');
        $csv = Storage::disk('local')->get('legacy/ruc-correcciones-dry.csv');
        $this->assertStringContainsString('icg_codcliente,sub_client_id,tax_id_actual,tax_id_propuesto,dv_actual,dv_propuesto,accion,motivo', $csv);
        $this->assertStringContainsString(self::RUC_LARGO, $csv);
        $this->assertStringContainsString('corregir', $csv);
    }

    public function test_apply_sin_operador_falla_sin_tocar_nada(): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->cliente(1003, $sub, self::CIF_12, self::RUC_LARGO);

        $this->fix(['--apply' => true, '--esperado' => $this->huella(), '--yes' => true]);
        $this->assertSame(1, $this->lastExit);
        $this->fix(['--apply' => true, '--operador' => '   ', '--esperado' => $this->huella(), '--yes' => true]);
        $this->assertSame(1, $this->lastExit);
        $this->assertSame(self::CIF_12, $sub->fresh()->tax_id);
    }

    public function test_precondicion_a_tax_id_editado_a_mano_se_omite(): void
    {
        $sub = $this->sub('OTRO-VALOR-9');
        $this->cliente(1004, $sub, self::CIF_12, self::RUC_LARGO, '35');

        $this->aplicar();

        $this->assertSame('OTRO-VALOR-9', $sub->fresh()->tax_id);
        $this->assertSame(0, ClienteRucCorreccion::query()->count());
        $this->assertStringContainsString('tax_id_distinto_del_cif', Storage::disk('local')->get($this->csvDeLaCorrida()));
    }

    public function test_precondicion_b_ruc_que_no_extiende_al_cif_se_omite(): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->cliente(1005, $sub, self::CIF_12, '999888777-1-2021', '10');

        $this->aplicar();

        $this->assertSame(self::CIF_12, $sub->fresh()->tax_id);
        $this->assertStringContainsString('ruc_no_extiende_al_cif', Storage::disk('local')->get($this->csvDeLaCorrida()));
    }

    public function test_precondicion_c_sub_client_compartido_con_ruc_distintos_se_omite(): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->cliente(1006, $sub, self::CIF_12, self::RUC_LARGO, '35');
        $this->cliente(1007, $sub, self::CIF_12, '155716554-2-9999', '35');

        $this->aplicar();

        $this->assertSame(self::CIF_12, $sub->fresh()->tax_id);
        $this->assertSame(0, ClienteRucCorreccion::query()->count());
        $this->assertStringContainsString('sub_client_compartido_con_ruc_distinto', Storage::disk('local')->get($this->csvDeLaCorrida()));
    }

    public function test_sub_client_compartido_con_el_mismo_ruc_se_corrige_una_sola_vez(): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->cliente(1008, $sub, self::CIF_12, self::RUC_LARGO, '35');
        $this->cliente(1009, $sub, self::CIF_12, self::RUC_LARGO, '35');

        $this->aplicar();

        $this->assertSame(self::RUC_LARGO, $sub->fresh()->tax_id);
        $this->assertSame(1, ClienteRucCorreccion::query()->count());
    }

    public function test_conflicto_con_otro_sub_client_que_ya_tiene_ese_tax_id_se_omite(): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->sub('155716554-2-2021', 'Otra Empresa');
        $this->cliente(1010, $sub, self::CIF_12, self::RUC_LARGO, '35');

        $this->aplicar();

        $this->assertSame(self::CIF_12, $sub->fresh()->tax_id);
        $this->assertStringContainsString('conflicto_otro_sub_client', Storage::disk('local')->get($this->csvDeLaCorrida()));
    }

    public function test_dos_propuestas_al_mismo_tax_id_se_omiten_ambas(): void
    {
        $a = $this->sub(self::CIF_12, 'A');
        $b = $this->sub(self::CIF_12, 'B');
        $this->cliente(1011, $a, self::CIF_12, self::RUC_LARGO);
        $this->cliente(1012, $b, self::CIF_12, self::RUC_LARGO);

        $this->aplicar();

        $this->assertSame(self::CIF_12, $a->fresh()->tax_id);
        $this->assertSame(self::CIF_12, $b->fresh()->tax_id);
        $this->assertSame(0, ClienteRucCorreccion::query()->count());
    }

    public function test_dv_no_se_pisa_ni_se_inventa(): void
    {
        $conDv = $this->sub(self::CIF_12, 'Con DV', '77');
        $this->cliente(1013, $conDv, self::CIF_12, self::RUC_LARGO, null);
        $sinDvIcg = $this->sub('1234567-1-2-', 'Cedula');
        $this->cliente(1014, $sinDvIcg, '1234567-1-2-', '1234567-1-2-345', null);

        $this->aplicar();

        $conDv->refresh();
        $this->assertSame(self::RUC_LARGO, $conDv->tax_id);
        $this->assertSame('77', $conDv->tax_id_dv);

        $sinDvIcg->refresh();
        $this->assertSame('1234567-1-2-345', $sinDvIcg->tax_id);
        $this->assertNull($sinDvIcg->tax_id_dv);
    }

    public function test_idempotente_el_segundo_apply_no_cambia_nada(): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->cliente(1015, $sub, self::CIF_12, self::RUC_LARGO, '35');

        $this->aplicar();
        $auditoriasAntes = AuditLog::query()->where('action', 'ventas_diseno.sub_client.updated')->count();

        $this->aplicar();

        $this->assertSame(0, $this->lastExit);
        $this->assertSame(1, ClienteRucCorreccion::query()->count());
        $this->assertSame($auditoriasAntes, AuditLog::query()->where('action', 'ventas_diseno.sub_client.updated')->count());
        $this->assertSame(self::RUC_LARGO, $sub->fresh()->tax_id);
    }

    public function test_revert_restaura_exacto_y_es_idempotente(): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->cliente(1016, $sub, self::CIF_12, self::RUC_LARGO, '35');
        $this->aplicar();
        $runId = ClienteRucCorreccion::query()->firstOrFail()->run_id;

        $this->fix(['--revert' => $runId, '--operador' => 'luis']);
        $this->assertSame(0, $this->lastExit);
        $sub->refresh();
        $this->assertSame(self::CIF_12, $sub->tax_id);
        $this->assertNull($sub->tax_id_dv);
        $this->assertNotNull(ClienteRucCorreccion::query()->firstOrFail()->revertida_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'ventas_diseno.sub_client.updated')->where('entity_id', (string) $sub->id)->where('new_values->tax_id', self::CIF_12)->count());

        $this->fix(['--revert' => $runId, '--operador' => 'luis']);
        $this->assertSame(0, $this->lastExit);
        $this->assertSame(self::CIF_12, $sub->fresh()->tax_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'ventas_diseno.sub_client.updated')->where('entity_id', (string) $sub->id)->where('new_values->tax_id', self::CIF_12)->count());
    }

    public function test_revert_de_un_valor_editado_despues_se_omite(): void
    {
        $sub = $this->sub(self::CIF_12);
        $this->cliente(1017, $sub, self::CIF_12, self::RUC_LARGO, '35');
        $this->aplicar();
        $runId = ClienteRucCorreccion::query()->firstOrFail()->run_id;

        $sub->refresh();
        $sub->tax_id = 'EDITADO-A-MANO';
        $sub->save();

        $out = $this->fix(['--revert' => $runId, '--operador' => 'luis']);

        $this->assertSame('EDITADO-A-MANO', $sub->fresh()->tax_id);
        $this->assertNull(ClienteRucCorreccion::query()->firstOrFail()->revertida_at);
        $this->assertStringContainsString('editado_despues_de_la_correccion', $out);
    }

    public function test_revert_exige_operador_y_run_id_existente(): void
    {
        $this->fix(['--revert' => '00000000-0000-4000-8000-000000000000', '--operador' => 'luis']);
        $this->assertSame(1, $this->lastExit);
        $this->fix(['--revert' => 'no-es-uuid', '--operador' => 'luis']);
        $this->assertSame(1, $this->lastExit);
        $this->fix(['--revert' => '00000000-0000-4000-8000-000000000000']);
        $this->assertSame(1, $this->lastExit);
    }

    public function test_casos_raros_quedan_intactos_y_se_listan_como_omitir(): void
    {
        $s7340 = $this->sub('RUC 1671600-', 'Caso 7340');
        $this->cliente(7340, $s7340, 'RUC 1671600-', 'RUC 1671600-', null, Cliente::MATCH_MATCHED, ['nif20' => null, 'ruc_origen' => 'cif', 'ruc_estado' => 'revisar']);
        $s7759 = $this->sub('155662320 2', 'Caso 7759');
        $this->cliente(7759, $s7759, '155662320 2', '155662320 2', null, Cliente::MATCH_PROMOTED, ['ruc_estado' => 'revisar']);
        $s5620 = $this->sub('6-712-881', 'Caso 5620');
        $this->cliente(5620, $s5620, '6-712-881', '6-712-881', null, Cliente::MATCH_MATCHED, ['ruc_estado' => 'truncado_posible', 'nif20' => null, 'ruc_origen' => 'cif']);

        $this->aplicar();

        $this->assertSame('RUC 1671600-', $s7340->fresh()->tax_id);
        $this->assertSame('155662320 2', $s7759->fresh()->tax_id);
        $this->assertSame('6-712-881', $s5620->fresh()->tax_id);
        $this->assertNull($s5620->fresh()->tax_id_dv);
        $this->assertSame(0, ClienteRucCorreccion::query()->count());

        $csv = Storage::disk('local')->get($this->csvDeLaCorrida());
        $this->assertStringContainsString('ruc_estado_revisar', $csv);
        $this->assertStringContainsString('ruc_estado_truncado_posible', $csv);
        $this->assertStringContainsString('7340', $csv);
        $this->assertStringContainsString('5620', $csv);
    }

    public function test_matched_y_promoted_son_candidatos_y_unmatched_no(): void
    {
        $m = $this->sub(self::CIF_12, 'M');
        $p = $this->sub('987695-1-533', 'P');
        $u = $this->sub('123456789-0-', 'U');
        $this->cliente(1020, $m, self::CIF_12, self::RUC_LARGO, '35', Cliente::MATCH_MATCHED);
        $this->cliente(1021, $p, '987695-1-533', '987695-1-533551', '09', Cliente::MATCH_PROMOTED);
        $this->cliente(1022, $u, '123456789-0-', '123456789-0-1111', '01', Cliente::MATCH_UNMATCHED);

        $this->aplicar();

        $this->assertSame(self::RUC_LARGO, $m->fresh()->tax_id);
        $this->assertSame('987695-1-533551', $p->fresh()->tax_id);
        $this->assertSame('09', $p->fresh()->tax_id_dv);
        $this->assertSame('123456789-0-', $u->fresh()->tax_id);
    }

    public function test_la_consola_no_muestra_rucs_completos_ni_nombres(): void
    {
        $sub = $this->sub(self::CIF_12, 'Empresa Reservada SA');
        $this->cliente(1030, $sub, self::CIF_12, self::RUC_LARGO, '35');
        $raro = $this->sub('RUC 1671600-', 'Otra Empresa Secreta');
        $this->cliente(1031, $raro, 'RUC 1671600-', 'RUC 1671600-', null, Cliente::MATCH_MATCHED, ['ruc_estado' => 'revisar']);

        $out  = $this->fix();
        $out .= $this->aplicar();

        foreach (['155716554', self::RUC_LARGO, '1671600', 'Empresa Reservada', 'Otra Empresa', 'Razon Social', 'Nombre Comercial'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $out);
        }
        $this->assertStringContainsString('1030', $out.Storage::disk('local')->get($this->csvDeLaCorrida()));
    }

    public function test_tenant_inexistente_falla_con_mensaje_claro(): void
    {
        $code = Artisan::call('legacy:fix-clientes-ruc', ['--tenant' => 'no-existe']);
        $this->tenant();

        $this->assertSame(1, $code);
        $this->assertStringContainsString("Tenant 'no-existe' no existe", Artisan::output());
    }

    public function test_csv_fuera_del_storage_privado_se_rechaza_y_uno_valido_se_acepta(): void
    {
        $this->fix(['--csv' => '/tmp/ruc.csv']);
        $this->assertSame(1, $this->lastExit);
        $this->fix(['--csv' => '../fuera.csv']);
        $this->assertSame(1, $this->lastExit);

        $this->fix(['--csv' => 'legacy/mi-revision.csv']);
        $this->assertSame(0, $this->lastExit);
        Storage::disk('local')->assertExists('legacy/mi-revision.csv');
    }

    public function test_un_fallo_de_verificacion_revierte_solo_ese_sub_client(): void
    {
        $a = $this->sub(self::CIF_12, 'A');
        $b = $this->sub('987695-1-533', 'B');
        $this->cliente(1040, $a, self::CIF_12, self::RUC_LARGO, '35');
        $this->cliente(1041, $b, '987695-1-533', '987695-1-533551', '09');

        ClienteRucCorrector::$despuesDeGuardar = static function (SubClient $s): void {
            if ($s->tax_id === self::RUC_LARGO) {
                // Simula un efecto concurrente que deja la fila distinta de lo aplicado.
                SubClient::query()->whereKey($s->id)->getQuery()->update(['tax_id' => 'ALTERADO']);
            }
        };
        try {
            $this->aplicar();
        } finally {
            ClienteRucCorrector::$despuesDeGuardar = null;
        }

        $this->assertSame(1, $this->lastExit);
        $this->assertSame(self::CIF_12, $a->fresh()->tax_id);
        $this->assertSame('987695-1-533551', $b->fresh()->tax_id);
        $this->assertSame(1, ClienteRucCorreccion::query()->count());
        $this->assertSame(SyncRun::STATUS_PARTIAL, SyncRun::query()->where('entidad', 'clientes_ruc_fix')->firstOrFail()->status);
    }

    /** Ruta del CSV de la última corrida (el único archivo en legacy/). */
    private function csvDeLaCorrida(): string
    {
        $files = Storage::disk('local')->files('legacy');
        $this->assertNotEmpty($files);

        return (string) end($files);
    }
}
