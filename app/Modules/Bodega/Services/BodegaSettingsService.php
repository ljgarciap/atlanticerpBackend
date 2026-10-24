<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Models\BodegaSetting;
use App\Shared\Auth\JwtActor;

/**
 * SCRUM-329 Oleada A / Batch A2 (REQ-310 RN1) — umbrales configurables del chip "🔥 Urgentes"
 * (ventana de días hasta la fecha de entrega comprometida). Regla global del proyecto: nunca
 * hardcodear un umbral de negocio como constante — se lee/siembra en `bodega_settings` con un
 * default razonable si falta la fila (comportamiento ya anticipado por el docblock de
 * `BodegaSetting`, este es el primer batch que la puebla de verdad).
 */
final class BodegaSettingsService
{
    private const KEY_URGENTE_WINDOW_DAYS = 'urgente_ventana_dias';

    private const DEFAULT_URGENTE_WINDOW_DAYS = 1; // REQ-310 RN1: "hoy o mañana" = ventana de 1 día.

    /**
     * SCRUM-369 (REQ-299) — "Pedido asignado sin iniciar picking hace más de N horas". Default
     * razonable acordado por el batch (Luis puede ajustarlo sin deploy, ver docblock de clase).
     */
    private const KEY_PENDIENTES_PICKING_HORAS = 'home_pendientes_picking_horas';

    private const DEFAULT_PENDIENTES_PICKING_HORAS = 3;

    /** SCRUM-369 (REQ-299) — "Guía de entrega sin subir hace más de N días" (pedido en Despachado). */
    private const KEY_PENDIENTES_GUIA_DIAS = 'home_pendientes_guia_dias';

    private const DEFAULT_PENDIENTES_GUIA_DIAS = 2;

    /** SCRUM-373 (REQ-303) — ventana de días del Kardex para "Mayor rotación". */
    private const KEY_ROTACION_VENTANA_DIAS = 'home_rotacion_ventana_dias';

    private const DEFAULT_ROTACION_VENTANA_DIAS = 30;

    /** SCRUM-373 (REQ-303) — cuántos productos entran en el top de "Mayor rotación". */
    private const KEY_ROTACION_TOP_N = 'home_rotacion_top_n';

    private const DEFAULT_ROTACION_TOP_N = 5;

    /**
     * SCRUM-871 Pre-QA (2026-09-13) — cuántos productos entran en el top de "Artículos críticos"
     * de Home. Antes `articulosCriticos()` devolvía TODOS los productos activos en estado
     * bajo_stock/sin_stock sin límite (11670 en dev), tardando 13-14s y transfiriendo 2.19MB solo
     * para alimentar una tarjeta que muestra 5 — mismo patrón que ya resuelve bien
     * `rotacionTopN()` arriba. El link "Ver los N artículos" sigue yendo a
     * `/bodega/inventario?filter=criticos` (paginado de verdad), nunca a un modal con el array
     * completo en memoria — por eso el default puede ser tan chico como el de rotación.
     */
    private const KEY_ARTICULOS_CRITICOS_TOP_N = 'home_articulos_criticos_top_n';

    private const DEFAULT_ARTICULOS_CRITICOS_TOP_N = 5;

    /**
     * SCRUM-493 (REQ-423 RN1) — umbral del aviso de concentración del reporte "Capacidad por
     * bodega": porcentaje del inventario total que una sola bodega debe concentrar para disparar
     * la alerta. "Umbral confirmado con el negocio" (75%) pero, como todo umbral de negocio de
     * este proyecto, nunca hardcodeado — mismo patrón KEY_ / DEFAULT_ que los 5 de arriba.
     */
    private const KEY_CAPACIDAD_CONCENTRACION_PCT = 'reports_capacidad_concentracion_pct';

    private const DEFAULT_CAPACIDAD_CONCENTRACION_PCT = 75;

    /**
     * REQ-635 (SCRUM-811) — umbrales de alertas de fraude en Bodega, `SecurityAnalysisJob`.
     * Ver `docs/security/THREAT-MODEL-bodega-admincont-fraude.md` para el razonamiento completo
     * de cada default (derivados del poco dato real disponible en producción al momento del
     * threat model, 2026-09-01 — conservadores a propósito, revisar con más volumen).
     */
    private const KEY_FRAUDE_AJUSTE_GRANDE_UNIDADES = 'fraude_ajuste_grande_unidades';

    private const DEFAULT_FRAUDE_AJUSTE_GRANDE_UNIDADES = 10;

    /**
     * Hallazgo 2 del threat model — ventana de "madrugada" es siempre [00:00, este valor), nunca
     * un rango de horario de oficina: los datos reales mostraron actividad legítima hasta las
     * 22:27 y en domingo, así que una ventana tipo "fuera de 8am-6pm" hubiera marcado 9 de los 11
     * eventos reales como sospechosos. Un solo límite (no dos) evita además el problema de
     * `intSetting()` con un default de 0 en el límite inferior (ver docblock de esa función).
     */
    private const KEY_FRAUDE_HORARIO_FIN_HORA = 'fraude_horario_fin_hora';

    private const DEFAULT_FRAUDE_HORARIO_FIN_HORA = 5;

    private const KEY_FRAUDE_VENTANA_CORRELACION_MINUTOS = 'fraude_ventana_correlacion_minutos';

    private const DEFAULT_FRAUDE_VENTANA_CORRELACION_MINUTOS = 60;

    private const KEY_FRAUDE_IP_LOOKBACK_DIAS = 'fraude_ip_lookback_dias';

    private const DEFAULT_FRAUDE_IP_LOOKBACK_DIAS = 90;

    /** Días hacia adelante desde hoy que cuentan como "urgente" (RN1: 1 = hoy o mañana). */
    public function urgenteWindowDays(): int
    {
        return $this->intSetting(self::KEY_URGENTE_WINDOW_DAYS, self::DEFAULT_URGENTE_WINDOW_DAYS);
    }

    /** SCRUM-369 (REQ-299) — umbral de horas en Asignado sin iniciar picking. */
    public function pendientesPickingHoras(): int
    {
        return $this->intSetting(self::KEY_PENDIENTES_PICKING_HORAS, self::DEFAULT_PENDIENTES_PICKING_HORAS);
    }

    /** SCRUM-369 (REQ-299) — umbral de días en Despachado sin guía de entrega subida. */
    public function pendientesGuiaDias(): int
    {
        return $this->intSetting(self::KEY_PENDIENTES_GUIA_DIAS, self::DEFAULT_PENDIENTES_GUIA_DIAS);
    }

    /** SCRUM-373 (REQ-303) — ventana de días del Kardex para "Mayor rotación". */
    public function rotacionVentanaDias(): int
    {
        return $this->intSetting(self::KEY_ROTACION_VENTANA_DIAS, self::DEFAULT_ROTACION_VENTANA_DIAS);
    }

    /** SCRUM-373 (REQ-303) — tamaño del top de "Mayor rotación". */
    public function rotacionTopN(): int
    {
        return $this->intSetting(self::KEY_ROTACION_TOP_N, self::DEFAULT_ROTACION_TOP_N);
    }

    /** SCRUM-871 Pre-QA — tamaño del top de "Artículos críticos" en Home. */
    public function articulosCriticosTopN(): int
    {
        return $this->intSetting(self::KEY_ARTICULOS_CRITICOS_TOP_N, self::DEFAULT_ARTICULOS_CRITICOS_TOP_N);
    }

    /** SCRUM-493 (REQ-423 RN1) — % de concentración que dispara el aviso de "Capacidad por bodega". */
    public function capacidadConcentracionPct(): int
    {
        return $this->intSetting(self::KEY_CAPACIDAD_CONCENTRACION_PCT, self::DEFAULT_CAPACIDAD_CONCENTRACION_PCT);
    }

    /** REQ-635 — unidades de una línea de ajuste a partir de las cuales se considera "grande". */
    public function fraudeAjusteGrandeUnidades(): int
    {
        return $this->intSetting(self::KEY_FRAUDE_AJUSTE_GRANDE_UNIDADES, self::DEFAULT_FRAUDE_AJUSTE_GRANDE_UNIDADES);
    }

    /** REQ-635 — la ventana de "madrugada" es siempre [00:00, este valor). */
    public function fraudeHorarioFinHora(): int
    {
        return $this->intSetting(self::KEY_FRAUDE_HORARIO_FIN_HORA, self::DEFAULT_FRAUDE_HORARIO_FIN_HORA);
    }

    /** REQ-635 — minutos de correlación entre un ajuste y una reubicación del mismo producto. */
    public function fraudeVentanaCorrelacionMinutos(): int
    {
        return $this->intSetting(self::KEY_FRAUDE_VENTANA_CORRELACION_MINUTOS, self::DEFAULT_FRAUDE_VENTANA_CORRELACION_MINUTOS);
    }

    /** REQ-635 — días hacia atrás para considerar "conocida" una IP de un usuario. */
    public function fraudeIpLookbackDias(): int
    {
        return $this->intSetting(self::KEY_FRAUDE_IP_LOOKBACK_DIAS, self::DEFAULT_FRAUDE_IP_LOOKBACK_DIAS);
    }

    /**
     * SCRUM-500/493/811 — los 10 valores configurables, con el nombre "de negocio" (API) mapeado
     * a su KEY_* interna. Usado por `BodegaSettingsController::show()`.
     *
     * @return array<string, int>
     */
    public function allSettings(): array
    {
        return [
            'urgente_ventana_dias'          => $this->urgenteWindowDays(),
            'pendientes_picking_horas'      => $this->pendientesPickingHoras(),
            'pendientes_guia_dias'          => $this->pendientesGuiaDias(),
            'rotacion_ventana_dias'         => $this->rotacionVentanaDias(),
            'rotacion_top_n'                => $this->rotacionTopN(),
            'articulos_criticos_top_n'      => $this->articulosCriticosTopN(),
            'capacidad_concentracion_pct'   => $this->capacidadConcentracionPct(),
            'fraude_ajuste_grande_unidades'      => $this->fraudeAjusteGrandeUnidades(),
            'fraude_horario_fin_hora'            => $this->fraudeHorarioFinHora(),
            'fraude_ventana_correlacion_minutos' => $this->fraudeVentanaCorrelacionMinutos(),
            'fraude_ip_lookback_dias'            => $this->fraudeIpLookbackDias(),
        ];
    }

    /**
     * SCRUM-500 — persiste solo los campos presentes en `$validated` (payload de
     * `BodegaSettingsRequest`, reglas `sometimes`). Nombre de API → KEY_* interna.
     *
     * @param array<string, int> $validated
     */
    public function updateSettings(array $validated): void
    {
        $map = [
            'urgente_ventana_dias'        => self::KEY_URGENTE_WINDOW_DAYS,
            'pendientes_picking_horas'    => self::KEY_PENDIENTES_PICKING_HORAS,
            'pendientes_guia_dias'        => self::KEY_PENDIENTES_GUIA_DIAS,
            'rotacion_ventana_dias'       => self::KEY_ROTACION_VENTANA_DIAS,
            'rotacion_top_n'              => self::KEY_ROTACION_TOP_N,
            'articulos_criticos_top_n'    => self::KEY_ARTICULOS_CRITICOS_TOP_N,
            'capacidad_concentracion_pct' => self::KEY_CAPACIDAD_CONCENTRACION_PCT,
            'fraude_ajuste_grande_unidades'      => self::KEY_FRAUDE_AJUSTE_GRANDE_UNIDADES,
            'fraude_horario_fin_hora'            => self::KEY_FRAUDE_HORARIO_FIN_HORA,
            'fraude_ventana_correlacion_minutos' => self::KEY_FRAUDE_VENTANA_CORRELACION_MINUTOS,
            'fraude_ip_lookback_dias'            => self::KEY_FRAUDE_IP_LOOKBACK_DIAS,
        ];

        foreach ($map as $apiField => $storageKey) {
            if (! array_key_exists($apiField, $validated)) {
                continue;
            }

            BodegaSetting::updateOrCreate(
                ['key' => $storageKey],
                ['value' => (string) $validated[$apiField]],
            );
        }
    }

    private function intSetting(string $key, int $default): int
    {
        $row = BodegaSetting::where('key', $key)->first();
        if ($row === null) {
            // SCRUM-1158 (Pre-QA) — «Ver como usuario» es de solo lectura: la fila de valor por defecto se crea de forma perezosa en la primera lectura; con `act` se
            // devuelve el mismo valor por defecto SIN persistirlo (lo creará la primera lectura real). Sin `act` queda idéntico.
            $row = JwtActor::currentRequestIsImpersonating()
                ? new BodegaSetting(['key' => $key, 'value' => (string) $default])
                : BodegaSetting::create(['key' => $key, 'value' => (string) $default]);
        }

        $value = (int) $row->value;

        return $value > 0 ? $value : $default;
    }
}
