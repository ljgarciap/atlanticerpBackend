<?php

declare(strict_types=1);

namespace Tests\Unit\Compras;

use App\Modules\Compras\Support\TranslationGuard;
use PHPUnit\Framework\TestCase;

/** SCRUM-1127 — validaciones en código de lo que devuelve la IA al traducir descripciones de la orden de compra. */
class TranslationGuardTest extends TestCase
{
    public function test_extrae_los_fragmentos_tecnicos_del_ejemplo_del_ticket(): void
    {
        $this->assertSame(
            ['3K', '24V', '10W', '5M', 'IP67'],
            TranslationGuard::technicalTokens('CINTA NEON 3K 24V 10W 5M/ROLLO IP67'),
        );
    }

    public function test_acepta_la_traduccion_del_ejemplo_del_ticket(): void
    {
        TranslationGuard::assertValid('CINTA NEON 3K 24V 10W 5M/ROLLO IP67', 'NEON STRIP 3K 24V 10W 5M/ROLL IP67', 3, 120);
        $this->addToAssertionCount(1);
    }

    public function test_rechaza_si_la_ia_altera_un_dato_tecnico(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        TranslationGuard::assertValid('CINTA NEON 3K 24V 10W 5M/ROLLO IP67', 'NEON STRIP 3K 12V 10W 5M/ROLL IP67', 3, 120);
    }

    public function test_rechaza_si_la_ia_omite_un_dato_tecnico(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        TranslationGuard::assertValid('DOWNLIGHT LED 12W 60X60', 'LED DOWNLIGHT 12W', 3, 120);
    }

    public function test_la_puntuacion_pegada_al_dato_tecnico_no_cuenta_como_alteracion(): void
    {
        TranslationGuard::assertValid('PANEL LED (60X60), 40W.', 'LED PANEL (60X60), 40W.', 3, 120);
        $this->addToAssertionCount(1);
    }

    public function test_rechaza_una_traduccion_desproporcionada(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        TranslationGuard::assertValid('LAMPARA', str_repeat('x', 500), 3, 120);
    }

    public function test_rechaza_caracteres_de_control(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        TranslationGuard::assertValid('LAMPARA', "LAMP\x07", 3, 120);
    }

    public function test_parsea_json_aun_con_texto_o_vallas_alrededor(): void
    {
        $this->assertSame(
            ['1' => 'LAMP', '2' => 'PANEL'],
            TranslationGuard::parseResponse("```json\n{\"1\": \"LAMP\", \"2\": \" PANEL \"}\n```", ['1', '2']),
        );
    }

    public function test_rechaza_si_falta_o_sobra_un_item(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        TranslationGuard::parseResponse('{"1": "LAMP"}', ['1', '2']);
    }

    public function test_rechaza_un_item_extra_que_no_se_pidio(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        TranslationGuard::parseResponse('{"1": "LAMP", "9": "otro"}', ['1']);
    }

    public function test_rechaza_valores_que_no_son_texto_o_estan_vacios(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        TranslationGuard::parseResponse('{"1": ""}', ['1']);
    }

    public function test_rechaza_una_respuesta_sin_json(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        TranslationGuard::parseResponse('Sorry, I cannot do that.', ['1']);
    }

    public function test_el_hash_colapsa_espacios_pero_distingue_mayusculas(): void
    {
        $this->assertSame(TranslationGuard::hashText("CINTA   NEON\n3K"), TranslationGuard::hashText(' CINTA NEON 3K '));
        $this->assertNotSame(TranslationGuard::hashText('CINTA'), TranslationGuard::hashText('Cinta'));
    }
}
