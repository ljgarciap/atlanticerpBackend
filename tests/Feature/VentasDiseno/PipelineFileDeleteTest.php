<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\PipelineFileService;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * `DELETE /api/ventas-diseno/pipeline/{card}/files/{file}` — quitar un archivo del pipeline.
 *
 * Reglas: permiso `ventas_diseno.edit` (ruta), acceso a la tarjeta, tarjeta editable (`lost` no), el archivo debe
 * pertenecer a la tarjeta (sin IDOR) y, en un proyecto Aprobado, diseño / propuesta / cotización firmada /
 * comprobante de aprobación NO se pueden quitar ni con el permiso `ventas_diseno.edit.approved` (REQ-013/015 y la
 * evidencia de aprobación); las fotos sí.
 */
class PipelineFileDeleteTest extends FeatureTestCase
{
    private const EMAIL = 'vendedor@atlantic.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
        Storage::fake('s3-private');
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param list<string> $permisos */
    private function permisos(array $permisos): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', self::EMAIL)->update(['extra_permissions' => json_encode($permisos)]);
    }

    private function conEdicion(bool $enAprobado = false): void
    {
        $base = ['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit'];
        $this->permisos($enAprobado ? [...$base, 'ventas_diseno.edit.approved'] : $base);
    }

    private function userId(): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', self::EMAIL)->value('id');
    }

    private function tarjeta(string $etapa): PipelineCard
    {
        $master  = MasterClient::create(['name' => 'Cliente ' . uniqid()]);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub ' . uniqid(), 'tax_id' => '155-' . random_int(1000, 9999) . '-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto ' . uniqid()]);

        return PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => $etapa, 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $this->userId(), 'stage_changed_at' => Carbon::now(),
        ]);
    }

    /** Sube por el servicio (sin el gate HTTP de subida) para poder probar el borrado en cualquier etapa. */
    private function archivo(PipelineCard $tarjeta, string $tipo): int
    {
        $this->tenant();
        $f = UploadedFile::fake()->create("{$tipo}.pdf", 50, 'application/pdf');

        return (int) app(PipelineFileService::class)->upload($f, $tarjeta, $tipo, $this->userId())->id;
    }

    private function token(): string
    {
        return $this->loginAs(self::EMAIL, 'Password123!', 'designer');
    }

    private function borrar(string $token, int $tarjetaId, int $archivoId): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeaders($token))->deleteJson("/api/ventas-diseno/pipeline/{$tarjetaId}/files/{$archivoId}");
    }

    private function existe(PipelineCard $tarjeta, int $archivoId): bool
    {
        $this->tenant();

        return $tarjeta->files()->whereKey($archivoId)->exists();
    }

    public function test_quita_el_archivo_de_la_base_y_del_storage_y_queda_auditado(): void
    {
        $this->conEdicion();
        $tarjeta = $this->tarjeta('design');
        $id      = $this->archivo($tarjeta, 'design');
        $this->tenant();
        $key = $tarjeta->files()->findOrFail($id)->storage_key;
        Storage::disk('s3-private')->assertExists($key);

        $this->borrar($this->token(), $tarjeta->id, $id)->assertStatus(200)->assertJsonPath('message', 'OK');

        $this->assertFalse($this->existe($tarjeta, $id));
        Storage::disk('s3-private')->assertMissing($key);
        $this->tenant();
        $this->assertTrue(AuditLog::query()->where('action', 'like', '%pipeline_card_file%')->where('action', 'like', '%deleted')->exists());
    }

    public function test_un_archivo_de_otra_tarjeta_responde_404_y_no_se_borra(): void
    {
        $this->conEdicion();
        $mia   = $this->tarjeta('design');
        $otra  = $this->tarjeta('design');
        $ajeno = $this->archivo($otra, 'design');

        $this->borrar($this->token(), $mia->id, $ajeno)->assertStatus(404);

        $this->assertTrue($this->existe($otra, $ajeno));
    }

    public function test_sin_token_401_y_sin_permiso_de_edicion_403(): void
    {
        $this->conEdicion();
        $tarjeta = $this->tarjeta('design');
        $id      = $this->archivo($tarjeta, 'design');

        $this->deleteJson("/api/ventas-diseno/pipeline/{$tarjeta->id}/files/{$id}")->assertStatus(401);

        // Un rol sin `ventas_diseno.edit` (electricista) no pasa el permiso de la ruta.
        $token = $this->loginAs('nivel2@atlantic.test', 'Password123!', 'electrician');
        $this->borrar($token, $tarjeta->id, $id)->assertStatus(403);
        $this->assertTrue($this->existe($tarjeta, $id));
    }

    public function test_un_proyecto_perdido_no_permite_quitar_archivos(): void
    {
        $this->conEdicion();
        $tarjeta = $this->tarjeta('lost');
        $id      = $this->archivo($tarjeta, 'design');

        $this->borrar($this->token(), $tarjeta->id, $id)->assertStatus(403);

        $this->assertTrue($this->existe($tarjeta, $id));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function tiposBloqueadosEnAprobado(): array
    {
        return [
            'diseño'                    => ['design', 'de diseño'],
            'propuesta'                 => ['proposal', 'de propuesta'],
            'cotización firmada'        => ['signed_quote', 'de la cotización firmada'],
            'comprobante de aprobación' => ['approval_proof', 'del comprobante de aprobación'],
        ];
    }

    /**
     * En Aprobado estos tipos no se quitan, ni siquiera con el permiso `ventas_diseno.edit.approved`: igual de
     * incondicional que el bloqueo de la subida (REQ-013/015) y protege la evidencia exigida para aprobar.
     *
     * @dataProvider tiposBloqueadosEnAprobado
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tiposBloqueadosEnAprobado')]
    public function test_en_un_proyecto_aprobado_no_se_quitan_los_tipos_protegidos(string $tipo, string $etiqueta): void
    {
        $this->conEdicion(enAprobado: true);
        $tarjeta = $this->tarjeta('approved');
        $id      = $this->archivo($tarjeta, $tipo);

        $this->borrar($this->token(), $tarjeta->id, $id)
            ->assertStatus(422)
            ->assertJsonPath('message', "No se pueden quitar archivos {$etiqueta} de un proyecto Aprobado.");

        $this->assertTrue($this->existe($tarjeta, $id));
    }

    public function test_las_fotos_si_se_pueden_quitar_en_un_proyecto_aprobado_con_el_permiso_de_aprobado(): void
    {
        $this->conEdicion(enAprobado: true);
        $tarjeta = $this->tarjeta('approved');
        $id      = $this->archivo($tarjeta, 'photo');

        $this->borrar($this->token(), $tarjeta->id, $id)->assertStatus(200);

        $this->assertFalse($this->existe($tarjeta, $id));
    }

    public function test_en_un_proyecto_aprobado_sin_el_permiso_de_aprobado_ni_las_fotos_se_quitan(): void
    {
        $this->conEdicion(enAprobado: false);
        $tarjeta = $this->tarjeta('approved');
        $id      = $this->archivo($tarjeta, 'photo');

        $this->borrar($this->token(), $tarjeta->id, $id)->assertStatus(403);

        $this->assertTrue($this->existe($tarjeta, $id));
    }

    public function test_antes_de_aprobar_cualquier_tipo_se_puede_quitar(): void
    {
        $this->conEdicion();
        $tarjeta = $this->tarjeta('proposal');
        $ids     = array_map(fn (string $t): int => $this->archivo($tarjeta, $t), ['design', 'proposal', 'signed_quote', 'approval_proof']);

        foreach ($ids as $id) {
            $this->borrar($this->token(), $tarjeta->id, $id)->assertStatus(200);
            $this->assertFalse($this->existe($tarjeta, $id));
        }
    }

    public function test_si_falla_el_storage_la_fila_se_quita_igual_y_el_error_se_registra(): void
    {
        $this->conEdicion();
        $tarjeta = $this->tarjeta('design');
        $id      = $this->archivo($tarjeta, 'design');

        $roto = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $roto->shouldReceive('delete')->andThrow(new \RuntimeException('s3 caído'));
        Storage::shouldReceive('disk')->with('s3-private')->andReturn($roto);
        Log::spy();

        $this->borrar($this->token(), $tarjeta->id, $id)->assertStatus(200);

        $this->assertFalse($this->existe($tarjeta, $id));
        Log::shouldHaveReceived('error')->withArgs(fn ($mensaje) => $mensaje === 'pipeline_file_service.storage_delete_failed')->once();
    }
}
