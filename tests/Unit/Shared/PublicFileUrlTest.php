<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Support\PublicFileUrl;
use Tests\TestCase;

class PublicFileUrlTest extends TestCase
{
    public function test_con_s3_usa_el_cdn_o_la_url_del_bucket_mas_el_prefijo_public(): void
    {
        config(['filesystems.disks.s3-public' => ['driver' => 's3', 'url' => 'https://bucket.example.test/', 'cdn_url' => null]]);
        $this->assertSame('https://bucket.example.test/public/a/b.png', PublicFileUrl::for('a/b.png'));

        config(['filesystems.disks.s3-public.cdn_url' => 'https://cdn.example.test']);
        $this->assertSame('https://cdn.example.test/public/a/b.png', PublicFileUrl::for('a/b.png'));
    }

    public function test_con_almacenamiento_local_usa_la_ruta_que_sirve_la_app(): void
    {
        config(['filesystems.disks.s3-public' => ['driver' => 'local', 'url' => 'http://localhost/storage-public']]);
        $this->assertSame('http://localhost/storage-public/a/b.png', PublicFileUrl::for('/a/b.png'));
    }

    public function test_sin_url_configurada_no_hay_url_publica(): void
    {
        config(['filesystems.disks.s3-public' => ['driver' => 's3', 'url' => '']]);
        $this->assertNull(PublicFileUrl::for('a.png'));
    }
}
