<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\CustomerReturn;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1180 (hijo de SCRUM-1132) — `POST /api/bodega/returns/{id}/form` y `POST /api/bodega/returns/{id}/delivery-guide` (antes GET): generan un PDF,
 * lo suben a S3 y (la guía) crean una fila en `order_documents`. Solo cambió el verbo: mismo permiso, misma respuesta `{url}`, sin cuerpo. El GET viejo
 * ya no existe (405) y «Ver como usuario» las bloquea por método.
 */
final class CustomerReturnPdfPostTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    private const PASSWORD = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
        Storage::fake('s3-private');
    }

    private function makeCustomerReturn(): CustomerReturn
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_ENTREGADO], ['qty_requested' => 10, 'qty_delivered' => 8]);
        $item  = $order->items->first();

        $customerReturn = CustomerReturn::create([
            'return_number' => 'DEV-2026-'.static::uniqueTestNumber(),
            'order_id'      => $order->id,
            'status'        => CustomerReturn::STATUS_PENDIENTE,
            'contact_name'  => 'Ana Pérez',
            'contact_phone' => '6000-1234',
            'created_by'    => $this->userIdByEmail('management@atlantic.test'),
        ]);
        $customerReturn->lines()->create([
            'order_item_id' => $item->id, 'reference_snapshot' => $item->reference_snapshot, 'description_snapshot' => $item->description_snapshot,
            'qty_requested' => 3, 'reason' => 'danado_defectuoso',
        ]);

        return $customerReturn;
    }

    /** @return array<string, string> */
    private function bodegaHeaders(): array
    {
        $this->grantBodegaPermissions('designer@atlantic.test');

        return $this->authHeaders($this->loginAs('designer@atlantic.test', self::PASSWORD, 'designer'));
    }

    /** @return list<string> */
    private function s3Files(): array
    {
        return Storage::disk('s3-private')->allFiles();
    }

    private function documentCount(): int
    {
        $this->makeCurrentTenant();

        return OrderDocument::query()->count();
    }

    // ── POST funciona igual que el GET anterior ───────────────────────────────────────────────────────────

    public function test_post_form_genera_el_pdf_lo_sube_y_responde_la_url(): void
    {
        $return  = $this->makeCustomerReturn();
        $headers = $this->bodegaHeaders();

        $response = $this->postJson("/api/bodega/returns/{$return->id}/form", [], $headers)->assertStatus(200);

        $this->assertNotEmpty($response->json('url'));
        $this->assertSame(['url'], array_keys($response->json()), 'misma respuesta que el GET anterior: solo {url}');
        $files = $this->s3Files();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith("bodega/customer-returns/{$return->id}/formulario-", $files[0]);
        $this->assertSame(0, $this->documentCount(), 'el formulario no crea fila en order_documents');
    }

    public function test_post_delivery_guide_crea_el_documento_lo_sube_y_responde_la_url(): void
    {
        $return  = $this->makeCustomerReturn();
        $headers = $this->bodegaHeaders();
        $before  = $this->documentCount();

        $response = $this->postJson("/api/bodega/returns/{$return->id}/delivery-guide", [], $headers)->assertStatus(200);

        $this->assertNotEmpty($response->json('url'));
        $this->assertSame(['url'], array_keys($response->json()));
        $this->assertCount(1, $this->s3Files());
        $this->assertSame($before + 1, $this->documentCount());
        $document = OrderDocument::query()->latest('id')->firstOrFail();
        $this->assertSame(OrderDocument::TIPO_GUIA_ENTREGA, $document->tipo);
        $this->assertSame($return->order_id, $document->order_id);
        $this->assertSame($this->userIdByEmail('designer@atlantic.test'), $document->uploaded_by);
    }

    /** Semántica de repetición (sin cambios respecto al GET): cada llamada genera un objeto S3 nuevo y, en la guía, un documento nuevo; no hay deduplicación. */
    public function test_repetir_la_accion_sigue_creando_un_objeto_nuevo_cada_vez(): void
    {
        $return  = $this->makeCustomerReturn();
        $headers = $this->bodegaHeaders();

        $this->postJson("/api/bodega/returns/{$return->id}/delivery-guide", [], $headers)->assertStatus(200);
        $this->postJson("/api/bodega/returns/{$return->id}/delivery-guide", [], $headers)->assertStatus(200);
        $this->assertSame(2, $this->documentCount(), 'la guía crea un documento por llamada');
        $this->assertCount(2, $this->s3Files());

        $this->postJson("/api/bodega/returns/{$return->id}/form", [], $headers)->assertStatus(200);
        $this->postJson("/api/bodega/returns/{$return->id}/form", [], $headers)->assertStatus(200);
        $this->assertCount(4, $this->s3Files(), 'el formulario sube un objeto nuevo por llamada');
        $this->assertSame(2, $this->documentCount(), 'el formulario no toca order_documents');
    }

    // ── 404, permisos, verbo ──────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function rutas(): array
    {
        return ['form' => ['form'], 'delivery-guide' => ['delivery-guide']];
    }

    #[DataProvider('rutas')]
    public function test_una_devolucion_inexistente_responde_404_y_no_sube_nada(string $ruta): void
    {
        $this->postJson("/api/bodega/returns/99999999/{$ruta}", [], $this->bodegaHeaders())->assertStatus(404);

        $this->assertSame([], $this->s3Files());
        $this->assertSame(0, $this->documentCount());
    }

    #[DataProvider('rutas')]
    public function test_sin_permiso_de_bodega_responde_403_y_no_genera_nada(string $ruta): void
    {
        $return = $this->makeCustomerReturn();
        $token  = $this->loginAs('electrician@atlantic.test', self::PASSWORD, 'electrician');

        $this->postJson("/api/bodega/returns/{$return->id}/{$ruta}", [], $this->authHeaders($token))->assertStatus(403);

        $this->assertSame([], $this->s3Files());
        $this->assertSame(0, $this->documentCount());
    }

    #[DataProvider('rutas')]
    public function test_sin_token_responde_401(string $ruta): void
    {
        $return = $this->makeCustomerReturn();

        $this->postJson("/api/bodega/returns/{$return->id}/{$ruta}")->assertStatus(401);
    }

    #[DataProvider('rutas')]
    public function test_el_get_viejo_ya_no_existe_y_responde_405(string $ruta): void
    {
        $return  = $this->makeCustomerReturn();
        $headers = $this->bodegaHeaders();

        $this->getJson("/api/bodega/returns/{$return->id}/{$ruta}", $headers)->assertStatus(405);

        $this->assertSame([], $this->s3Files());
        $this->assertSame(0, $this->documentCount());
    }

    // ── «Ver como usuario» ────────────────────────────────────────────────────────────────────────────────

    #[DataProvider('rutas')]
    public function test_con_claim_act_el_post_da_403_impersonation_read_only_sin_crear_nada(string $ruta): void
    {
        $return = $this->makeCustomerReturn();
        $this->grantBodegaPermissions('designer@atlantic.test');
        $designer = $this->userIdByEmail('designer@atlantic.test');

        $admin = $this->authHeaders($this->loginAs('superadmin@atlantic.test', self::PASSWORD, 'superadmin'));
        $token = (string) $this->postJson("/api/users/{$designer}/impersonate", [
            'reason' => 'SCRUM-1180 ver devoluciones', 'operator_password' => self::PASSWORD,
        ], $admin)->assertStatus(201)->json('token');

        $this->postJson("/api/bodega/returns/{$return->id}/{$ruta}", [], $this->authHeaders($token))
            ->assertStatus(403)->assertJsonPath('code', 'impersonation_read_only');

        $this->assertSame([], $this->s3Files(), 'no se sube nada a S3');
        $this->assertSame(0, $this->documentCount(), 'no se crea ningún documento');
        $this->makeCurrentTenant();
        $log = AuditLog::query()->where('action', AuditService::AUTH_IMPERSONATION_WRITE_BLOCKED)->latest('id')->firstOrFail();
        $this->assertSame("api/bodega/returns/{$return->id}/{$ruta}", $log->entity_id);
        $this->assertSame('POST', $log->metadata['method']);
        $this->assertSame($this->userIdByEmail('superadmin@atlantic.test'), $log->user_id);
        $this->assertSame($designer, $log->metadata['impersonated_user_id']);
    }

    #[DataProvider('rutas')]
    public function test_con_claim_act_el_get_viejo_tambien_esta_cerrado(string $ruta): void
    {
        $this->grantBodegaPermissions('designer@atlantic.test');
        $designer = $this->userIdByEmail('designer@atlantic.test');
        $admin = $this->authHeaders($this->loginAs('superadmin@atlantic.test', self::PASSWORD, 'superadmin'));
        $token = (string) $this->postJson("/api/users/{$designer}/impersonate", [
            'reason' => 'SCRUM-1180 ver devoluciones', 'operator_password' => self::PASSWORD,
        ], $admin)->assertStatus(201)->json('token');

        $this->getJson("/api/bodega/returns/1/{$ruta}", $this->authHeaders($token))->assertStatus(405);
    }
}
