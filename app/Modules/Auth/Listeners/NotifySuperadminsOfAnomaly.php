<?php

declare(strict_types=1);

namespace App\Modules\Auth\Listeners;

use App\Models\User;
use App\Modules\Auth\Events\SecurityAnomalyDetected;
use App\Modules\Auth\Mail\SecurityAnomalyMail;
use App\Modules\Auth\Models\Role;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class NotifySuperadminsOfAnomaly
{
    public function handle(SecurityAnomalyDetected $event): void
    {
        $superadmins = User::query()
            ->where('is_active', true)
            ->withRoleKey([Role::KEY_SUPERADMIN])
            ->get();

        if ($superadmins->isEmpty()) {
            Log::warning('security_anomaly.no_superadmins_to_notify', ['anomaly_id' => $event->anomaly->id]);

            return;
        }

        $affectedUser = $event->anomaly->affected_user_id !== null
            ? User::find($event->anomaly->affected_user_id)
            : null;

        $affectedUserName = $affectedUser !== null
            ? "{$affectedUser->first_name} {$affectedUser->last_name}"
            : null;

        $panelUrl = rtrim((string) config('app.frontend_url'), '/') . '/security/alerts';

        foreach ($superadmins as $superadmin) {
            Mail::to($superadmin->email)->send(new SecurityAnomalyMail(
                severity:          $event->anomaly->severity,
                reasoning:         $event->anomaly->reasoning,
                affectedUserName:  $affectedUserName,
                panelUrl:          $panelUrl,
            ));
        }
    }
}
