<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Modules\Legacy\Support\OperatorContext;
use PHPUnit\Framework\TestCase;

/** SCRUM-1114 (S-04) — contexto real del proceso en los eventos manuales de stock. */
final class OperatorContextTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('OPERATOR_OS_USER');
        putenv('SSH_CONNECTION');
        parent::tearDown();
    }

    public function test_el_usuario_del_proceso_nunca_queda_vacio(): void
    {
        $ctx = OperatorContext::describe('Luis');

        $this->assertSame('Luis', $ctx['operador']);
        $this->assertNotSame('', $ctx['usuario_so']);
        $this->assertNotSame('', $ctx['host']);
    }

    public function test_sin_variables_de_ssh_esos_campos_son_nulos(): void
    {
        putenv('OPERATOR_OS_USER');
        putenv('SSH_CONNECTION');

        $ctx = OperatorContext::describe('Luis');

        $this->assertNull($ctx['usuario_ssh']);
        $this->assertNull($ctx['origen_ssh']);
        $this->assertStringNotContainsString('sesión ssh', OperatorContext::linea($ctx));
    }

    public function test_toma_el_usuario_y_solo_la_ip_de_origen_de_la_sesion_ssh_reenviada(): void
    {
        putenv('OPERATOR_OS_USER=ubuntu');
        putenv('SSH_CONNECTION=190.34.1.20 51822 172.31.0.5 22');

        $ctx = OperatorContext::describe('Luis');

        $this->assertSame('ubuntu', $ctx['usuario_ssh']);
        $this->assertSame('190.34.1.20', $ctx['origen_ssh']); // sin puertos ni la IP del servidor
        $linea = OperatorContext::linea($ctx);
        $this->assertStringContainsString('sesión ssh de ubuntu desde 190.34.1.20', $linea);
        $this->assertStringContainsString('Operador declarado: Luis', $linea);
    }

    public function test_limpia_caracteres_de_control_y_acota_el_largo(): void
    {
        putenv("OPERATOR_OS_USER=ubu\nntu\r" . str_repeat('x', 200));

        $ctx = OperatorContext::describe('Luis');

        $this->assertStringNotContainsString("\n", (string) $ctx['usuario_ssh']);
        $this->assertStringNotContainsString("\r", (string) $ctx['usuario_ssh']);
        $this->assertSame(64, mb_strlen((string) $ctx['usuario_ssh']));
    }

    public function test_una_variable_vacia_cuenta_como_ausente(): void
    {
        putenv('OPERATOR_OS_USER=   ');

        $this->assertNull(OperatorContext::describe('Luis')['usuario_ssh']);
    }
}
