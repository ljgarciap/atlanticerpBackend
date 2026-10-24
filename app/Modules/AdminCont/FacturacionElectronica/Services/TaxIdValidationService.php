<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCRUM-1002 — valida un RUC/Cédula contra la DGI vía el PAC (`GET /v1/validar-ruc` de MSL) para la UI de Clientes.
 *
 * Reglas (decisiones de Luis, 2026-09-29):
 *  - Respeta el interruptor "Apagado" de Configuración Fiscal: con `pac_ambiente = apagado` NO se llama a MSL
 *    (mantiene la garantía "apagado = cero llamadas a MSL").
 *  - NUNCA lanza: cualquier falla (sin API key, circuito abierto, MSL caído, proveedor no wireado) se devuelve como
 *    `disponible = false` con un `codigo`, para que crear/editar un cliente siga funcionando igual.
 *  - No deja la respuesta cruda ni la API key fuera de esta capa, y el log lleva el RUC enmascarado.
 *  - Usa el circuito propio de consultas del adapter (`msl_lookup_<ambiente>`), no el de certificación.
 */
final class TaxIdValidationService
{
    public const CODIGO_OK              = 'ok';
    public const CODIGO_NO_ENCONTRADO   = 'no_encontrado';
    public const CODIGO_APAGADO         = 'apagado';
    public const CODIGO_SIN_CREDENCIALES = 'sin_credenciales';
    public const CODIGO_NO_DISPONIBLE   = 'no_disponible';

    public function __construct(private readonly PacProviderFactory $providers)
    {
    }

    /**
     * @return array{disponible: bool, valido: bool|null, nombre: string|null, dv: string|null, tipo_ruc: string|null, estado: string|null, codigo: string}
     */
    public function validar(string $taxId): array
    {
        $taxId = trim($taxId);

        if ($taxId === '') {
            return $this->noDisponible(self::CODIGO_NO_DISPONIBLE); // nada que consultar (SCRUM-1019)
        }

        if (AdminContFiscalSettings::current()->pac_ambiente === 'apagado') {
            return $this->noDisponible(self::CODIGO_APAGADO);
        }

        if (! $this->providers->tieneCredencialesReales()) {
            return $this->noDisponible(self::CODIGO_SIN_CREDENCIALES);
        }

        try {
            $resultado = $this->providers->make()->validarReceptor($taxId);
        } catch (Throwable $e) {
            Log::warning('ruc_lookup.fallo', ['ruc' => self::enmascarar($taxId), 'exception_class' => $e::class]);

            return $this->noDisponible(self::CODIGO_NO_DISPONIBLE);
        }

        if (! $resultado->disponible) {
            Log::info('ruc_lookup.no_disponible', ['ruc' => self::enmascarar($taxId)]);

            return $this->noDisponible(self::CODIGO_NO_DISPONIBLE);
        }

        Log::info('ruc_lookup', ['ruc' => self::enmascarar($taxId), 'valido' => $resultado->valido, 'estado' => $resultado->estado]);

        return [
            'disponible' => true,
            'valido'     => $resultado->valido,
            'nombre'     => $resultado->valido ? $resultado->nombre : null,
            'dv'         => $resultado->valido && trim((string) $resultado->dv) !== '' ? trim((string) $resultado->dv) : null,
            'tipo_ruc'   => $resultado->valido ? $resultado->tipo : null,
            'estado'     => $resultado->valido ? $resultado->estado : null,
            'codigo'     => $resultado->valido ? self::CODIGO_OK : self::CODIGO_NO_ENCONTRADO,
        ];
    }

    /** RUC sin datos completos en logs: primeros 2 y últimos 2 caracteres. */
    public static function enmascarar(string $taxId): string
    {
        $len = mb_strlen($taxId);

        return $len <= 4 ? str_repeat('*', $len) : mb_substr($taxId, 0, 2).str_repeat('*', $len - 4).mb_substr($taxId, -2);
    }

    /**
     * @return array{disponible: bool, valido: null, nombre: null, dv: null, tipo_ruc: null, estado: null, codigo: string}
     */
    private function noDisponible(string $codigo): array
    {
        return ['disponible' => false, 'valido' => null, 'nombre' => null, 'dv' => null, 'tipo_ruc' => null, 'estado' => null, 'codigo' => $codigo];
    }
}
