<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Services\ClienteMatcher;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-755 — tests puros de `ClienteMatcher::normalizeCif()`/`normalizeEmail()` (sin DB), las
 * únicas partes de `ClienteMatcher` que no dependen de Eloquent/tenant. El resto del matching
 * (índices contra sub_clients/sub_client_contacts, persistencia) se cubre en
 * tests/Feature/Legacy/ClienteMatcherTest.php.
 */
final class ClienteMatcherNormalizationTest extends TestCase
{
    public function test_normalize_cif_trim_mayusculas_sin_puntos_ni_guiones(): void
    {
        $this->assertSame('8123456', ClienteMatcher::normalizeCif(' 8.123-456 '));
    }

    public function test_normalize_cif_ya_normalizado_no_cambia(): void
    {
        $this->assertSame('E8123456', ClienteMatcher::normalizeCif('E8123456'));
    }

    public function test_normalize_cif_minusculas_se_convierten(): void
    {
        $this->assertSame('E8123456', ClienteMatcher::normalizeCif('e8123456'));
    }

    public function test_normalize_cif_nulo_devuelve_nulo(): void
    {
        $this->assertNull(ClienteMatcher::normalizeCif(null));
    }

    public function test_normalize_cif_string_vacio_tras_normalizar_devuelve_nulo(): void
    {
        $this->assertNull(ClienteMatcher::normalizeCif('  .-  '));
    }

    public function test_normalize_email_trim_minusculas(): void
    {
        $this->assertSame('juan@cliente.com', ClienteMatcher::normalizeEmail('  Juan@Cliente.COM  '));
    }

    public function test_normalize_email_nulo_devuelve_nulo(): void
    {
        $this->assertNull(ClienteMatcher::normalizeEmail(null));
    }

    public function test_normalize_email_string_vacio_devuelve_nulo(): void
    {
        $this->assertNull(ClienteMatcher::normalizeEmail('   '));
    }
}
