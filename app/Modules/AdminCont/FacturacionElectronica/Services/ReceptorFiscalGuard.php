<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

use App\Modules\AdminCont\FacturacionElectronica\DTOs\ReceptorFiscalSnapshot;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\SubClientTaxDataService;
use App\Modules\VentasDiseno\Support\ReceptorTipo;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Guarda de "Crear factura" (docs/specs/guarda-validacion-ruc-al-facturar.md, decisiones de Luis 2026-10-07): antes de
 * crear una factura (y por tanto de gastar su número ante MSL) se valida el RUC del receptor contra la DGI con
 * `TaxIdValidationService`. Una factura solo se genera si la DGI reconoce el RUC, el DV coincide y la razón social
 * coincide una vez normalizada.
 *
 * No es `final` a propósito: los tests de facturación que no son de la guarda la sustituyen por un doble
 * (`Tests\Feature\AdminCont\Concerns\StubsReceptorGuard`) para no depender de credenciales ni de un `validar-ruc`.
 *
 * SCRUM-1140 (docs/specs/receptor-tipo-documento-al-facturar.md): la razón social distinta de la DGI ya NO bloquea. La factura
 * envía siempre la razón social validada por la DGI (`receptorResuelto()`), así que la diferencia es solo informativa y
 * "Actualizar cliente" es una acción opcional (`actualizable`). El DV solo se exige cuando la DGI lo trae.
 *
 * Políticas:
 *  - Estado `I` de la DGI: se ignora (decisión 1) — `TaxIdValidationService` lo devuelve solo informativo.
 *  - Sin validación disponible (sin credenciales, MSL caído/circuito abierto): bloquea con `pac_ambiente` `production` y
 *    `testing` (decisión 2); con `apagado` no se consulta a MSL y se deja pasar con aviso.
 *  - Consumidor Final (`02`): no se valida, no tiene RUC.
 *  - Candado 409 (decisión 4): si el cliente ya tiene documentos certificados/pendientes, el estado lo informa en
 *    `edicion_bloqueada` y no se ofrece corrección.
 */
class ReceptorFiscalGuard
{
    public const OK                = 'ok';
    public const NO_APLICA         = 'no_aplica';        // Consumidor Final
    public const NO_VALIDADO       = 'no_validado';      // PAC apagado: no se consultó, no bloquea
    public const SIN_RUC           = 'sin_ruc';
    public const NO_ENCONTRADO     = 'no_encontrado';
    public const DV_DISTINTO       = 'dv_distinto';
    public const NOMBRE_DISTINTO   = 'nombre_distinto';
    public const NO_DISPONIBLE     = 'no_disponible';    // no se pudo consultar a la DGI

    public function __construct(
        private readonly TaxIdValidationService $validation,
        private readonly SubClientTaxDataService $taxData,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluar(?SubClient $subClient): array
    {
        if ($subClient === null) {
            return $this->resultado(self::NO_APLICA, false, 'El pedido no tiene cliente fiscal asociado.', null);
        }

        return $this->evaluarDatos(
            $subClient,
            (string) ($subClient->receptor_tipo ?? ReceptorTipo::CONTRIBUYENTE),
            (string) $subClient->tax_id,
            $subClient->tax_id_dv !== null ? (string) $subClient->tax_id_dv : null,
            (string) $subClient->business_name,
        );
    }

    /**
     * Pedido gemelo de ICG: la guarda es más dura que la de un pedido normal (fe-panama C3). Sin cliente fiscal, sin RUC/cédula o
     * como Consumidor Final se bloquea siempre (también con el PAC apagado: un gemelo nunca sale a Consumidor Final por omisión).
     * El DV no se exige aparte: lo decide la validación contra la DGI de siempre. Si la DGI informa un DV y el cliente no lo tiene,
     * bloquea; si la DGI no informa DV (persona natural con cédula), deja facturar.
     *
     * @return array<string, mixed>
     */
    public function evaluarGemelo(?SubClient $subClient): array
    {
        if ($subClient === null) {
            return $this->resultado(self::SIN_RUC, true, 'El pedido de ICG no tiene cliente fiscal asociado. Vincúlelo para poder facturar.', null);
        }

        $tipo = (string) ($subClient->receptor_tipo ?? ReceptorTipo::CONTRIBUYENTE);
        $taxId = trim((string) $subClient->tax_id);
        $nombre = (string) $subClient->business_name;

        if ($tipo === ReceptorTipo::CONSUMIDOR_FINAL) {
            return $this->resultado(self::SIN_RUC, true, 'Un pedido de ICG no se factura a Consumidor Final: el cliente debe ser contribuyente con RUC.', $subClient, $taxId, null, $nombre);
        }
        if ($taxId === '') {
            return $this->resultado(self::SIN_RUC, true, 'El cliente no tiene RUC o cédula. Ingréselo para poder facturar el pedido de ICG.', $subClient, $taxId, null, $nombre);
        }

        return $this->evaluar($subClient);
    }

    /**
     * Evalúa datos candidatos de un SubCliente (el modal de corrección) sin guardarlos.
     *
     * @return array<string, mixed>
     */
    public function evaluarDatos(SubClient $subClient, string $tipo, string $taxId, ?string $dv, string $nombre): array
    {
        if ($tipo === ReceptorTipo::CONSUMIDOR_FINAL) {
            return $this->resultado(self::NO_APLICA, false, 'Consumidor Final: no requiere RUC.', $subClient);
        }

        $taxId = trim($taxId);
        $dv    = $dv !== null ? trim($dv) : null;

        if ($taxId === '') {
            // Con el PAC apagado nada viaja a la DGI/MSL: igual que cualquier otra falta de validación, deja pasar con aviso.
            if (AdminContFiscalSettings::current()->pac_ambiente === 'apagado') {
                return $this->resultado(self::NO_VALIDADO, false, 'La facturación electrónica está apagada: el cliente no tiene RUC y no se validó contra la DGI.', $subClient, $taxId, $dv, $nombre);
            }

            return $this->resultado(self::SIN_RUC, true, 'El cliente no tiene RUC o cédula. Ingréselo para poder facturar.', $subClient, $taxId, $dv, $nombre);
        }

        $dgi = $this->consultarDgi($taxId);

        if (! $dgi['disponible']) {
            $ambiente = AdminContFiscalSettings::current()->pac_ambiente;

            if ($dgi['codigo'] === TaxIdValidationService::CODIGO_APAGADO || $ambiente === 'apagado') {
                return $this->resultado(self::NO_VALIDADO, false, 'La facturación electrónica está apagada: el RUC no se validó contra la DGI.', $subClient, $taxId, $dv, $nombre);
            }

            return $this->resultado(
                self::NO_DISPONIBLE,
                true, // `production` y `testing` bloquean (decisión 2)
                'No se pudo validar el RUC contra la DGI en este momento. Intente de nuevo en unos minutos.',
                $subClient, $taxId, $dv, $nombre,
                codigoDgi: (string) $dgi['codigo'],
            );
        }

        if (! $dgi['valido']) {
            return $this->resultado(self::NO_ENCONTRADO, true, "La DGI no reconoce el RUC {$taxId}. Corrija el RUC para poder facturar.", $subClient, $taxId, $dv, $nombre);
        }

        $nombreDgi = $this->limpiarNombre((string) $dgi['nombre']);
        $dvDgi     = $dgi['dv'];

        if ($dvDgi !== null && ($dv === null || $dv === '' || $dv !== (string) $dvDgi)) {
            $detalle = ($dv === null || $dv === '') ? 'El cliente no tiene DV' : "El DV registrado ({$dv}) no coincide";

            return $this->resultado(self::DV_DISTINTO, true, "{$detalle}; la DGI indica DV {$dvDgi}.", $subClient, $taxId, $dv, $nombre, $nombreDgi, $dvDgi);
        }

        if ($this->normalizarNombre($nombre) !== $this->normalizarNombre($nombreDgi)) {
            // SCRUM-1140: informativo, no bloquea — viaja la razón social de la DGI.
            return $this->resultado(self::NOMBRE_DISTINTO, false, "La razón social no coincide con la de la DGI: \"{$nombreDgi}\". Se enviará la de la DGI en la factura.", $subClient, $taxId, $dv, $nombre, $nombreDgi, $dvDgi);
        }

        return $this->resultado(self::OK, false, 'RUC validado contra la DGI.', $subClient, $taxId, $dv, $nombre, $nombreDgi, $dvDgi);
    }

    /**
     * SCRUM-1140 — el receptor que se congela en la factura (`ReceptorFiscalSnapshot`) a partir de una evaluación de esta
     * misma guarda. NULL cuando la evaluación bloquea: no hay receptor facturable.
     *  - `ok` / `nombre_distinto`: contribuyente con la razón social y el DV que devolvió la DGI (el DV solo si la DGI lo
     *    trajo), con la hora de la validación.
     *  - `no_validado` (PAC apagado): contribuyente tal como está en el cliente, sin validación — como hoy.
     *  - `no_aplica` (consumidor final del cliente, o pedido sin cliente fiscal) y un cliente sin RUC con el PAC apagado:
     *    consumidor final, igual que `FiscalDocumentBuilder::receptorDeSubClient()`.
     *
     * @param array<string, mixed> $evaluacion resultado de `evaluar()` / `evaluarDatos()`
     */
    public function receptorResuelto(array $evaluacion): ?ReceptorFiscalSnapshot
    {
        $ruc = trim((string) ($evaluacion['ruc'] ?? ''));

        return match ($evaluacion['estado'] ?? null) {
            self::OK, self::NOMBRE_DISTINTO => ReceptorFiscalSnapshot::contribuyente(
                $ruc,
                is_string($evaluacion['dv_dgi'] ?? null) ? $evaluacion['dv_dgi'] : null,
                trim((string) ($evaluacion['nombre_dgi'] ?? '')) !== '' ? (string) $evaluacion['nombre_dgi'] : (string) ($evaluacion['nombre'] ?? ''),
                new DateTimeImmutable(),
            ),
            self::NO_VALIDADO => $ruc === ''
                ? ReceptorFiscalSnapshot::consumidorFinal()
                : ReceptorFiscalSnapshot::contribuyente($ruc, is_string($evaluacion['dv'] ?? null) ? $evaluacion['dv'] : null, (string) ($evaluacion['nombre'] ?? '')),
            self::NO_APLICA => ReceptorFiscalSnapshot::consumidorFinal(),
            default => null,
        };
    }

    /** Segundos que se recuerda una respuesta DEFINITIVA de la DGI (válido / no encontrado) para no repetir la consulta. */
    public const CACHE_SEGUNDOS = 60;

    public static function claveCache(string $ambiente, string $taxId): string
    {
        return 'receptor-guard:dgi:'.$ambiente.':'.sha1($taxId);
    }

    /**
     * Una consulta a la DGI por RUC cada `CACHE_SEGUNDOS`, no una por cada vista previa/guardado/clic. Solo se recuerdan las
     * respuestas definitivas (`disponible = true`): una falla de la DGI (circuito abierto, timeout) nunca se cachea, así que
     * en cuanto se recupera la siguiente consulta ya la ve.
     *
     * @return array{disponible: bool, valido: bool|null, nombre: string|null, dv: string|null, tipo_ruc: string|null, estado: string|null, codigo: string}
     */
    private function consultarDgi(string $taxId): array
    {
        $clave = self::claveCache((string) AdminContFiscalSettings::current()->pac_ambiente, $taxId);

        /** @var array{disponible: bool, valido: bool|null, nombre: string|null, dv: string|null, tipo_ruc: string|null, estado: string|null, codigo: string}|null $guardada */
        $guardada = Cache::get($clave);
        if (is_array($guardada)) {
            return $guardada;
        }

        $dgi = $this->validation->validar($taxId);
        if ($dgi['disponible']) {
            Cache::put($clave, $dgi, self::CACHE_SEGUNDOS);
        }

        return $dgi;
    }

    /** Mayúsculas, sin acentos, solo letras y dígitos: "CONSTRUCTORA GRUPO V, SA" == "CONSTRUCTORA GRUPO V  S A  ". */
    public function normalizarNombre(string $nombre): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($nombre)));
    }

    /** Largo de `invoices.receptor_nombre`. */
    private const NOMBRE_MAX = 255;

    /** Sin caracteres de control, espacios colapsados y como máximo `NOMBRE_MAX` (texto externo de la DGI que viaja al PAC y al PDF). */
    private function limpiarNombre(string $nombre): string
    {
        $sinControl = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $nombre);

        return trim(mb_substr(trim((string) preg_replace('/\s+/u', ' ', $sinControl)), 0, self::NOMBRE_MAX));
    }

    /**
     * @return array<string, mixed>
     */
    private function resultado(
        string $estado,
        bool $bloquea,
        string $mensaje,
        ?SubClient $subClient,
        ?string $ruc = null,
        ?string $dv = null,
        ?string $nombre = null,
        ?string $nombreDgi = null,
        ?string $dvDgi = null,
        ?string $codigoDgi = null,
    ): array {
        $corregible  = $bloquea && $subClient !== null && in_array($estado, [self::SIN_RUC, self::NO_ENCONTRADO, self::DV_DISTINTO], true);
        // SCRUM-1140: razón social distinta no bloquea, pero el usuario puede (opcionalmente) actualizar el cliente con la de la DGI.
        $actualizable = $estado === self::NOMBRE_DISTINTO && $subClient !== null;

        return [
            'estado'            => $estado,
            'bloquea'           => $bloquea,
            'mensaje'           => $mensaje,
            'sub_client_id'     => $subClient?->id,
            'ruc'               => $ruc,
            'dv'                => $dv,
            'nombre'            => $nombre,
            'nombre_dgi'        => $nombreDgi,
            'dv_dgi'            => $dvDgi,
            'codigo_dgi'        => $codigoDgi,
            // Decisión 4: con documentos certificados/pendientes solo se muestra el error, sin corrección.
            'edicion_bloqueada' => ($corregible || $actualizable) && $this->taxData->conDocumentosFiscales([(int) $subClient->id]) !== [],
            'corregible'        => $corregible,
            'actualizable'      => $actualizable,
        ];
    }
}
