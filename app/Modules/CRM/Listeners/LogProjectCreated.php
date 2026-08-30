<?php

namespace App\Modules\CRM\Listeners;

use App\Modules\CRM\Events\ProjectCreated;
use Illuminate\Support\Facades\Log;

class LogProjectCreated
{
    public function handle(ProjectCreated $event): void
    {
        Log::info($event->eventName(), $event->payload());
    }
}
