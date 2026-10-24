<?php

declare(strict_types=1);

namespace App\Modules\Biblioteca\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Models\SystemSetting;
use App\Modules\Biblioteca\Exceptions\LibraryFinalizeInProgressException;
use App\Modules\Biblioteca\Exceptions\LibraryObjectChangedException;
use App\Modules\Biblioteca\Exceptions\LibraryRejectedException;
use App\Modules\Biblioteca\Exceptions\LibraryStorageUnavailableException;
use App\Modules\Biblioteca\Services\LibraryService;
use App\Modules\Biblioteca\Services\LibrarySettings;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * SCRUM-984 — Manuales de Usuario: biblioteca de PDF y video. Lectura para cualquier usuario
 * autenticado (como Soporte); subir/borrar exige `manuales.manage` (superadmin por
 * `superadmin.all`, Daniela vía SpecialPermissionSeeder) — el gate va en la ruta, no solo en el
 * frontend. El 503 sin bucket lo resuelve el middleware EnsureLibraryConfigured.
 */
final class BibliotecaController extends Controller
{
    public function __construct(
        private readonly LibraryService $service,
        private readonly LibrarySettings $settings,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'modulo' => ['nullable', 'string', Rule::in((array) config('biblioteca.modules'))],
            'tipo'   => ['nullable', 'string', Rule::in(['pdf', 'video'])],
        ]);

        try {
            return response()->json($this->service->list($data['modulo'] ?? null, $data['tipo'] ?? null));
        } catch (LibraryStorageUnavailableException|LibraryObjectChangedException) {
            // S3 caído o mal configurado al leer el catálogo: 503 reintentable, no un 500 genérico.
            return response()->json(['message' => trans('biblioteca.storage_unavailable')], 503);
        }
    }

    public function url(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['accion' => ['required', Rule::in(['ver', 'descargar'])]]);

        try {
            $result = $this->service->presignedUrl($id, $data['accion'], $this->actor($request));
        } catch (LibraryStorageUnavailableException|LibraryObjectChangedException) {
            return response()->json(['message' => trans('biblioteca.storage_unavailable')], 503);
        }
        if ($result === null) {
            return response()->json(['message' => trans('biblioteca.not_found')], 404);
        }

        return response()->json($result);
    }

    public function config(): JsonResponse
    {
        return response()->json($this->settings->toApi());
    }

    /**
     * Solo superadmin: el CRUD de los topes/formatos/TTL (regla de parámetros sin deploy). Acepta
     * las mismas claves que devuelve GET /config (los derivados `max_*_bytes` y `modules` son de
     * solo lectura) y audita el cambio: subir un tope o un TTL es un cambio de postura de seguridad.
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            // El tope sale del escáner de PDF (pdf_scan_max_bytes): un PDF mayor se rechazaría en finalize.
            'max_pdf_mb'             => ['sometimes', 'integer', 'min:1', 'max:' . $this->settings->maxPdfMbCeiling()],
            'max_video_mb'           => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'allowed_extensions'     => ['sometimes', 'array', 'min:1'],
            'allowed_extensions.*'   => ['string', Rule::in(array_keys((array) config('biblioteca.extensions')))],
            'url_ttl_view_seconds'   => ['sometimes', 'integer', 'min:60', 'max:3600'],
            'url_ttl_video_seconds'  => ['sometimes', 'integer', 'min:60', 'max:14400'],
            'url_ttl_upload_seconds' => ['sometimes', 'integer', 'min:60', 'max:7200'],
            'max_pending_uploads'    => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $user = $this->actor($request);
        $old  = array_intersect_key($this->settings->toApi(), $data);

        foreach ($data as $apiKey => $value) {
            SystemSetting::updateOrCreate(['key' => LibrarySettings::API_KEYS[$apiKey]], [
                'value' => $value, 'updated_by' => $user->id, 'updated_at' => now(),
            ]);
        }

        $current = $this->settings->toApi();
        if ($data !== []) {
            $this->audit->log(
                AuditService::BIBLIOTECA_CONFIG_UPDATED,
                'library_config',
                'settings',
                $old,
                array_intersect_key($current, $data),
                null,
                $user->id,
            );
        }

        return response()->json($current);
    }

    public function initUpload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module'       => ['required', 'string'],
            'name'         => ['required', 'string', 'max:200'],
            'filename'     => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'max:100'],
            'size_bytes'   => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($this->service->initUpload(
            $this->actor($request),
            $data['module'],
            $data['name'],
            $data['filename'],
            $data['content_type'],
            (int) $data['size_bytes'],
        ), 201);
    }

    public function finalizeUpload(Request $request, string $uploadId): JsonResponse
    {
        try {
            return response()->json($this->service->finalizeUpload($this->actor($request), $uploadId), 201);
        } catch (\DomainException) {
            return response()->json(['message' => trans('biblioteca.upload_not_found')], 404);
        } catch (LibraryFinalizeInProgressException $e) {
            // No es un rechazo del archivo: el frontend lo reconoce por el 409 / `code` y ofrece reintentar.
            return response()->json(['message' => $e->getMessage(), 'code' => 'finalize_in_progress'], 409);
        } catch (LibraryRejectedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (LibraryStorageUnavailableException) {
            // Reintentable: el upload sigue registrado y el pending intacto (ver LibraryService::finalizeLocked).
            return response()->json(['message' => trans('biblioteca.storage_unavailable')], 503);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            if (! $this->service->delete($id, $this->actor($request))) {
                return response()->json(['message' => trans('biblioteca.not_found')], 404);
            }
        } catch (LibraryStorageUnavailableException|LibraryObjectChangedException) {
            // Fallo de S3 al borrar: 503 reintentable, no un 500 genérico (el servicio ya lo registró en el log).
            return response()->json(['message' => trans('biblioteca.storage_unavailable')], 503);
        }

        return response()->json(null, 204);
    }

    private function actor(Request $request): User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');
        /** @var User $user */
        $user = User::findOrFail((int) ($payload['sub'] ?? 0));

        return $user;
    }
}
