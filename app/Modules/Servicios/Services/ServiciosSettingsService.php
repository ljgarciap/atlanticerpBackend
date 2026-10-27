<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\AdminCont\Services\ItbmsRateResolver;
use App\Modules\Servicios\Models\ServiciosSetting;
use App\Modules\Servicios\Models\Ticket;
use App\Shared\Auth\JwtActor;

/**
 * Batch 2 (REQ-222 RN4) — umbral configurable de "días sin agendar" para la tarjeta de
 * estadísticas "Sin agendar" (`TicketService::stats()`). Debe ser el MISMO valor que consumirá el
 * panel Pendientes de Inicio (REQ-210) cuando se construya (Batch 15/16) — se define acá primero
 * para que esa pantalla futura solo tenga que leer esta misma key, no inventar la suya.
 *
 * Batch 4 (REQ-248 RN2) — límites de adjuntos por ticket. El propio criterio de aceptación dice
 * "valores estándar razonables... Tecnología puede ajustar estos números durante el desarrollo si
 * el cliente indica otra preferencia, sin que sea un cambio estructural" — mismo criterio que el
 * resto de umbrales de este servicio (nunca hardcodeados como constante de código, CLAUDE.md §
 * "Nunca").
 *
 * Batch 10 (REQ-258, decisión de Luis 2026-08-11) — `all()`/`update()`/`schema()` agregan un CRUD
 * genérico sobre esta misma tabla `servicios_settings`, consumido por la pantalla de admin
 * "Ajustes de Servicios" (`ServiciosSettingsController`). Con esto TODAS las keys de este servicio
 * (no solo SLA) tienen por fin una vista de administración real — antes existían como default de
 * solo lectura pese al docblock de arriba. Agregar una key nueva a este archivo ahora solo
 * requiere sumarla también en `schema()` para que aparezca en la pantalla, nada más.
 */
final class ServiciosSettingsService
{
    private const KEY_SIN_AGENDAR_UMBRAL_DIAS = 'sin_agendar_umbral_dias';

    private const DEFAULT_SIN_AGENDAR_UMBRAL_DIAS = 3;

    private const KEY_ADJUNTOS_MAX_ARCHIVOS = 'adjuntos_max_archivos';

    private const DEFAULT_ADJUNTOS_MAX_ARCHIVOS = 10;

    private const KEY_ADJUNTOS_MAX_SIZE_MB = 'adjuntos_max_size_mb';

    private const DEFAULT_ADJUNTOS_MAX_SIZE_MB = 15;

    // Grupo C — Home (REQ-212 RN1, SCRUM-275) — umbral configurable de "días sin responder" del
    // panel "Servicios sin responder" de Inicio. Mismo patrón que KEY_SIN_AGENDAR_UMBRAL_DIAS,
    // pero deliberadamente una key separada: el panel de REQ-212 es más angosto (solo
    // `estado = reported`, ni agendado ni ningún avance) que "Sin agendar" (cualquier estado
    // no cerrado/cancelado sin `scheduled_at`) — no son el mismo concepto de negocio pese al
    // nombre parecido, no deben compartir umbral.
    private const KEY_SIN_RESPONDER_UMBRAL_DIAS = 'sin_responder_umbral_dias';

    private const DEFAULT_SIN_RESPONDER_UMBRAL_DIAS = 5;

    // Batch 7 (REQ-292 RN2) — SLA por tipo de ticket, en días hábiles. "valores propuestos como
    // punto de partida razonable, ajustables por Gerencia sin intervención de Tecnología" — nunca
    // hardcodeados en el cálculo de Cumplimiento de tiempos (REQ-258 RN3), solo acá como default.
    private const KEY_SLA_DIAS_CLAIM = 'sla_dias_claim';

    private const DEFAULT_SLA_DIAS_CLAIM = 2;

    private const KEY_SLA_DIAS_WARRANTY = 'sla_dias_warranty';

    private const DEFAULT_SLA_DIAS_WARRANTY = 3;

    private const KEY_SLA_DIAS_INSTALLATION = 'sla_dias_installation';

    private const DEFAULT_SLA_DIAS_INSTALLATION = 7;

    private const KEY_SLA_DIAS_RETROFIT = 'sla_dias_retrofit';

    private const DEFAULT_SLA_DIAS_RETROFIT = 10;

    // Batch 10 (REQ-258 RN1/RN6) — pesos y montos máximos de los 5 criterios del "Plan de
    // Bonificación por Desempeño" real (documento RRHH, Plan_Bonificacion_Carlos_Vergara.docx,
    // adjunto a SCRUM-321). Configurable a pedido explícito de Luis (2026-08-11) — ya existía el
    // mismo gap en los umbrales de conversión de Batch 7 (ver commissionUmbrales* abajo), corregido
    // en el mismo batch para no dejar dos capas de la misma regla ("Nunca" del CLAUDE.md raíz) con
    // tratamiento distinto.
    public const CRITERIOS = ['calidad', 'satisfaccion', 'sla', 'puntualidad', 'actitud'];

    private const COMMISSION_DEFAULT_PESO_PCT = [
        'calidad'      => 25,
        'satisfaccion' => 25,
        'sla'          => 25,
        'puntualidad'  => 15,
        'actitud'      => 10,
    ];

    private const COMMISSION_DEFAULT_MONTO_MAX = [
        'calidad'      => 100,
        'satisfaccion' => 100,
        'sla'          => 100,
        'puntualidad'  => 60,
        'actitud'      => 40,
    ];

    // Umbrales de conversión — migrados desde constantes hardcodeadas en
    // CommissionCalculationService (ahí desde Batch 7, sin flag de configurable en ese momento).
    private const KEY_COMMISSION_SATISFACCION_UMBRAL_100 = 'commission_satisfaccion_umbral_100';

    private const DEFAULT_COMMISSION_SATISFACCION_UMBRAL_100 = 4.5;

    private const KEY_COMMISSION_SATISFACCION_UMBRAL_60 = 'commission_satisfaccion_umbral_60';

    private const DEFAULT_COMMISSION_SATISFACCION_UMBRAL_60 = 3.5;

    private const KEY_COMMISSION_SLA_UMBRAL_100_PCT = 'commission_sla_umbral_100_pct';

    private const DEFAULT_COMMISSION_SLA_UMBRAL_100_PCT = 95;

    private const KEY_COMMISSION_SLA_UMBRAL_60_PCT = 'commission_sla_umbral_60_pct';

    private const DEFAULT_COMMISSION_SLA_UMBRAL_60_PCT = 85;

    private const KEY_COMMISSION_PUNTUALIDAD_MAX_INCIDENCIAS_50PCT = 'commission_puntualidad_max_incidencias_50pct';

    private const DEFAULT_COMMISSION_PUNTUALIDAD_MAX_INCIDENCIAS_50PCT = 1;

    private const KEY_COMMISSION_ACTITUD_ESCALA_MAX = 'commission_actitud_escala_max';

    private const DEFAULT_COMMISSION_ACTITUD_ESCALA_MAX = 5;

    // Batch 11 (REQ-233) — tasa de ITBMS aplicada a Cotización de Servicio. Tasa legal vigente en
    // Panamá (7%) como default, pero igual pasa por este servicio en vez de vivir como literal en
    // ServiceQuote::computeTotals() — mismo criterio que el resto de porcentajes de este módulo
    // (CLAUDE.md § "Nunca"): si el legislador la cambia, Gerencia la ajusta en "Ajustes de
    // Servicios" sin depender de un deploy.
    private const KEY_ITBMS_PERCENT = 'itbms_percent';

    /**
     * Fase 7a — el valor por omisión ya no es un 7 fijo (tasa de Panamá): es la tasa por defecto de Configuración Fiscal
     * (`itbms_rates`) del país de la empresa. El ajuste sigue permitiendo una tasa propia para las cotizaciones de Servicios.
     */
    private function defaultItbmsPercent(): int
    {
        return (int) round(ItbmsRateResolver::defaultPercentage());
    }

    public function itbmsPercent(): int
    {
        return $this->intSetting(self::KEY_ITBMS_PERCENT, $this->defaultItbmsPercent());
    }

    // Batch 12 (REQ-237, SCRUM-300) — condiciones del servicio, texto libre configurado por
    // Gerencia. Primera key `type => 'text'` de este servicio — hasta acá `schema()`/`all()`/
    // `update()` solo sabían de `int`/`float` (ver docblocks de esos 3 métodos, extendidos para
    // soportar `text` sin romper las keys numéricas existentes). Valor semilla EXACTO pedido por
    // Luis (SCRUM-300) — nunca se hardcodea como fallback disperso una vez que existe este
    // parámetro (CLAUDE.md § "Nunca"): `ServiceQuoteService::send()` es el único punto que lo
    // congela dentro de una cotización.
    private const KEY_CONDICIONES_COTIZACION_SERVICIOS = 'condiciones_cotizacion_servicios';

    // SCRUM-300 (rebote QA 2026-08-13) — el valor sembrado originalmente era el texto de EJEMPLO
    // del mockup (5A__Servicios_Tickets.html), no el texto real pactado con el cliente que el
    // propio RN3 del ticket define literal. Toda cotización enviada mientras este valor estuvo mal
    // salió con forma de pago/validez/garantía distintas a las pactadas (50/50 vs 100% anticipado,
    // 15 vs 10 días, 90 vs 30 días) — ver migración de datos
    // 2026_08_19_100000_fix_servicios_condiciones_cotizacion_seed.php para el fix del valor YA
    // persistido en cada entorno (esta constante sola no alcanza: `textSetting()` graba el
    // default en la tabla la primera vez que se lee, no es un fallback sin estado).
    private const DEFAULT_CONDICIONES_COTIZACION_SERVICIOS =
        "Cotización válida por 10 días a partir de la fecha de emisión.\n"
        ."Forma de pago: 100% de pago completo antes de generar la instalación.\n"
        ."Garantía de mano de obra e instalación: 30 días desde la fecha de entrega.\n"
        ."Los productos suministrados mantienen la garantía de fábrica correspondiente (no la garantía del servicio).\n"
        ."Precios sujetos a cambio sin previo aviso una vez vencida la fecha de validez de la cotización.";

    /** REQ-237 — texto vigente de Condiciones (editable por superadmin/management, visible para todo el módulo). */
    public function conditionsCotizacionServicios(): string
    {
        return $this->textSetting(self::KEY_CONDICIONES_COTIZACION_SERVICIOS, self::DEFAULT_CONDICIONES_COTIZACION_SERVICIOS);
    }

    // Batch 15 (REQ-211 RN2a/RN2b) — meta de "instalaciones completadas este mes" (panel
    // Indicadores del mes de Inicio). RN2b es explícita: ni la ventana de historial ni el % de
    // crecimiento pueden quedar fijos en el código. El override MANUAL por mes puntual (RN2a-c,
    // "Gerencia puede sobrescribir sin romper el recálculo de meses futuros") no vive acá como una
    // constante — es una key dinámica por (año, mes), ver installationGoalOverride()/
    // setInstallationGoalOverride() abajo, fuera del catálogo genérico de schema()/all() porque no
    // tiene un único valor fijo que administrar desde la pantalla de Ajustes.
    private const KEY_INSTALLATION_GOAL_MANUAL_DEFAULT = 'installation_goal_manual_default';

    private const DEFAULT_INSTALLATION_GOAL_MANUAL_DEFAULT = 36;

    private const KEY_INSTALLATION_GOAL_HISTORY_MONTHS = 'installation_goal_history_months';

    private const DEFAULT_INSTALLATION_GOAL_HISTORY_MONTHS = 3;

    private const KEY_INSTALLATION_GOAL_GROWTH_PCT = 'installation_goal_growth_pct';

    private const DEFAULT_INSTALLATION_GOAL_GROWTH_PCT = 10;

    private const INSTALLATION_GOAL_OVERRIDE_KEY_PREFIX = 'installation_goal_override_';

    public function sinAgendarUmbralDias(): int
    {
        return $this->intSetting(self::KEY_SIN_AGENDAR_UMBRAL_DIAS, self::DEFAULT_SIN_AGENDAR_UMBRAL_DIAS);
    }

    public function adjuntosMaxArchivos(): int
    {
        return $this->intSetting(self::KEY_ADJUNTOS_MAX_ARCHIVOS, self::DEFAULT_ADJUNTOS_MAX_ARCHIVOS);
    }

    public function adjuntosMaxSizeMb(): int
    {
        return $this->intSetting(self::KEY_ADJUNTOS_MAX_SIZE_MB, self::DEFAULT_ADJUNTOS_MAX_SIZE_MB);
    }

    /** REQ-212 RN1 — umbral del panel "Servicios sin responder" de Inicio. */
    public function sinResponderUmbralDias(): int
    {
        return $this->intSetting(self::KEY_SIN_RESPONDER_UMBRAL_DIAS, self::DEFAULT_SIN_RESPONDER_UMBRAL_DIAS);
    }

    /**
     * REQ-292 RN2 — SLA (días hábiles) por tipo de ticket, para el mapa que consume la UI de ajuste.
     *
     * @return array<string, int>
     */
    public function slaSettings(): array
    {
        return [
            Ticket::TIPO_CLAIM        => $this->slaDiasByTipo(Ticket::TIPO_CLAIM),
            Ticket::TIPO_WARRANTY     => $this->slaDiasByTipo(Ticket::TIPO_WARRANTY),
            Ticket::TIPO_INSTALLATION => $this->slaDiasByTipo(Ticket::TIPO_INSTALLATION),
            Ticket::TIPO_RETROFIT     => $this->slaDiasByTipo(Ticket::TIPO_RETROFIT),
        ];
    }

    /** REQ-258 RN3 — SLA vigente para un tipo de ticket puntual (consumido por el cálculo de Cumplimiento de tiempos). */
    public function slaDiasByTipo(string $tipo): int
    {
        return match ($tipo) {
            Ticket::TIPO_CLAIM        => $this->intSetting(self::KEY_SLA_DIAS_CLAIM, self::DEFAULT_SLA_DIAS_CLAIM),
            Ticket::TIPO_WARRANTY     => $this->intSetting(self::KEY_SLA_DIAS_WARRANTY, self::DEFAULT_SLA_DIAS_WARRANTY),
            Ticket::TIPO_INSTALLATION => $this->intSetting(self::KEY_SLA_DIAS_INSTALLATION, self::DEFAULT_SLA_DIAS_INSTALLATION),
            Ticket::TIPO_RETROFIT     => $this->intSetting(self::KEY_SLA_DIAS_RETROFIT, self::DEFAULT_SLA_DIAS_RETROFIT),
            default                   => self::DEFAULT_SLA_DIAS_INSTALLATION,
        };
    }

    /**
     * REQ-292 RN2 — actualización de Gerencia. Solo pisa las claves presentes en `$data` (permite
     * ajustar un solo tipo sin reenviar los otros 3).
     *
     * @param array<string, int> $data claves: Ticket::TIPO_CLAIM|TIPO_WARRANTY|TIPO_INSTALLATION|TIPO_RETROFIT
     */
    public function updateSlaSettings(array $data): void
    {
        $map = [
            Ticket::TIPO_CLAIM        => self::KEY_SLA_DIAS_CLAIM,
            Ticket::TIPO_WARRANTY     => self::KEY_SLA_DIAS_WARRANTY,
            Ticket::TIPO_INSTALLATION => self::KEY_SLA_DIAS_INSTALLATION,
            Ticket::TIPO_RETROFIT     => self::KEY_SLA_DIAS_RETROFIT,
        ];

        foreach ($map as $tipo => $key) {
            if (! array_key_exists($tipo, $data)) {
                continue;
            }

            ServiciosSetting::updateOrCreate(['key' => $key], ['value' => (string) $data[$tipo]]);
        }
    }

    /** REQ-258 RN1 — peso configurado de un criterio (`ServiciosSettingsService::CRITERIOS`). */
    public function commissionPesoPct(string $criterio): int
    {
        return $this->intSetting("commission_peso_{$criterio}_pct", self::COMMISSION_DEFAULT_PESO_PCT[$criterio] ?? 0);
    }

    /** REQ-258 RN1 — monto máximo configurado de un criterio. */
    public function commissionMontoMax(string $criterio): int
    {
        return $this->intSetting("commission_monto_max_{$criterio}", self::COMMISSION_DEFAULT_MONTO_MAX[$criterio] ?? 0);
    }

    /** REQ-258 RN6 — tope mensual, siempre la suma de los 5 montos máximos configurados (nunca un valor separado que pueda divergir). */
    public function commissionTopeMensual(): int
    {
        return array_sum(array_map(fn (string $criterio): int => $this->commissionMontoMax($criterio), self::CRITERIOS));
    }

    /** @return array{100: float, 60: float} REQ-292 RN3 — umbrales de conversión de Satisfacción. */
    public function commissionSatisfaccionUmbrales(): array
    {
        return [
            100 => $this->floatSetting(self::KEY_COMMISSION_SATISFACCION_UMBRAL_100, self::DEFAULT_COMMISSION_SATISFACCION_UMBRAL_100),
            60  => $this->floatSetting(self::KEY_COMMISSION_SATISFACCION_UMBRAL_60, self::DEFAULT_COMMISSION_SATISFACCION_UMBRAL_60),
        ];
    }

    /** @return array{100: int, 60: int} REQ-258 RN3 — umbrales de conversión de Cumplimiento de tiempos/SLA. */
    public function commissionSlaUmbrales(): array
    {
        return [
            100 => $this->intSetting(self::KEY_COMMISSION_SLA_UMBRAL_100_PCT, self::DEFAULT_COMMISSION_SLA_UMBRAL_100_PCT),
            60  => $this->intSetting(self::KEY_COMMISSION_SLA_UMBRAL_60_PCT, self::DEFAULT_COMMISSION_SLA_UMBRAL_60_PCT),
        ];
    }

    /** REQ-292 RN3 — máximo de incidencias que todavía califica 50% en Puntualidad (0 = 100%, > este valor = 0%). */
    public function commissionPuntualidadMaxIncidencias50Pct(): int
    {
        return $this->intSetting(self::KEY_COMMISSION_PUNTUALIDAD_MAX_INCIDENCIAS_50PCT, self::DEFAULT_COMMISSION_PUNTUALIDAD_MAX_INCIDENCIAS_50PCT);
    }

    /** REQ-292 RN3 — escala máxima de la calificación de Actitud (divisor de la fórmula lineal). */
    public function commissionActitudEscalaMax(): int
    {
        return $this->intSetting(self::KEY_COMMISSION_ACTITUD_ESCALA_MAX, self::DEFAULT_COMMISSION_ACTITUD_ESCALA_MAX);
    }

    /** REQ-211 RN2a — punto de partida antes de tener 3+ meses de historial real. */
    public function installationGoalManualDefault(): int
    {
        return $this->intSetting(self::KEY_INSTALLATION_GOAL_MANUAL_DEFAULT, self::DEFAULT_INSTALLATION_GOAL_MANUAL_DEFAULT);
    }

    /** REQ-211 RN2b — ventana de meses de historial usada por el cálculo automático, nunca fija en el código. */
    public function installationGoalHistoryMonths(): int
    {
        return $this->intSetting(self::KEY_INSTALLATION_GOAL_HISTORY_MONTHS, self::DEFAULT_INSTALLATION_GOAL_HISTORY_MONTHS);
    }

    /** REQ-211 RN2b — % de crecimiento aplicado sobre el promedio de historial, nunca fijo en el código. */
    public function installationGoalGrowthPct(): int
    {
        return $this->intSetting(self::KEY_INSTALLATION_GOAL_GROWTH_PCT, self::DEFAULT_INSTALLATION_GOAL_GROWTH_PCT);
    }

    /**
     * REQ-211 RN2a(c) — override manual de Gerencia para UN mes puntual (ej. temporada baja
     * conocida). `null` = sin override, el mes se resuelve por el cálculo automático o el default
     * manual (ver InstallationGoalService::goalForMonth()). A propósito NO usa intSetting() (que
     * auto-crea la fila con el default) — la ausencia de fila acá es una señal real ("nadie
     * sobrescribió este mes"), no un valor de 0 disfrazado.
     */
    public function installationGoalOverride(int $year, int $month): ?int
    {
        $row = ServiciosSetting::where('key', $this->installationGoalOverrideKey($year, $month))->first();

        return $row !== null ? (int) $row->value : null;
    }

    /** REQ-211 RN2a(c) — Gerencia sobrescribe la meta de un mes puntual sin afectar otros meses. */
    public function setInstallationGoalOverride(int $year, int $month, int $value): void
    {
        ServiciosSetting::updateOrCreate(
            ['key' => $this->installationGoalOverrideKey($year, $month)],
            ['value' => (string) $value],
        );
    }

    private function installationGoalOverrideKey(int $year, int $month): string
    {
        return self::INSTALLATION_GOAL_OVERRIDE_KEY_PREFIX."{$year}_{$month}";
    }

    /**
     * Pantalla "Ajustes de Servicios" (superadmin/lider_servicios) — catálogo completo de keys
     * configurables de este servicio, agrupadas para el CRUD genérico. Único punto de verdad de
     * qué keys existen: agregar una key nueva acá es lo único que hace falta para que aparezca en
     * la pantalla de admin.
     *
     * @return list<array{key: string, group: string, label: string, type: string, value: int|float|string}>
     */
    public function all(): array
    {
        $schema = $this->schema();

        return array_map(fn (array $entry): array => [
            ...$entry,
            'value' => match ($entry['type']) {
                'text'  => $this->textSetting($entry['key'], (string) $entry['default']),
                'float' => $this->floatSetting($entry['key'], (float) $entry['default']),
                default => $this->intSetting($entry['key'], (int) $entry['default']),
            },
        ], $schema);
    }

    /** Tipo declarado de una key en `schema()`, o `null` si no existe — usado por el controller para saber qué keys eximir de la validación numérica genérica (ver `ServiciosSettingsController::update()`). */
    public function fieldType(string $key): ?string
    {
        $byKey = array_column($this->schema(), null, 'key');

        return $byKey[$key]['type'] ?? null;
    }

    /**
     * Escritura genérica del CRUD — solo pisa las keys presentes en `$data` y conocidas por
     * `schema()`; una key desconocida se ignora (defensivo, el frontend siempre manda keys reales
     * del catálogo de `all()`).
     *
     * @param array<string, int|float|string> $data
     */
    public function update(array $data): void
    {
        $byKey = array_column($this->schema(), null, 'key');

        foreach ($data as $key => $value) {
            $entry = $byKey[$key] ?? null;
            if ($entry === null) {
                continue;
            }

            $normalized = match ($entry['type']) {
                'text'  => (string) $value,
                'float' => (string) (float) $value,
                default => (string) (int) $value,
            };

            ServiciosSetting::updateOrCreate(['key' => $key], ['value' => $normalized]);
        }
    }

    /** @return list<array{key: string, group: string, label: string, type: string, default: int|float|string}> */
    private function schema(): array
    {
        $entries = [
            ['key' => self::KEY_SIN_AGENDAR_UMBRAL_DIAS, 'group' => 'operacion', 'label' => 'Días sin agendar (umbral tarjeta "Sin agendar")', 'type' => 'int', 'default' => self::DEFAULT_SIN_AGENDAR_UMBRAL_DIAS],
            ['key' => self::KEY_ADJUNTOS_MAX_ARCHIVOS, 'group' => 'operacion', 'label' => 'Máximo de adjuntos por ticket', 'type' => 'int', 'default' => self::DEFAULT_ADJUNTOS_MAX_ARCHIVOS],
            ['key' => self::KEY_ADJUNTOS_MAX_SIZE_MB, 'group' => 'operacion', 'label' => 'Tamaño máximo por adjunto (MB)', 'type' => 'int', 'default' => self::DEFAULT_ADJUNTOS_MAX_SIZE_MB],
            ['key' => self::KEY_SIN_RESPONDER_UMBRAL_DIAS, 'group' => 'operacion', 'label' => 'Inicio — Días sin responder (umbral panel "Servicios sin responder")', 'type' => 'int', 'default' => self::DEFAULT_SIN_RESPONDER_UMBRAL_DIAS],
            ['key' => self::KEY_ITBMS_PERCENT, 'group' => 'cotizacion', 'label' => 'Cotización — '.\App\Shared\Multitenancy\TenantRegion::taxLabel().' (%)', 'type' => 'int', 'default' => $this->defaultItbmsPercent()],
            ['key' => self::KEY_CONDICIONES_COTIZACION_SERVICIOS, 'group' => 'cotizacion', 'label' => 'Cotización — Condiciones del servicio (texto)', 'type' => 'text', 'default' => self::DEFAULT_CONDICIONES_COTIZACION_SERVICIOS],
            ['key' => self::KEY_SLA_DIAS_CLAIM, 'group' => 'sla', 'label' => 'SLA — Reclamos (días hábiles)', 'type' => 'int', 'default' => self::DEFAULT_SLA_DIAS_CLAIM],
            ['key' => self::KEY_SLA_DIAS_WARRANTY, 'group' => 'sla', 'label' => 'SLA — Garantías (días hábiles)', 'type' => 'int', 'default' => self::DEFAULT_SLA_DIAS_WARRANTY],
            ['key' => self::KEY_SLA_DIAS_INSTALLATION, 'group' => 'sla', 'label' => 'SLA — Instalación (días hábiles)', 'type' => 'int', 'default' => self::DEFAULT_SLA_DIAS_INSTALLATION],
            ['key' => self::KEY_SLA_DIAS_RETROFIT, 'group' => 'sla', 'label' => 'SLA — Retrofit (días hábiles)', 'type' => 'int', 'default' => self::DEFAULT_SLA_DIAS_RETROFIT],
            ['key' => self::KEY_COMMISSION_SATISFACCION_UMBRAL_100, 'group' => 'comision', 'label' => 'Comisión — Satisfacción, umbral 100% (promedio ≥)', 'type' => 'float', 'default' => self::DEFAULT_COMMISSION_SATISFACCION_UMBRAL_100],
            ['key' => self::KEY_COMMISSION_SATISFACCION_UMBRAL_60, 'group' => 'comision', 'label' => 'Comisión — Satisfacción, umbral 60% (promedio ≥)', 'type' => 'float', 'default' => self::DEFAULT_COMMISSION_SATISFACCION_UMBRAL_60],
            ['key' => self::KEY_COMMISSION_SLA_UMBRAL_100_PCT, 'group' => 'comision', 'label' => 'Comisión — Cumplimiento SLA, umbral 100% (% cumplimiento ≥)', 'type' => 'int', 'default' => self::DEFAULT_COMMISSION_SLA_UMBRAL_100_PCT],
            ['key' => self::KEY_COMMISSION_SLA_UMBRAL_60_PCT, 'group' => 'comision', 'label' => 'Comisión — Cumplimiento SLA, umbral 60% (% cumplimiento ≥)', 'type' => 'int', 'default' => self::DEFAULT_COMMISSION_SLA_UMBRAL_60_PCT],
            ['key' => self::KEY_COMMISSION_PUNTUALIDAD_MAX_INCIDENCIAS_50PCT, 'group' => 'comision', 'label' => 'Comisión — Puntualidad, máx. incidencias para 50%', 'type' => 'int', 'default' => self::DEFAULT_COMMISSION_PUNTUALIDAD_MAX_INCIDENCIAS_50PCT],
            ['key' => self::KEY_COMMISSION_ACTITUD_ESCALA_MAX, 'group' => 'comision', 'label' => 'Comisión — Actitud, escala máxima de calificación', 'type' => 'int', 'default' => self::DEFAULT_COMMISSION_ACTITUD_ESCALA_MAX],
            ['key' => self::KEY_INSTALLATION_GOAL_MANUAL_DEFAULT, 'group' => 'operacion', 'label' => 'Inicio — Meta de instalaciones (valor manual, < 3 meses de historial)', 'type' => 'int', 'default' => self::DEFAULT_INSTALLATION_GOAL_MANUAL_DEFAULT],
            ['key' => self::KEY_INSTALLATION_GOAL_HISTORY_MONTHS, 'group' => 'operacion', 'label' => 'Inicio — Meta de instalaciones, ventana de historial (meses)', 'type' => 'int', 'default' => self::DEFAULT_INSTALLATION_GOAL_HISTORY_MONTHS],
            ['key' => self::KEY_INSTALLATION_GOAL_GROWTH_PCT, 'group' => 'operacion', 'label' => 'Inicio — Meta de instalaciones, % de crecimiento', 'type' => 'int', 'default' => self::DEFAULT_INSTALLATION_GOAL_GROWTH_PCT],
        ];

        foreach (self::CRITERIOS as $criterio) {
            $entries[] = ['key' => "commission_peso_{$criterio}_pct", 'group' => 'comision', 'label' => "Comisión — Peso ({$criterio}) %", 'type' => 'int', 'default' => self::COMMISSION_DEFAULT_PESO_PCT[$criterio]];
            $entries[] = ['key' => "commission_monto_max_{$criterio}", 'group' => 'comision', 'label' => "Comisión — Monto máx. ({$criterio}) $", 'type' => 'int', 'default' => self::COMMISSION_DEFAULT_MONTO_MAX[$criterio]];
        }

        return $entries;
    }

    private function intSetting(string $key, int $default): int
    {
        $row = ServiciosSetting::where('key', $key)->first();
        if ($row === null) {
            // SCRUM-1158 (Pre-QA) — «Ver como usuario» es de solo lectura: la fila de valor por defecto se crea de forma perezosa en la primera lectura; con `act` se
            // devuelve el mismo valor por defecto SIN persistirlo (lo creará la primera lectura real). Sin `act` queda idéntico.
            $row = JwtActor::currentRequestIsImpersonating()
                ? new ServiciosSetting(['key' => $key, 'value' => (string) $default])
                : ServiciosSetting::create(['key' => $key, 'value' => (string) $default]);
        }

        $value = (int) $row->value;

        return $value > 0 ? $value : $default;
    }

    private function floatSetting(string $key, float $default): float
    {
        $row = ServiciosSetting::where('key', $key)->first();
        if ($row === null) {
            $row = JwtActor::currentRequestIsImpersonating()
                ? new ServiciosSetting(['key' => $key, 'value' => (string) $default])
                : ServiciosSetting::create(['key' => $key, 'value' => (string) $default]);
        }

        $value = (float) $row->value;

        return $value > 0 ? $value : $default;
    }

    /**
     * A diferencia de `intSetting()`/`floatSetting()`, NO cae al default cuando el valor guardado
     * es "falsy" (`''` pasaría `> 0` en esos dos, pero acá una cadena vacía es un valor real que
     * Gerencia pudo haber guardado a propósito, ej. limpiar las Condiciones — no una señal de "fila
     * ausente"). Solo la ausencia real de la fila dispara el default (y la crea, mismo criterio que
     * los otros dos).
     */
    private function textSetting(string $key, string $default): string
    {
        $row = ServiciosSetting::where('key', $key)->first();
        if ($row === null) {
            $row = JwtActor::currentRequestIsImpersonating()
                ? new ServiciosSetting(['key' => $key, 'value' => $default])
                : ServiciosSetting::create(['key' => $key, 'value' => $default]);
        }

        return (string) $row->value;
    }
}
