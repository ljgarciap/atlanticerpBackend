<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Modules\CRM\Models\Contact;
use App\Modules\CRM\Models\Project;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CrmExportController extends Controller
{
    /**
     * GET /api/admin/export/crm
     *
     * Downloads a full JSON snapshot of all CRM data for the current tenant.
     * Restricted to superadmin via route middleware.
     */
    public function __invoke(): StreamedResponse
    {
        $projects = Project::with(['activities', 'reminders', 'assignees'])->get();
        $contacts = Contact::orderBy('nombre')->get();

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'projects'    => $projects->toArray(),
            'contacts'    => $contacts->toArray(),
        ];

        $filename = 'atlanticerp-crm-' . now()->format('Y-m-d') . '.json';

        return response()->streamDownload(
            function () use ($payload): void {
                echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            },
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }
}
