<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\ClienteRucCorreccion;
use App\Modules\Legacy\Models\ClienteRucCotizacionCorreccion;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\ClienteRucCotizacionSync;
use App\Modules\VentasDiseno\Models\MasterClient;
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
 * SCRUM-1137 F3 (cotizaciones) — `legacy:fix-clientes-ruc --cotizaciones`: ajusta `quotes.ruc` al RUC ya corregido del sub_client. Cubre el caso feliz con su
 * reversa y su auditoría, lo que nunca se toca, idempotencia, huella/confirmación, reversa que respeta ediciones posteriores y que la consola no muestre RUC.
 */
final class LegacyFixCotizacionesRucCommandTest extends FeatureTestCase
{
    private const RUC_LARGO = '155716554-2-2021';
    private const CIF_12    = '155716554-2-';

    private int $lastExit = 0;

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

    private function huellaCot(): string
    {
        $s = new ClienteRucCotizacionSync();

        return $s->huella($s->plan());
    }

    /** @param array<string, mixed> $extra */
    private function aplicarCot(array $extra = []): string
    {
        return $this->fix($extra + ['--cotizaciones' => true, '--apply' => true, '--operador' => 'luis', '--esperado' => $this->huellaCot(), '--yes' => true]);
    }

    /** Sub_client ya corregido (tax_id_nuevo) con su registro de reversa de sub_clients, como lo deja `ClienteRucCorrector`. */
    private function subCorregido(int $codigo = 1001, bool $revertida = false): SubClient
    {
        $master = MasterClient::query()->create(['name' => 'Empresa Reservada '.$codigo]);
        $sub    = SubClient::query()->create(['master_client_id' => $master->id, 'business_name' => 'Empresa Reservada '.$codigo, 'tax_id' => self::RUC_LARGO]);
        Cliente::query()->create([
            'icg_codcliente' => $codigo, 'razon_social' => 'Razon Social Privada '.$codigo, 'cif' => self::CIF_12, 'nif20' => self::RUC_LARGO, 'ruc' => self::RUC_LARGO,
            'ruc_origen' => 'nif20', 'ruc_estado' => 'ok', 'sub_client_id' => $sub->id, 'match_status' => Cliente::MATCH_MATCHED, 'bloqueado' => false,
        ]);
        ClienteRucCorreccion::query()->create([
            'run_id' => (string) \Illuminate\Support\Str::uuid(), 'sub_client_id' => $sub->id, 'icg_codcliente' => $codigo,
            'tax_id_anterior' => self::CIF_12, 'tax_id_nuevo' => self::RUC_LARGO, 'operador' => 'luis', 'aplicada_at' => now(),
            'revertida_at' => $revertida ? now() : null,
        ]);

        return $sub;
    }

    private function quote(SubClient $sub, ?string $ruc, string $origin = 'comercial'): Quote
    {
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto '.uniqid()]);
        $ownerId = (int) DB::table('atlantic_auth.users')->where('email', 'contabilidad@test.com')->value('id');
        $quote   = Quote::create([
            'owner_id' => $ownerId, 'master_client_id' => $sub->master_client_id, 'sub_client_id' => $sub->id, 'sales_project_id' => $project->id,
            'folio' => 'COT-2026-'.static::uniqueTestNumber(), 'generated_at' => Carbon::now(), 'ruc' => $ruc,
        ]);
        if ($origin !== 'comercial') {
            DB::table('atlantic_ventas_diseno.quotes')->where('id', $quote->id)->update(['origin' => $origin]);
        }

        return $quote;
    }

    private function rucDe(Quote $q): ?string
    {
        return Quote::query()->withoutGlobalScopes()->find($q->id)?->ruc;
    }

    private function csvDeLaCorrida(): string
    {
        $files = Storage::disk('local')->files('legacy');
        $this->assertNotEmpty($files);

        return (string) end($files);
    }

    public function test_la_cotizacion_con_el_ruc_truncado_se_actualiza_audita_y_deja_la_reversa(): void
    {
        $sub = $this->subCorregido();
        $q   = $this->quote($sub, self::CIF_12);

        $out = $this->aplicarCot();
        $this->assertSame(0, $this->lastExit, $out);

        $this->assertSame(self::RUC_LARGO, $this->rucDe($q));
        $corr = ClienteRucCotizacionCorreccion::query()->firstOrFail();
        $this->assertSame($q->id, (int) $corr->quote_id);
        $this->assertSame(self::CIF_12, $corr->ruc_anterior);
        $this->assertSame(self::RUC_LARGO, $corr->ruc_nuevo);
        $this->assertSame('luis', $corr->operador);
        $this->assertNull($corr->revertida_at);

        $audit = AuditLog::query()->where('action', 'ventas_diseno.quote.updated')->where('entity_id', (string) $q->id)->latest('id')->firstOrFail();
        $this->assertSame(self::CIF_12, $audit->old_values['ruc']);
        $this->assertSame(self::RUC_LARGO, $audit->new_values['ruc']);

        $run = SyncRun::query()->where('entidad', 'cotizaciones_ruc_fix')->firstOrFail();
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->cargados);
        $this->assertStringContainsString($corr->run_id, $out);
        $this->assertSame(1, AuditLog::query()->where('metadata->modo', 'cotizaciones_aplicar')->count());
    }

    public function test_una_cotizacion_con_otro_ruc_vacio_o_ya_corregido_no_se_toca(): void
    {
        $sub      = $this->subCorregido();
        $editada  = $this->quote($sub, '8-123-456');
        $vacia    = $this->quote($sub, null);
        $yaIgual  = $this->quote($sub, self::RUC_LARGO);
        $truncada = $this->quote($sub, self::CIF_12);

        $this->aplicarCot();

        $this->assertSame('8-123-456', $this->rucDe($editada));
        $this->assertNull($this->rucDe($vacia));
        $this->assertSame(self::RUC_LARGO, $this->rucDe($yaIgual));
        $this->assertSame(self::RUC_LARGO, $this->rucDe($truncada));
        $this->assertSame(1, ClienteRucCotizacionCorreccion::query()->count());
        $csv = Storage::disk('local')->get($this->csvDeLaCorrida());
        $this->assertStringContainsString('ruc_distinto_del_truncado', $csv);
        $this->assertStringContainsString('ya_igual_al_ruc_completo', $csv);
    }

    public function test_la_cotizacion_de_otro_sub_client_no_se_toca(): void
    {
        $corregido = $this->subCorregido(1001);
        $master    = MasterClient::query()->create(['name' => 'Otra Empresa']);
        $otro      = SubClient::query()->create(['master_client_id' => $master->id, 'business_name' => 'Otra Empresa', 'tax_id' => self::CIF_12]);
        $qOtro     = $this->quote($otro, self::CIF_12);   // mismo RUC truncado, pero su sub_client nunca se corrigió
        $this->quote($corregido, self::CIF_12);

        $this->aplicarCot();

        $this->assertSame(self::CIF_12, $this->rucDe($qOtro));
        $this->assertSame(1, ClienteRucCotizacionCorreccion::query()->count());
    }

    public function test_las_cotizaciones_ocultas_por_un_scope_global_tambien_se_actualizan(): void
    {
        $sub    = $this->subCorregido();
        $oculta = $this->quote($sub, self::CIF_12, 'cotizaya');
        $this->assertNull(Quote::query()->find($oculta->id), 'precondición: el scope de origen la oculta');

        $this->aplicarCot();

        $this->assertSame(self::RUC_LARGO, $this->rucDe($oculta));
    }

    public function test_si_el_sub_client_ya_no_tiene_el_ruc_corregido_no_se_toca_la_cotizacion(): void
    {
        $sub = $this->subCorregido();
        $q   = $this->quote($sub, self::CIF_12);
        $sub->tax_id = '9-999-999';
        $sub->save();

        $this->aplicarCot();

        $this->assertSame(self::CIF_12, $this->rucDe($q));
        $this->assertSame(0, ClienteRucCotizacionCorreccion::query()->count());
    }

    public function test_correcciones_de_sub_client_revertidas_no_generan_candidatas(): void
    {
        $sub = $this->subCorregido(1001, true);
        $q   = $this->quote($sub, self::CIF_12);

        $this->aplicarCot();

        $this->assertSame(self::CIF_12, $this->rucDe($q));
        $this->assertSame(0, ClienteRucCotizacionCorreccion::query()->count());
    }

    public function test_idempotente_el_segundo_apply_no_cambia_nada(): void
    {
        $sub = $this->subCorregido();
        $this->quote($sub, self::CIF_12);

        $this->aplicarCot();
        $auditos = AuditLog::query()->where('action', 'ventas_diseno.quote.updated')->count();
        $this->aplicarCot();

        $this->assertSame(0, $this->lastExit);
        $this->assertSame(1, ClienteRucCotizacionCorreccion::query()->count());
        $this->assertSame($auditos, AuditLog::query()->where('action', 'ventas_diseno.quote.updated')->count());
    }

    public function test_dry_run_no_escribe_y_apply_exige_huella_frase_y_operador(): void
    {
        $sub = $this->subCorregido();
        $q   = $this->quote($sub, self::CIF_12);

        $out = $this->fix(['--cotizaciones' => true]);
        $this->assertSame(0, $this->lastExit);
        $this->assertMatchesRegularExpression('/A corregir: 1\. Huella del plan: [0-9a-f]{12}/', $out);
        $this->assertSame(self::CIF_12, $this->rucDe($q));
        $this->assertSame(0, ClienteRucCotizacionCorreccion::query()->count());
        $this->assertSame(0, SyncRun::query()->where('entidad', 'cotizaciones_ruc_fix')->count());

        // huella desactualizada
        $this->fix(['--cotizaciones' => true, '--apply' => true, '--operador' => 'luis', '--esperado' => 'aaaaaaaaaaaa', '--yes' => true]);
        $this->assertSame(1, $this->lastExit);
        // sin huella
        $this->fix(['--cotizaciones' => true, '--apply' => true, '--operador' => 'luis', '--yes' => true]);
        $this->assertSame(1, $this->lastExit);
        // frase incorrecta
        $this->fix(['--cotizaciones' => true, '--apply' => true, '--operador' => 'luis', '--esperado' => $this->huellaCot(), '--confirmar' => 'APLICAR production atlantic']);
        $this->assertSame(1, $this->lastExit);
        // sin operador
        $this->fix(['--cotizaciones' => true, '--apply' => true, '--esperado' => $this->huellaCot(), '--yes' => true]);
        $this->assertSame(1, $this->lastExit);

        $this->assertSame(self::CIF_12, $this->rucDe($q));
        $this->assertSame(0, ClienteRucCotizacionCorreccion::query()->count());
    }

    public function test_revert_restaura_exacto_y_es_idempotente(): void
    {
        $sub = $this->subCorregido();
        $q   = $this->quote($sub, self::CIF_12);
        $this->aplicarCot();
        $runId = ClienteRucCotizacionCorreccion::query()->firstOrFail()->run_id;

        $out = $this->fix(['--revert' => $runId, '--operador' => 'luis']);
        $this->assertSame(0, $this->lastExit, $out);
        $this->assertSame(self::CIF_12, $this->rucDe($q));
        $this->assertNotNull(ClienteRucCotizacionCorreccion::query()->firstOrFail()->revertida_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'ventas_diseno.quote.updated')->where('entity_id', (string) $q->id)->where('new_values->ruc', self::CIF_12)->count());

        $this->fix(['--revert' => $runId, '--operador' => 'luis']);
        $this->assertSame(0, $this->lastExit);
        $this->assertSame(1, AuditLog::query()->where('action', 'ventas_diseno.quote.updated')->where('entity_id', (string) $q->id)->where('new_values->ruc', self::CIF_12)->count());
        // tras revertir, el plan vuelve a proponerla
        $this->assertStringContainsString('A corregir: 1.', $this->fix(['--cotizaciones' => true]));
    }

    public function test_revert_no_pisa_una_cotizacion_editada_despues(): void
    {
        $sub = $this->subCorregido();
        $q   = $this->quote($sub, self::CIF_12);
        $this->aplicarCot();
        $runId = ClienteRucCotizacionCorreccion::query()->firstOrFail()->run_id;
        $edit = Quote::query()->withoutGlobalScopes()->findOrFail($q->id);
        $edit->ruc = '8-777-777';
        $edit->save();

        $out = $this->fix(['--revert' => $runId, '--operador' => 'luis']);

        $this->assertSame('8-777-777', $this->rucDe($q));
        $this->assertNull(ClienteRucCotizacionCorreccion::query()->firstOrFail()->revertida_at);
        $this->assertStringContainsString('editada_despues_de_la_correccion', $out);
    }

    public function test_el_revert_de_un_run_de_sub_clients_se_niega_mientras_haya_cotizaciones_sin_revertir(): void
    {
        $sub = $this->subCorregido();
        $q   = $this->quote($sub, self::CIF_12);
        $this->aplicarCot();
        $runSub  = (string) ClienteRucCorreccion::query()->firstOrFail()->run_id;
        $runCot  = (string) ClienteRucCotizacionCorreccion::query()->firstOrFail()->run_id;

        $out = $this->fix(['--revert' => $runSub, '--operador' => 'luis']);

        // el sub_client se omite con su motivo: ni él ni la cotización cambian y no queda nada marcado como revertido
        $this->assertStringContainsString('cotizaciones_sin_revertir', $out);
        $this->assertSame(self::RUC_LARGO, $sub->fresh()->tax_id);
        $this->assertSame(self::RUC_LARGO, $this->rucDe($q));
        $this->assertNull(ClienteRucCotizacionCorreccion::query()->firstOrFail()->revertida_at);
        $this->assertNull(ClienteRucCorreccion::query()->firstOrFail()->revertida_at);

        // en el orden correcto (cotizaciones primero) sí se puede deshacer todo
        $this->fix(['--revert' => $runCot, '--operador' => 'luis']);
        $this->assertSame(self::CIF_12, $this->rucDe($q));
        $this->fix(['--revert' => $runSub, '--operador' => 'luis']);
        $this->assertSame(self::CIF_12, $sub->fresh()->tax_id);
        $this->assertNotNull(ClienteRucCorreccion::query()->firstOrFail()->revertida_at);
    }

    public function test_corregir_y_revertir_no_mueven_el_updated_at_de_una_cotizacion_aprobada(): void
    {
        $sub = $this->subCorregido();
        $q   = $this->quote($sub, self::CIF_12);
        DB::table('atlantic_ventas_diseno.quotes')->where('id', $q->id)->update(['status' => 'approved', 'updated_at' => '2026-03-01 10:00:00']);
        $antes = Quote::query()->withoutGlobalScopes()->findOrFail($q->id)->updated_at->toIso8601String();

        $this->aplicarCot();
        $this->assertSame(self::RUC_LARGO, $this->rucDe($q));
        $this->assertSame($antes, Quote::query()->withoutGlobalScopes()->findOrFail($q->id)->updated_at->toIso8601String());
        $this->assertSame('approved', Quote::query()->withoutGlobalScopes()->findOrFail($q->id)->status);

        $this->fix(['--revert' => ClienteRucCotizacionCorreccion::query()->firstOrFail()->run_id, '--operador' => 'luis']);
        $this->assertSame(self::CIF_12, $this->rucDe($q));
        $this->assertSame($antes, Quote::query()->withoutGlobalScopes()->findOrFail($q->id)->updated_at->toIso8601String());
    }

    public function test_una_correccion_que_no_cambia_el_tax_id_no_genera_candidatas(): void
    {
        // Corrección «solo DV»: tax_id anterior y nuevo iguales; ninguna cotización tiene nada que ajustar.
        $sub = $this->subCorregido();
        ClienteRucCorreccion::query()->update(['tax_id_anterior' => self::RUC_LARGO]);
        $q = $this->quote($sub, self::RUC_LARGO);

        $plan = (new ClienteRucCotizacionSync())->plan();

        $this->assertSame([], array_values(array_filter($plan, static fn (array $f): bool => $f['accion'] === ClienteRucCotizacionSync::ACCION_CORREGIR)));
        $this->assertSame('ya_igual_al_ruc_completo', $plan[0]['motivo']);
        $this->assertSame(self::RUC_LARGO, $this->rucDe($q));
    }

    public function test_mas_de_mil_sub_clients_se_procesan_por_lotes(): void
    {
        $quotes = [];
        for ($i = 1; $i <= 1001; $i++) {
            $quotes[] = $this->quote($this->subCorregido(10000 + $i), self::CIF_12)->id;
        }

        $plan = (new ClienteRucCotizacionSync())->plan();

        $this->assertCount(1001, array_filter($plan, static fn (array $f): bool => $f['accion'] === ClienteRucCotizacionSync::ACCION_CORREGIR));
        $this->assertEqualsCanonicalizing($quotes, array_column($plan, 'quote_id'));
    }

    public function test_el_csv_de_cotizaciones_neutraliza_formulas(): void
    {
        $sub = $this->subCorregido();
        $this->quote($sub, '=HYPERLINK("http://x")');
        $this->quote($sub, '-1+1');

        $this->fix(['--cotizaciones' => true]);
        $csv = Storage::disk('local')->get($this->csvDeLaCorrida());

        $this->assertStringContainsString('\'=HYPERLINK', $csv);
        $this->assertStringContainsString('\'-1+1', $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringNotContainsString(',-1+1', $csv);
    }

    public function test_revert_de_run_inexistente_o_sin_operador_falla(): void
    {
        $this->fix(['--cotizaciones' => true, '--revert' => (string) \Illuminate\Support\Str::uuid(), '--operador' => 'luis']);
        $this->assertSame(1, $this->lastExit);

        $this->fix(['--cotizaciones' => true, '--revert' => (string) \Illuminate\Support\Str::uuid()]);
        $this->assertSame(1, $this->lastExit);
    }

    public function test_la_consola_no_muestra_rucs_ni_nombres_y_el_csv_si_trae_los_valores(): void
    {
        $sub = $this->subCorregido();
        $this->quote($sub, self::CIF_12);

        $out  = $this->fix(['--cotizaciones' => true]);
        $out .= $this->aplicarCot();

        foreach (['155716554', self::RUC_LARGO, 'Empresa Reservada', 'Razon Social'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $out);
        }
        $this->assertStringContainsString(self::RUC_LARGO, Storage::disk('local')->get($this->csvDeLaCorrida()));
    }

    public function test_un_fallo_de_verificacion_revierte_solo_esa_cotizacion(): void
    {
        $sub = $this->subCorregido();
        $q1  = $this->quote($sub, self::CIF_12);
        $q2  = $this->quote($sub, self::CIF_12);

        $n = 0;
        ClienteRucCotizacionSync::$despuesDeGuardar = static function (Quote $q) use (&$n): void {
            if (++$n === 1) {
                // simula un cambio que contradice lo guardado, para que la verificación posterior falle
                DB::table('atlantic_ventas_diseno.quotes')->where('id', $q->id)->update(['ruc' => 'otro']);
            }
        };
        try {
            $s   = new ClienteRucCotizacionSync();
            $res = $s->aplicar($s->plan(), 'luis', 'testing', (string) \Illuminate\Support\Str::uuid());
        } finally {
            ClienteRucCotizacionSync::$despuesDeGuardar = null;
        }

        $this->assertSame(1, $res['corregidos']);
        $this->assertCount(1, $res['errores']);
        $this->assertSame(1, ClienteRucCotizacionCorreccion::query()->count());
        $this->assertSame(self::CIF_12, $this->rucDe($q1));
        $this->assertSame(self::RUC_LARGO, $this->rucDe($q2));
    }
}
