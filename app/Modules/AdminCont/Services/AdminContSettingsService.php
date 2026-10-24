<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContSetting;
use App\Shared\Auth\JwtActor;

/**
 * REQ-635 (SCRUM-811) — umbrales configurables de alertas de fraude en Admin&Cont, consumidos por
 * `SecurityAnalysisJob`. Mismo patrón exacto que `BodegaSettingsService`. Ver
 * `docs/security/THREAT-MODEL-bodega-admincont-fraude.md` para el razonamiento de cada default —
 * a diferencia de Bodega, Admin&Cont no tenía NINGÚN dato histórico real en producción al momento
 * del threat model (2026-09-01), así que el umbral de monto (Hallazgo 5) es auto-calibrado por
 * desviación estándar en vez de una cifra fija en dólares que nadie podía justificar con datos.
 */
final class AdminContSettingsService
{
    private const KEY_FRAUDE_MONTO_DESVIACIONES = 'fraude_monto_desviaciones';

    private const DEFAULT_FRAUDE_MONTO_DESVIACIONES = 3;

    /** Fase 1 (aislamiento del núcleo): antes `PendingAlertsService::DIAS_UMBRAL_COMISION_PENDIENTE = 10` fijo en código. */
    private const KEY_COMISION_PENDIENTE_DIAS = 'comision_pendiente_dias';

    private const DEFAULT_COMISION_PENDIENTE_DIAS = 10;

    private const KEY_FRAUDE_MONTO_VENTANA_DIAS = 'fraude_monto_ventana_dias';

    private const DEFAULT_FRAUDE_MONTO_VENTANA_DIAS = 90;

    private const KEY_FRAUDE_APROBACIONES_REPETIDAS_N = 'fraude_aprobaciones_repetidas_n';

    private const DEFAULT_FRAUDE_APROBACIONES_REPETIDAS_N = 5;

    private const KEY_FRAUDE_APROBACIONES_REPETIDAS_MINUTOS = 'fraude_aprobaciones_repetidas_minutos';

    private const DEFAULT_FRAUDE_APROBACIONES_REPETIDAS_MINUTOS = 10;

    /** REQ-635 (Hallazgo 5) — cuántas desviaciones estándar sobre la media móvil disparan la alerta. */
    public function fraudeMontoDesviaciones(): int
    {
        return $this->intSetting(self::KEY_FRAUDE_MONTO_DESVIACIONES, self::DEFAULT_FRAUDE_MONTO_DESVIACIONES);
    }

    /** Días de antigüedad (estrictamente mayores) a partir de los cuales una comisión pendiente genera alerta en Inicio. */
    public function comisionPendienteDias(): int
    {
        return $this->intSetting(self::KEY_COMISION_PENDIENTE_DIAS, self::DEFAULT_COMISION_PENDIENTE_DIAS);
    }

    /** REQ-635 (Hallazgo 5) — ventana (días) de la media móvil usada para calibrar el umbral de monto. */
    public function fraudeMontoVentanaDias(): int
    {
        return $this->intSetting(self::KEY_FRAUDE_MONTO_VENTANA_DIAS, self::DEFAULT_FRAUDE_MONTO_VENTANA_DIAS);
    }

    /** REQ-635 (Hallazgo 6) — cantidad de aprobaciones del mismo actor que dispara la alerta. */
    public function fraudeAprobacionesRepetidasN(): int
    {
        return $this->intSetting(self::KEY_FRAUDE_APROBACIONES_REPETIDAS_N, self::DEFAULT_FRAUDE_APROBACIONES_REPETIDAS_N);
    }

    /** REQ-635 (Hallazgo 6) — ventana en minutos para contar aprobaciones repetidas. */
    public function fraudeAprobacionesRepetidasMinutos(): int
    {
        return $this->intSetting(self::KEY_FRAUDE_APROBACIONES_REPETIDAS_MINUTOS, self::DEFAULT_FRAUDE_APROBACIONES_REPETIDAS_MINUTOS);
    }

    /**
     * Usado por `AdminContSettingsController::show()`.
     *
     * @return array<string, int>
     */
    public function allSettings(): array
    {
        return [
            'fraude_monto_desviaciones'             => $this->fraudeMontoDesviaciones(),
            'fraude_monto_ventana_dias'              => $this->fraudeMontoVentanaDias(),
            'fraude_aprobaciones_repetidas_n'        => $this->fraudeAprobacionesRepetidasN(),
            'fraude_aprobaciones_repetidas_minutos'  => $this->fraudeAprobacionesRepetidasMinutos(),
            'comision_pendiente_dias'                => $this->comisionPendienteDias(),
        ];
    }

    /**
     * Persiste solo los campos presentes en `$validated` (payload de
     * `AdminContSettingsRequest`, reglas `sometimes`). Nombre de API → KEY_* interna.
     *
     * @param array<string, int> $validated
     */
    public function updateSettings(array $validated): void
    {
        $map = [
            'fraude_monto_desviaciones'            => self::KEY_FRAUDE_MONTO_DESVIACIONES,
            'fraude_monto_ventana_dias'             => self::KEY_FRAUDE_MONTO_VENTANA_DIAS,
            'fraude_aprobaciones_repetidas_n'       => self::KEY_FRAUDE_APROBACIONES_REPETIDAS_N,
            'fraude_aprobaciones_repetidas_minutos' => self::KEY_FRAUDE_APROBACIONES_REPETIDAS_MINUTOS,
            'comision_pendiente_dias'               => self::KEY_COMISION_PENDIENTE_DIAS,
        ];

        foreach ($map as $apiField => $storageKey) {
            if (! array_key_exists($apiField, $validated)) {
                continue;
            }

            AdminContSetting::updateOrCreate(
                ['key' => $storageKey],
                ['value' => (string) $validated[$apiField]],
            );
        }
    }

    private function intSetting(string $key, int $default): int
    {
        $row = AdminContSetting::where('key', $key)->first();
        if ($row === null) {
            // SCRUM-1158 (Pre-QA) — «Ver como usuario» es de solo lectura: la fila de valor por defecto se crea de forma perezosa en la primera lectura; con `act` se
            // devuelve el mismo valor por defecto SIN persistirlo (lo creará la primera lectura real). Sin `act` queda idéntico.
            $row = JwtActor::currentRequestIsImpersonating()
                ? new AdminContSetting(['key' => $key, 'value' => (string) $default])
                : AdminContSetting::create(['key' => $key, 'value' => (string) $default]);
        }

        $value = (int) $row->value;

        return $value > 0 ? $value : $default;
    }
}
