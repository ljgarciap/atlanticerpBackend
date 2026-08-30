<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Modules\Servicios\Services\ServiciosSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Fase 4 — Servicios, Batch 10 (decisión de Luis 2026-08-11). Pantalla "Ajustes de Servicios" —
 * CRUD genérico sobre TODAS las keys de `ServiciosSettingsService` (operación, SLA, comisión),
 * reemplaza el endpoint angosto `/internal-technicians/sla-settings` (sigue existiendo por
 * compatibilidad, ningún frontend lo consume todavía salvo el nuevo). Lectura amplia (mismo
 * criterio que `sla-settings` hoy: visible para todo el módulo), escritura exclusiva de Gerencia.
 */
final class ServiciosSettingsController extends Controller
{
    public function __construct(private readonly ServiciosSettingsService $settings) {}

    /** GET /api/servicios/settings */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all()]);
    }

    /**
     * PATCH /api/servicios/settings — solo pisa las keys presentes en el body. Sin FormRequest: el
     * set de keys válidas es dinámico (`ServiciosSettingsService::schema()`), no hay una forma fija
     * que declarar de antemano — `update()` del servicio ya ignora cualquier key desconocida.
     *
     * Batch 12 — la validación "debe ser numérico" solo aplica a las keys `int`/`float` del
     * catálogo; una key `type => 'text'` (ej. `condiciones_cotizacion_servicios`, REQ-237) se deja
     * pasar tal cual, incluso vacía (ver docblock de `ServiciosSettingsService::textSetting()`).
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->all();

        foreach ($data as $key => $value) {
            if ($this->settings->fieldType((string) $key) === 'text') {
                continue;
            }

            if (! is_numeric($value)) {
                return response()->json(['message' => "El valor de \"{$key}\" debe ser numérico."], 422);
            }
        }

        $this->settings->update($data);

        return response()->json(['data' => $this->settings->all()]);
    }
}
