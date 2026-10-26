<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Http\Requests\UpdateCompanyProfileRequest;
use App\Modules\AdminCont\Http\Requests\UploadCompanyLogoRequest;
use App\Modules\AdminCont\Models\AdminContCompanyProfile;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantRegion;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SCRUM-638/639/642 (REQ-561/562/565) — Datos de la Empresa, gateada por EnsureIsPrimaryApprover.
 * `razon_social`/`nombre_comercial` se leen en vivo de AdminContFiscalSettings (RN1 de REQ-562) —
 * nunca se duplican acá, para que nunca se muestren razones sociales distintas entre pantallas.
 */
class AdminContCompanyProfileController
{
    private const DISK = 's3-public';

    public function show(): JsonResponse
    {
        return response()->json($this->format(AdminContCompanyProfile::current()));
    }

    public function update(UpdateCompanyProfileRequest $request): JsonResponse
    {
        $profile = AdminContCompanyProfile::current();
        $validated = $request->validated();

        // Fase 6a — país/moneda/zona/locale son del tenant (fuente de verdad); la zona horaria se refleja también en el perfil por compatibilidad.
        $region = array_filter([
            'country' => $validated['pais'] ?? null,
            'currency' => $validated['moneda'] ?? null,
            'timezone' => $validated['zona_horaria'] ?? null,
            'locale' => $validated['locale'] ?? null,
        ], static fn ($v): bool => $v !== null);
        if ($region !== []) {
            Tenant::current()?->update($region);
        }

        $profile->update(array_diff_key($validated, array_flip(['pais', 'moneda', 'locale'])));

        return response()->json($this->format($profile->refresh()));
    }

    public function uploadLogo(UploadCompanyLogoRequest $request): JsonResponse
    {
        $profile = AdminContCompanyProfile::current();

        /** @var UploadedFile $file */
        $file = $request->validated()['file'];
        $ext = strtolower($file->getClientOriginalExtension());
        $key = 'admin-contab/company/'.Str::uuid()->toString().($ext !== '' ? ".{$ext}" : '');

        (new CircuitBreaker(self::DISK))->call(function () use ($file, $key): void {
            $uploaded = Storage::disk(self::DISK)->putFileAs('admin-contab/company', $file, basename($key));

            if ($uploaded === false) {
                throw new \RuntimeException('S3 upload failed for disk ['.self::DISK.'] key ['.$key.']');
            }
        });

        $base = rtrim((string) (config('filesystems.disks.s3-public.cdn_url') ?: config('filesystems.disks.s3-public.url', '')), '/');
        $publicUrl = $base !== '' ? "{$base}/public/{$key}" : null;

        $previousKey = $profile->logo_key;

        // `logo_key`/`logo_public_url` a propósito NO están en $fillable (nunca settable vía el
        // PUT general de /company-profile) — se asignan directo, no vía update() masivo.
        $profile->logo_key = $key;
        $profile->logo_public_url = $publicUrl;
        $profile->save();

        if ($previousKey !== null) {
            try {
                Storage::disk(self::DISK)->delete($previousKey);
            } catch (\Throwable $e) {
                Log::error('admin_contab.company_logo.cleanup_failed', ['key' => $previousKey, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['logo_url' => $publicUrl]);
    }

    /** @return array<string, mixed> */
    private function format(AdminContCompanyProfile $profile): array
    {
        $fiscal = AdminContFiscalSettings::current();

        return [
            'razon_social' => $fiscal->razon_social,
            'nombre_comercial' => $fiscal->nombre_comercial,
            'logo_url' => $profile->logo_public_url,
            'descripcion_corta' => $profile->descripcion_corta,
            'sitio_web' => $profile->sitio_web,
            'redes_sociales' => $profile->redes_sociales ?? [],
            'pais' => TenantRegion::country(),
            'moneda' => TenantRegion::currency(),
            'anio_fiscal' => $profile->anio_fiscal,
            'zona_horaria' => TenantRegion::timezone(),
            'locale' => TenantRegion::locale(),
            // Catálogo de países con su contexto regional por omisión (config/regions.php): el frontend lo usa para proponer moneda/zona/locale.
            'paises' => collect((array) config('regions.countries'))
                ->map(fn (array $c, string $code): array => ['code' => $code, ...array_intersect_key($c, array_flip(['name', 'currency', 'timezone', 'locale']))])
                ->values()
                ->all(),
        ];
    }
}
