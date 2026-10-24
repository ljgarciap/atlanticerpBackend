<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Notifications\Mail\NotificationMail;
use Tests\TestCase;

/** SCRUM-1153 — el botón «Ver en AtlanticERP» del correo debe apuntar a una URL completa aunque el `cta_url` sea una ruta del frontend. */
class NotificationMailCtaUrlTest extends TestCase
{
    public function test_una_ruta_relativa_se_completa_con_la_url_del_frontend(): void
    {
        config(['app.frontend_url' => 'https://dev.atlanticerp.ai/']);

        $html = (new NotificationMail('Asunto', 'Cuerpo', '/inventario?search=ML-1'))->render();

        $this->assertStringContainsString('href="https://dev.atlanticerp.ai/inventario?search=ML-1"', $html);
    }

    public function test_una_url_absoluta_se_deja_tal_cual(): void
    {
        $html = (new NotificationMail('Asunto', 'Cuerpo', 'https://otro.example/x'))->render();

        $this->assertStringContainsString('href="https://otro.example/x"', $html);
    }

    public function test_sin_cta_no_hay_boton(): void
    {
        $html = (new NotificationMail('Asunto', 'Cuerpo'))->render();

        $this->assertStringNotContainsString('Ver en AtlanticERP', $html);
    }
}
