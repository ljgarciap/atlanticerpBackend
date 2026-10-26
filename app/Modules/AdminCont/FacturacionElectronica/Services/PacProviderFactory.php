<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

use App\Modules\AdminCont\FacturacionElectronica\Adapters\DigifactPacAdapter;
use App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl\MslPacAdapter;
use App\Modules\AdminCont\FacturacionElectronica\Contracts\PacProviderContract;
use App\Modules\AdminCont\FacturacionElectronica\Exceptions\PacAmbienteNoPermitidoException;
use App\Modules\AdminCont\FacturacionElectronica\Exceptions\SinFacturacionElectronicaException;
use App\Modules\AdminCont\FacturacionElectronica\Mappers\NucJsonMapper;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;

/**
 * SCRUM-943 — único punto que arma un `PacProviderContract` real, leyendo la configuración de
 * NEGOCIO del tenant (`fiscal_settings.pac_provider`/`pac_ambiente`, editable por Gerencia en
 * Configuración Fiscal) en vez de que cada adapter se resuelva solo desde `.env`. Mismo principio
 * que `FiscalDocumentItemsBuilder`: el resto de la capa `FacturacionElectronica` (DTOs, mappers,
 * adapters) no conoce `AdminContFiscalSettings` a propósito — este factory es la única costura.
 *
 * Fase 6b — los proveedores válidos por país viven en `FiscalProviderRegistry` (`config/fiscal.php`). `msl` y `digifact` (Panamá) están
 * wireados; `none` (país sin paquete fiscal) NO construye adaptador: lanza `SinFacturacionElectronicaException` (quien llama debe
 * comprobar `AdminContFiscalSettings::sinFacturacionElectronica()` antes). Cualquier otro valor sigue siendo un error de configuración
 * explícito, no un fallback silencioso a un provider no probado. OJO: el adaptador `digifact` se probó solo contra la documentación
 * pública y el sandbox (ver `DigifactPacAdapter`: `normalizeEstado()` es lo menos confirmado); validarlo con credenciales reales antes de producción.
 */
final class PacProviderFactory
{
    public function make(): PacProviderContract
    {
        $settings = AdminContFiscalSettings::current();

        return match ($settings->pac_provider) {
            'msl' => $this->makeMsl($settings->pac_ambiente),
            'digifact' => $this->makeDigifact($settings->pac_ambiente),
            FiscalProviderRegistry::NINGUNO => throw new SinFacturacionElectronicaException(),
            default => throw new \RuntimeException(
                "pac_provider '{$settings->pac_provider}' no está wireado — implementados: msl, digifact (y 'none' = sin facturación electrónica)."
            ),
        };
    }

    /**
     * SCRUM-943 (hallazgo de Luis en vivo, 2026-09-25) — `fiscal_settings.pac_connection_status`
     * era un valor fijo en BD ('conectado' por default, REQ-557 2026-08-19), pensado como
     * puramente informativo porque en ese momento no existía ninguna integración real con ningún
     * PAC. Ahora que sí la hay, mostrar siempre "Conectado" es engañoso — esto reemplaza esa
     * lectura estática por un chequeo real: ¿hay una API key configurada para el proveedor/ambiente
     * que `make()` usaría de verdad ahora mismo? `apagado` y cualquier proveedor no wireado
     * (`digifact`) siempre son `false` — no hay nada a lo que conectarse a propósito.
     */
    public function tieneCredencialesReales(): bool
    {
        $settings = AdminContFiscalSettings::current();
        if ($settings->sinFacturacionElectronica()) {
            return false;
        }

        if ($settings->pac_provider === 'digifact') {
            foreach (['ruc', 'username', 'password'] as $campo) {
                if (trim((string) config("services.digifact.{$campo}")) === '') {
                    return false;
                }
            }

            return true;
        }

        if ($settings->pac_provider !== 'msl') {
            return false;
        }

        $credenciales = config('services.msl_fe.'.($settings->pac_ambiente === 'production' ? 'production' : 'sandbox'));

        return trim((string) ($credenciales['api_key'] ?? '')) !== '';
    }

    private function makeDigifact(string $pacAmbiente): DigifactPacAdapter
    {
        if ($pacAmbiente === 'production') {
            $this->guardAmbienteProduccion();
        }

        return new DigifactPacAdapter(
            new NucJsonMapper(config('services.digifact.branch_code') !== null ? (string) config('services.digifact.branch_code') : null),
            new DigifactTokenService(),
            $pacAmbiente === 'production' ? 'produccion' : 'pruebas',
        );
    }

    private function makeMsl(string $pacAmbiente): MslPacAdapter
    {
        if ($pacAmbiente === 'production') {
            $this->guardAmbienteProduccion();

            $credenciales = config('services.msl_fe.production');

            return new MslPacAdapter(
                baseUrl: (string) $credenciales['base_url'],
                apiKey: (string) $credenciales['api_key'],
                ambiente: 'produccion',
            );
        }

        // 'testing' (único otro valor válido hoy — 'apagado' se agrega en SCRUM-943 tarea 3 y se
        // corta ANTES de llegar acá, en el gate del controller, no en este factory) → sandbox.
        $credenciales = config('services.msl_fe.sandbox');

        return new MslPacAdapter(
            baseUrl: (string) $credenciales['base_url'],
            apiKey: (string) $credenciales['api_key'],
            ambiente: 'pruebas',
        );
    }

    /**
     * SCRUM-943 — recomendación de Cybersecurity aceptada por Luis (2026-09-24): un `APP_ENV` de
     * AtlanticERP que no es `production` (dev/qa/local) nunca puede certificar contra producción real de
     * MSL, aunque `fiscal_settings.pac_ambiente` de ese entorno diga `production` por error de
     * config o dato de prueba mal cargado — evita mandar un documento fiscal real a la DGI desde un
     * entorno que no es el productivo de verdad.
     */
    private function guardAmbienteProduccion(): void
    {
        if (! app()->environment('production')) {
            throw new PacAmbienteNoPermitidoException((string) app()->environment());
        }
    }
}
