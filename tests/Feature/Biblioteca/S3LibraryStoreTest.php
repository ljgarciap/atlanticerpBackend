<?php

declare(strict_types=1);

namespace Tests\Feature\Biblioteca;

use App\Modules\Biblioteca\Exceptions\LibraryObjectChangedException;
use App\Modules\Biblioteca\Exceptions\LibraryStorageUnavailableException;
use App\Modules\Biblioteca\Services\S3LibraryStore;
use Aws\CommandInterface;
use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * SCRUM-984 (ronda 3) — el store REAL contra un MockHandler del SDK: nunca se toca S3 desde PHPUnit.
 * Cubre lo que el FakeLibraryStore no puede ver: los argumentos exactos de CopyObject (CopySource con
 * versionId o CopySourceIfMatch, MetadataDirective=REPLACE) y la traducción de errores de S3.
 */
class S3LibraryStoreTest extends TestCase
{
    private MockHandler $mock;

    /** @var list<CommandInterface> comandos que llegaron al handler, en orden */
    private array $commands = [];

    private S3LibraryStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.s3-library.bucket' => 'atlanticerp-library']);
        $this->mock  = new MockHandler();
        $client      = new S3Client([
            'version' => 'latest', 'region' => 'us-east-1',
            'credentials' => ['key' => 'AKIATEST', 'secret' => 'secret'],
            'handler' => $this->mock,
            'retries' => 0, // el SDK reintenta los 5xx/errores de red: con el mock vaciaría la cola
        ]);
        $this->store = (new S3LibraryStore())->withClient($client);
    }

    /** Responde OK y registra el comando. */
    private function ok(array $result = []): void
    {
        $this->mock->append(function (CommandInterface $cmd, RequestInterface $req) use ($result): Result {
            $this->commands[] = $cmd;

            return new Result($result);
        });
    }

    private function fails(string $code, int $status): void
    {
        $this->mock->append(function (CommandInterface $cmd) use ($code, $status): S3Exception {
            $this->commands[] = $cmd;

            return new S3Exception($code, $cmd, ['code' => $code, 'response' => new Response($status)]);
        });
    }

    public function test_copy_con_version_ata_la_copia_a_esa_version_y_reemplaza_el_content_type(): void
    {
        $this->ok();

        $this->store->copy('_pending/abc.pdf', 'crm/pdf/abc.pdf', 'VER-1', '"etag-1"', 'application/pdf');

        $args = $this->commands[0]->toArray();
        $this->assertSame('CopyObject', $this->commands[0]->getName());
        $this->assertSame('atlanticerp-library', $args['Bucket']);
        $this->assertSame('library/crm/pdf/abc.pdf', $args['Key']);
        $this->assertSame('atlanticerp-library/library/_pending/abc.pdf?versionId=VER-1', $args['CopySource']);
        $this->assertSame('REPLACE', $args['MetadataDirective']);
        $this->assertSame('application/pdf', $args['ContentType']);
        $this->assertArrayNotHasKey('CopySourceIfMatch', $args, 'con versionId la versión ya identifica lo validado');
    }

    public function test_copy_sin_versionado_usa_copy_source_if_match_con_el_etag_validado(): void
    {
        $this->ok();

        $this->store->copy('_pending/abc.mp4', 'servicios/video/abc.mp4', null, '"etag-9"', 'video/mp4');

        $args = $this->commands[0]->toArray();
        $this->assertSame('atlanticerp-library/library/_pending/abc.mp4', $args['CopySource']);
        $this->assertSame('"etag-9"', $args['CopySourceIfMatch']);
        $this->assertSame('REPLACE', $args['MetadataDirective']);
    }

    public function test_copy_sin_content_type_no_reemplaza_los_metadatos(): void
    {
        $this->ok();

        $this->store->copy('_pending/a.pdf', 'crm/pdf/a.pdf', 'V', null, null);

        $args = $this->commands[0]->toArray();
        $this->assertArrayNotHasKey('MetadataDirective', $args);
        $this->assertArrayNotHasKey('ContentType', $args);
    }

    public function test_copy_de_una_version_inexistente_es_objeto_cambiado(): void
    {
        $this->fails('NoSuchVersion', 404);

        $this->expectException(LibraryObjectChangedException::class);
        $this->store->copy('_pending/a.pdf', 'crm/pdf/a.pdf', 'VER-BORRADA', null, 'application/pdf');
    }

    public function test_copy_con_precondicion_fallida_es_objeto_cambiado(): void
    {
        $this->fails('PreconditionFailed', 412);

        $this->expectException(LibraryObjectChangedException::class);
        $this->store->copy('_pending/a.pdf', 'crm/pdf/a.pdf', null, '"viejo"', 'application/pdf');
    }

    public function test_copy_con_access_denied_es_almacenamiento_no_disponible(): void
    {
        $this->fails('AccessDenied', 403);

        $this->expectException(LibraryStorageUnavailableException::class);
        $this->store->copy('_pending/a.pdf', 'crm/pdf/a.pdf', 'V', null, 'application/pdf');
    }

    public function test_copy_con_5xx_es_almacenamiento_no_disponible(): void
    {
        $this->fails('SlowDown', 503);

        $this->expectException(LibraryStorageUnavailableException::class);
        $this->store->copy('_pending/a.pdf', 'crm/pdf/a.pdf', 'V', null, 'application/pdf');
    }

    public function test_head_de_una_clave_inexistente_devuelve_null_y_un_5xx_es_no_disponible(): void
    {
        $this->fails('NotFound', 404);
        $this->assertNull($this->store->head('_pending/nada.pdf'));

        $this->fails('InternalError', 500);
        $this->expectException(LibraryStorageUnavailableException::class);
        $this->store->head('_pending/a.pdf');
    }

    public function test_head_devuelve_version_y_etag(): void
    {
        $this->ok(['ContentLength' => 209, 'ContentType' => 'application/pdf', 'VersionId' => 'VER-7', 'ETag' => '"e7"']);

        $this->assertSame(
            ['size' => 209, 'content_type' => 'application/pdf', 'version_id' => 'VER-7', 'etag' => '"e7"'],
            $this->store->head('_pending/a.pdf'),
        );
    }

    public function test_read_range_ata_la_lectura_a_la_version_y_un_416_equivale_a_no_hay_mas_bytes(): void
    {
        $this->ok(['Body' => '%PDF-1.7']);
        $this->assertSame('%PDF-1.7', $this->store->readRange('_pending/a.pdf', 0, 8, 'VER-1'));
        $args = $this->commands[0]->toArray();
        $this->assertSame('VER-1', $args['VersionId']);
        $this->assertSame('bytes=0-7', $args['Range']);

        $this->fails('InvalidRange', 416);
        $this->assertSame('', $this->store->readRange('_pending/a.pdf', 9999, 8, 'VER-1'));
    }

    public function test_read_range_de_una_version_inexistente_es_objeto_cambiado(): void
    {
        $this->fails('NoSuchVersion', 404);

        $this->expectException(LibraryObjectChangedException::class);
        $this->store->readRange('_pending/a.pdf', 0, 16, 'VER-BORRADA');
    }

    public function test_un_fallo_de_red_sin_respuesta_es_almacenamiento_no_disponible(): void
    {
        $this->mock->append(function (CommandInterface $cmd): S3Exception {
            return new S3Exception('cURL error 28: timeout', $cmd, ['connection_error' => true]);
        });

        $this->expectException(LibraryStorageUnavailableException::class);
        $this->store->copy('_pending/a.pdf', 'crm/pdf/a.pdf', 'V', null, 'application/pdf');
    }

    public function test_delete_y_put_json_traducen_los_errores_de_s3(): void
    {
        $this->fails('AccessDenied', 403);
        try {
            $this->store->delete('_pending/a.pdf');
            $this->fail('debía lanzar');
        } catch (LibraryStorageUnavailableException) {
            $this->addToAssertionCount(1);
        }

        $this->fails('SlowDown', 503);
        $this->expectException(LibraryStorageUnavailableException::class);
        $this->store->putJson('crm/pdf/a.json', ['id' => 'a']);
    }

    /** @return array<string, array{string, int}> */
    public static function respuestasDeS3QueNoSonObjetoCambiado(): array
    {
        return [
            'NoSuchBucket (AWS_LIBRARY_BUCKET mal puesto)' => ['NoSuchBucket', 404],
            'otro 404 con un código distinto'              => ['SomethingElse', 404],
            'AccessDenied'                                 => ['AccessDenied', 403],
            'InvalidRequest'                               => ['InvalidRequest', 400],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('respuestasDeS3QueNoSonObjetoCambiado')]
    public function test_solo_nosuchversion_nosuchkey_y_preconditionfailed_son_objeto_cambiado(string $code, int $status): void
    {
        $this->fails($code, $status);
        try {
            $this->store->copy('_pending/a.pdf', 'crm/pdf/a.pdf', 'V', null, 'application/pdf');
            $this->fail('debía lanzar');
        } catch (LibraryStorageUnavailableException $e) {
            $this->assertSame($code, $e->getMessage(), 'conserva el código AWS para el log del operador');
        }

        $this->fails($code, $status);
        $this->expectException(LibraryStorageUnavailableException::class);
        $this->store->readRange('_pending/a.pdf', 0, 16, 'V');
    }

    public function test_nosuchkey_es_objeto_cambiado_tambien_al_copiar(): void
    {
        $this->fails('NoSuchKey', 404);

        $this->expectException(LibraryObjectChangedException::class);
        $this->store->copy('_pending/a.pdf', 'crm/pdf/a.pdf', null, '"e"', 'application/pdf');
    }

    // ── getJsonMany (bug real en dev: CommandPool indexa los resultados por POSICIÓN, no por clave) ──────

    /** Respuesta de GetObject con el Body como STREAM, igual que el SDK real (no un string suelto). */
    private function okBody(string $body): void
    {
        $this->ok(['Body' => Utils::streamFor($body)]);
    }

    public function test_get_json_many_devuelve_cada_sidecar_bajo_su_clave_con_claves_con_barras_y_punto_json(): void
    {
        $keys = [
            'admin_contabilidad/pdf/aaaaaaaa-0000-4000-8000-000000000001.json',
            'crm/pdf/bbbbbbbb-0000-4000-8000-000000000002.json',
            'ventas_diseno/video/cccccccc-0000-4000-8000-000000000003.json',
            'gerencia/pdf/dddddddd-0000-4000-8000-000000000004.json',
        ];
        $this->okBody('{"id":"uno","name":"Ventas&Diseño – Guía"}');
        $this->okBody('{"id":"dos"}');
        $this->okBody('{"id":"tres"}');
        $this->okBody('{"id":"cuatro"}');

        $out = $this->store->getJsonMany($keys);

        $this->assertSame($keys, array_keys($out), 'el orden de salida es el de $keys');
        $this->assertSame('uno', $out[$keys[0]]['id']);
        $this->assertSame('Ventas&Diseño – Guía', $out[$keys[0]]['name'], 'UTF-8 y & intactos a través del Body stream');
        $this->assertSame('dos', $out[$keys[1]]['id']);
        $this->assertSame('tres', $out[$keys[2]]['id']);
        $this->assertSame('cuatro', $out[$keys[3]]['id']);
        $this->assertCount(4, $this->commands);
        $this->assertSame('library/' . $keys[2], $this->commands[2]->toArray()['Key'], 'cada comando apunta a la clave física correcta');
    }

    public function test_get_json_many_un_nosuchkey_en_medio_es_null_solo_para_esa_clave(): void
    {
        $keys = ['crm/pdf/a.json', 'crm/pdf/b.json', 'crm/pdf/c.json'];
        $this->okBody('{"id":"a"}');
        $this->fails('NoSuchKey', 404);
        $this->okBody('{"id":"c"}');

        $out = $this->store->getJsonMany($keys);

        $this->assertSame($keys, array_keys($out));
        $this->assertSame(['id' => 'a'], $out['crm/pdf/a.json']);
        $this->assertNull($out['crm/pdf/b.json']);
        $this->assertSame(['id' => 'c'], $out['crm/pdf/c.json']);
    }

    public function test_get_json_many_un_access_denied_es_almacenamiento_no_disponible(): void
    {
        $this->okBody('{"id":"a"}');
        $this->fails('AccessDenied', 403);
        $this->okBody('{"id":"c"}');

        try {
            $this->store->getJsonMany(['crm/pdf/a.json', 'crm/pdf/b.json', 'crm/pdf/c.json']);
            $this->fail('debía lanzar');
        } catch (LibraryStorageUnavailableException $e) {
            $this->assertSame('AccessDenied', $e->getMessage());
        }
    }

    public function test_get_json_many_un_nosuchbucket_no_se_disfraza_de_objeto_inexistente(): void
    {
        $this->fails('NoSuchBucket', 404);

        $this->expectException(LibraryStorageUnavailableException::class);
        $this->store->getJsonMany(['crm/pdf/a.json']);
    }

    public function test_get_json_many_con_un_contenido_que_no_es_json_es_null_y_sin_claves_no_llama_a_s3(): void
    {
        $this->okBody('esto no es json');
        $this->assertSame(['crm/pdf/a.json' => null], $this->store->getJsonMany(['crm/pdf/a.json']));

        $this->assertSame([], $this->store->getJsonMany([]));
        $this->assertCount(1, $this->commands, 'con []: ningún comando');
    }

    public function test_get_json_many_con_mas_claves_que_la_concurrencia_mantiene_el_mapeo(): void
    {
        $keys = [];
        for ($i = 0; $i < 25; $i++) {
            $keys[] = "crm/pdf/item-{$i}.json";
            $this->okBody('{"n":' . $i . '}');
        }

        $out = $this->store->getJsonMany($keys);

        $this->assertSame($keys, array_keys($out));
        foreach ($keys as $i => $key) {
            $this->assertSame($i, $out[$key]['n'], "la clave {$key} recibió el contenido de otra");
        }
    }

    // ── getJson / putJson: Body como stream, UTF-8, errores ────────────────────────────────────────────

    public function test_put_json_y_get_json_ida_y_vuelta_con_tildes_ampersand_y_unicode(): void
    {
        $item = ['id' => 'x', 'name' => 'Ventas&Diseño "Guía" — 日本', 'size_bytes' => 209];

        $this->ok();
        $this->store->putJson('crm/pdf/x.json', $item);
        $put = $this->commands[0]->toArray();
        $this->assertSame('PUT', 'PUT');
        $this->assertSame('library/crm/pdf/x.json', $put['Key']);
        $this->assertSame('application/json', $put['ContentType']);
        $this->assertStringContainsString('Ventas&Diseño', (string) $put['Body'], 'JSON_UNESCAPED_UNICODE: sin \\u00f1');

        $this->okBody((string) $put['Body']); // lo que S3 devolvería, como stream
        $this->assertSame($item, $this->store->getJson('crm/pdf/x.json'));
    }

    public function test_get_json_distingue_inexistente_de_fallo_de_almacenamiento(): void
    {
        $this->fails('NoSuchKey', 404);
        $this->assertNull($this->store->getJson('crm/pdf/nada.json'));

        $this->fails('NoSuchBucket', 404);
        try {
            $this->store->getJson('crm/pdf/a.json');
            $this->fail('NoSuchBucket no debe verse como "no existe"');
        } catch (LibraryStorageUnavailableException $e) {
            $this->assertSame('NoSuchBucket', $e->getMessage());
        }

        $this->fails('AccessDenied', 403);
        $this->expectException(LibraryStorageUnavailableException::class);
        $this->store->getJson('crm/pdf/a.json');
    }

    public function test_get_json_un_fallo_de_red_es_almacenamiento_no_disponible(): void
    {
        $this->mock->append(fn (CommandInterface $cmd): S3Exception => new S3Exception('cURL error 28', $cmd, ['connection_error' => true]));

        $this->expectException(LibraryStorageUnavailableException::class);
        $this->store->getJson('crm/pdf/a.json');
    }

    // ── listKeys: paginación con ContinuationToken ─────────────────────────────────────────────────────

    public function test_list_keys_sigue_la_paginacion_con_continuation_token_y_quita_el_prefijo_fisico(): void
    {
        $this->ok([
            'Contents' => [['Key' => 'library/crm/pdf/a.json'], ['Key' => 'library/crm/pdf/a.pdf']],
            'IsTruncated' => true, 'NextContinuationToken' => 'TOKEN-2',
        ]);
        $this->ok([
            'Contents' => [['Key' => 'library/_pending/p.pdf'], ['Key' => 'library/gerencia/video/v.json']],
            'IsTruncated' => false,
        ]);

        $keys = $this->store->listKeys('');

        $this->assertSame(['crm/pdf/a.json', 'crm/pdf/a.pdf', '_pending/p.pdf', 'gerencia/video/v.json'], $keys);
        $first  = $this->commands[0]->toArray();
        $second = $this->commands[1]->toArray();
        $this->assertSame('ListObjectsV2', $this->commands[0]->getName());
        $this->assertSame('library/', $first['Prefix']);
        $this->assertSame('atlanticerp-library', $first['Bucket']);
        $this->assertArrayNotHasKey('ContinuationToken', $first);
        $this->assertSame('TOKEN-2', $second['ContinuationToken'], 'la segunda página pide el token de la primera');
    }

    public function test_list_keys_con_prefijo_de_modulo_y_sin_resultados(): void
    {
        $this->ok(['IsTruncated' => false]); // sin 'Contents', como responde S3 cuando no hay nada

        $this->assertSame([], $this->store->listKeys('crm/'));
        $this->assertSame('library/crm/', $this->commands[0]->toArray()['Prefix']);
    }

    public function test_list_keys_un_error_de_s3_es_almacenamiento_no_disponible(): void
    {
        $this->fails('AccessDenied', 403);

        $this->expectException(LibraryStorageUnavailableException::class);
        $this->store->listKeys('');
    }

    // ── Presign GET/PUT con el cliente real (la firma es local, no hay red) ─────────────────────────────

    /** @return array{host: string, path: string, query: array<string, string>} */
    private function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);

        return ['host' => (string) $parts['host'], 'path' => (string) $parts['path'], 'query' => array_map('strval', $query)];
    }

    public function test_presign_get_firma_ttl_clave_y_fuerza_content_type_y_disposition_con_utf8(): void
    {
        $disposition = 'attachment; filename="Ventas_Dise_o.pdf"; filename*=UTF-8\'\'' . rawurlencode('Ventas&Diseño.pdf');

        $u = $this->parseUrl($this->store->presignGet('crm/pdf/u-1.pdf', 900, 'application/pdf', $disposition));

        $this->assertSame('atlanticerp-library.s3.amazonaws.com', $u['host']);
        $this->assertSame('/library/crm/pdf/u-1.pdf', $u['path']);
        $this->assertSame('900', $u['query']['X-Amz-Expires']);
        $this->assertSame('application/pdf', $u['query']['response-content-type']);
        $this->assertSame($disposition, $u['query']['response-content-disposition'], 'la disposition viaja intacta, con & y ñ codificados');
        $this->assertNotSame('', $u['query']['X-Amz-Signature']);
        $this->assertStringStartsWith('AKIATEST/', $u['query']['X-Amz-Credential']);
    }

    public function test_presign_put_firma_ttl_y_clave_de_pending_y_no_firma_content_length_ni_content_type(): void
    {
        $presigned = $this->store->presignPut('_pending/u-1.pdf', 'application/pdf', 209, 1800);
        $u         = $this->parseUrl($presigned['url']);

        $this->assertSame('/library/_pending/u-1.pdf', $u['path']);
        $this->assertSame('1800', $u['query']['X-Amz-Expires']);
        $this->assertSame(['Content-Type' => 'application/pdf'], $presigned['headers']);
        // Hallazgo M-1 (aceptado): el SDK solo firma `host`; el tope de tamaño lo impone finalize, no S3.
        $this->assertSame('host', $u['query']['X-Amz-SignedHeaders']);
    }

    // ── copy / head: claves con caracteres especiales, sin versionado ──────────────────────────────────

    public function test_copy_codifica_el_copy_source_con_espacios_tildes_y_ampersand_y_conserva_las_barras(): void
    {
        $this->ok();

        $this->store->copy('_pending/a ñ&b.pdf', 'crm/pdf/a.pdf', 'V 1', null, null);

        $args = $this->commands[0]->toArray();
        $this->assertSame('atlanticerp-library/library/_pending/a%20%C3%B1%26b.pdf?versionId=V%201', $args['CopySource']);
    }

    public function test_head_de_un_bucket_sin_versionado_devuelve_version_id_null(): void
    {
        $this->ok(['ContentLength' => 12, 'ContentType' => 'video/mp4', 'ETag' => '"abc"']);

        $this->assertSame(
            ['size' => 12, 'content_type' => 'video/mp4', 'version_id' => null, 'etag' => '"abc"'],
            $this->store->head('_pending/v.mp4'),
        );
    }

    public function test_read_range_con_body_stream_y_sin_version_no_envia_version_id(): void
    {
        $this->ok(['Body' => Utils::streamFor('ftypisom')]);

        $this->assertSame('ftypisom', $this->store->readRange('_pending/v.mp4', 4, 8));
        $args = $this->commands[0]->toArray();
        $this->assertArrayNotHasKey('VersionId', $args);
        $this->assertSame('bytes=4-11', $args['Range']);
    }
}
