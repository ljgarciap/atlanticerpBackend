<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl;

use App\Modules\AdminCont\FacturacionElectronica\Contracts\PacProviderContract;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocument;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentResult;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalPartyLookupResult;
use App\Modules\AdminCont\FacturacionElectronica\Exceptions\MslTransportException;
use App\Modules\AdminCont\FacturacionElectronica\Mappers\MslJsonMapper;
use App\Shared\Support\CircuitBreaker;
use App\Shared\Support\CircuitOpenException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Adapter hacia MSL (`fedigifact.appmsl.com`) — certifica a través de Digifact sin exponer XML ni
 * firma a AtlanticERP (GUIA_ILUMINATION.md). Ver docs/architecture/facturacion-electronica-pac-diseno.md
 * y docs/architecture/facturacion-electronica-adapter-msl-notas.md para el diseño completo y los
 * gaps encontrados al construir esto.
 *
 * Circuit breaker envuelve solo la llamada HTTP en sí (fallas de transporte/5xx) — una respuesta
 * de negocio legítima (400/422/202) nunca cuenta como falla de circuito, se mapea normal.
 */
final class MslPacAdapter implements PacProviderContract
{
    private const int DEFAULT_TIMEOUT = 15;
    private const string CIRCUIT_PREFIX = 'msl_fe';
    private const string LOOKUP_CIRCUIT_PREFIX = 'msl_lookup';

    private readonly string $baseUrl;
    private readonly string $apiKey;
    private readonly string $ambiente;
    private readonly MslJsonMapper $payloadMapper;

    /**
     * SCRUM-943 — sin argumentos explícitos, los defaults SIEMPRE resuelven a sandbox, nunca a
     * producción: el par de credenciales real a usar es una decisión de negocio
     * (`fiscal_settings.pac_ambiente`), no un default de constructor. `PacProviderFactory` es el
     * único punto que arma este adapter en `ambiente: 'produccion'` — nunca este constructor.
     */
    public function __construct(?string $baseUrl = null, ?string $apiKey = null, ?string $ambiente = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.msl_fe.sandbox.base_url'), '/');
        $this->apiKey = $apiKey ?? (string) config('services.msl_fe.sandbox.api_key');
        $this->ambiente = $ambiente ?? 'pruebas';
        $this->payloadMapper = new MslJsonMapper(new MslPaymentMethodMapper());
    }

    public function providerName(): string
    {
        return 'msl';
    }

    public function certificarFactura(FiscalDocument $documento): FiscalDocumentResult
    {
        return $this->certificar('/v1/facturas', $documento);
    }

    public function certificarNotaCredito(FiscalDocument $documento): FiscalDocumentResult
    {
        return $this->certificar('/v1/notas-credito', $documento);
    }

    public function consultarEstado(string $serie, string $numero): FiscalDocumentResult
    {
        $endpoint = "/v1/documentos/{$serie}/{$numero}";
        $inicio   = hrtime(true);

        try {
            $response = $this->breaker()->call(
                fn () => $this->send('get', $endpoint)
            );
        } catch (CircuitOpenException|MslTransportException) {
            return $this->serviceUnavailableResult($endpoint, null, $inicio);
        }

        return $this->mapCertificacionResponse($response, $endpoint, null, $inicio);
    }

    /**
     * SCRUM-1002 — circuito PROPIO (`msl_lookup_<ambiente>`, no `msl_fe_<ambiente>`): una ráfaga de consultas fallidas
     * desde la UI de Clientes no debe abrir el circuito de certificación y frenar la emisión de facturas.
     *
     * `disponible = false` cuando no se pudo consultar (circuito abierto, timeout/5xx, API key rechazada 401/403,
     * límite de tasa 429, o respuesta que no es JSON); `valido = false` con `disponible = true` cuando MSL respondió
     * que el RUC no existe (cualquier otro 4xx de negocio).
     */
    public function validarReceptor(string $rucOCedula): FiscalPartyLookupResult
    {
        try {
            $response = $this->breaker(self::LOOKUP_CIRCUIT_PREFIX)->call(
                fn () => $this->send('get', '/v1/validar-ruc', ['ruc' => $rucOCedula, 'ambiente' => $this->ambiente])
            );
        } catch (CircuitOpenException|MslTransportException) {
            return new FiscalPartyLookupResult(valido: false, disponible: false);
        }

        if (in_array($response->status(), [401, 403, 408, 429], true)) {
            return new FiscalPartyLookupResult(valido: false, disponible: false);
        }

        if (! $response->successful()) {
            return new FiscalPartyLookupResult(valido: false);
        }

        $body = $response->json();
        if (! is_array($body)) {
            return new FiscalPartyLookupResult(valido: false, disponible: false);
        }

        return new FiscalPartyLookupResult(
            valido: (bool) ($body['valido'] ?? false),
            nombre: isset($body['nombre']) ? (string) $body['nombre'] : null,
            // SCRUM-1019 — MSL puede mandar `dv` vacío (""): se normaliza a null, nunca un DV de cadena vacía.
            dv: isset($body['dv']) && trim((string) $body['dv']) !== '' ? trim((string) $body['dv']) : null,
            tipo: isset($body['tipoRuc']) ? (string) $body['tipoRuc'] : null,
            estado: isset($body['estado']) ? (string) $body['estado'] : null,
        );
    }

    private function certificar(string $endpoint, FiscalDocument $documento): FiscalDocumentResult
    {
        $payload = $this->payloadMapper->map($documento, $this->ambiente);
        $inicio  = hrtime(true);

        try {
            $response = $this->breaker()->call(
                fn () => $this->send('post', $endpoint, $payload)
            );
        } catch (CircuitOpenException|MslTransportException) {
            return $this->serviceUnavailableResult($endpoint, $payload, $inicio);
        }

        return $this->mapCertificacionResponse($response, $endpoint, $payload, $inicio);
    }

    /**
     * @param array<string, mixed>|null $requestPayload
     */
    private function mapCertificacionResponse(Response $response, string $endpoint, ?array $requestPayload, int $inicio): FiscalDocumentResult
    {
        $body = $response->json() ?? [];
        $estadoMsl = $body['estado'] ?? null;

        $estado = match (true) {
            $response->status() === 200 && $estadoMsl === 'CERTIFICADO' => 'certificado',
            // SCRUM-1019 — idempotencia de MSL (respuesta de Edgar 2026-10-02): reenviar una serie+número que MSL ya certificó
            // responde HTTP 200 "Documento ya certificado previamente" con el CUFE/QR/XML ORIGINALES. Es un éxito (el documento
            // existe ante la DGI), no un error de configuración. Se exige CUFE para no marcar certificado algo sin evidencia.
            $response->status() === 200 && $this->esYaCertificadoPreviamente($body) => 'certificado',
            $response->status() === 202 || $estadoMsl === 'PENDIENTE' => 'pendiente',
            $response->status() === 422 || $estadoMsl === 'RECHAZADO' => 'rechazado',
            // 400/401/403 (o cualquier respuesta sin `estado` reconocido): MSL nunca llegó a
            // evaluar el documento fiscalmente — es un problema de configuración/integración
            // nuestro (API key inválida, ambiente no habilitado, payload malformado), no un
            // rechazo de la DGI. 5xx no cae acá — send() ya lo intercepta como falla de circuito.
            default => 'error_configuracion',
        };

        return new FiscalDocumentResult(
            estado: $estado,
            pacProvider: $this->providerName(),
            cufe: $body['cufe'] ?? null,
            qr: $body['qr'] ?? null,
            xmlFirmadoBase64: $body['xmlFirmado'] ?? null,
            errores: $this->extractErrores($response, $body),
            rawResponse: $body,
            requestPayload: $requestPayload,
            httpStatus: $response->status(),
            duracionMs: $this->duracionMs($inicio),
            endpoint: $endpoint,
            ambiente: $this->ambiente,
        );
    }

    /** @param array<string, mixed> $body */
    private function esYaCertificadoPreviamente(array $body): bool
    {
        $cufe = $body['cufe'] ?? null;
        if (! is_string($cufe) || trim($cufe) === '') {
            return false;
        }

        foreach (['mensaje', 'message', 'detalle', 'error'] as $campo) {
            $texto = $body[$campo] ?? null;
            if (is_string($texto) && stripos($texto, 'ya certificado previamente') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $body
     * @return string[]
     */
    private function extractErrores(Response $response, array $body): array
    {
        if ($response->status() === 401) {
            return ['MSL respondió 401 (API key inválida o ausente) — error de configuración nuestro, no un rechazo de la DGI.'];
        }

        if ($response->status() === 403) {
            return ['MSL respondió 403 (ambiente no habilitado, ej. producción antes del go-live) — error de configuración nuestro, no un rechazo de la DGI.'];
        }

        if (isset($body['detalles']) && is_array($body['detalles'])) {
            return array_values(array_map('strval', $body['detalles']));
        }

        if (isset($body['error'])) {
            return [(string) $body['error']];
        }

        if (! $response->successful() && $response->status() !== 202) {
            return ["MSL respondió HTTP {$response->status()} sin detalle."];
        }

        return [];
    }

    /** @param array<string, mixed>|null $requestPayload */
    private function serviceUnavailableResult(string $endpoint, ?array $requestPayload, int $inicio): FiscalDocumentResult
    {
        return new FiscalDocumentResult(
            estado: 'pendiente',
            pacProvider: $this->providerName(),
            errores: ['El servicio de facturación electrónica (MSL) no está disponible temporalmente.'],
            errorTransporte: true,
            requestPayload: $requestPayload,
            duracionMs: $this->duracionMs($inicio),
            endpoint: $endpoint,
            ambiente: $this->ambiente,
        );
    }

    /** Milisegundos transcurridos desde `$inicio` (`hrtime(true)`, en nanosegundos). */
    private function duracionMs(int $inicio): int
    {
        return (int) round((hrtime(true) - $inicio) / 1_000_000);
    }

    /** @param array<string, mixed> $queryOrBody */
    private function send(string $method, string $path, array $queryOrBody = []): Response
    {
        $request = Http::withHeaders(['x-api-key' => $this->apiKey])->timeout(self::DEFAULT_TIMEOUT);

        try {
            $response = $method === 'get'
                ? $request->get($this->baseUrl . $path, $queryOrBody)
                : $request->post($this->baseUrl . $path, $queryOrBody);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new MslTransportException("MSL sin respuesta (timeout/conexión): {$e->getMessage()}", previous: $e);
        }

        // Solo 5xx/timeout de transporte cuentan como falla de circuito — 4xx es respuesta de
        // negocio legítima (ver docblock de clase).
        if ($response->serverError()) {
            throw new MslTransportException("MSL respondió error de servidor: HTTP {$response->status()}");
        }

        return $response;
    }

    private function breaker(string $prefix = self::CIRCUIT_PREFIX): CircuitBreaker
    {
        // Un circuito por ambiente: una caída de MSL producción no debe abrir el circuito del sandbox
        // del mismo entorno de AtlanticERP (ni viceversa) — endpoints y credenciales distintos.
        return new CircuitBreaker($prefix.'_'.$this->ambiente, threshold: 5, windowSeconds: 60, openTimeout: 30);
    }
}
