<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Notifications\Jobs\SendNotificationEmailJob;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Services\NotificationService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\FeatureTestCase;

class NotificationServiceTest extends FeatureTestCase
{
    private function currentUserId(string $token): int
    {
        return (int) $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/auth/me')
            ->json('id');
    }

    /**
     * NotificationService no se llama desde un controller en esta suite (a diferencia de
     * NotificationControllerTest) — se llama directo, fuera de cualquier request HTTP. El
     * tenant solo queda activo durante la request de AuthenticateJwt (ver
     * Tenant::forgetCurrent() en el finally del middleware), así que hay que reactivarlo
     * manualmente antes de tocar tablas de tenant.
     */
    private function activateTenant(): void
    {
        Tenant::where('slug', 'illuminations')->first()->makeCurrent();
    }

    protected function tearDown(): void
    {
        Tenant::forgetCurrent();
        parent::tearDown();
    }

    public function test_send_in_app_crea_notification_inmediata(): void
    {
        $userId = $this->currentUserId($this->loginAs());
        $this->activateTenant();

        $send = app(NotificationService::class)->send(
            audience: [$userId],
            content: ['subject' => 'Proyecto cerrado', 'body' => 'El proyecto X cambió a cerrado.'],
            channels: ['in_app'],
        );

        $this->assertNotNull($send->sent_at);
        $this->assertDatabaseHas('notifications', [
            'user_id'              => $userId,
            'notification_send_id' => $send->id,
        ]);
        $this->assertSame(1, Notification::where('user_id', $userId)->count());
    }

    public function test_send_email_despacha_job_y_no_toca_notifications(): void
    {
        Bus::fake();

        $userId = $this->currentUserId($this->loginAs());
        $this->activateTenant();

        app(NotificationService::class)->send(
            audience: [$userId],
            content: ['subject' => 'Asunto', 'body' => 'Cuerpo'],
            channels: ['email'],
        );

        Bus::assertDispatched(SendNotificationEmailJob::class);
        $this->assertSame(0, Notification::where('user_id', $userId)->count());
    }

    public function test_send_ambos_canales_crea_notification_y_despacha_email(): void
    {
        Bus::fake();

        $userId = $this->currentUserId($this->loginAs());
        $this->activateTenant();

        app(NotificationService::class)->send(
            audience: [$userId],
            content: ['subject' => 'Asunto', 'body' => 'Cuerpo'],
            channels: ['in_app', 'email'],
        );

        Bus::assertDispatched(SendNotificationEmailJob::class);
        $this->assertSame(1, Notification::where('user_id', $userId)->count());
    }

    public function test_send_programado_a_futuro_no_entrega_todavia(): void
    {
        $userId = $this->currentUserId($this->loginAs());
        $this->activateTenant();

        $send = app(NotificationService::class)->send(
            audience: [$userId],
            content: ['subject' => 'Asunto', 'body' => 'Cuerpo'],
            channels: ['in_app'],
            scheduledAt: now()->addDay(),
        );

        $this->assertNull($send->sent_at);
        $this->assertSame(0, Notification::where('user_id', $userId)->count());
    }

    public function test_deliver_es_idempotente_sobre_un_send_ya_procesado(): void
    {
        $userId = $this->currentUserId($this->loginAs());
        $this->activateTenant();

        $service = app(NotificationService::class);
        $send    = $service->send(
            audience: [$userId],
            content: ['subject' => 'Asunto', 'body' => 'Cuerpo'],
            channels: ['in_app'],
        );

        // Reintento manual (simula el scheduler procesando dos veces por error) — no debe duplicar
        $service->deliver($send->fresh());

        $this->assertSame(1, Notification::where('user_id', $userId)->count());
    }

    public function test_send_excluye_link_url_cuando_content_no_trae_cta_url(): void
    {
        $userId = $this->currentUserId($this->loginAs());
        $this->activateTenant();

        app(NotificationService::class)->send(
            audience: [$userId],
            content: ['subject' => 'Asunto', 'body' => 'Cuerpo'],
            channels: ['in_app'],
        );

        $notification = Notification::where('user_id', $userId)->first();
        $this->assertNotNull($notification);
        $this->assertNull($notification->link_url);
    }

    public function test_send_incluye_link_url_cuando_content_trae_cta_url(): void
    {
        $userId = $this->currentUserId($this->loginAs());
        $this->activateTenant();

        app(NotificationService::class)->send(
            audience: [$userId],
            content: ['subject' => 'Asunto', 'body' => 'Cuerpo', 'cta_url' => '/crm/proyectos/1'],
            channels: ['in_app'],
        );

        $notification = Notification::where('user_id', $userId)->first();
        $this->assertNotNull($notification);
        $this->assertSame('/crm/proyectos/1', $notification->link_url);
    }
}
