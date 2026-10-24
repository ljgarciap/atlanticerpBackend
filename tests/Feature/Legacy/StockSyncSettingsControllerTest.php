<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Legacy\Mail\LegacySyncAlertMail;
use App\Modules\Legacy\Models\StockSyncSettings;
use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTestCase;

/** SCRUM-1114 — ajustes y estado del sync de stock. Gate `role:superadmin` exclusivo. */
final class StockSyncSettingsControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function superadmin(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin'));
    }

    public function test_solo_el_superadmin_puede_ver_y_editar(): void
    {
        $h = $this->authHeaders($this->loginAs('gerencia@test.com', 'gerencia@test.com', 'management'));

        $this->withHeaders($h)->getJson('/api/legacy/migracion/stock-sync')->assertStatus(403);
        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['activo' => true])->assertStatus(403);
    }

    public function test_el_superadmin_ve_los_ajustes_y_las_ultimas_corridas_de_stock(): void
    {
        SyncRun::query()->create([
            'entidad' => 'stock', 'origen' => SyncRun::ORIGEN_SCHEDULER, 'extraidos' => 100, 'staged' => 90, 'mapeados' => 5,
            'cargados' => 5, 'excluidos' => 0, 'errores' => 0, 'duracion_ms' => 10, 'status' => SyncRun::STATUS_SUCCESS,
            'resumen' => ['con_delta' => 5], 'started_at' => now(), 'finished_at' => now(), 'created_at' => now(),
        ]);
        SyncRun::query()->create([
            'entidad' => 'productos', 'origen' => SyncRun::ORIGEN_SCHEDULER, 'extraidos' => 1, 'staged' => 1, 'mapeados' => 1,
            'cargados' => 1, 'excluidos' => 0, 'errores' => 0, 'duracion_ms' => 10, 'status' => SyncRun::STATUS_SUCCESS,
            'started_at' => now(), 'finished_at' => now(), 'created_at' => now(),
        ]);

        $json = $this->withHeaders($this->superadmin())->getJson('/api/legacy/migracion/stock-sync')->assertStatus(200)->json();

        $this->assertFalse($json['settings']['activo']);
        $this->assertNull($json['settings']['baseline_inicializada_at']);
        $this->assertCount(1, $json['ultimas_corridas']); // solo la entidad `stock`
        $this->assertSame(5, $json['ultimas_corridas'][0]['aplicados']);
        $this->assertSame(['con_delta' => 5], $json['ultimas_corridas'][0]['resumen']);
    }

    public function test_no_se_puede_activar_el_sync_sin_la_linea_base_inicializada(): void
    {
        $this->withHeaders($this->superadmin())
            ->putJson('/api/legacy/migracion/stock-sync', ['activo' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.activo.0', 'La línea base de stock no está inicializada.');
    }

    public function test_el_superadmin_edita_interruptor_umbrales_y_correos_y_queda_auditado(): void
    {
        StockSyncSettings::current()->update(['baseline_inicializada_at' => now()]);
        $json = $this->withHeaders($this->superadmin())->putJson('/api/legacy/migracion/stock-sync', [
            'activo'                        => true,
            'max_productos_con_delta_pct'   => 12.5,
            'max_unidades_abs_por_producto' => 500,
            'max_unidades_abs_total'        => 9000,
            'correos_alerta'                => ['Ops@Atlantic.com.pa'],
        ])->assertStatus(200)->json();

        $this->assertTrue($json['settings']['activo']);
        $this->assertSame(12.5, $json['settings']['max_productos_con_delta_pct']);
        $this->assertSame(500, $json['settings']['max_unidades_abs_por_producto']);
        $this->assertSame(['Ops@Atlantic.com.pa'], $json['settings']['correos_alerta']);
        // Quien cambia el interruptor o un umbral deja rastro en un correo a los superadmins (S-02).
        Mail::assertSent(LegacySyncAlertMail::class, fn (LegacySyncAlertMail $m): bool => str_contains($m->titulo, 'Ajustes de la sincronización') && collect($m->lineas)->contains(fn (string $l): bool => str_contains($l, 'activo')));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent(); // la petición HTTP olvida el tenant al terminar
        $this->assertTrue(StockSyncSettings::current()->activo);
        $this->assertGreaterThanOrEqual(1, AuditLog::query()->where('action', 'like', 'legacy.%stock_sync_settings%')->count());
    }

    public function test_valida_los_valores_y_no_deja_editar_la_marca_de_base_inicializada(): void
    {
        $h = $this->superadmin();

        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['max_productos_con_delta_pct' => 150])->assertStatus(422);
        // Topes de validación: un umbral sin techo real dejaría el circuit breaker sin efecto (S-02).
        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['max_productos_con_delta_pct' => 51])->assertStatus(422);
        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['max_unidades_abs_por_producto' => 50001])->assertStatus(422);
        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['max_unidades_abs_total' => 500001])->assertStatus(422);
        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['min_filas_icg_pct' => 0])->assertStatus(422);
        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['horas_aviso_apagado' => 0])->assertStatus(422);
        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['max_unidades_abs_total' => 0])->assertStatus(422);
        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['correos_alerta' => ['no-es-correo']])->assertStatus(422);
        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['correos_alerta' => ['a@x.com', 'A@X.com']])->assertStatus(422);

        // Un campo que no se puede editar responde 422 (no se ignora en silencio) y no cambia nada.
        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['baseline_inicializada_at' => now()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors(['baseline_inicializada_at']);
        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['campo_inventado' => 1])->assertStatus(422)->assertJsonValidationErrors(['campo_inventado']);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertNull(StockSyncSettings::current()->baseline_inicializada_at);
    }

    public function test_el_dashboard_de_corridas_filtra_por_la_entidad_stock_y_expone_el_resumen(): void
    {
        SyncRun::query()->create([
            'entidad' => 'stock', 'origen' => SyncRun::ORIGEN_MANUAL, 'extraidos' => 1, 'staged' => 1, 'mapeados' => 1,
            'cargados' => 1, 'excluidos' => 0, 'errores' => 0, 'duracion_ms' => 10, 'status' => SyncRun::STATUS_SUCCESS,
            'resumen' => ['con_delta' => 1], 'started_at' => now(), 'finished_at' => now(), 'created_at' => now(),
        ]);

        $json = $this->withHeaders($this->superadmin())->getJson('/api/legacy/migracion/dashboard/runs?entidad=stock')->assertStatus(200)->json();

        $this->assertSame(1, $json['total']);
        $this->assertSame(['con_delta' => 1], $json['data'][0]['resumen']);
    }

    public function test_con_lista_de_dominios_permitidos_rechaza_un_correo_externo(): void
    {
        config(['legacy.alert_allowed_domains' => ['atlantic.com.pa']]);
        $h = $this->superadmin();

        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['correos_alerta' => ['atacante@externo.com']])->assertStatus(422);
        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['correos_alerta' => ['ops@atlantic.com.pa']])->assertStatus(200);
    }

    public function test_expone_y_edita_el_guard_de_respuesta_parcial_y_las_horas_de_aviso(): void
    {
        $json = $this->withHeaders($this->superadmin())->putJson('/api/legacy/migracion/stock-sync', [
            'min_filas_icg_pct' => 90, 'horas_aviso_apagado' => 24,
        ])->assertStatus(200)->json();

        $this->assertEquals(90, $json['settings']['min_filas_icg_pct']);
        $this->assertSame(24, $json['settings']['horas_aviso_apagado']);
    }

    public function test_un_correo_sin_punto_en_el_dominio_se_rechaza_igual_que_en_la_pantalla(): void
    {
        $this->withHeaders($this->superadmin())
            ->putJson('/api/legacy/migracion/stock-sync', ['correos_alerta' => ['a@x']])
            ->assertStatus(422)->assertJsonValidationErrors(['correos_alerta.0']);
    }

    public function test_los_mensajes_de_validacion_usan_nombres_legibles_no_el_campo_tecnico(): void
    {
        $json = $this->withHeaders($this->superadmin())
            ->putJson('/api/legacy/migracion/stock-sync', ['max_productos_con_delta_pct' => 99])
            ->assertStatus(422)->json();

        $mensaje = $json['errors']['max_productos_con_delta_pct'][0];
        $this->assertStringContainsString('% máximo de productos con delta', $mensaje);
        $this->assertStringNotContainsString('max productos con delta pct', $mensaje);
    }

    public function test_el_diff_del_correo_parte_del_valor_anterior_real_de_cada_cambio(): void
    {
        $h = $this->superadmin();
        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['max_unidades_abs_total' => 30002])->assertStatus(200);
        $this->withHeaders($h)->putJson('/api/legacy/migracion/stock-sync', ['max_unidades_abs_total' => 30004])->assertStatus(200);

        Mail::assertSent(LegacySyncAlertMail::class, fn (LegacySyncAlertMail $m): bool => collect($m->lineas)->contains('max_unidades_abs_total: 30000 -> 30002'));
        Mail::assertSent(LegacySyncAlertMail::class, fn (LegacySyncAlertMail $m): bool => collect($m->lineas)->contains('max_unidades_abs_total: 30002 -> 30004'));
    }
}
