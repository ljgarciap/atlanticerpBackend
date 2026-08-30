<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Models\ServiciosSetting;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Batch 4 resto (REQ-248) — Observaciones y Adjuntos de "Nuevo ticket".
 * Mismos usuarios reales que TicketBatch2Test/TicketBatch3Test/TicketBatch3Part2Test.
 *
 * Nota de orden: los fixtures (makeSalesProject/createTicket con Eloquent directo) SIEMPRE se
 * resuelven ANTES de loginAs() en cada test — loginAs() dispara una request HTTP real, cuyo
 * middleware de tenancy hace su propio ciclo completo y termina limpiando el search_path que
 * setUp() dejó activo; una llamada Eloquent directa DESPUÉS de loginAs() (sin pasar por otra
 * request) se queda sin tenant activo y falla con "relation ... does not exist" (mismo patrón que
 * TicketBatch3Part2Test ya sigue).
 */
class TicketBatch4Test extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'servicio@atlantic.com.pa';

    private const TECNICO_SERVICIOS = 'carlos@atlantic.com.pa';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
    }

    private function makeSalesProject(): SalesProject
    {
        $unique = uniqid();
        $master = MasterClient::create(['name' => "Cliente {$unique}"]);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => "Cliente {$unique} SA", 'tax_id' => $unique]);

        return SalesProject::create(['sub_client_id' => $sub->id, 'name' => "Proyecto {$unique}"]);
    }

    private function createTicket(string $token, int $salesProjectId, array $overrides = []): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets', array_merge([
                'tipo'             => Ticket::TIPO_CLAIM,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion'      => 'Breve descripción',
                'sales_project_id' => $salesProjectId,
            ], $overrides))
            ->assertStatus(201);

        return (int) $response->json('id');
    }

    /** RN1 — observaciones es un campo distinto de descripcion, opcional. */
    public function test_crear_ticket_con_observaciones(): void
    {
        $salesProjectId = $this->makeSalesProject()->id;
        $token          = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets', [
                'tipo'             => Ticket::TIPO_CLAIM,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion'      => 'Breve descripción',
                'observaciones'    => 'Confirmar acceso con el prospecto antes de la visita.',
                'sales_project_id' => $salesProjectId,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('observaciones', 'Confirmar acceso con el prospecto antes de la visita.');
        $response->assertJsonPath('adjuntos', []);
    }

    public function test_crear_ticket_sin_observaciones_queda_null(): void
    {
        $salesProjectId = $this->makeSalesProject()->id;
        $token          = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $ticketId       = $this->createTicket($token, $salesProjectId);

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticketId}")
            ->assertStatus(200)
            ->assertJsonPath('observaciones', null);
    }

    /** Escenario 1 (REQ-248) — 2 fotos adjuntadas quedan asociadas al ticket. */
    public function test_adjuntar_2_fotos_quedan_asociadas_al_ticket(): void
    {
        $salesProjectId = $this->makeSalesProject()->id;
        $token          = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $ticketId       = $this->createTicket($token, $salesProjectId);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticketId}/attachments", [
                'file' => UploadedFile::fake()->create('foto-referencia-1.jpg', 500, 'image/jpeg'),
            ])
            ->assertStatus(201)
            ->assertJsonPath('nombre_archivo', 'foto-referencia-1.jpg');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticketId}/attachments", [
                'file' => UploadedFile::fake()->create('foto-referencia-2.jpg', 500, 'image/jpeg'),
            ])
            ->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticketId}")
            ->assertStatus(200);

        $this->assertCount(2, $detail->json('adjuntos'));
        $names = collect($detail->json('adjuntos'))->pluck('nombre_archivo')->all();
        $this->assertContains('foto-referencia-1.jpg', $names);
        $this->assertContains('foto-referencia-2.jpg', $names);
    }

    /** RN2 — límite configurable de cantidad de archivos (ServiciosSettingsService). */
    public function test_adjuntar_supera_el_maximo_configurado_retorna_422(): void
    {
        $salesProjectId = $this->makeSalesProject()->id;
        ServiciosSetting::create(['key' => 'adjuntos_max_archivos', 'value' => '1']);
        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $ticketId = $this->createTicket($token, $salesProjectId);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticketId}/attachments", [
                'file' => UploadedFile::fake()->create('foto-1.jpg', 200, 'image/jpeg'),
            ])
            ->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticketId}/attachments", [
                'file' => UploadedFile::fake()->create('foto-2.jpg', 200, 'image/jpeg'),
            ])
            ->assertStatus(422);
    }

    /**
     * RN2 — cubre el camino secuencial repetido (el test de PHPUnit no puede disparar requests
     * realmente en paralelo). NO prueba la condición de carrera en sí — eso exige 2 procesos/
     * conexiones reales concurrentes — pero documenta el contrato esperado: el límite se
     * re-verifica DENTRO de la transacción con el ticket bloqueado (`lockForUpdate`, ver
     * TicketAttachmentService::upload()), no en un chequeo previo separado en el controller que
     * pudiera pasar 2 veces antes de que cualquiera de las 2 inserte.
     */
    public function test_adjuntar_repetido_nunca_supera_el_maximo_configurado(): void
    {
        $salesProjectId = $this->makeSalesProject()->id;
        ServiciosSetting::create(['key' => 'adjuntos_max_archivos', 'value' => '1']);
        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $ticketId = $this->createTicket($token, $salesProjectId);

        $statuses = [];
        foreach (['foto-a.jpg', 'foto-b.jpg', 'foto-c.jpg'] as $name) {
            $response = $this->withHeaders($this->authHeaders($token))
                ->postJson("/api/servicios/tickets/{$ticketId}/attachments", [
                    'file' => UploadedFile::fake()->create($name, 200, 'image/jpeg'),
                ]);
            $statuses[] = $response->status();
        }

        $this->assertSame([201, 422, 422], $statuses);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticketId}")
            ->assertStatus(200);
        $this->assertCount(1, $detail->json('adjuntos'));
    }

    /** RN2 — extensión fuera del whitelist (fotos/videos/documentos de referencia). */
    public function test_adjuntar_extension_no_permitida_retorna_422(): void
    {
        $salesProjectId = $this->makeSalesProject()->id;
        $token          = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $ticketId       = $this->createTicket($token, $salesProjectId);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticketId}/attachments", [
                'file' => UploadedFile::fake()->create('script.exe', 200, 'application/octet-stream'),
            ])
            ->assertStatus(422);
    }

    /** RN2 — límite configurable de tamaño por archivo (ServiciosSettingsService). */
    public function test_adjuntar_archivo_mas_grande_que_el_maximo_configurado_retorna_422(): void
    {
        $salesProjectId = $this->makeSalesProject()->id;
        ServiciosSetting::create(['key' => 'adjuntos_max_size_mb', 'value' => '1']);
        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $ticketId = $this->createTicket($token, $salesProjectId);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticketId}/attachments", [
                'file' => UploadedFile::fake()->create('foto-grande.jpg', 2 * 1024, 'image/jpeg'),
            ])
            ->assertStatus(422);
    }

    public function test_adjuntar_a_ticket_inexistente_retorna_404(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets/999999/attachments', [
                'file' => UploadedFile::fake()->create('foto.jpg', 200, 'image/jpeg'),
            ])
            ->assertStatus(404);
    }

    public function test_url_firmada_de_adjunto_inexistente_retorna_404(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tickets/attachments/999999/url')
            ->assertStatus(404);
    }

    /** PERMISOS — mismos roles que pueden crear tickets pueden adjuntar. */
    public function test_tecnico_servicios_no_puede_adjuntar(): void
    {
        $salesProjectId = $this->makeSalesProject()->id;
        $liderToken     = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $ticketId       = $this->createTicket($liderToken, $salesProjectId);

        $tecnicoToken = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($tecnicoToken))
            ->postJson("/api/servicios/tickets/{$ticketId}/attachments", [
                'file' => UploadedFile::fake()->create('foto.jpg', 200, 'image/jpeg'),
            ])
            ->assertStatus(403);
    }

    public function test_obtener_url_firmada_de_un_adjunto(): void
    {
        $salesProjectId = $this->makeSalesProject()->id;
        $token          = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $ticketId       = $this->createTicket($token, $salesProjectId);

        $uploadResponse = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticketId}/attachments", [
                'file' => UploadedFile::fake()->create('foto.jpg', 200, 'image/jpeg'),
            ])
            ->assertStatus(201);

        $attachmentId = $uploadResponse->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/attachments/{$attachmentId}/url")
            ->assertStatus(200)
            ->assertJsonPath('filename', 'foto.jpg')
            ->assertJsonStructure(['url', 'filename']);
    }
}
