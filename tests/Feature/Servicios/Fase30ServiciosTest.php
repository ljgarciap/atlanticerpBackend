<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Servicios\Models\ClaimSheet;
use App\Modules\Servicios\Models\InspectionReport;
use App\Modules\Servicios\Models\InsumoSetting;
use App\Modules\Servicios\Models\InternalTechnician;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Models\Tool;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Tests\Feature\FeatureTestCase;

/** Fase 30 — Servicios: informe, hoja de reclamo, cierre de instalaciones, reportes, insumos y herramientas. */
class Fase30ServiciosTest extends FeatureTestCase
{
    private const LIDER = 'liderservicios@test.com';

    private const MANAGEMENT = 'gerencia@test.com';

    private const VENDEDOR = 'vendedor@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reMake();
        \Illuminate\Support\Facades\Storage::fake('s3-private');
        (new \Database\Seeders\ServiciosDemoSeeder)->seedParaTests();
    }

    private function reMake(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array<string, string> */
    private function as(string $email, string $role): array
    {
        return $this->authHeaders($this->loginAs($email, $email, $role));
    }

    /** @param array<string, mixed> $o */
    private function ticket(array $o = []): Ticket
    {
        $this->reMake();
        return Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(),
            'tipo' => Ticket::TIPO_INSTALLATION,
            'subtipo' => Ticket::SUBTIPO_INSPECTION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion' => 'Ticket Fase 30',
            'cliente' => 'Cliente',
            'estado' => Ticket::ESTADO_ON_SITE,
            'quote_status' => Ticket::QUOTE_STATUS_NOT_APPLICABLE,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
            ...$o,
        ]);
    }

    private function reserva(): int
    {
        return (int) Warehouse::where('name', 'Reserva Servicios')->value('id');
    }

    private function product(int $stock): CatalogProduct
    {
        $p = CatalogProduct::create(['reference' => 'F30-'.uniqid(), 'name' => 'Insumo F30', 'description' => '', 'price_full' => 5, 'cost' => 2, 'is_active' => true]);
        ProductWarehouseStock::create(['catalog_product_id' => $p->id, 'warehouse_id' => $this->reserva(), 'quantity' => $stock]);

        return $p;
    }

    // ── Grupo 2: informe ──────────────────────────────────────────────────────────────

    public function test_corregir_un_material_ajusta_el_kardex_por_diferencia(): void
    {
        $p = $this->product(20);
        $t = $this->ticket();
        $h = $this->as(self::LIDER, 'lider_servicios');
        $url = "/api/servicios/tickets/{$t->id}/inspection-report";

        $this->withHeaders($h)->putJson($url, ['conclusion' => 'ok', 'materiales' => [['nombre_material' => 'x', 'cantidad' => '5', 'catalog_product_id' => $p->id]]])->assertStatus(200);
        $this->withHeaders($h)->putJson($url, ['conclusion' => 'ok', 'materiales' => [['nombre_material' => 'x', 'cantidad' => '8', 'catalog_product_id' => $p->id]]])->assertStatus(200);
        $this->reMake();
        $this->assertSame(12, ProductWarehouseStock::where('catalog_product_id', $p->id)->where('warehouse_id', $this->reserva())->value('quantity'));

        $this->withHeaders($h)->putJson($url, ['conclusion' => 'ok', 'materiales' => [['nombre_material' => 'x', 'cantidad' => '2', 'catalog_product_id' => $p->id]]])->assertStatus(200);
        $this->withHeaders($h)->putJson($url, ['conclusion' => 'ok', 'materiales' => [['nombre_material' => 'x', 'cantidad' => '2', 'catalog_product_id' => $p->id]]])->assertStatus(200);
        $this->reMake();
        $this->assertSame(18, ProductWarehouseStock::where('catalog_product_id', $p->id)->where('warehouse_id', $this->reserva())->value('quantity'));
        $this->assertSame(3, InventoryMovement::where('catalog_product_id', $p->id)->where('tipo', InventoryMovement::TIPO_CONSUMO)->count());
    }

    public function test_borrador_no_completa_ni_consume_ni_desbloquea_el_cierre(): void
    {
        $p = $this->product(10);
        $t = $this->ticket();
        $h = $this->as(self::LIDER, 'lider_servicios');

        $this->withHeaders($h)->putJson("/api/servicios/tickets/{$t->id}/inspection-report", [
            'borrador' => true,
            'materiales' => [['nombre_material' => 'x', 'cantidad' => '4', 'catalog_product_id' => $p->id]],
        ])->assertStatus(200)->assertJsonPath('estado', 'pending');

        $this->reMake();
        $this->assertSame(Ticket::INSPECTION_STATUS_PENDING, $t->fresh()->inspection_report_status);
        $this->assertSame(0, InventoryMovement::where('tipo', InventoryMovement::TIPO_CONSUMO)->count());

        $this->withHeaders($h)->patchJson("/api/servicios/tickets/{$t->id}/estado", ['estado' => Ticket::ESTADO_RESOLVED])->assertStatus(422);

        // Sin borrador la conclusión sigue siendo obligatoria; y completar consume.
        $this->withHeaders($h)->putJson("/api/servicios/tickets/{$t->id}/inspection-report", [])->assertStatus(422);
        $this->withHeaders($h)->putJson("/api/servicios/tickets/{$t->id}/inspection-report", [
            'conclusion' => 'Listo',
            'materiales' => [['nombre_material' => 'x', 'cantidad' => '4', 'catalog_product_id' => $p->id]],
        ])->assertStatus(200)->assertJsonPath('estado', 'completed');

        // Un informe completado no vuelve a borrador.
        $this->withHeaders($h)->putJson("/api/servicios/tickets/{$t->id}/inspection-report", ['borrador' => true])->assertStatus(422);
    }

    public function test_guardar_formulario_y_archivo_no_se_pisan(): void
    {
        $t = $this->ticket();
        $h = $this->as(self::LIDER, 'lider_servicios');
        $base = "/api/servicios/tickets/{$t->id}/inspection-report";

        $this->withHeaders($h)->putJson($base, ['conclusion' => 'Conclusión del formulario'])->assertStatus(200);
        $this->withHeaders($h)->post($base.'/upload', ['archivo' => UploadedFile::fake()->create('i.pdf', 10, 'application/pdf')])->assertStatus(200);
        $this->reMake();
        $r = InspectionReport::where('ticket_id', $t->id)->firstOrFail();
        $this->assertSame('Conclusión del formulario', $r->conclusion);
        $this->assertNotNull($r->archivo_adjunto);

        $this->withHeaders($h)->putJson($base, ['conclusion' => 'Corregida'])->assertStatus(200);
        $this->reMake();
        $this->assertNotNull(InspectionReport::where('ticket_id', $t->id)->firstOrFail()->archivo_adjunto);
    }

    public function test_fotos_se_borran_y_reemplazan_pero_no_con_el_informe_firmado(): void
    {
        $t = $this->ticket();
        $h = $this->as(self::LIDER, 'lider_servicios');
        $base = "/api/servicios/tickets/{$t->id}/inspection-report";
        $this->withHeaders($h)->putJson($base, ['conclusion' => 'ok'])->assertStatus(200);

        $id = $this->withHeaders($h)->post($base.'/photos', ['categoria' => 'antes', 'file' => UploadedFile::fake()->image('a.png', 50, 50)])->assertStatus(201)->json('id');
        $nuevo = $this->withHeaders($h)->post($base."/photos/{$id}/replace", ['file' => UploadedFile::fake()->image('b.png', 50, 50)])->assertStatus(200)->json('id');
        $this->assertNotSame($id, $nuevo);
        $this->withHeaders($h)->deleteJson($base."/photos/{$nuevo}")->assertStatus(200);
        $this->withHeaders($h)->deleteJson($base."/photos/{$nuevo}")->assertStatus(404);

        $id2 = $this->withHeaders($h)->post($base.'/photos', ['categoria' => 'antes', 'file' => UploadedFile::fake()->image('c.png', 50, 50)])->json('id');
        $this->reMake();
        InspectionReport::where('ticket_id', $t->id)->update(['firma_cliente_imagen' => 'servicios/x.png']);
        $this->withHeaders($h)->deleteJson($base."/photos/{$id2}")->assertStatus(409);
    }

    // ── Grupo 1: compuerta de cierre de instalaciones ─────────────────────────────────

    public function test_instalacion_exige_informe_completado_y_evidencia_para_cerrarse(): void
    {
        $t = $this->ticket(['subtipo' => Ticket::SUBTIPO_INSTALLATION, 'inspection_report_status' => Ticket::INSPECTION_STATUS_NOT_APPLICABLE]);
        $h = $this->as(self::LIDER, 'lider_servicios');
        $url = "/api/servicios/tickets/{$t->id}/estado";

        $this->withHeaders($h)->patchJson($url, ['estado' => Ticket::ESTADO_RESOLVED])->assertStatus(422)
            ->assertJsonFragment(['message' => 'No se puede cerrar: la instalación requiere el informe de inspección completado.']);

        $this->withHeaders($h)->putJson("/api/servicios/tickets/{$t->id}/inspection-report", ['conclusion' => 'Instalado'])->assertStatus(200);
        $this->withHeaders($h)->patchJson($url, ['estado' => Ticket::ESTADO_RESOLVED])->assertStatus(422)
            ->assertJsonFragment(['message' => 'No se puede cerrar: la instalación requiere al menos una evidencia (foto o archivo) en el informe.']);

        $this->withHeaders($h)->post("/api/servicios/tickets/{$t->id}/inspection-report/photos", ['categoria' => 'despues', 'file' => UploadedFile::fake()->image('f.png', 50, 50)])->assertStatus(201);
        $this->withHeaders($h)->patchJson($url, ['estado' => Ticket::ESTADO_RESOLVED])->assertStatus(200);
    }

    public function test_alias_technician_user_id_en_la_salida(): void
    {
        $t = $this->ticket();
        $h = $this->as(self::LIDER, 'lider_servicios');
        $this->withHeaders($h)->getJson("/api/servicios/tickets/{$t->id}")->assertStatus(200)->assertJsonStructure(['technician_user_id']);
    }

    // ── Grupo 3: hoja de reclamo y reportes ───────────────────────────────────────────

    public function test_reclamo_exige_hoja_completada_y_se_reabre_con_motivo(): void
    {
        $t = $this->ticket(['tipo' => Ticket::TIPO_CLAIM, 'subtipo' => null, 'quote_status' => Ticket::QUOTE_STATUS_APPROVED, 'inspection_report_status' => Ticket::INSPECTION_STATUS_NOT_APPLICABLE]);
        $h = $this->as(self::LIDER, 'lider_servicios');
        $est = "/api/servicios/tickets/{$t->id}/estado";
        $hoja = "/api/servicios/tickets/{$t->id}/claim-sheet";

        $this->withHeaders($h)->patchJson($est, ['estado' => Ticket::ESTADO_RESOLVED])->assertStatus(422)
            ->assertJsonFragment(['message' => 'No se puede cerrar: falta completar la hoja de reclamo.']);

        // Borrador: sin diagnóstico ni firma, no completa.
        $this->withHeaders($h)->putJson($hoja, ['borrador' => true, 'descripcion_problema' => 'Parpadea'])->assertStatus(200)->assertJsonPath('estado', 'pending');
        $this->withHeaders($h)->putJson($hoja, [])->assertStatus(422);

        $this->withHeaders($h)->putJson($hoja, ['diagnostico' => ClaimSheet::DIAGNOSTICOS[0], 'firma_responsable' => 'Responsable'])->assertStatus(200)->assertJsonPath('estado', 'completed');
        $this->withHeaders($h)->putJson($hoja, ['diagnostico' => ClaimSheet::DIAGNOSTICOS[0], 'firma_responsable' => 'Otro'])->assertStatus(409);

        $this->withHeaders($h)->patchJson($hoja.'/reabrir', ['motivo' => 'corto'])->assertStatus(422);
        $this->withHeaders($h)->patchJson($hoja.'/reabrir', ['motivo' => 'El diagnóstico estaba equivocado'])->assertStatus(200)
            ->assertJsonPath('estado', 'pending')->assertJsonPath('reopened_reason', 'El diagnóstico estaba equivocado');
        $this->withHeaders($h)->patchJson($est, ['estado' => Ticket::ESTADO_RESOLVED])->assertStatus(422);

        $this->withHeaders($h)->putJson($hoja, ['diagnostico' => ClaimSheet::DIAGNOSTICOS[0], 'firma_responsable' => 'Corregida'])->assertStatus(200);
        $this->withHeaders($h)->patchJson($est, ['estado' => Ticket::ESTADO_RESOLVED])->assertStatus(200);
    }

    public function test_reabrir_hoja_solo_quien_gestiona_tickets(): void
    {
        $t = $this->ticket(['tipo' => Ticket::TIPO_CLAIM, 'subtipo' => null]);
        $this->withHeaders($this->as(self::VENDEDOR, 'vendedor'))->patchJson("/api/servicios/tickets/{$t->id}/claim-sheet/reabrir", ['motivo' => 'Motivo suficiente'])->assertStatus(403);
    }

    public function test_carga_por_tecnico_y_comision_solo_lider_y_gerencia(): void
    {
        $v = $this->as(self::VENDEDOR, 'vendedor');
        $this->withHeaders($v)->getJson('/api/servicios/reportes/distribucion-tecnico')->assertStatus(403);
        $this->withHeaders($v)->getJson('/api/servicios/reportes/comision-tecnico')->assertStatus(403);

        foreach ([[self::LIDER, 'lider_servicios'], [self::MANAGEMENT, 'management']] as [$e, $r]) {
            $this->withHeaders($this->as($e, $r))->getJson('/api/servicios/reportes/distribucion-tecnico')->assertStatus(200);
        }
    }

    public function test_completados_anio_separa_resueltos_y_cerrados_y_biblioteca_valida_el_tipo(): void
    {
        $h = $this->as(self::LIDER, 'lider_servicios');
        foreach ([Ticket::ESTADO_RESOLVED, Ticket::ESTADO_CLOSED] as $estado) {
            $t = $this->ticket(['estado' => $estado]);
            $t->forceFill(['closed_at' => now()])->save();
        }

        $mes = collect($this->withHeaders($h)->getJson('/api/servicios/reportes/completados-anio')->assertStatus(200)->json('data'))->firstWhere('mes', (int) now()->format('n'));
        $this->assertGreaterThanOrEqual(1, $mes['resueltos']);
        $this->assertGreaterThanOrEqual(1, $mes['cerrados']);
        $this->assertSame($mes['resueltos'] + $mes['cerrados'], $mes['total']);

        $this->withHeaders($h)->getJson('/api/servicios/reportes/biblioteca?tipo_documento=inventado')->assertStatus(422);
        $this->withHeaders($h)->getJson('/api/servicios/reportes/biblioteca?tipo_documento=claim_sheet')->assertStatus(200);
    }

    // ── Grupo 4: insumos, herramientas y técnicos ─────────────────────────────────────

    public function test_insumo_con_existencia_inicial_no_nace_bajo_minimo_y_se_edita_y_da_de_baja(): void
    {
        $p = CatalogProduct::create(['reference' => 'F30I-'.uniqid(), 'name' => 'Insumo nuevo', 'description' => '', 'price_full' => 5, 'cost' => 2, 'is_active' => true]);
        $h = $this->as(self::MANAGEMENT, 'management');

        $row = $this->withHeaders($h)->postJson('/api/servicios/insumos', ['catalog_product_id' => $p->id, 'minimo' => 3, 'cantidad_inicial' => 0, 'existencia_inicial' => 10])
            ->assertStatus(201)->json();
        $this->assertSame('ok', $row['estado']);
        $this->assertSame(10, $row['disponible']);

        $this->withHeaders($h)->patchJson("/api/servicios/insumos/{$row['id']}", ['minimo' => 50])->assertStatus(200)->assertJsonPath('estado', 'bajo_minimo');
        $this->withHeaders($h)->deleteJson("/api/servicios/insumos/{$row['id']}")->assertStatus(200);
        $this->assertNotContains($row['id'], array_column($this->withHeaders($h)->getJson('/api/servicios/insumos')->json() ?? [], 'id'));

        $this->reMake();
        $this->assertFalse((bool) InsumoSetting::find($row['id'])->is_active);
        // Dar de alta de nuevo lo reactiva en vez de duplicarlo.
        $this->withHeaders($h)->postJson('/api/servicios/insumos', ['catalog_product_id' => $p->id, 'minimo' => 1, 'cantidad_inicial' => 0])->assertStatus(201);
        $this->reMake();
        $this->assertSame(1, InsumoSetting::where('catalog_product_id', $p->id)->count());
    }

    public function test_vendedor_no_gestiona_insumos_ni_herramientas(): void
    {
        $v = $this->as(self::VENDEDOR, 'vendedor');
        $this->withHeaders($v)->patchJson('/api/servicios/insumos/1', ['minimo' => 1])->assertStatus(403);
        $this->withHeaders($v)->deleteJson('/api/servicios/tools/1')->assertStatus(403);
    }

    public function test_recibir_herramienta_exige_referencia_y_se_da_de_baja(): void
    {
        $h = $this->as(self::LIDER, 'lider_servicios');
        $sol = $this->withHeaders($h)->postJson('/api/servicios/tools/purchase-requests', ['nombre' => 'Taladro F30', 'cantidad' => 1])->assertStatus(201)->json('id');

        $this->withHeaders($h)->postJson("/api/servicios/tools/purchase-requests/{$sol}/recibir", [])->assertStatus(422);
        $res = $this->withHeaders($h)->postJson("/api/servicios/tools/purchase-requests/{$sol}/recibir", ['referencia' => 'FAC-123'])->assertStatus(200);
        $res->assertJsonPath('received_reference', 'FAC-123');
        $toolId = $res->json('tools.0.id');

        $this->withHeaders($h)->deleteJson("/api/servicios/tools/{$toolId}")->assertStatus(200);
        $this->reMake();
        $this->assertFalse((bool) Tool::find($toolId)->is_active);
        $this->withHeaders($h)->deleteJson("/api/servicios/tools/{$toolId}")->assertStatus(404);
    }

    public function test_patch_de_tecnico_no_borra_lo_no_reenviado_y_liga_usuario(): void
    {
        $h = $this->as(self::LIDER, 'lider_servicios');
        $id = $this->withHeaders($h)->postJson('/api/servicios/internal-technicians', ['nombre' => 'Técnico F30', 'telefono' => '555', 'email' => 'f30@test.com', 'especialidad' => 'general'])->assertStatus(201)->json('id');

        $this->withHeaders($h)->patchJson("/api/servicios/internal-technicians/{$id}", ['nombre' => 'Técnico F30 B'])->assertStatus(200);
        $this->reMake();
        $t = InternalTechnician::findOrFail($id);
        $this->assertSame('555', $t->telefono);
        $this->assertSame('f30@test.com', $t->email);

        $uid = \App\Models\User::query()->where('email', self::VENDEDOR)->value('id');
        $this->withHeaders($h)->patchJson("/api/servicios/internal-technicians/{$id}", ['user_id' => $uid])->assertStatus(200)->assertJsonPath('user_id', $uid);
        $otro = $this->withHeaders($h)->postJson('/api/servicios/internal-technicians', ['nombre' => 'Otro F30', 'especialidad' => 'general'])->json('id');
        $this->withHeaders($h)->patchJson("/api/servicios/internal-technicians/{$otro}", ['user_id' => $uid])->assertStatus(422);
    }
}
