<?php

namespace App\Modules\CRM\Listeners;

use App\Modules\CRM\Events\ProjectStageChanged;
use Illuminate\Support\Facades\Log;

class LogProjectStageChanged
{
    public function handle(ProjectStageChanged $event): void
    {
        Log::info($event->eventName(), $event->payload());
    }
}
