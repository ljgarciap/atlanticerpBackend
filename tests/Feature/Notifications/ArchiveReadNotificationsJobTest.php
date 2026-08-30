<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Notifications\Jobs\ArchiveReadNotificationsJob;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Services\NotificationService;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

class ArchiveReadNotificationsJobTest extends FeatureTestCase
{
    private function currentUserId(string $token): int
    {
        return (int) $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/auth/me')
            ->json('id');
    }

    private function activateTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()->makeCurrent();
    }

    protected function tearDown(): void
    {
        Tenant::forgetCurrent();
        parent::tearDown();
    }

    public function test_archiva_leidas_hace_mas_de_30_dias(): void
    {
        $userId = $this->currentUserId($this->loginAs());
        $this->activateTenant();

        app(NotificationService::class)->send([$userId], ['subject' => 'Vieja', 'body' => 'x'], ['in_app']);
        Notification::where('user_id', $userId)->update(['read_at' => now()->subDays(31)]);

        (new ArchiveReadNotificationsJob('atlantic'))->handle();

        $this->activateTenant();
        $notification = Notification::where('user_id', $userId)->first();
        $this->assertNotNull($notification->archived_at);
    }

    public function test_no_archiva_leidas_recientes(): void
    {
        $userId = $this->currentUserId($this->loginAs());
        $this->activateTenant();

        app(NotificationService::class)->send([$userId], ['subject' => 'Reciente', 'body' => 'x'], ['in_app']);
        Notification::where('user_id', $userId)->update(['read_at' => now()->subDay()]);

        (new ArchiveReadNotificationsJob('atlantic'))->handle();

        $this->activateTenant();
        $notification = Notification::where('user_id', $userId)->first();
        $this->assertNull($notification->archived_at);
    }

    public function test_no_archiva_no_leidas_aunque_sean_viejas(): void
    {
        $userId = $this->currentUserId($this->loginAs());
        $this->activateTenant();

        app(NotificationService::class)->send([$userId], ['subject' => 'No leida', 'body' => 'x'], ['in_app']);
        Notification::where('user_id', $userId)->update(['created_at' => now()->subDays(90)]);

        (new ArchiveReadNotificationsJob('atlantic'))->handle();

        $this->activateTenant();
        $notification = Notification::where('user_id', $userId)->first();
        $this->assertNull($notification->archived_at);
    }
}
