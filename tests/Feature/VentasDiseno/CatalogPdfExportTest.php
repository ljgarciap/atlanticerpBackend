<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\CRM\Models\CrmSetting;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\VentasDiseno\Jobs\GenerateCatalogPdfVolumesJob;
use App\Modules\VentasDiseno\Models\CatalogPdfExport;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Services\CatalogPdfExportService;
use App\Modules\VentasDiseno\Services\CatalogPdfService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-998 (auditoría SCRUM-995, sitio E; docs/specs/scrum998-catalog-pdf-background.md) — PDF de Catálogo con muchos
 * productos: hasta `catalog_pdf_max_single_products` sigue siendo el PDF plano síncrono; por encima se genera en segundo plano
 * un ZIP de volúmenes (un PDF por tanda) con aviso in-app. Con el catálogo real (~30K) dompdf agotaba los 256 MB de PHP-FPM
 * (299 MB y 45 s con solo 2.000 productos), y `max_execution_time` es de 30 s.
 *
 * Los umbrales se fijan directo en `crm_settings` (no por la API) para poder usar tandas de 2 productos: la validación de la
 * API exige volúmenes de al menos 50 (ver `CrmSettingsRequest`).
 */
class CatalogPdfExportTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    private const OWNER = 'designer@atlantic.test';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
    }

    /**
     * El cliente HTTP de pruebas resetea el tenant "actual" al terminar cada petición; sin esto, cualquier modelo tocado después
     * de un `postJson`/`getJson` resolvería tablas sin el schema del tenant (`crm_settings`, `catalog_pdf_exports`).
     *
     * @param string $method
     * @param string $uri
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @param int $options
     */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $response = parent::json($method, $uri, $data, $headers, $options);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return $response;
    }

    private function grantRead(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', $email)->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
    }

    private function setting(string $key, int $value): void
    {
        CrmSetting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    /** @return array<string, string> */
    private function ownerHeaders(): array
    {
        $this->grantRead(self::OWNER);

        return $this->authHeaders($this->loginAs(self::OWNER, 'Password123!', 'designer'));
    }

    /** @param array<string, mixed> $overrides */
    private function product(int $i, array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => sprintf('EXP-%03d', $i), 'name' => sprintf('Producto export %03d', $i), 'description' => 'x',
            'brand' => 'Marca', 'price_full' => 100 + $i, 'cost' => 50, 'stock_quantity' => 7, 'is_active' => true,
            ...$overrides,
        ]);
    }

    private function products(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->product($i);
        }
    }

    /** Un export recién pedido (sin correr el job), con Queue::fake() para no tocar Redis. */
    private function requestExport(int $userId, int $total = 5, string $mode = 'completo'): CatalogPdfExport
    {
        Queue::fake();

        return app(CatalogPdfExportService::class)->start(\App\Models\User::findOrFail($userId), $mode, [], $total);
    }

    private function ownerId(): int
    {
        return $this->userIdByEmail(self::OWNER);
    }

    // ── send-pdf: PDF plano hasta el máximo, 202 por encima ──────────────────────────────────

    public function test_hasta_el_maximo_devuelve_el_pdf_plano_como_siempre(): void
    {
        $this->setting('catalog_pdf_max_single_products', 5);
        $this->products(5);
        Queue::fake();

        $this->withHeaders($this->ownerHeaders())
            ->postJson('/api/ventas-diseno/catalog/send-pdf', ['mode' => 'completo'])
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');

        Queue::assertNothingPushed();
        $this->assertSame(0, CatalogPdfExport::count());
    }

    public function test_por_encima_del_maximo_responde_202_y_encola_el_job_en_la_cola_larga(): void
    {
        $this->setting('catalog_pdf_max_single_products', 5);
        $this->setting('catalog_pdf_volume_size', 2);
        $this->products(6);
        Queue::fake();

        $response = $this->withHeaders($this->ownerHeaders())
            ->postJson('/api/ventas-diseno/catalog/send-pdf', ['mode' => 'completo'])
            ->assertStatus(202);

        $this->assertSame('queued', $response->json('status'));
        $this->assertSame(6, $response->json('total_products'));
        $this->assertSame(3, $response->json('volumes'), '6 productos en tandas de 2');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $response->json('export_id'));
        $this->assertArrayNotHasKey('id', $response->json(), 'el id interno nunca se expone');

        Queue::assertPushed(GenerateCatalogPdfVolumesJob::class, fn (GenerateCatalogPdfVolumesJob $job): bool => $job->connection === 'redis-long' && $job->queue === 'pdf');

        $export = CatalogPdfExport::where('uuid', $response->json('export_id'))->firstOrFail();
        $this->assertSame(2, $export->volume_size, 'guarda el tamaño de volumen vigente al pedir');
        $this->assertSame($this->ownerId(), $export->requested_by);
    }

    public function test_seleccionados_por_encima_del_maximo_tambien_va_en_segundo_plano_y_guarda_los_ids(): void
    {
        $this->setting('catalog_pdf_max_single_products', 2);
        $ids = [];
        for ($i = 1; $i <= 4; $i++) {
            $ids[] = $this->product($i)->id;
        }
        Queue::fake();

        $response = $this->withHeaders($this->ownerHeaders())
            ->postJson('/api/ventas-diseno/catalog/send-pdf', ['mode' => 'seleccionados', 'product_ids' => $ids])
            ->assertStatus(202);

        $export = CatalogPdfExport::where('uuid', $response->json('export_id'))->firstOrFail();
        $this->assertSame('seleccionados', $export->mode);
        $this->assertEqualsCanonicalizing($ids, $export->product_ids);
    }

    public function test_product_ids_con_mas_de_5000_elementos_retorna_422(): void
    {
        $this->withHeaders($this->ownerHeaders())
            ->postJson('/api/ventas-diseno/catalog/send-pdf', ['mode' => 'seleccionados', 'product_ids' => range(1, 5001)])
            ->assertStatus(422);
    }

    public function test_el_pdf_plano_nunca_hidrata_mas_de_1000_productos(): void
    {
        $table = (new CatalogProduct())->getTable();
        $now = now()->toDateTimeString();
        foreach (array_chunk(range(1, 1001), 500) as $chunk) {
            DB::table($table)->insert(array_map(fn (int $i): array => [
                'reference' => "BIG-{$i}", 'name' => "Big {$i}", 'description' => 'x', 'price_full' => 1, 'cost' => 1,
                'stock_quantity' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }

        $this->expectException(\DomainException::class);
        app(CatalogPdfService::class)->generate('completo');
    }

    // ── El job: volúmenes, ZIP, S3, aviso ────────────────────────────────────────────────────

    /** @return list<string> contenido de cada archivo del ZIP guardado en el disco de pruebas */
    private function zipEntries(CatalogPdfExport $export): array
    {
        $bytes = Storage::disk('s3-private')->get((string) $export->storage_key);
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true, 'el ZIP se abre');
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($tmp);

        return $entries;
    }

    public function test_run_genera_un_pdf_por_volumen_los_zipea_y_avisa_al_solicitante(): void
    {
        $this->setting('catalog_pdf_volume_size', 2);
        $this->setting('catalog_pdf_export_retention_days', 3);
        $this->products(5);
        $export = $this->requestExport($this->ownerId(), 5);

        app(CatalogPdfExportService::class)->run($export->id);

        $export->refresh();
        $this->assertSame('done', $export->status);
        $this->assertNotNull($export->storage_key);
        $this->assertStringStartsWith("ventas-diseno/catalog-exports/{$export->uuid}/", (string) $export->storage_key);
        Storage::disk('s3-private')->assertExists((string) $export->storage_key);
        $this->assertGreaterThan(0, (int) $export->size_bytes);
        $this->assertEqualsWithDelta(now()->addDays(3)->timestamp, $export->expires_at?->timestamp, 5);

        $entries = $this->zipEntries($export);
        $this->assertCount(3, $entries, '5 productos en tandas de 2 = 3 volúmenes');
        $names = array_keys($entries);
        sort($names);
        $this->assertMatchesRegularExpression('/-vol-01-de-03\.pdf$/', $names[0]);
        $this->assertMatchesRegularExpression('/-vol-03-de-03\.pdf$/', $names[2]);
        foreach ($entries as $name => $content) {
            $this->assertStringStartsWith('%PDF', $content, "{$name} es un PDF");
        }

        $send = NotificationSend::where('source', NotificationSend::SOURCE_CATALOG_PDF_EXPORT)->sole();
        $this->assertSame([$this->ownerId()], $send->audience);
        $this->assertSame(['in_app'], $send->channels);
        $this->assertSame('Tu catálogo en PDF está listo', $send->content['subject']);
        $this->assertSame("/ventas-diseno/catalog?export={$export->uuid}", $send->content['cta_url']);
    }

    public function test_cada_volumen_lleva_solo_su_tanda_ordenada_por_nombre_y_sin_datos_de_stock(): void
    {
        $this->setting('catalog_pdf_volume_size', 2);
        $this->products(5);
        $seen = [];
        View::composer('ventas-diseno.catalog-pdf', function ($view) use (&$seen): void {
            $seen[] = ['label' => $view->getData()['volumeLabel'], 'rows' => $view->getData()['products']];
        });
        $export = $this->requestExport($this->ownerId(), 5);

        app(CatalogPdfExportService::class)->run($export->id);

        $this->assertCount(3, $seen);
        $this->assertSame(['Volumen 1 de 3', 'Volumen 2 de 3', 'Volumen 3 de 3'], array_column($seen, 'label'));
        $refs = array_map(fn (array $v): array => array_column($v['rows'], 'reference'), $seen);
        $this->assertSame([['EXP-001', 'EXP-002'], ['EXP-003', 'EXP-004'], ['EXP-005']], $refs, 'tandas contiguas, ordenadas, sin repetir ni omitir');
        foreach ($seen as $volume) {
            foreach ($volume['rows'] as $row) {
                foreach (['stock_quantity', 'disponible', 'en_camino', 'cost', 'margin_percent', 'por_servir'] as $forbidden) {
                    $this->assertArrayNotHasKey($forbidden, $row, "RN1: [{$forbidden}] nunca va al PDF");
                }
            }
        }
    }

    public function test_no_hidrata_cada_producto_mas_de_una_vez(): void
    {
        $this->setting('catalog_pdf_volume_size', 50);
        $this->products(120);
        $hydrated = 0;
        $active = false;
        CatalogProduct::retrieved(function () use (&$hydrated, &$active): void {
            if ($active) {
                $hydrated++;
            }
        });
        $export = $this->requestExport($this->ownerId(), 120);

        $active = true;
        app(CatalogPdfExportService::class)->run($export->id);
        $active = false;

        $this->assertSame('done', $export->fresh()->status);
        $this->assertSame(120, $hydrated, 'las tres tandas leen cada producto UNA vez (nunca el catálogo entero por tanda)');
    }

    public function test_solo_incluye_productos_activos_y_en_seleccionados_solo_los_pedidos(): void
    {
        $this->setting('catalog_pdf_volume_size', 50);
        $a = $this->product(1);
        $this->product(2);
        $this->product(3, ['is_active' => false]);
        $seen = [];
        View::composer('ventas-diseno.catalog-pdf', function ($view) use (&$seen): void {
            $seen[] = array_column($view->getData()['products'], 'reference');
        });

        Queue::fake();
        $service = app(CatalogPdfExportService::class);
        $completo = $service->start(\App\Models\User::findOrFail($this->ownerId()), 'completo', [], 2);
        $seleccion = $service->start(\App\Models\User::findOrFail($this->ownerId()), 'seleccionados', [$a->id], 1);
        $service->run($completo->id);
        $service->run($seleccion->id);

        $this->assertSame([['EXP-001', 'EXP-002'], ['EXP-001']], $seen);
    }

    public function test_un_job_repetido_o_concurrente_no_duplica_nada(): void
    {
        $this->setting('catalog_pdf_volume_size', 50);
        $this->products(3);
        $export = $this->requestExport($this->ownerId(), 3);
        $service = app(CatalogPdfExportService::class);

        $service->run($export->id);
        $key = (string) $export->fresh()->storage_key;
        $service->run($export->id); // segunda ejecución sobre una exportación ya terminada

        $this->assertSame(1, NotificationSend::where('source', NotificationSend::SOURCE_CATALOG_PDF_EXPORT)->count());
        $this->assertCount(1, Storage::disk('s3-private')->allFiles());
        $this->assertSame($key, (string) $export->fresh()->storage_key);

        // Exportación ya tomada por otro worker (running): esta ejecución sale sin tocar nada.
        $other = $this->requestExport($this->ownerId(), 3);
        $other->update(['status' => 'running']);
        $service->run($other->id);
        $this->assertSame('running', $other->fresh()->status);
        $this->assertNull($other->fresh()->storage_key);
    }

    public function test_si_falla_marca_failed_con_mensaje_seguro_avisa_y_no_deja_archivos(): void
    {
        $this->setting('catalog_pdf_volume_size', 50);
        $this->products(3);
        $export = $this->requestExport($this->ownerId(), 3);
        // Fallo real al renderizar un volumen (CatalogPdfService es `final`, no se mockea): la vista lanza una excepción.
        View::composer('ventas-diseno.catalog-pdf', function (): void {
            throw new \RuntimeException('detalle interno con secreto');
        });

        app(CatalogPdfExportService::class)->run($export->id);

        $export->refresh();
        $this->assertSame('failed', $export->status);
        $this->assertSame(CatalogPdfExportService::FAILURE_MESSAGE, $export->error_message);
        $this->assertStringNotContainsString('secreto', (string) $export->error_message);
        $this->assertNull($export->storage_key);
        $this->assertSame([], Storage::disk('s3-private')->allFiles());

        $send = NotificationSend::where('source', NotificationSend::SOURCE_CATALOG_PDF_EXPORT)->sole();
        $this->assertSame('No se pudo generar tu catálogo en PDF', $send->content['subject']);
        $this->assertStringNotContainsString('secreto', $send->content['body']);
    }

    public function test_el_hook_failed_del_job_cierra_una_exportacion_colgada_y_no_pisa_una_terminada(): void
    {
        $this->products(2);
        $running = $this->requestExport($this->ownerId(), 2);
        $running->update(['status' => 'running']);
        $done = $this->requestExport($this->ownerId(), 2);
        $done->update(['status' => 'done', 'storage_key' => 'ventas-diseno/catalog-exports/x/x.zip']);

        (new GenerateCatalogPdfVolumesJob($running->id, 'atlantic'))->failed(new \RuntimeException('timeout de Horizon'));
        (new GenerateCatalogPdfVolumesJob($done->id, 'atlantic'))->failed(new \RuntimeException('timeout de Horizon'));

        $this->assertSame('failed', $running->fresh()->status);
        $this->assertSame('done', $done->fresh()->status, 'una exportación ya terminada no se pisa');
        $this->assertSame(1, NotificationSend::where('source', NotificationSend::SOURCE_CATALOG_PDF_EXPORT)->count());
    }

    // ── GET /catalog/pdf-exports/{uuid} ──────────────────────────────────────────────────────

    public function test_estado_y_descarga_solo_para_el_solicitante_o_un_superadmin(): void
    {
        $this->setting('catalog_pdf_volume_size', 50);
        $this->products(2);
        $export = $this->requestExport($this->ownerId(), 2);
        $owner = $this->ownerHeaders();

        $pending = $this->withHeaders($owner)->getJson("/api/ventas-diseno/catalog/pdf-exports/{$export->uuid}")->assertStatus(200);
        $this->assertSame('queued', $pending->json('status'));
        $this->assertNull($pending->json('download_url'), 'sin ZIP todavía no hay enlace');

        app(CatalogPdfExportService::class)->run($export->id);
        $done = $this->withHeaders($owner)->getJson("/api/ventas-diseno/catalog/pdf-exports/{$export->uuid}")->assertStatus(200);
        $this->assertSame('done', $done->json('status'));
        $this->assertNotEmpty($done->json('download_url'));
        $this->assertSame(2, $done->json('total_products'));
        $this->assertArrayNotHasKey('id', $done->json());
        $this->assertArrayNotHasKey('storage_key', $done->json(), 'la clave de S3 nunca se expone, solo la URL firmada');

        // Otra persona con el mismo permiso de lectura: 404, igual que una exportación inexistente.
        $this->grantRead('readonly@atlantic.test');
        $stranger = $this->authHeaders($this->loginAs('readonly@atlantic.test', 'Password123!', 'electrician'));
        $this->withHeaders($stranger)->getJson("/api/ventas-diseno/catalog/pdf-exports/{$export->uuid}")->assertStatus(404);

        // Superadmin sí.
        $super = $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin'));
        $this->withHeaders($super)->getJson("/api/ventas-diseno/catalog/pdf-exports/{$export->uuid}")->assertStatus(200);

        // Inexistente, uuid mal formado y sin autenticar.
        $this->withHeaders($owner)->getJson('/api/ventas-diseno/catalog/pdf-exports/00000000-0000-4000-8000-000000000000')->assertStatus(404);
        $this->withHeaders($owner)->getJson('/api/ventas-diseno/catalog/pdf-exports/no-es-un-uuid')->assertStatus(404);
        $this->getJson("/api/ventas-diseno/catalog/pdf-exports/{$export->uuid}", ['Authorization' => ''])->assertStatus(401);
    }

    public function test_una_exportacion_vencida_ya_no_da_enlace(): void
    {
        $this->setting('catalog_pdf_volume_size', 50);
        $this->products(1);
        $export = $this->requestExport($this->ownerId(), 1);
        app(CatalogPdfExportService::class)->run($export->id);
        $export->update(['expires_at' => now()->subMinute()]);

        $this->withHeaders($this->ownerHeaders())->getJson("/api/ventas-diseno/catalog/pdf-exports/{$export->uuid}")
            ->assertStatus(200)
            ->assertJsonPath('download_url', null);
    }

    // ── Purga programada ─────────────────────────────────────────────────────────────────────

    public function test_purga_borra_zip_y_fila_de_lo_vencido_y_cierra_lo_colgado(): void
    {
        $this->setting('catalog_pdf_volume_size', 50);
        $this->products(1);
        $vencido = $this->requestExport($this->ownerId(), 1);
        app(CatalogPdfExportService::class)->run($vencido->id);
        $vigente = $this->requestExport($this->ownerId(), 1);
        app(CatalogPdfExportService::class)->run($vigente->id);
        $vencido->update(['expires_at' => now()->subDay()]);
        $colgado = $this->requestExport($this->ownerId(), 1);
        $colgado->forceFill(['status' => 'running', 'created_at' => now()->subHours(7)])->save(); // `created_at` no es fillable
        $keyVencido = (string) $vencido->fresh()->storage_key;
        $this->assertNotSame('', $keyVencido);

        $purged = app(CatalogPdfExportService::class)->purgeExpired();

        $this->assertSame(1, $purged);
        $this->assertNull(CatalogPdfExport::find($vencido->id));
        Storage::disk('s3-private')->assertMissing($keyVencido);
        $this->assertNotNull(CatalogPdfExport::find($vigente->id));
        Storage::disk('s3-private')->assertExists((string) $vigente->fresh()->storage_key);
        $this->assertSame('failed', $colgado->fresh()->status);
    }

    public function test_el_comando_de_purga_corre_por_tenant(): void
    {
        $this->setting('catalog_pdf_volume_size', 50);
        $this->products(1);
        $export = $this->requestExport($this->ownerId(), 1);
        app(CatalogPdfExportService::class)->run($export->id);
        $export->update(['expires_at' => now()->subDay()]);

        $this->artisan('catalog-pdf:purge-exports')->assertExitCode(0);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertNull(CatalogPdfExport::find($export->id));
    }

    // ── Precarga de fotos en paralelo ────────────────────────────────────────────────────────

    private function jpeg(): string
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }

    public function test_precarga_fotos_embebidas_y_las_que_fallan_quedan_como_sin_imagen(): void
    {
        $this->product(1, ['photo_url' => 'https://fotos.test/ok.jpg']);
        $this->product(2, ['photo_url' => 'https://fotos.test/no-existe.jpg']);
        $this->product(3, ['photo_url' => 'https://fotos.test/es-html']);
        $this->product(4, ['photo_url' => 'https://fotos.test/enorme.jpg']);
        $this->product(5, ['photo_url' => 'https://fotos.test/cae.jpg']);
        $this->product(6, ['photo_url' => null]);
        Http::fake([
            'https://fotos.test/ok.jpg'         => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'https://fotos.test/no-existe.jpg'  => Http::response('nf', 404),
            'https://fotos.test/es-html'        => Http::response('<html>no soy una imagen</html>', 200, ['Content-Type' => 'image/jpeg']),
            'https://fotos.test/enorme.jpg'     => Http::response(str_repeat('a', 3 * 1024 * 1024), 200),
            'https://fotos.test/cae.jpg'        => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
        ]);
        $rows = [];
        View::composer('ventas-diseno.catalog-pdf', function ($view) use (&$rows): void {
            $rows = $view->getData()['products'];
        });

        $response = app(CatalogPdfService::class)->generate('completo')->output();

        $this->assertStringStartsWith('%PDF', $response, 'el PDF se genera aunque fallen fotos');
        $byRef = array_column($rows, 'photo_url', 'reference');
        $this->assertStringStartsWith('data:image/jpeg;base64,', (string) $byRef['EXP-001']);
        foreach (['EXP-002', 'EXP-003', 'EXP-004', 'EXP-005', 'EXP-006'] as $ref) {
            $this->assertNull($byRef[$ref], "{$ref} queda como Sin imagen");
        }
        Http::assertSent(fn ($request): bool => $request->url() === 'https://fotos.test/ok.jpg');
        $this->assertGreaterThanOrEqual(4, count(Http::recorded()), 'se intentó descargar cada foto remota');
    }

    public function test_reintenta_una_sola_vez_las_fotos_que_fallaron_por_una_causa_transitoria(): void
    {
        $this->product(1, ['photo_url' => 'https://fotos.test/intermitente.jpg']);   // cae la 1.ª vez, responde la 2.ª
        $this->product(2, ['photo_url' => 'https://fotos.test/error-5xx.jpg']);      // 503 y luego bien
        $this->product(3, ['photo_url' => 'https://fotos.test/siempre-cae.jpg']);    // nunca responde: queda "Sin imagen"
        $this->product(4, ['photo_url' => 'https://fotos.test/no-existe.jpg']);      // 404 definitivo: NO se reintenta
        $this->product(5, ['photo_url' => 'https://fotos.test/ok.jpg']);
        // Un cierre (no `Http::sequence()`): `Http::recorded()` no cuenta las conexiones que fallan, y acá hay que contar CADA intento.
        $hits = [];
        $jpeg = $this->jpeg();
        Http::fake(function ($request) use (&$hits, $jpeg) {
            $url = $request->url();
            $hits[$url] = ($hits[$url] ?? 0) + 1;
            $attempt = $hits[$url];

            return match ($url) {
                'https://fotos.test/intermitente.jpg' => $attempt === 1 ? throw new \Illuminate\Http\Client\ConnectionException('cURL error 28') : Http::response($jpeg, 200),
                'https://fotos.test/error-5xx.jpg'    => $attempt === 1 ? Http::response('x', 503) : Http::response($jpeg, 200),
                'https://fotos.test/siempre-cae.jpg'  => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28'),
                'https://fotos.test/no-existe.jpg'    => Http::response('nf', 404),
                default                               => Http::response($jpeg, 200),
            };
        });
        $rows = [];
        View::composer('ventas-diseno.catalog-pdf', function ($view) use (&$rows): void {
            $rows = $view->getData()['products'];
        });

        app(CatalogPdfService::class)->generate('completo')->output();

        $byRef = array_column($rows, 'photo_url', 'reference');
        $this->assertStringStartsWith('data:image/jpeg;base64,', (string) $byRef['EXP-001'], 'la foto intermitente se recupera con el reintento');
        $this->assertStringStartsWith('data:image/jpeg;base64,', (string) $byRef['EXP-002'], 'un 5xx transitorio también se reintenta');
        $this->assertNull($byRef['EXP-003'], 'si el reintento también falla queda como Sin imagen (el PDF no se frena)');
        $this->assertNull($byRef['EXP-004']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', (string) $byRef['EXP-005']);

        $this->assertSame(2, $hits['https://fotos.test/intermitente.jpg'], '1 intento + 1 reintento');
        $this->assertSame(2, $hits['https://fotos.test/error-5xx.jpg'], 'un 5xx transitorio también se reintenta una vez');
        $this->assertSame(2, $hits['https://fotos.test/siempre-cae.jpg'], 'un solo reintento, no un bucle');
        $this->assertSame(1, $hits['https://fotos.test/no-existe.jpg'], 'un 404 no se reintenta');
        $this->assertSame(1, $hits['https://fotos.test/ok.jpg'], 'lo que salió bien no se vuelve a pedir');
    }

    public function test_dompdf_ya_no_sale_a_la_red_y_la_foto_embebida_aparece_en_el_pdf(): void
    {
        $this->product(1, ['photo_url' => 'https://fotos.test/ok.jpg']);
        Http::fake(['https://fotos.test/ok.jpg' => Http::response($this->jpeg(), 200)]);

        $pdf = app(CatalogPdfService::class)->generate('completo')->output();

        $this->assertStringContainsString('/Subtype /Image', $pdf, 'la foto precargada (data URI) se dibuja aunque isRemoteEnabled esté apagado');
        Http::assertSentCount(1); // solo la precarga: dompdf no descarga nada por su cuenta
    }

    // ── Máximo de fotos del PDF plano (cada foto remota es una descarga; nginx corta a los 60 s) ────

    public function test_cuenta_como_foto_photo_url_o_photo_key_y_no_los_vacios(): void
    {
        $this->product(1, ['photo_url' => 'https://fotos.test/a.jpg']);
        $this->product(2, ['photo_key' => 'catalog/2/foto.jpg']);
        $this->product(3, ['photo_url' => '']);
        $this->product(4, ['photo_url' => null, 'photo_key' => null]);
        $this->product(5, ['photo_url' => 'https://fotos.test/inactivo.jpg', 'is_active' => false]);

        $service = app(CatalogPdfService::class);

        $this->assertSame(2, $service->countPhotos('completo'), 'solo activos con photo_url o photo_key no vacíos');
        $ids = CatalogProduct::whereIn('reference', ['EXP-001', 'EXP-003'])->pluck('id')->all();
        $this->assertSame(1, $service->countPhotos('seleccionados', $ids), 'en seleccionados solo cuentan los pedidos');
    }

    public function test_pocos_productos_pero_muchas_fotos_tambien_va_en_segundo_plano(): void
    {
        $this->setting('catalog_pdf_max_single_products', 500);
        $this->setting('catalog_pdf_max_single_photos', 2);
        for ($i = 1; $i <= 3; $i++) {
            $this->product($i, ['photo_url' => "https://fotos.test/{$i}.jpg"]);
        }
        Queue::fake();
        Http::fake();
        $headers = $this->ownerHeaders();

        $this->withHeaders($headers)->postJson('/api/ventas-diseno/catalog/send-pdf', ['mode' => 'completo'])->assertStatus(202);
        Queue::assertPushed(GenerateCatalogPdfVolumesJob::class);

        // Con margen de fotos vuelve al PDF plano (200) y descarga las 3 fotos en paralelo.
        $this->setting('catalog_pdf_max_single_photos', 3);
        Http::fake(['https://fotos.test/*' => Http::response($this->jpeg(), 200)]);
        $this->withHeaders($headers)->postJson('/api/ventas-diseno/catalog/send-pdf', ['mode' => 'completo'])
            ->assertStatus(200)->assertHeader('content-type', 'application/pdf');
    }

    public function test_muchos_productos_sin_fotos_dentro_del_maximo_sigue_siendo_pdf_plano(): void
    {
        $this->setting('catalog_pdf_max_single_products', 10);
        $this->setting('catalog_pdf_max_single_photos', 1);
        $this->products(6);
        Queue::fake();

        $this->withHeaders($this->ownerHeaders())->postJson('/api/ventas-diseno/catalog/send-pdf', ['mode' => 'completo'])->assertStatus(200);

        Queue::assertNothingPushed();
    }

    // ── Umbrales configurables (CrmSettings) ─────────────────────────────────────────────────

    public function test_los_umbrales_tienen_default_y_se_editan_solo_por_superadmin_con_rangos(): void
    {
        $super = $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin'));

        $show = $this->withHeaders($super)->getJson('/api/crm/settings')->assertStatus(200);
        $this->assertSame(500, $show->json('catalog_pdf_max_single_products'));
        $this->assertSame(200, $show->json('catalog_pdf_max_single_photos'));
        $this->assertSame(500, $show->json('catalog_pdf_volume_size'));
        $this->assertSame(7, $show->json('catalog_pdf_export_retention_days'));

        $this->withHeaders($super)->putJson('/api/crm/settings', [
            'catalog_pdf_max_single_products' => 800, 'catalog_pdf_max_single_photos' => 120, 'catalog_pdf_volume_size' => 250, 'catalog_pdf_export_retention_days' => 14,
        ])->assertStatus(200)
            ->assertJsonPath('catalog_pdf_max_single_photos', 120)
            ->assertJsonPath('catalog_pdf_max_single_products', 800)
            ->assertJsonPath('catalog_pdf_volume_size', 250)
            ->assertJsonPath('catalog_pdf_export_retention_days', 14);

        foreach ([
            ['catalog_pdf_max_single_products' => 0], ['catalog_pdf_max_single_products' => 1001],
            ['catalog_pdf_max_single_photos' => 0], ['catalog_pdf_max_single_photos' => 501],
            ['catalog_pdf_volume_size' => 49], ['catalog_pdf_volume_size' => 1001],
            ['catalog_pdf_export_retention_days' => 0], ['catalog_pdf_export_retention_days' => 91],
        ] as $invalid) {
            $this->withHeaders($super)->putJson('/api/crm/settings', $invalid)->assertStatus(422);
        }

        $this->withHeaders($this->ownerHeaders())->putJson('/api/crm/settings', ['catalog_pdf_volume_size' => 100])->assertStatus(403);
    }

    public function test_el_umbral_configurado_decide_entre_pdf_plano_y_segundo_plano(): void
    {
        $this->products(3);
        Queue::fake();
        $headers = $this->ownerHeaders();

        $this->setting('catalog_pdf_max_single_products', 3);
        $this->withHeaders($headers)->postJson('/api/ventas-diseno/catalog/send-pdf', ['mode' => 'completo'])->assertStatus(200);

        $this->setting('catalog_pdf_max_single_products', 2);
        $this->withHeaders($headers)->postJson('/api/ventas-diseno/catalog/send-pdf', ['mode' => 'completo'])->assertStatus(202);
    }
}
