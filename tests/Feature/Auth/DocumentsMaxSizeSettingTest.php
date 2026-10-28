<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\GlobalSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

class DocumentsMaxSizeSettingTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        Cache::forget('global:documents.max_size_mb');
    }

    // ── GET /settings/documents/max-size ──────────────────────────────────────

    public function test_cualquier_usuario_autenticado_puede_leer_max_size(): void
    {
        $designer = $this->loginAs('vendedor@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($designer))
            ->getJson('/api/settings/documents/max-size')
            ->assertStatus(200)
            ->assertJsonStructure(['max_size_mb'])
            ->assertJsonPath('max_size_mb', 500);
    }

    public function test_get_max_size_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/settings/documents/max-size')
            ->assertStatus(401);
    }

    // ── PUT /settings/documents/max-size ──────────────────────────────────────

    public function test_superadmin_puede_actualizar_max_size(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/settings/documents/max-size', ['max_size_mb' => 250])
            ->assertStatus(200)
            ->assertJsonPath('max_size_mb', 250);

        // Verificar que el valor persiste en BD
        $setting = GlobalSetting::find('documents.max_size_mb');
        $this->assertNotNull($setting);
        $this->assertSame('250', $setting->value);
    }

    public function test_usuario_sin_superadmin_all_no_puede_actualizar_max_size(): void
    {
        $designer = $this->loginAs('vendedor@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($designer))
            ->putJson('/api/settings/documents/max-size', ['max_size_mb' => 100])
            ->assertStatus(403);
    }

    public function test_put_max_size_sin_autenticar_retorna_401(): void
    {
        $this->putJson('/api/settings/documents/max-size', ['max_size_mb' => 100])
            ->assertStatus(401);
    }

    public function test_valor_cero_retorna_422(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/settings/documents/max-size', ['max_size_mb' => 0])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['max_size_mb']]);
    }

    public function test_valor_501_supera_limite_de_infra_retorna_422(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/settings/documents/max-size', ['max_size_mb' => 501])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['max_size_mb']]);
    }

    public function test_valor_no_entero_retorna_422(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/settings/documents/max-size', ['max_size_mb' => 'abc'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['max_size_mb']]);
    }

    // ── Cache invalidation ────────────────────────────────────────────────────

    public function test_actualizar_max_size_invalida_cache_y_afecta_siguiente_upload(): void
    {
        $token     = $this->loginAs();
        $projectId = (int) $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', [
                'nombre' => 'Proyecto Cache Test',
                'tipo'   => 'ambos',
                'etapa'  => 'lead',
                'requires_technical_study' => false,
                'assignee_ids' => [],
            ])
            ->assertStatus(201)
            ->json('id');

        // Reducir límite a 1 MB vía endpoint
        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/settings/documents/max-size', ['max_size_mb' => 1])
            ->assertStatus(200);

        // Upload de 2 MB debe fallar con el nuevo límite (cache invalidada)
        $bigFile = UploadedFile::fake()->create('grande.pdf', 2048, 'application/pdf');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file'      => $bigFile,
                'categoria' => 'plano',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['file']]);
    }
}
