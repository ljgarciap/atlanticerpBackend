<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentResult;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFeTransmision;
use App\Modules\AdminCont\Models\AdminContInvoice;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCRUM-987 — deja constancia de cada transmisión al PAC (payload enviado + respuesta), append-only.
 * Único punto que escribe `fe_transmisiones`.
 *
 * Regla dura, igual que el resto de la capa: un fallo acá NUNCA bloquea ni revierte la factura/NC
 * ni cambia el resultado de la certificación — se atrapa y se loguea.
 *
 * El INSERT va dentro de `DB::transaction()` para que, si algún caller llegara a certificar con una
 * transacción abierta, un error SQL aborte solo un savepoint y no la transacción de la factura.
 * Ante un fallo se loguea SOLO la clase de la excepción y su código: el mensaje de una
 * QueryException trae el SQL con los valores interpolados (RUC, nombre y correo del receptor) y eso
 * no debe terminar en `laravel.log`/CloudWatch.
 *
 * Saneamiento antes de guardar: se quita `xmlFirmado` (y `responseData1`, donde Digifact manda el
 * mismo XML; ya se archiva en S3 y es enorme), se
 * redactan las API keys configuradas ANTES de truncar (si se truncara primero, una key partida por
 * el corte dejaría un pedazo irreconocible — ver `FiscalCertificationService::sanitizarDetalle()`),
 * y cada campo JSON se acota a 64 KB dejando marca `_truncado`.
 */
final class FeTransmisionRecorder
{
    private const MAX_BYTES = 65536;

    public function registrar(
        AdminContInvoice|AdminContCreditNote $modelo,
        string $tipoDocumento,
        FiscalDocumentResult $resultado,
        string $origen,
        ?string $serie,
        ?string $numeroFiscal,
    ): void {
        try {
            $body = $resultado->rawResponse;
            unset($body['xmlFirmado'], $body['responseData1'], $body['ResponseData1']);

            DB::transaction(fn () => AdminContFeTransmision::create([
                'documento_tipo'  => $tipoDocumento,
                'documento_id'    => $modelo->id,
                'provider'        => $resultado->pacProvider,
                'ambiente'        => $resultado->ambiente,
                'operacion'       => in_array($origen, ['polling', 'consulta_manual'], true) ? 'consultar_estado' : 'certificar',
                'origen'          => $origen,
                'endpoint'        => $resultado->endpoint,
                'serie'           => $serie,
                'numero_fiscal'   => $numeroFiscal,
                'request_payload' => $this->sanear($resultado->requestPayload),
                'http_status'     => $resultado->httpStatus,
                'response_body'   => $body === [] ? null : $this->sanear($body),
                'resultado'       => $resultado->errorTransporte ? 'sin_respuesta' : $resultado->estado,
                'duracion_ms'     => $resultado->duracionMs,
                'request_id'      => $this->requestId(),
            ]));
        } catch (Throwable $e) {
            Log::error('Facturación electrónica: no se pudo registrar la transmisión al PAC', [
                'tipo'            => $tipoDocumento,
                'id'              => $modelo->id,
                'exception_class' => $e::class,
                'codigo'          => $e->getCode(), // SQLSTATE en una QueryException; nunca getMessage() (trae el SQL con PII)
            ]);
        }
    }

    /**
     * @param array<string, mixed>|null $datos
     * @return array<string, mixed>|null
     */
    private function sanear(?array $datos): ?array
    {
        if ($datos === null) {
            return null;
        }

        // jsonb rechaza el carácter NUL: un solo byte así perdería el registro entero. Se quita de los
        // strings ANTES de codificar (no del JSON ya codificado: reemplazar la secuencia `\u0000` en el
        // texto resultante podría dejar una barra suelta y volver inválido el JSON).
        array_walk_recursive($datos, static function (mixed &$valor): void {
            if (is_string($valor)) {
                $valor = str_replace("\0", '', $valor);
            }
        });

        $json = (string) json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        foreach (['sandbox', 'production'] as $ambiente) {
            $apiKey = trim((string) config("services.msl_fe.{$ambiente}.api_key"));
            if ($apiKey !== '') {
                $json = str_replace($apiKey, '[redactado]', $json);
            }
        }

        if (strlen($json) > self::MAX_BYTES) {
            return ['_truncado' => true, 'contenido_parcial' => mb_strcut($json, 0, self::MAX_BYTES)];
        }

        /** @var array<string, mixed> $saneado */
        $saneado = json_decode($json, true) ?? [];

        return $saneado;
    }

    private function requestId(): ?string
    {
        try {
            $id = Context::get('request_id');

            return is_string($id) && $id !== '' ? $id : null;
        } catch (Throwable) {
            return null;
        }
    }
}
