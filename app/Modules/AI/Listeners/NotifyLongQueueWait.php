<?php

declare(strict_types=1);

namespace App\Modules\AI\Listeners;

use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Events\LongWaitDetected;

final class NotifyLongQueueWait
{
    public function handle(LongWaitDetected $event): void
    {
        Log::warning('AI queue: espera excesiva detectada', [
            'connection' => $event->connection,
            'queue'      => $event->queue,
            'seconds'    => $event->seconds,
        ]);
    }
}
