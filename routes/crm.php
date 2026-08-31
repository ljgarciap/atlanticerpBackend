<?php

use App\Modules\CRM\Http\Controllers\ActivityController;
use App\Modules\CRM\Http\Controllers\AutocompleteController;
use App\Modules\CRM\Http\Controllers\ContactController;
use App\Modules\CRM\Http\Controllers\DashboardController;
use App\Modules\CRM\Http\Controllers\DirectoryController;
use App\Modules\CRM\Http\Controllers\DocumentController;
use App\Modules\CRM\Http\Controllers\ProjectController;
use App\Modules\CRM\Http\Controllers\ReminderController;
use App\Modules\CRM\Http\Controllers\ReportsController;
use App\Modules\CRM\Http\Controllers\ShareLinkController;
use Illuminate\Support\Facades\Route;

// Documents — document-level routes (no project in path)
Route::prefix('documents')->group(function (): void {
    Route::get('/{documentId}/url', [DocumentController::class, 'url']);
    Route::delete('/{documentId}', [DocumentController::class, 'destroy'])->middleware('permission:crm.delete');
});

// Share links — document-level revoke by id (not by token: the raw token only
// exists once, right after creation — see ShareLink::createFor()/rawToken — so
// revoking an already-listed link has to address it by its numeric id instead).
// permission:crm.delete (not crm.write) — revoke es destructivo/irreversible, mismo
// nivel que el resto de los DELETE de este archivo (documents, contacts, projects, etc.).
Route::prefix('share-links')->group(function (): void {
    Route::delete('/{id}', [ShareLinkController::class, 'revoke'])->middleware('permission:crm.delete');
});

// Dashboard — KPIs and pending reminders for the authenticated user
Route::prefix('dashboard')->group(function (): void {
    Route::get('/kpis', [DashboardController::class, 'kpis']);
    Route::get('/reminders', [DashboardController::class, 'reminders']);
});

// Directory — contacts aggregated from project fields (read-only derived view)
Route::get('/directory', [DirectoryController::class, 'index']);

// Contacts — managed contact records with CRUD
Route::prefix('contacts')->group(function (): void {
    Route::get('/', [ContactController::class, 'index']);
    Route::post('/', [ContactController::class, 'store'])->middleware('permission:crm.write');
    Route::post('/import', [ContactController::class, 'import'])->middleware('permission:crm.write');
    Route::put('/{id}', [ContactController::class, 'update'])->middleware('permission:crm.edit');
    Route::delete('/{id}', [ContactController::class, 'destroy'])->middleware('permission:crm.delete');
});

// Reports — monthly KPIs, CSV export and team performance
Route::prefix('reports')->group(function (): void {
    Route::get('/monthly/export', [ReportsController::class, 'export'])->middleware('permission:reports.export');
    Route::get('/monthly', [ReportsController::class, 'monthly'])->middleware('permission:reports.read');
    // Fase 3 de la auditoría de permisos (Atlantic/CLAUDE.md, 2026-08-30) — reusa
    // ventas_diseno.view_team, ya sembrado exclusivamente para management (el frontend ya trata
    // "CRM" como parte del mismo dominio de Ventas & Diseño para efectos de visibilidad, ver
    // Sidebar.tsx). Reemplaza el `role:superadmin,management` hardcodeado.
    Route::middleware('permission:ventas_diseno.view_team')->group(function (): void {
        Route::get('/team', [ReportsController::class, 'team'])->middleware('permission:reports.read');
    });
});

// Autocomplete — shared suggestions for project form fields and user search
Route::prefix('autocomplete')->group(function (): void {
    Route::get('/fields', [AutocompleteController::class, 'fields']);
    Route::get('/users', [AutocompleteController::class, 'users']);
    Route::get('/contacts', [AutocompleteController::class, 'contacts']);
});

Route::prefix('projects')->group(function (): void {
    Route::get('/', [ProjectController::class, 'index']);
    Route::post('/', [ProjectController::class, 'store'])->middleware('permission:crm.write');
    Route::get('/{id}', [ProjectController::class, 'show']);
    Route::put('/{id}', [ProjectController::class, 'update'])->middleware('permission:crm.edit');
    Route::patch('/{id}/stage', [ProjectController::class, 'changeStage'])->middleware('permission:crm.edit');

    // Fase 3 de la auditoría de permisos (2026-08-30) — permiso angosto nuevo, distinto del
    // genérico crm.delete (que niveles 4+ ya tienen ampliamente): borrar un proyecto queda
    // exclusivo de management, sin importar qué otro permiso de borrado tenga el actor. Asignado
    // solo al nivel 9 ("Admin"), exclusivo de management — sin riesgo de fuga cruzada (ver
    // hallazgo de seguridad de la Fase 2 en Atlantic/CLAUDE.md).
    Route::middleware('permission:crm.projects.delete')->group(function (): void {
        Route::delete('/{id}', [ProjectController::class, 'destroy'])->middleware('permission:crm.delete');
    });

    // Activities — nested under projects
    Route::prefix('/{projectId}/activities')->group(function (): void {
        Route::get('/', [ActivityController::class, 'index']);
        Route::post('/', [ActivityController::class, 'store'])->middleware('permission:crm.write');
        Route::delete('/{actId}', [ActivityController::class, 'destroy'])->middleware('permission:crm.delete');
    });

    // Reminders — nested under projects
    Route::prefix('/{projectId}/reminders')->group(function (): void {
        Route::get('/', [ReminderController::class, 'index']);
        Route::post('/', [ReminderController::class, 'store'])->middleware('permission:crm.write');
        Route::patch('/{remId}/toggle', [ReminderController::class, 'toggle'])->middleware('permission:crm.edit');
        Route::delete('/{remId}', [ReminderController::class, 'destroy'])->middleware('permission:crm.delete');
    });

    // Documents — nested under projects
    // GET routes have no crm.read middleware by design: access is controlled by project
    // assignment in DocumentController::canAccess(), consistent with all other CRM GET routes.
    Route::prefix('/{projectId}/documents')->group(function (): void {
        Route::get('/', [DocumentController::class, 'index']);
        Route::get('/history', [DocumentController::class, 'history']);
        Route::post('/', [DocumentController::class, 'store'])->middleware('permission:crm.write');
        Route::get('/{documentId}/download', [DocumentController::class, 'download']);
        Route::delete('/{documentId}', [DocumentController::class, 'destroy'])->middleware('permission:crm.delete');

        // Share links — GET follows the same no-permission-middleware convention as
        // the document GET routes above (project assignment check only); POST needs crm.write.
        Route::prefix('/{documentId}/share-links')->group(function (): void {
            Route::get('/', [ShareLinkController::class, 'index']);
            Route::post('/', [ShareLinkController::class, 'store'])->middleware('permission:crm.write');
        });
    });
});
