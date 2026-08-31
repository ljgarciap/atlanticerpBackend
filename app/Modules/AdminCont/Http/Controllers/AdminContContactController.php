<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Http\Requests\StoreContactRequest;
use App\Modules\AdminCont\Http\Requests\UpdateContactRequest;
use App\Modules\AdminCont\Models\AdminContContact;
use Illuminate\Http\JsonResponse;

/** SCRUM-641 (REQ-564) — contactos generales por área, gateado por EnsureIsPrimaryApprover. */
class AdminContContactController
{
    public function index(): JsonResponse
    {
        return response()->json(AdminContContact::query()->orderBy('id')->get());
    }

    public function store(StoreContactRequest $request): JsonResponse
    {
        // `activo` no está en las reglas de StoreContactRequest — mismo criterio que Locations,
        // ver AdminContLocationController::store().
        $contact = new AdminContContact($request->validated());
        $contact->activo = true;
        $contact->save();

        return response()->json($contact, 201);
    }

    public function update(UpdateContactRequest $request, int $id): JsonResponse
    {
        $contact = AdminContContact::find($id);
        if ($contact === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $contact->update($request->validated());

        return response()->json($contact->fresh());
    }
}
