<?php

declare(strict_types=1);

namespace Tests\Feature\Biblioteca;

use App\Modules\Biblioteca\Contracts\LibraryStore;
use App\Modules\Biblioteca\Services\S3LibraryStore;
use App\Shared\Multitenancy\Tenant;
use Aws\Command;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-984 — los endpoints contra el store REAL (`S3LibraryStore`) sobre un MockHandler del SDK, sin
 * FakeLibraryStore. El fake en memoria nunca pasa por el SDK, y por eso no vio el bug de getJsonMany
 * (CommandPool indexa por posición): aquí el listado, la URL prefirmada, el borrado y el flujo de subida
 * atraviesan el cliente S3 de verdad (Body como stream, paginación, firma local, traducción de errores).
 */
class BibliotecaRealStoreTest extends FeatureTestCase
{
    private MockHandler $mock;

    /** @var list<CommandInterface> comandos que llegaron al handler, en orden */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Cache::flush();

        config([
            'filesystems.disks.s3-library.bucket' => 'atlanticerp-library',
            'filesystems.disks.s3-library.key'    => 'AKIATEST',
            'filesystems.disks.s3-library.secret' => 'secret',
        ]);

        $this->mock = new MockHandler();
        $client     = new S3Client([
            'version' => 'latest', 'region' => 'us-east-1',
            'credentials' => ['key' => 'AKIATEST', 'secret' => 'secret'],
            'handler' => $this->mock, 'retries' => 0,
        ]);
        $this->app->instance(LibraryStore::class, (new S3LibraryStore())->withClient($client));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function ok(array $result = []): void
    {
        $this->mock->append(function (CommandInterface $cmd, RequestInterface $req) use ($result): Result {
            $this->commands[] = $cmd;

            return new Result($result);
        });
    }

    private function okBody(string $body): void
    {
        $this->ok(['Body' => Utils::streamFor($body)]);
    }

    private function fails(string $code, int $status): void
    {
        $this->mock->append(function (CommandInterface $cmd) use ($code, $status): S3Exception {
            $this->commands[] = $cmd;

            return new S3Exception($code, $cmd, ['code' => $code, 'response' => new Response($status)]);
        });
    }

    /** @return array<string, string> */
    private function normalHeaders(): array
    {
        return $this->authHeaders($this->loginAs('designer@atlantic.test', 'Password123!', 'designer'));
    }

    /** @return array<string, string> designer + manuales.manage */
    private function managerHeaders(): array
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', 'designer@atlantic.test')
            ->update(['extra_permissions' => json_encode(['manuales.manage'])]);

        return $this->normalHeaders();
    }

    /** @return array{id: string, name: string, module: string, type: string, ext: string, size_bytes: int, uploaded_by: int, created_at: string} */
    private function item(string $module, string $type, string $ext, string $name, string $createdAt): array
    {
        return [
            'id' => (string) Str::uuid(), 'name' => $name, 'module' => $module, 'type' => $type, 'ext' => $ext,
            'size_bytes' => 209, 'uploaded_by' => 1, 'created_at' => $createdAt,
        ];
    }

    private function sidecarKey(array $item): string
    {
        return "library/{$item['module']}/{$item['type']}/{$item['id']}.json";
    }

    /**
     * Encola lo que S3 respondería para listar el catálogo: un ListObjectsV2 con los sidecars, sus objetos, un
     * pending y un archivo ajeno, y después un GetObject (Body como stream) por cada sidecar, en orden.
     *
     * @param list<array<string, mixed>> $items
     */
    private function enqueueCatalog(array $items): void
    {
        $contents = [['Key' => 'library/_pending/zz-prueba-x.pdf'], ['Key' => 'library/notas.txt']];
        foreach ($items as $item) {
            $contents[] = ['Key' => "library/{$item['module']}/{$item['type']}/{$item['id']}.{$item['ext']}"];
            $contents[] = ['Key' => $this->sidecarKey($item)];
        }
        $this->ok(['Contents' => $contents, 'IsTruncated' => false]);

        foreach ($items as $item) {
            $this->okBody((string) json_encode($item, JSON_UNESCAPED_UNICODE));
        }
    }

    private function pdf(): string
    {
        return "%PDF-1.7\n" . str_repeat('x', 200);
    }

    // ── listado ──────────────────────────────────────────────────────────────

    public function test_el_listado_pasa_por_list_objects_y_get_object_y_ordena_por_fecha(): void
    {
        $a = $this->item('admin_contabilidad', 'pdf', 'pdf', 'Manual Admin', '2026-09-01T10:00:00+00:00');
        $b = $this->item('ventas_diseno', 'pdf', 'pdf', 'Ventas&Diseño', '2026-09-03T10:00:00+00:00');
        $c = $this->item('crm', 'video', 'mp4', 'Tutorial CRM', '2026-09-02T10:00:00+00:00');
        $this->enqueueCatalog([$a, $b, $c]);

        $response = $this->withHeaders($this->normalHeaders())->getJson('/api/biblioteca')->assertOk();

        $this->assertSame([$b['id'], $c['id'], $a['id']], array_column($response->json(), 'id'), 'más reciente primero');
        $this->assertSame('Ventas&Diseño', $response->json('0.name'), 'el nombre con & y ñ llega intacto por el Body stream');
        $this->assertSame(['ListObjectsV2', 'GetObject', 'GetObject', 'GetObject'], array_map(static fn (CommandInterface $c): string => $c->getName(), $this->commands));
        $this->assertSame('library/', $this->commands[0]->toArray()['Prefix']);
        $this->assertSame($this->sidecarKey($a), $this->commands[1]->toArray()['Key'], 'solo se piden los .json fuera de _pending');
    }

    public function test_el_listado_por_modulo_pide_solo_el_prefijo_de_ese_modulo(): void
    {
        $crm = $this->item('crm', 'pdf', 'pdf', 'Manual CRM', '2026-09-01T10:00:00+00:00');
        $this->enqueueCatalog([$crm]);

        $this->withHeaders($this->normalHeaders())->getJson('/api/biblioteca?modulo=crm')->assertOk()->assertJsonCount(1);

        $this->assertSame('library/crm/', $this->commands[0]->toArray()['Prefix']);
    }

    public function test_un_sidecar_que_desaparece_entre_el_listado_y_la_lectura_se_omite_sin_romper_el_resto(): void
    {
        $a = $this->item('crm', 'pdf', 'pdf', 'Uno', '2026-09-01T10:00:00+00:00');
        $b = $this->item('crm', 'pdf', 'pdf', 'Dos', '2026-09-02T10:00:00+00:00');
        $c = $this->item('crm', 'pdf', 'pdf', 'Tres', '2026-09-03T10:00:00+00:00');
        $this->ok(['Contents' => [['Key' => $this->sidecarKey($a)], ['Key' => $this->sidecarKey($b)], ['Key' => $this->sidecarKey($c)]], 'IsTruncated' => false]);
        $this->okBody((string) json_encode($a));
        $this->fails('NoSuchKey', 404); // $b lo borraron en medio
        $this->okBody((string) json_encode($c));

        $response = $this->withHeaders($this->normalHeaders())->getJson('/api/biblioteca')->assertOk();

        $this->assertSame([$c['id'], $a['id']], array_column($response->json(), 'id'));
    }

    public function test_el_listado_con_s3_caido_o_bucket_mal_puesto_responde_503_y_no_500(): void
    {
        $this->fails('AccessDenied', 403);
        $this->withHeaders($this->normalHeaders())->getJson('/api/biblioteca')
            ->assertStatus(503)->assertJsonPath('message', trans('biblioteca.storage_unavailable'));

        Cache::flush();
        $this->fails('NoSuchBucket', 404);
        $this->withHeaders($this->normalHeaders())->getJson('/api/biblioteca')->assertStatus(503);

        Cache::flush();
        $this->mock->append(fn (CommandInterface $cmd): S3Exception => new S3Exception('cURL error 28', $cmd, ['connection_error' => true]));
        $this->withHeaders($this->normalHeaders())->getJson('/api/biblioteca')->assertStatus(503);
    }

    // ── URL prefirmada ───────────────────────────────────────────────────────

    public function test_la_url_de_descarga_lleva_la_disposition_con_utf8_y_se_firma_sin_red(): void
    {
        $item = $this->item('ventas_diseno', 'pdf', 'pdf', 'Ventas&Diseño', '2026-09-03T10:00:00+00:00');
        $this->enqueueCatalog([$item]);

        $json = $this->withHeaders($this->normalHeaders())->getJson("/api/biblioteca/{$item['id']}/url?accion=descargar")->assertOk()->json();

        $parts = parse_url((string) $json['url']);
        parse_str((string) $parts['query'], $q);
        $this->assertSame('atlanticerp-library.s3.amazonaws.com', $parts['host']);
        $this->assertSame("/library/ventas_diseno/pdf/{$item['id']}.pdf", $parts['path']);
        $this->assertSame('application/pdf', $q['response-content-type'], 'el mime sale de la extensión validada');
        $this->assertStringContainsString("filename*=UTF-8''" . rawurlencode('Ventas&Diseño.pdf'), (string) $q['response-content-disposition']);
        $this->assertStringContainsString('attachment; filename="Ventas_Dise_o.pdf"', (string) $q['response-content-disposition']);
        $this->assertSame('900', $q['X-Amz-Expires']);
        $this->assertNotNull(DB::table('atlantic_auth.audit_logs')->where('action', 'biblioteca.file.downloaded')->where('entity_id', $item['id'])->first());
    }

    public function test_la_url_para_ver_un_video_usa_el_ttl_de_video_y_disposition_inline(): void
    {
        $item = $this->item('crm', 'video', 'mp4', 'Tutorial', '2026-09-03T10:00:00+00:00');
        $this->enqueueCatalog([$item]);

        $json = $this->withHeaders($this->normalHeaders())->getJson("/api/biblioteca/{$item['id']}/url?accion=ver")->assertOk()->json();

        parse_str((string) parse_url((string) $json['url'], PHP_URL_QUERY), $q);
        $this->assertSame('video/mp4', $q['response-content-type']);
        $this->assertSame('inline', $q['response-content-disposition']);
        $this->assertSame('3600', $q['X-Amz-Expires']);
    }

    public function test_la_url_con_s3_caido_responde_503(): void
    {
        $this->fails('SlowDown', 503);

        $this->withHeaders($this->normalHeaders())->getJson('/api/biblioteca/' . Str::uuid() . '/url?accion=ver')->assertStatus(503);
    }

    // ── borrado ──────────────────────────────────────────────────────────────

    public function test_el_borrado_contra_el_store_real_borra_primero_el_sidecar_y_luego_el_objeto(): void
    {
        $item = $this->item('crm', 'pdf', 'pdf', 'Manual', '2026-09-01T10:00:00+00:00');
        $this->enqueueCatalog([$item]);
        $this->ok(); // DeleteObject del sidecar
        $this->ok(); // DeleteObject del objeto

        $this->withHeaders($this->managerHeaders())->deleteJson("/api/biblioteca/{$item['id']}")->assertStatus(204);

        $deletes = array_values(array_filter($this->commands, static fn (CommandInterface $c): bool => $c->getName() === 'DeleteObject'));
        $this->assertCount(2, $deletes);
        $this->assertSame("library/crm/pdf/{$item['id']}.json", $deletes[0]->toArray()['Key']);
        $this->assertSame("library/crm/pdf/{$item['id']}.pdf", $deletes[1]->toArray()['Key']);
    }

    // ── subida completa (init -> PUT simulado -> finalize) ────────────────────

    public function test_el_finalize_contra_el_store_real_valida_copia_atada_a_la_version_y_escribe_el_sidecar(): void
    {
        $headers = $this->managerHeaders();
        $content = $this->pdf();

        // init: la firma del PUT es local, no toca S3.
        $init = $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [
            'module' => 'ventas_diseno', 'name' => 'Ventas&Diseño', 'filename' => 'v.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => strlen($content),
        ])->assertStatus(201);
        $id = (string) $init->json('upload_id');
        $this->assertStringContainsString("/library/_pending/{$id}.pdf", (string) $init->json('url'));

        $this->ok(['ContentLength' => strlen($content), 'ContentType' => 'application/pdf', 'VersionId' => 'VER-1', 'ETag' => '"e1"']); // head pending
        $this->okBody(substr($content, 0, 16));                                                                                          // magic bytes
        $this->okBody($content);                                                                                                         // escáner de PDF
        $this->ok(['CopyObjectResult' => ['ETag' => '"e2"']]);                                                                           // copy
        $this->ok(['ContentLength' => strlen($content), 'ContentType' => 'application/pdf', 'VersionId' => 'VER-2', 'ETag' => '"e2"']); // head final
        $this->ok();                                                                                                                     // sidecar
        $this->ok();                                                                                                                     // borra el pending

        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")
            ->assertStatus(201)
            ->assertJsonPath('name', 'Ventas&Diseño')
            ->assertJsonPath('module', 'ventas_diseno');

        $this->assertSame(
            ['HeadObject', 'GetObject', 'GetObject', 'CopyObject', 'HeadObject', 'PutObject', 'DeleteObject'],
            array_map(static fn (CommandInterface $c): string => $c->getName(), $this->commands),
        );
        $this->assertSame('VER-1', $this->commands[1]->toArray()['VersionId'], 'la lectura de magic bytes va atada a la versión validada');
        $this->assertSame('bytes=0-15', $this->commands[1]->toArray()['Range']);
        $copy = $this->commands[3]->toArray();
        $this->assertSame("atlanticerp-library/library/_pending/{$id}.pdf?versionId=VER-1", $copy['CopySource']);
        $this->assertSame("library/ventas_diseno/pdf/{$id}.pdf", $copy['Key']);
        $this->assertSame('REPLACE', $copy['MetadataDirective']);
        $this->assertSame('application/pdf', $copy['ContentType']);
        $sidecar = (array) json_decode((string) $this->commands[5]->toArray()['Body'], true);
        $this->assertSame($id, $sidecar['id']);
        $this->assertSame('Ventas&Diseño', $sidecar['name']);
        $this->assertSame("library/ventas_diseno/pdf/{$id}.json", $this->commands[5]->toArray()['Key']);
    }

    public function test_el_finalize_contra_el_store_real_con_la_version_borrada_rechaza_y_con_access_denied_es_503(): void
    {
        $headers = $this->managerHeaders();
        $content = $this->pdf();
        $init    = $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [
            'module' => 'crm', 'name' => 'M', 'filename' => 'm.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => strlen($content),
        ])->assertStatus(201);
        $id = (string) $init->json('upload_id');

        // 1) IAM sin GetObjectVersion (el caso real N-1): la lectura atada a la versión da AccessDenied => 503 reintentable.
        $this->ok(['ContentLength' => strlen($content), 'ContentType' => 'application/pdf', 'VersionId' => 'VER-1', 'ETag' => '"e1"']);
        $this->fails('AccessDenied', 403);
        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")
            ->assertStatus(503)->assertJsonPath('message', trans('biblioteca.storage_unavailable'));

        // 2) La versión ya no existe: rechazo 422 y el cupo se libera.
        $this->ok(['ContentLength' => strlen($content), 'ContentType' => 'application/pdf', 'VersionId' => 'VER-1', 'ETag' => '"e1"']);
        $this->fails('NoSuchVersion', 404);
        $this->ok(); // limpieza del pending
        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")
            ->assertStatus(422)->assertJsonPath('message', trans('biblioteca.rejected_changed'));
        $this->assertSame(0, (int) Cache::get('biblioteca:pending:' . $this->userId(), 0));
    }

    private function userId(): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', 'designer@atlantic.test')->value('id');
    }
}
