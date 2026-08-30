<?php

namespace App\Modules\CRM\Listeners;

use App\Modules\CRM\Events\ActivityAdded;
use Illuminate\Support\Facades\Log;

class LogActivityAdded
{
    public function handle(ActivityAdded $event): void
    {
        Log::info($event->eventName(), $event->payload());
    }
}
