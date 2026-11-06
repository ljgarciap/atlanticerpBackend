<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Auth\Totp;
use PHPUnit\Framework\TestCase;

/** Fase 27c — vectores de prueba del RFC 6238 (apéndice B, SHA-1, secreto ASCII «12345678901234567890»). */
final class TotpTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return array<string, array{int, string}> */
    public static function vectores(): array
    {
        return [
            't=59'          => [59, '287082'],
            't=1111111109'  => [1111111109, '081804'],
            't=1111111111'  => [1111111111, '050471'],
            't=1234567890'  => [1234567890, '005924'],
            't=2000000000'  => [2000000000, '279037'],
            't=20000000000' => [20000000000, '353130'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('vectores')]
    public function test_coincide_con_los_vectores_del_rfc(int $time, string $esperado): void
    {
        $this->assertSame($esperado, Totp::codeAt(self::SECRET, Totp::currentStep($time)));
    }

    public function test_base32_ida_y_vuelta(): void
    {
        $secret = Totp::generateSecret();
        $this->assertSame(32, strlen($secret));
        $this->assertSame($secret, Totp::base32Encode(Totp::base32Decode($secret)));
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::SECRET));
    }

    public function test_acepta_la_ventana_de_mas_menos_un_paso_y_devuelve_el_paso(): void
    {
        $now  = 1111111109;
        $step = Totp::currentStep($now);
        $this->assertSame($step, Totp::matchStep(self::SECRET, Totp::codeAt(self::SECRET, $step), $now));
        $this->assertSame($step - 1, Totp::matchStep(self::SECRET, Totp::codeAt(self::SECRET, $step - 1), $now));
        $this->assertSame($step + 1, Totp::matchStep(self::SECRET, Totp::codeAt(self::SECRET, $step + 1), $now));
        $this->assertNull(Totp::matchStep(self::SECRET, Totp::codeAt(self::SECRET, $step + 2), $now));
        $this->assertNull(Totp::matchStep(self::SECRET, Totp::codeAt(self::SECRET, $step - 2), $now));
    }

    public function test_rechaza_formato_invalido(): void
    {
        $this->assertNull(Totp::matchStep(self::SECRET, '12345', 59));
        $this->assertNull(Totp::matchStep(self::SECRET, 'abcdef', 59));
        $this->assertNull(Totp::matchStep(self::SECRET, '', 59));
    }

    public function test_la_uri_lleva_emisor_cuenta_y_parametros(): void
    {
        $uri = Totp::uri('ABC234', 'ana@example.test', 'AtlanticERP');
        $this->assertStringStartsWith('otpauth://totp/AtlanticERP%3Aana%40example.test?secret=ABC234&issuer=AtlanticERP', $uri);
        $this->assertStringContainsString('digits=6&period=30', $uri);
    }
}
