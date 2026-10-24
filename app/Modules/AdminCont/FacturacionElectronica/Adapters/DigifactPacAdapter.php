<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Adapters;

use App\Modules\AdminCont\FacturacionElectronica\Contracts\PacProviderContract;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocument;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentResult;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalPartyLookupResult;
use App\Modules\AdminCont\FacturacionElectronica\Mappers\NucJsonMapper;
use App\Modules\AdminCont\FacturacionElectronica\Services\DigifactTokenService;
use App\Shared\Support\CircuitBreaker;
use App\Shared\Support\CircuitOpenException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Adapter directo contra el PAC Digifact (Panamá, NUC v2.0.8) — sin intermediario. Ver
 * docs/architecture/facturacion-electronica-pac-diseno.md y
 * docs/architecture/facturacion-electronica-adapter-digifact-notas.md para el detalle de qué
 * partes del mapeo NUC quedan "a confirmar" antes de Senior Review.
 *
 * AVISO: `normalizeEstado()` es la pieza de mayor incertidumbre de este adapter — los 3 estados
 * async del NUC (RECIBIDO_EXITOSAMENTE/EN_COLA_DE_ENVIO/ERROR_AL_ENVIAR) y el shape exacto de la
 * respuesta de éxito con el CUFE no están confirmados campo a campo, solo inferidos del fetch
 * automatizado de la doc pública.
 */
final class DigifactPacAdapter implements PacProviderContract
{
    public function __construct(
        private readonly NucJsonMapper $mapper,
        private readonly DigifactTokenService $tokenService,
        private readonly string $ambiente = 'pruebas',
    ) {
    }

    public function providerName(): string
    {
        return 'digifact';
    }

    public function certificarFactura(FiscalDocument $documento): FiscalDocumentResult
    {
        return $this->certificar($documento);
    }

    public function certificarNotaCredito(FiscalDocument $documento): FiscalDocumentResult
    {
        return $this->certificar($documento);
    }

    private function certificar(FiscalDocument $documento): FiscalDocumentResult
    {
        $body = $this->mapper->map($documento, $this->ambiente);

        try {
            $response = (new CircuitBreaker('digifact-transform'))->call(
                fn (): Response => $this->post('/v2/transform/nuc_json', $body)
            );
        } catch (CircuitOpenException) {
            return $this->serviceUnavailableResult();
        }

        return $this->toResult($response);
    }

    public function consultarEstado(string $serie, string $numero): FiscalDocumentResult
    {
        // AVISO: parámetros exactos de SHAREDINFO (por serie+numero vs. por CUFE) sin confirmar —
        // ver docs/architecture/facturacion-electronica-adapter-digifact-notas.md.
        try {
            $response = (new CircuitBreaker('digifact-sharedinfo'))->call(
                fn (): Response => $this->get('/SHAREDINFO', ['serial' => $serie, 'number' => $numero])
            );
        } catch (CircuitOpenException) {
            return $this->serviceUnavailableResult();
        }

        return $this->toResult($response);
    }

    public function validarReceptor(string $rucOCedula): FiscalPartyLookupResult
    {
        try {
            $response = (new CircuitBreaker('digifact-getinforuc'))->call(
                fn (): Response => $this->get('/GetInfoRuc', ['taxId' => $rucOCedula])
            );
        } catch (CircuitOpenException) {
            return new FiscalPartyLookupResult(valido: false, disponible: false);
        }

        if ($response->failed()) {
            return new FiscalPartyLookupResult(valido: false);
        }

        $data = $response->json();

        return new FiscalPartyLookupResult(
            valido: (bool) ($data['valido'] ?? $data['Valid'] ?? false),
            nombre: $data['nombre'] ?? $data['Name'] ?? null,
            dv: $data['dv'] ?? $data['DV'] ?? null,
            tipo: $data['tipo'] ?? $data['TipoReceptor'] ?? null,
        );
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body, bool $isRetry = false): Response
    {
        $response = $this->httpClient()->post($path, $body);

        if ($response->status() === 401 && !$isRetry) {
            $this->tokenService->forget($this->ambiente);

            return $this->post($path, $body, isRetry: true);
        }

        return $response;
    }

    /** @param array<string, mixed> $query */
    private function get(string $path, array $query, bool $isRetry = false): Response
    {
        $response = $this->httpClient()->get($path, $query);

        if ($response->status() === 401 && !$isRetry) {
            $this->tokenService->forget($this->ambiente);

            return $this->get($path, $query, isRetry: true);
        }

        return $response;
    }

    private function httpClient(): \Illuminate\Http\Client\PendingRequest
    {
        $baseUrl = $this->ambiente === 'produccion'
            ? config('services.digifact.base_url_produccion')
            : config('services.digifact.base_url_pruebas');

        return Http::baseUrl($baseUrl)
            ->withToken($this->tokenService->token($this->ambiente))
            ->timeout(15);
    }

    private function serviceUnavailableResult(): FiscalDocumentResult
    {
        return new FiscalDocumentResult(
            estado: 'pendiente',
            pacProvider: $this->providerName(),
            errores: ['El servicio de facturación electrónica (Digifact) no está disponible temporalmente.'],
        );
    }

    private function toResult(Response $response): FiscalDocumentResult
    {
        if ($response->status() === 401) {
            // 401 persistente tras refrescar el token una vez (ver post()/get()) — falla de
            // configuración/credenciales, no un rechazo fiscal real. Mismo criterio de
            // degradación con gracia que serviceUnavailableResult(), nunca una excepción cruda
            // hacia el caller (rompería el contrato uniforme de PacProviderContract).
            return new FiscalDocumentResult(
                estado: 'pendiente',
                pacProvider: $this->providerName(),
                errores: ['Digifact rechazó la autenticación tras refrescar el token — revisar credenciales.'],
            );
        }

        $data = $response->json() ?? [];

        return new FiscalDocumentResult(
            estado: $this->normalizeEstado($response, $data),
            pacProvider: $this->providerName(),
            cufe: $data['authNumber'] ?? $data['AuthNumber'] ?? $data['cufe'] ?? null,
            qr: null, // AVISO: el NUC no expone un campo de QR directo confirmado — a resolver
                      // junto con GetDocument (HTML/PDF), ver notas del adapter.
            xmlFirmadoBase64: $data['responseData1'] ?? $data['ResponseData1'] ?? null,
            errores: $this->extraerErrores($data),
            rawResponse: $data,
        );
    }

    /** @param array<string, mixed> $data */
    private function normalizeEstado(Response $response, array $data): string
    {
        $estadoCrudo = $data['status'] ?? $data['Status'] ?? $data['evento'] ?? null;

        if ($response->successful() && (isset($data['authNumber']) || isset($data['AuthNumber']))) {
            return 'certificado';
        }

        return match ($estadoCrudo) {
            'RECIBIDO_EXITOSAMENTE' => 'certificado',
            'EN_COLA_DE_ENVIO' => 'pendiente',
            'ERROR_AL_ENVIAR' => 'rechazado',
            default => $response->successful() ? 'pendiente' : 'rechazado',
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function extraerErrores(array $data): array
    {
        $infoDetails = $data['infoDetails'] ?? $data['InfoDetails'] ?? [];

        if (!is_array($infoDetails)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($detalle): ?string => is_array($detalle) ? ($detalle['dMsgRes'] ?? $detalle['description'] ?? null) : null,
            $infoDetails
        )));
    }
}
