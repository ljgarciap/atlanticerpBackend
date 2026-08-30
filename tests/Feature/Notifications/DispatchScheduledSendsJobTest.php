<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Notifications\Jobs\DispatchScheduledSendsJob;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Services\NotificationService;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

class DispatchScheduledSendsJobTest extends FeatureTestCase
{
    private function currentUserId(string $token): int
    {
        return (int) $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/auth/me')
            ->json('id');
    }

    private function activateTenant(): void
    {
        Tenant::where('slug', 'illuminations')->first()->makeCurrent();
    }

    protected function tearDown(): void
    {
        Tenant::forgetCurrent();
        parent::tearDown();
    }

    public function test_entrega_sends_cuyo_scheduled_at_ya_vencio(): void
    {
        $userId = $this->currentUserId($this->loginAs());
        $this->activateTenant();

        // Se crea a futuro (no se entrega al crearse) y luego se simula que el tiempo pasó
        // actualizando scheduled_at directo en BD — send() siempre entrega de una si
        // scheduled_at ya está vencido al momento de llamarlo, así que la única forma real
        // de tener un send "pendiente y vencido" es que el tiempo pase después de crearlo.
        $send = app(NotificationService::class)->send(
            audience: [$userId],
            content: ['subject' => 'Circular vencida', 'body' => 'x'],
            channels: ['in_app'],
            scheduledAt: now()->addDay(),
        );
        $this->assertNull($send->sent_at);

        $send->update(['scheduled_at' => now()->subMinute()]);

        (new DispatchScheduledSendsJob('illuminations'))->handle(app(NotificationService::class));

        $this->activateTenant();
        $this->assertSame(1, Notification::where('user_id', $userId)->count());
        $this->assertNotNull($send->fresh()->sent_at);
    }

    public function test_no_entrega_sends_programados_a_futuro(): void
    {
        $userId = $this->currentUserId($this->loginAs());
        $this->activateTenant();

        app(NotificationService::class)->send(
            audience: [$userId],
            content: ['subject' => 'Circular futura', 'body' => 'x'],
            channels: ['in_app'],
            scheduledAt: now()->addDay(),
        );

        (new DispatchScheduledSendsJob('illuminations'))->handle(app(NotificationService::class));

        $this->activateTenant();
        $this->assertSame(0, Notification::where('user_id', $userId)->count());
    }

    public function test_no_duplica_un_send_ya_entregado(): void
    {
        $userId = $this->currentUserId($this->loginAs());
        $this->activateTenant();

        app(NotificationService::class)->send(
            audience: [$userId],
            content: ['subject' => 'Inmediata', 'body' => 'x'],
            channels: ['in_app'],
        );

        (new DispatchScheduledSendsJob('illuminations'))->handle(app(NotificationService::class));

        $this->activateTenant();
        $this->assertSame(1, Notification::where('user_id', $userId)->count());
    }
}
