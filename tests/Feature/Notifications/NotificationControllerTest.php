<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Services\NotificationService;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

class NotificationControllerTest extends FeatureTestCase
{
    private function currentUserId(string $token): int
    {
        return (int) $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/auth/me')
            ->json('id');
    }

    /** Ver nota en NotificationServiceTest — el tenant no queda activo fuera de una request HTTP. */
    private function activateTenant(): void
    {
        Tenant::where('slug', 'illuminations')->first()->makeCurrent();
    }

    protected function tearDown(): void
    {
        Tenant::forgetCurrent();
        parent::tearDown();
    }

    public function test_listar_notificaciones_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
    }

    public function test_listar_notificaciones_solo_muestra_las_propias(): void
    {
        $mgmtToken = $this->loginAs();
        $mgmtId    = $this->currentUserId($mgmtToken);

        $designerToken = $this->loginAs('designer@illuminations.test', 'Password123!');
        $designerId    = $this->currentUserId($designerToken);

        $this->activateTenant();
        $service = app(NotificationService::class);
        $service->send([$mgmtId], ['subject' => 'Para management', 'body' => 'x'], ['in_app']);
        $service->send([$designerId], ['subject' => 'Para designer', 'body' => 'x'], ['in_app']);

        $response = $this->withHeaders($this->authHeaders($mgmtToken))->getJson('/api/notifications');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Para management', $response->json('data.0.content.subject'));
    }

    public function test_listar_notificaciones_no_incluye_archivadas(): void
    {
        $token  = $this->loginAs();
        $userId = $this->currentUserId($token);

        $this->activateTenant();
        $service = app(NotificationService::class);
        $service->send([$userId], ['subject' => 'Visible', 'body' => 'x'], ['in_app']);
        $service->send([$userId], ['subject' => 'Archivada', 'body' => 'x'], ['in_app']);

        Notification::where('user_id', $userId)
            ->whereJsonContains('content->subject', 'Archivada')
            ->update(['archived_at' => now(), 'read_at' => now()]);

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/notifications');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Visible', $response->json('data.0.content.subject'));
    }

    public function test_marcar_como_leida(): void
    {
        $token  = $this->loginAs();
        $userId = $this->currentUserId($token);

        $this->activateTenant();
        app(NotificationService::class)->send([$userId], ['subject' => 'X', 'body' => 'x'], ['in_app']);
        $notificationId = Notification::where('user_id', $userId)->first()->id;

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/notifications/{$notificationId}/read");

        $response->assertStatus(200);
        $this->assertTrue($response->json('is_read'));

        $this->activateTenant();
        $notification = Notification::find($notificationId);
        $this->assertNotNull($notification->read_at);
    }

    public function test_marcar_como_leida_de_otro_usuario_retorna_404(): void
    {
        $mgmtToken = $this->loginAs();
        $mgmtId    = $this->currentUserId($mgmtToken);

        $designerToken = $this->loginAs('designer@illuminations.test', 'Password123!');

        $this->activateTenant();
        app(NotificationService::class)->send([$mgmtId], ['subject' => 'X', 'body' => 'x'], ['in_app']);
        $notificationId = Notification::where('user_id', $mgmtId)->first()->id;

        $this->withHeaders($this->authHeaders($designerToken))
            ->postJson("/api/notifications/{$notificationId}/read")
            ->assertStatus(404);
    }

    public function test_marcar_todas_como_leidas(): void
    {
        $token  = $this->loginAs();
        $userId = $this->currentUserId($token);

        $this->activateTenant();
        $service = app(NotificationService::class);
        $service->send([$userId], ['subject' => 'A', 'body' => 'x'], ['in_app']);
        $service->send([$userId], ['subject' => 'B', 'body' => 'x'], ['in_app']);

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/notifications/read-all')
            ->assertStatus(200);

        $this->activateTenant();
        $this->assertSame(
            0,
            Notification::where('user_id', $userId)->whereNull('read_at')->count(),
        );
    }

    public function test_unread_count(): void
    {
        $token  = $this->loginAs();
        $userId = $this->currentUserId($token);

        $this->activateTenant();
        $service = app(NotificationService::class);
        $service->send([$userId], ['subject' => 'A', 'body' => 'x'], ['in_app']);
        $service->send([$userId], ['subject' => 'B', 'body' => 'x'], ['in_app']);

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/notifications/unread-count');

        $response->assertStatus(200)->assertJson(['count' => 2]);
    }
}
