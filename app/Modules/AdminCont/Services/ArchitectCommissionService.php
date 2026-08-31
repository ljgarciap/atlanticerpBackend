<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Exceptions\ArchitectCommissionStateException;
use App\Modules\AdminCont\Jobs\SendArchitectReminderEmailJob;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\ArchitectCommission;
use App\Modules\AdminCont\Models\ArchitectFiscalProfile;
use App\Modules\VentasDiseno\Models\Architect;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\CircuitBreaker;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Batch 16 de Admin&Cont (SCRUM-585→590, REQ-508→513) — Comisiones Externas (arquitectos). Ver
 * docs/adr/ADR-SCRUM585-590-batch16-comisiones-externas.md — deliberadamente independiente de
 * `CommissionOrderService` (Comisiones Internas), mismo criterio ya establecido en ese servicio
 * de no forzar reuso entre motores de comisión de distintos módulos/audiencias.
 *
 * "Proyecto referido comisionable" tampoco es una tabla propia — mismo espíritu que
 * `CommissionOrderService::rawOrders()`: join de solo lectura `ventas_diseno.pipeline_cards`
 * (stage=approved) → `bodega.orders` → `admin_contab.invoices` (+ `admin_contab.payments` para
 * "cobro 100%"), sin FK cross-schema. La diferencia real: acá el proyecto SOLO cuenta si tiene un
 * arquitecto asociado — resuelto vía la cotización más reciente de ese `sales_project` que tenga
 * `architect_id` no nulo (`ventas_diseno.quotes`, único lugar del sistema donde ya se captura ese
 * vínculo hoy — no existe columna de arquitecto en `sales_projects`/`pipeline_cards`).
 *
 * A diferencia de Internas, acá SÍ hace falta un overlay persistido (`ArchitectCommission`, por
 * `pipeline_card_id`) para la cuenta de cobro y la fecha de pago manual — no hay tiered %, así que
 * tampoco hace falta ningún snapshot de mes cerrado (REQ-511 RN1: 10% fijo en este batch, override
 * por proyecto llega en REQ-514/Batch 17).
 *
 * Batch 17 (SCRUM-591→596, REQ-514→519) agrega: comprobante de retención (solo régimen 50%),
 * "Marcar como pagado" con validación en cadena, % de comisión por proyecto (propuesta + aprobación
 * de Mark), cuenta de pago editable, recordatorio por correo al arquitecto, y modal de detalle por
 * proyecto (consolida REQ-508→518, sin lógica nueva propia).
 *
 * @phpstan-type ArchitectProjectRow array{pipeline_card_id: int, architect_id: int,
 *     numero_pedido: mixed, cliente: mixed, fecha_pedido: CarbonImmutable|null,
 *     monto_proyecto: float, total_facturado: float|null, total_cobrado: float,
 *     cuenta_cobro_nombre_archivo: string|null, cuenta_cobro_uploaded_at: CarbonImmutable|null,
 *     fecha_pago: CarbonImmutable|null, estado: string,
 *     comprobante_retencion_nombre_archivo: string|null,
 *     comprobante_retencion_uploaded_at: CarbonImmutable|null,
 *     bank_account_id: int|null, porcentaje_aprobado: float|null, porcentaje_pendiente: float|null,
 *     porcentaje_pendiente_motivo: string|null, fecha_cobro_completo: CarbonImmutable|null}
 */
final class ArchitectCommissionService
{
    public const ESTADO_AUN_NO_GENERADA = 'aun_no_generada';

    public const ESTADO_PENDIENTE_FACTURA = 'pendiente_factura_arquitecto';

    public const ESTADO_PAGADA = 'pagada';

    /** REQ-513 RN1 — mismo disco privado que el resto de adjuntos del módulo. */
    private const DISK = 's3-private';

    /**
     * Sin caché de instancia a propósito (Batch 17): un caché por instancia parecía seguro
     * (dura solo lo que dura un request real) pero `Illuminate\Routing\Route::getController()`
     * cachea la instancia del controller en el propio objeto `Route`, que sí sobrevive entre
     * llamadas `postJson()` dentro de un mismo test — dos "requests" simuladas dentro del mismo
     * test de Feature terminan compartiendo la misma instancia de este servicio y, con ella, un
     * `allProjects()` obsoleto justo para los métodos de escritura que necesitan leer el estado
     * más reciente (ej. `proponerPorcentaje()` rechazando una segunda propuesta). Recalcular en
     * cada llamada cuesta una query extra por acción, aceptable frente al riesgo de un chequeo de
     * validación silenciosamente obsoleto.
     *
     * @return Collection<int, ArchitectProjectRow>
     */
    public function allProjects(): Collection
    {
        return $this->rawProjects();
    }

    /** @return Collection<int, ArchitectProjectRow> */
    private function rawProjects(): Collection
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return collect();
        }

        $pipelineTable = "{$tenant->slug}_ventas_diseno.pipeline_cards";
        $quoteTable = "{$tenant->slug}_ventas_diseno.quotes";
        $orderTable = "{$tenant->slug}_bodega.orders";
        $invoiceTable = "{$tenant->slug}_admin_contab.invoices";
        $paymentTable = "{$tenant->slug}_admin_contab.payments";
        $payInvTable = "{$tenant->slug}_admin_contab.payment_invoices";
        $architectCommissionTable = "{$tenant->slug}_admin_contab.architect_commissions";

        $rows = DB::table("{$pipelineTable} as pc")
            ->leftJoin("{$orderTable} as o", 'o.pipeline_card_id', '=', 'pc.id')
            ->leftJoin("{$invoiceTable} as inv", 'inv.order_id', '=', 'o.id')
            ->leftJoin("{$architectCommissionTable} as ac", 'ac.pipeline_card_id', '=', 'pc.id')
            ->where('pc.stage', 'approved')
            ->select([
                'pc.id as pipeline_card_id',
                'pc.amount as monto_proyecto',
                'pc.stage_changed_at',
                'o.order_number as numero_pedido',
                'o.customer_name as cliente',
                'inv.monto as total_facturado',
                'inv.saldo_aplicado as total_cobrado',
                'ac.cuenta_cobro_nombre_archivo',
                'ac.cuenta_cobro_uploaded_at',
                'ac.fecha_pago',
                'ac.comprobante_retencion_nombre_archivo',
                'ac.comprobante_retencion_uploaded_at',
                'ac.bank_account_id',
                'ac.porcentaje_aprobado',
                'ac.porcentaje_pendiente',
                'ac.porcentaje_pendiente_motivo',
                // REQ-508 RN1 — único lugar del sistema donde ya se captura "quién refirió este
                // proyecto" (ver docblock de clase): la cotización más reciente de este
                // sales_project que tenga architect_id. Sin FK cross-schema, mismo criterio que el
                // resto del módulo.
                DB::raw("(
                    select q.architect_id from {$quoteTable} q
                    where q.sales_project_id = pc.sales_project_id and q.architect_id is not null
                    order by q.created_at desc limit 1
                ) as architect_id"),
                DB::raw("(
                    select max(p.created_at) from {$paymentTable} p
                    inner join {$payInvTable} pi on pi.payment_id = p.id
                    where pi.invoice_id = inv.id and p.estado = 'confirmado'
                ) as fecha_cobro_completo"),
            ])
            ->get();

        // RN1 de REQ-508 — solo proyectos con arquitecto asociado; se filtra en PHP porque el
        // origen es una subquery correlacionada, no una columna real para un WHERE directo.
        $withArchitect = $rows->filter(fn (object $row) => $row->architect_id !== null);
        if ($withArchitect->isEmpty()) {
            return collect();
        }

        return $withArchitect->map(function (object $row): array {
            $monto = (float) $row->monto_proyecto;
            $totalFacturado = $row->total_facturado !== null ? (float) $row->total_facturado : null;
            $totalCobrado = $row->total_cobrado !== null ? (float) $row->total_cobrado : 0.0;
            $fechaPago = $row->fecha_pago !== null ? CarbonImmutable::parse($row->fecha_pago) : null;

            return [
                'pipeline_card_id' => (int) $row->pipeline_card_id,
                'architect_id' => (int) $row->architect_id,
                'numero_pedido' => $row->numero_pedido,
                'cliente' => $row->cliente,
                'fecha_pedido' => $row->stage_changed_at !== null ? CarbonImmutable::parse($row->stage_changed_at) : null,
                'monto_proyecto' => $monto,
                'total_facturado' => $totalFacturado,
                'total_cobrado' => $totalCobrado,
                'cuenta_cobro_nombre_archivo' => $row->cuenta_cobro_nombre_archivo !== null ? (string) $row->cuenta_cobro_nombre_archivo : null,
                'cuenta_cobro_uploaded_at' => $row->cuenta_cobro_uploaded_at !== null ? CarbonImmutable::parse($row->cuenta_cobro_uploaded_at) : null,
                'fecha_pago' => $fechaPago,
                'estado' => $this->estadoFor($totalFacturado, $totalCobrado, $fechaPago),
                'comprobante_retencion_nombre_archivo' => $row->comprobante_retencion_nombre_archivo !== null ? (string) $row->comprobante_retencion_nombre_archivo : null,
                'comprobante_retencion_uploaded_at' => $row->comprobante_retencion_uploaded_at !== null ? CarbonImmutable::parse($row->comprobante_retencion_uploaded_at) : null,
                'bank_account_id' => $row->bank_account_id !== null ? (int) $row->bank_account_id : null,
                'porcentaje_aprobado' => $row->porcentaje_aprobado !== null ? (float) $row->porcentaje_aprobado : null,
                'porcentaje_pendiente' => $row->porcentaje_pendiente !== null ? (float) $row->porcentaje_pendiente : null,
                'porcentaje_pendiente_motivo' => $row->porcentaje_pendiente_motivo !== null ? (string) $row->porcentaje_pendiente_motivo : null,
                // Batch 18 (SCRUM-597→601) — "fecha estimada" de una comisión pendiente para el
                // flujo de caja proyectado (ADR-SCRUM597-601, reconciliación punto 7). Ya se
                // seleccionaba en la query de arriba pero nunca se exponía en el array mapeado.
                'fecha_cobro_completo' => $row->fecha_cobro_completo !== null ? CarbonImmutable::parse($row->fecha_cobro_completo) : null,
            ];
        })->values();
    }

    /**
     * REQ-512, RN1-RN3. "Todo o nada" (RN1, mismo criterio que Comisiones Internas REQ-501) — un
     * cobro parcial o vencido nunca genera comisión. "Pagada" solo se alcanza con `fecha_pago`
     * real (RN3) — nunca automático, a diferencia de Internas.
     */
    public function estadoFor(?float $totalFacturado, float $totalCobrado, ?CarbonImmutable $fechaPago): string
    {
        if ($totalFacturado === null || $totalFacturado <= 0 || $totalCobrado < $totalFacturado) {
            return self::ESTADO_AUN_NO_GENERADA;
        }

        return $fechaPago !== null ? self::ESTADO_PAGADA : self::ESTADO_PENDIENTE_FACTURA;
    }

    /** REQ-508/511 RN1 — 10% fijo por defecto en este batch (override por proyecto es REQ-514,
     *  Batch 17). Vive en `AdminContFiscalSettings.comision_externa_default_percent` (singleton
     *  ya existente de Configuración Fiscal, ver migración `2026_08_25_100008`) — nunca una
     *  constante propia, mismo criterio ya aplicado a `primary_approval_threshold` en ese mismo
     *  singleton. Sin pantalla de edición propia todavía (mismo patrón que
     *  `client_billing_profiles`), editable por tinker hasta que Batch 17 traiga la UI real. */
    private function porcentajeDefault(): float
    {
        return AdminContFiscalSettings::current()->comision_externa_default_percent;
    }

    /**
     * REQ-511, RN1-RN6. `$regimenFiscal` null → impuesto/total no calculables (RN6, nunca se
     * asume "Exento" por defecto).
     *
     * Tasa de ITBMS vía `ItbmsRateResolver::defaultPercentage()` (REQ-480, Batch 10 de Notas
     * Crédito) — su propio docblock ya lista "Comisiones Externas" como consumidor previsto,
     * nunca un `0.07` hardcodeado propio (regla dura del proyecto contra hardcodear porcentajes de
     * negocio). RN5: la retención del 50% es la mitad de ESA MISMA tasa base, no un valor
     * independiente — aunque el catálogo también tenga sembrada una fila "3.5%" propia (misma
     * relación, ver su descripción), calcularlo como mitad del % vigente sigue el texto literal del
     * REQ ("la mitad del 7% de ITBMS") y se mantiene correcto si el % base cambia alguna vez.
     *
     * REQ-516 RN1 — `$porcentajeOverride` es el % aprobado por Mark para ESTE proyecto puntual
     * (`ArchitectCommission.porcentaje_aprobado`); `null` cae al default global.
     *
     * @return array{comision: float, impuesto: float|null, total: float|null}
     */
    public function montoFor(float $montoProyecto, ?string $regimenFiscal, ?float $porcentajeOverride = null): array
    {
        $comision = round($montoProyecto * ($porcentajeOverride ?? $this->porcentajeDefault()) / 100, 2);

        $itbmsPercent = ItbmsRateResolver::defaultPercentage();
        $impuesto = match ($regimenFiscal) {
            ArchitectFiscalProfile::REGIMEN_EXENTO => 0.0,
            ArchitectFiscalProfile::REGIMEN_CON_ITBMS => round($comision * $itbmsPercent / 100, 2),
            ArchitectFiscalProfile::REGIMEN_RETENCION_50 => round($comision * $itbmsPercent / 100 / 2, 2),
            default => null,
        };

        return [
            'comision' => $comision,
            'impuesto' => $impuesto,
            'total' => $impuesto !== null ? round($comision + $impuesto, 2) : null,
        ];
    }

    /**
     * REQ-508→509 — resumen completo (5 tarjetas, siempre globales sin importar los filtros de
     * abajo) + tabla de arquitectos filtrada (búsqueda/mes/arquitecto/estado, todos combinados
     * AND — RN2 REQ-509). Un arquitecto sin ningún proyecto que matchee los filtros activos se
     * oculta por completo de la tabla (RN3 REQ-509).
     *
     * @param  array{search?: string, mes?: string, architect_id?: int, estado?: string}  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters = []): array
    {
        $projects = $this->allProjects();

        $architectIds = $projects->pluck('architect_id')->unique()->values();
        /** @var Collection<int, Architect> $architects */
        $architects = Architect::whereIn('id', $architectIds)->get()->keyBy('id');
        /** @var Collection<int, ArchitectFiscalProfile> $fiscalProfiles */
        $fiscalProfiles = ArchitectFiscalProfile::whereIn('architect_id', $architectIds)->get()->keyBy('architect_id');
        /** @var Collection<int, AdminContBankAccount> $bankAccounts */
        $bankAccounts = AdminContBankAccount::all()->keyBy('id');
        $defaultBankAccount = $this->defaultBankAccount();

        $cards = $this->buildCards($projects, $fiscalProfiles);

        $search = isset($filters['search']) && trim((string) $filters['search']) !== '' ? mb_strtolower(trim((string) $filters['search'])) : null;
        $mes = $filters['mes'] ?? null;
        $archId = $filters['architect_id'] ?? null;
        $estado = $filters['estado'] ?? null;

        $mesesDisponibles = $projects->pluck('fecha_pedido')->filter()
            ->unique(fn (CarbonImmutable $m) => $m->format('Y-m'))
            ->sortByDesc(fn (CarbonImmutable $m) => $m->format('Y-m'))
            ->map(fn (CarbonImmutable $m) => $m->format('Y-m'))->values();

        $architectRows = $architectIds->map(function (int $id) use ($projects, $architects, $fiscalProfiles, $bankAccounts, $defaultBankAccount, $search, $mes, $archId, $estado): ?array {
            if ($archId !== null && $id !== $archId) {
                return null;
            }

            $architect = $architects->get($id);
            if ($architect === null) {
                // architect_id viene de quotes.architect_id (FK nullOnDelete) — si el arquitecto
                // se borró justo entre el join y este punto, la fila queda huérfana; se omite
                // defensivamente en vez de mostrar un nombre vacío.
                return null;
            }

            $profile = $fiscalProfiles->get($id);
            $regimen = $profile?->regimen_fiscal;

            $architectProjects = $projects->filter(fn (array $p) => $p['architect_id'] === $id)
                ->filter(function (array $p) use ($estado, $mes, $search, $architect) {
                    if ($estado !== null && $p['estado'] !== $estado) {
                        return false;
                    }
                    if ($mes !== null && $p['fecha_pedido']?->format('Y-m') !== $mes) {
                        return false;
                    }
                    if ($search !== null) {
                        $haystack = mb_strtolower($architect->name.' '.($p['cliente'] ?? '').' '.($p['numero_pedido'] ?? ''));
                        if (! str_contains($haystack, $search)) {
                            return false;
                        }
                    }

                    return true;
                })
                ->values();

            // RN3 REQ-509 — arquitecto sin ningún proyecto que matchee se oculta por completo.
            if ($architectProjects->isEmpty()) {
                return null;
            }

            $enrichedProjects = $architectProjects->map(fn (array $p) => $this->enrichProject($p, $regimen, $bankAccounts, $defaultBankAccount))->values();

            // Mockup del ticket (4F__Admin_Contabilidad_ComisionesExt.html) — la fila de cada
            // arquitecto lleva 3 columnas de monto (Generada/Pagada/Pendiente), mismo desglose que
            // las tarjetas globales (§buildCards) pero acotado a los proyectos YA filtrados de
            // este arquitecto — nunca a su universo completo, para que quede consistente con lo
            // que el usuario ve al expandir la fila.
            $generada = 0.0;
            $pagada = 0.0;
            $pendiente = 0.0;
            foreach ($enrichedProjects as $ep) {
                if ($ep['total'] === null) {
                    continue; // datos fiscales incompletos — mismo criterio que buildCards()
                }
                if ($ep['estado'] === self::ESTADO_PAGADA) {
                    $pagada += $ep['total'];
                    $generada += $ep['total'];
                } elseif ($ep['estado'] === self::ESTADO_PENDIENTE_FACTURA) {
                    $pendiente += $ep['total'];
                    $generada += $ep['total'];
                }
            }

            return [
                'architect_id' => $id,
                'nombre' => $architect->name,
                'empresa' => $profile?->empresa,
                'ruc' => $profile?->ruc,
                'regimen_fiscal' => $regimen,
                'datos_fiscales_completos' => $profile !== null,
                'generada' => round($generada, 2),
                'pagada' => round($pagada, 2),
                'pendiente' => round($pendiente, 2),
                'proyectos' => $enrichedProjects,
            ];
        })->filter()->values()->all();

        return [
            ...$cards,
            'meses_disponibles' => $mesesDisponibles,
            'arquitectos' => $architectRows,
        ];
    }

    /**
     * Batch 23 (SCRUM-648, REQ-571) — comisiones externas ya PAGADAS, una fila por proyecto con su
     * fecha real de pago. Mismo filtro y misma fórmula que `buildCards()` (estado=pagada, perfil
     * fiscal completo, `comisión = monto_proyecto * porcentaje/100`, sin impuesto) — nunca una
     * fórmula paralela. `pendiente_factura`/`aun_no_generada` quedan fuera a propósito, mismo
     * criterio que `commissionsCollectedByDate()` de `CommissionOrderService`: "generada" acá es
     * "ya real", no una proyección.
     *
     * @return Collection<int, array{fecha: string, monto: float}>
     */
    public function commissionsPaidByDate(): Collection
    {
        $projects = $this->allProjects();
        $architectIds = $projects->pluck('architect_id')->unique()->values();
        /** @var Collection<int, ArchitectFiscalProfile> $fiscalProfiles */
        $fiscalProfiles = ArchitectFiscalProfile::whereIn('architect_id', $architectIds)->get()->keyBy('architect_id');

        $rows = collect();
        foreach ($projects as $p) {
            if ($p['estado'] !== self::ESTADO_PAGADA || $p['fecha_pago'] === null) {
                continue;
            }
            if ($fiscalProfiles->get($p['architect_id']) === null) {
                continue;
            }

            $comision = round((float) $p['monto_proyecto'] * ($p['porcentaje_aprobado'] ?? $this->porcentajeDefault()) / 100, 2);
            $rows->push(['fecha' => $p['fecha_pago']->toDateString(), 'monto' => $comision]);
        }

        return $rows;
    }

    /**
     * @param  Collection<int, ArchitectProjectRow>  $projects
     * @param  Collection<int, ArchitectFiscalProfile>  $fiscalProfiles
     * @return array<string, float>
     */
    private function buildCards(Collection $projects, Collection $fiscalProfiles): array
    {
        $currentMonth = CarbonImmutable::now()->format('Y-m');

        $pagada = 0.0;
        $pagadoEsteMes = 0.0;
        $pendienteFactura = 0.0;
        $aunNoGenerada = 0.0;

        foreach ($projects as $p) {
            $profile = $fiscalProfiles->get($p['architect_id']);
            $comision = round($p['monto_proyecto'] * ($p['porcentaje_aprobado'] ?? $this->porcentajeDefault()) / 100, 2);

            if ($p['estado'] === self::ESTADO_AUN_NO_GENERADA) {
                // RN4 REQ-508 — no exige datos fiscales completos, es un estimado en potencia.
                $aunNoGenerada += $comision;

                continue;
            }

            // RN2 REQ-508 — Comisión generada/Pagada/Pendiente de factura solo suman con datos
            // fiscales completos (si faltan, no se puede calcular el impuesto todavía).
            if ($profile === null) {
                continue;
            }

            if ($p['estado'] === self::ESTADO_PAGADA) {
                $pagada += $comision;
                if ($p['fecha_pago'] !== null && $p['fecha_pago']->format('Y-m') === $currentMonth) {
                    $pagadoEsteMes += $comision;
                }
            } elseif ($p['estado'] === self::ESTADO_PENDIENTE_FACTURA) {
                $pendienteFactura += $comision;
            }
        }

        return [
            'comision_generada' => round($pagada + $pendienteFactura, 2),
            'pagada_total' => round($pagada, 2),
            'pagado_este_mes' => round($pagadoEsteMes, 2),
            'pendiente_factura' => round($pendienteFactura, 2),
            'aun_no_generada' => round($aunNoGenerada, 2),
        ];
    }

    /**
     * @param  ArchitectProjectRow  $p
     * @param  Collection<int, AdminContBankAccount>  $bankAccounts
     * @return array<string, mixed>
     */
    private function enrichProject(array $p, ?string $regimenFiscal, Collection $bankAccounts, ?AdminContBankAccount $defaultBankAccount): array
    {
        $montos = $this->montoFor((float) $p['monto_proyecto'], $regimenFiscal, $p['porcentaje_aprobado']);
        // REQ-517 RN1 — todo proyecto SIN cuenta explícita todavía muestra el default resuelto
        // ("Banco General — Cuenta Corriente"), nunca "—" — la fila `architect_commissions` solo
        // se crea al primer archivo subido (Batch 16), así que la mayoría de los proyectos llegan
        // acá sin `bank_account_id` propio.
        $bankAccount = $p['bank_account_id'] !== null ? $bankAccounts->get($p['bank_account_id']) : $defaultBankAccount;

        return [
            'pipeline_card_id' => $p['pipeline_card_id'],
            'numero_pedido' => $p['numero_pedido'],
            'cliente' => $p['cliente'],
            'fecha_pedido' => $p['fecha_pedido']?->toDateString(),
            'monto_proyecto' => $p['monto_proyecto'],
            'total_facturado' => $p['total_facturado'],
            'total_cobrado' => $p['total_cobrado'],
            'porcentaje' => $p['porcentaje_aprobado'] ?? $this->porcentajeDefault(),
            'comision' => $montos['comision'],
            'impuesto' => $montos['impuesto'],
            'total' => $montos['total'],
            'estado' => $p['estado'],
            'cuenta_cobro' => $p['cuenta_cobro_nombre_archivo'] !== null ? [
                'nombre_archivo' => $p['cuenta_cobro_nombre_archivo'],
                'uploaded_at' => $p['cuenta_cobro_uploaded_at']?->toIso8601String(),
            ] : null,
            // REQ-514 RN1 — el frontend decide si mostrar la zona de carga según el régimen del
            // arquitecto (ya expuesto en la fila padre); acá solo va el estado del archivo.
            'comprobante_retencion' => $p['comprobante_retencion_nombre_archivo'] !== null ? [
                'nombre_archivo' => $p['comprobante_retencion_nombre_archivo'],
                'uploaded_at' => $p['comprobante_retencion_uploaded_at']?->toIso8601String(),
            ] : null,
            'fecha_pago' => $p['fecha_pago']?->toDateString(),
            // REQ-516 — propuesta de % pendiente de aprobación de Mark, null si no hay ninguna.
            'porcentaje_pendiente' => $p['porcentaje_pendiente'],
            'porcentaje_pendiente_motivo' => $p['porcentaje_pendiente_motivo'],
            // REQ-517 — cuenta de pago; `null` hasta que se resuelva el default (REQ-517 RN1) o se
            // edite manualmente. El frontend muestra el default resuelto vía `bankAccountOptions()`.
            'bank_account' => $bankAccount !== null ? ['id' => $bankAccount->id, 'label' => $bankAccount->label()] : null,
        ];
    }

    /** REQ-510 RN1 — los 3 campos son obligatorios en conjunto (validado en el Form Request, no
     *  acá). RN2 — único por arquitecto, `updateOrCreate` nunca duplica. */
    public function upsertFiscalProfile(int $architectId, string $empresa, string $ruc, string $regimenFiscal): ArchitectFiscalProfile
    {
        return ArchitectFiscalProfile::updateOrCreate(
            ['architect_id' => $architectId],
            ['empresa' => $empresa, 'ruc' => $ruc, 'regimen_fiscal' => $regimenFiscal],
        );
    }

    /**
     * REQ-513 — sube/reemplaza (RN3) la cuenta de cobro de un proyecto. Mismo patrón que
     * `AdminContPaymentAttachmentService::upload()` (disco privado, cleanup si falla la fila), pero
     * sin tabla de adjuntos propia — la fila vive directo en `architect_commissions` (RN3: a lo
     * sumo 1 vigente por proyecto, "Reemplazar" no "Agregar otra"). El archivo viejo se borra del
     * disco recién después de confirmar el nuevo, para no dejar el proyecto sin cuenta de cobro si
     * el upload nuevo falla a mitad de camino.
     */
    public function uploadCuentaCobro(int $pipelineCardId, int $architectId, UploadedFile $file, int $uploadedBy): ArchitectCommission
    {
        $existing = ArchitectCommission::where('pipeline_card_id', $pipelineCardId)->first();
        $oldKey = $existing?->cuenta_cobro_storage_key;

        $ext = strtolower($file->getClientOriginalExtension());
        $key = "admin-contab/architect-commissions/{$pipelineCardId}/".Str::uuid()->toString().($ext !== '' ? ".{$ext}" : '');

        (new CircuitBreaker(self::DISK))->call(function () use ($file, $key): void {
            $uploaded = Storage::disk(self::DISK)->putFileAs(dirname($key), $file, basename($key));

            if ($uploaded === false) {
                throw new \RuntimeException('S3 upload failed for disk ['.self::DISK."] key [{$key}]");
            }
        });

        try {
            $commission = ArchitectCommission::updateOrCreate(
                ['pipeline_card_id' => $pipelineCardId],
                [
                    'architect_id' => $architectId,
                    'cuenta_cobro_storage_key' => $key,
                    'cuenta_cobro_nombre_archivo' => $file->getClientOriginalName(),
                    'cuenta_cobro_size_bytes' => $file->getSize(),
                    'cuenta_cobro_mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'cuenta_cobro_uploaded_at' => now(),
                    'cuenta_cobro_uploaded_by' => $uploadedBy,
                ],
            );
        } catch (\Throwable $e) {
            try {
                Storage::disk(self::DISK)->delete($key);
            } catch (\Throwable $se) {
                Log::error('architect_commission_service.storage_cleanup_failed', [
                    'disk' => self::DISK, 'key' => $key, 'error' => $se->getMessage(),
                ]);
            }

            throw $e;
        }

        if ($oldKey !== null && $oldKey !== $key) {
            try {
                Storage::disk(self::DISK)->delete($oldKey);
            } catch (\Throwable $se) {
                Log::error('architect_commission_service.old_cuenta_cobro_cleanup_failed', [
                    'disk' => self::DISK, 'key' => $oldKey, 'error' => $se->getMessage(),
                ]);
            }
        }

        return $commission;
    }

    /** REQ-513 — URL firmada de 15 min para ver la cuenta de cobro, mismo TTL que el resto del
     *  módulo. */
    public function cuentaCobroPresignedUrl(ArchitectCommission $commission): ?string
    {
        if ($commission->cuenta_cobro_storage_key === null) {
            return null;
        }

        return Storage::disk(self::DISK)->temporaryUrl(
            $commission->cuenta_cobro_storage_key,
            now()->addMinutes(15),
        );
    }

    /**
     * REQ-514 — mismo mecanismo de storage que `uploadCuentaCobro()`, pero RN1 la restringe a
     * arquitectos con régimen "Retención del 50%" — validado acá server-side (nunca solo ocultando
     * el botón en la UI).
     */
    public function uploadComprobanteRetencion(int $pipelineCardId, int $architectId, UploadedFile $file, int $uploadedBy): ArchitectCommission
    {
        $profile = ArchitectFiscalProfile::where('architect_id', $architectId)->first();
        if ($profile === null || $profile->regimen_fiscal !== ArchitectFiscalProfile::REGIMEN_RETENCION_50) {
            throw new ArchitectCommissionStateException('El comprobante de retención solo aplica a arquitectos con régimen "Retención del 50%".');
        }

        $existing = ArchitectCommission::where('pipeline_card_id', $pipelineCardId)->first();
        $oldKey = $existing?->comprobante_retencion_storage_key;

        $ext = strtolower($file->getClientOriginalExtension());
        $key = "admin-contab/architect-commissions/{$pipelineCardId}/retencion-".Str::uuid()->toString().($ext !== '' ? ".{$ext}" : '');

        (new CircuitBreaker(self::DISK))->call(function () use ($file, $key): void {
            $uploaded = Storage::disk(self::DISK)->putFileAs(dirname($key), $file, basename($key));

            if ($uploaded === false) {
                throw new \RuntimeException('S3 upload failed for disk ['.self::DISK."] key [{$key}]");
            }
        });

        try {
            $commission = ArchitectCommission::updateOrCreate(
                ['pipeline_card_id' => $pipelineCardId],
                [
                    'architect_id' => $architectId,
                    'comprobante_retencion_storage_key' => $key,
                    'comprobante_retencion_nombre_archivo' => $file->getClientOriginalName(),
                    'comprobante_retencion_size_bytes' => $file->getSize(),
                    'comprobante_retencion_mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'comprobante_retencion_uploaded_at' => now(),
                    'comprobante_retencion_uploaded_by' => $uploadedBy,
                ],
            );
        } catch (\Throwable $e) {
            try {
                Storage::disk(self::DISK)->delete($key);
            } catch (\Throwable $se) {
                Log::error('architect_commission_service.retencion_storage_cleanup_failed', [
                    'disk' => self::DISK, 'key' => $key, 'error' => $se->getMessage(),
                ]);
            }

            throw $e;
        }

        if ($oldKey !== null && $oldKey !== $key) {
            try {
                Storage::disk(self::DISK)->delete($oldKey);
            } catch (\Throwable $se) {
                Log::error('architect_commission_service.old_retencion_cleanup_failed', [
                    'disk' => self::DISK, 'key' => $oldKey, 'error' => $se->getMessage(),
                ]);
            }
        }

        return $commission;
    }

    /** REQ-514 — URL firmada de 15 min para ver el comprobante de retención. */
    public function comprobanteRetencionPresignedUrl(ArchitectCommission $commission): ?string
    {
        if ($commission->comprobante_retencion_storage_key === null) {
            return null;
        }

        return Storage::disk(self::DISK)->temporaryUrl(
            $commission->comprobante_retencion_storage_key,
            now()->addMinutes(15),
        );
    }

    /**
     * REQ-517 RN1 — mismo query que `PaymentService::defaultBankAccountFor()` (Cobros) para
     * "Banco General — Cuenta Corriente", deliberadamente duplicado en vez de depender de ese
     * servicio — mismo criterio de independencia entre motores ya establecido en el docblock de
     * clase. `null` si esa cuenta específica no existe todavía en este tenant (nunca bloquea nada,
     * el campo queda sin resolver hasta que Felix/Yaneth la creen en Cuentas Bancarias).
     */
    public function defaultBankAccount(): ?AdminContBankAccount
    {
        return AdminContBankAccount::query()
            ->where('activa', true)
            ->where('banco', 'Banco General')
            ->where('tipo_cuenta', AdminContBankAccount::TIPO_CORRIENTE)
            ->first();
    }

    /** REQ-517 — opciones del selector de cuenta de pago (solo cuentas activas).
     *  @return Collection<int, array{id: int, label: string}> */
    public function bankAccountOptions(): Collection
    {
        return AdminContBankAccount::query()->where('activa', true)->orderBy('banco')->get()
            ->map(fn (AdminContBankAccount $a) => ['id' => $a->id, 'label' => $a->label()])->values();
    }

    /**
     * REQ-517 RN2/RN3 — editable libremente mientras no esté "Pagada"; una vez pagada, bloqueado
     * server-side (nunca solo deshabilitando el control en la UI).
     */
    public function actualizarCuentaPago(int $pipelineCardId, int $architectId, ?int $bankAccountId): ArchitectCommission
    {
        $project = $this->allProjects()->firstWhere('pipeline_card_id', $pipelineCardId);
        if ($project !== null && $project['estado'] === self::ESTADO_PAGADA) {
            throw new ArchitectCommissionStateException('La comisión ya está pagada — la cuenta de pago no se puede modificar.');
        }

        return ArchitectCommission::updateOrCreate(
            ['pipeline_card_id' => $pipelineCardId],
            ['architect_id' => $architectId, 'bank_account_id' => $bankAccountId],
        );
    }

    /**
     * REQ-516 RN2/RN3 — propone un % de comisión distinto para ESTE proyecto. El % vigente sigue
     * siendo el actual (aprobado o default) hasta que Mark decida — nunca se aplica de inmediato.
     */
    public function proponerPorcentaje(int $pipelineCardId, int $architectId, float $nuevoPorcentaje, string $motivo, int $actorId): ArchitectCommission
    {
        $project = $this->allProjects()->firstWhere('pipeline_card_id', $pipelineCardId);
        if ($project === null) {
            throw new ArchitectCommissionStateException('Proyecto no encontrado.');
        }
        if ($project['estado'] === self::ESTADO_PAGADA) {
            throw new ArchitectCommissionStateException('No se puede proponer un cambio de % — la comisión de este proyecto ya está pagada.');
        }
        if ($project['porcentaje_pendiente'] !== null) {
            throw new ArchitectCommissionStateException('Ya hay un cambio de % pendiente de aprobación del aprobador configurado para este proyecto.');
        }

        return ArchitectCommission::updateOrCreate(
            ['pipeline_card_id' => $pipelineCardId],
            [
                'architect_id' => $architectId,
                'porcentaje_pendiente' => $nuevoPorcentaje,
                'porcentaje_pendiente_motivo' => $motivo,
                'porcentaje_pendiente_propuesto_por' => $actorId,
                'porcentaje_pendiente_propuesto_at' => now(),
            ],
        );
    }

    /**
     * REQ-516 RN4 — exclusivo de Mark (gateado en la ruta, no acá). Al aprobar, el % pendiente pasa
     * a ser el % aprobado (aplica de inmediato al cálculo). Al rechazar, se descarta sin tocar
     * `porcentaje_aprobado` — el % vuelve exactamente al valor anterior porque nunca se tocó.
     */
    public function decidirPorcentaje(int $pipelineCardId, bool $aprobar): ArchitectCommission
    {
        $commission = ArchitectCommission::where('pipeline_card_id', $pipelineCardId)->first();
        if ($commission === null || $commission->porcentaje_pendiente === null) {
            throw new ArchitectCommissionStateException('No hay ningún cambio de % pendiente de aprobación para este proyecto.');
        }

        if ($aprobar) {
            $commission->porcentaje_aprobado = $commission->porcentaje_pendiente;
        }
        $commission->porcentaje_pendiente = null;
        $commission->porcentaje_pendiente_motivo = null;
        $commission->porcentaje_pendiente_propuesto_por = null;
        $commission->porcentaje_pendiente_propuesto_at = null;
        $commission->save();

        return $commission->refresh();
    }

    /**
     * REQ-515 RN1/RN2 — validación en cadena, en el orden exacto del REQ: (a) sin % pendiente,
     * (b) datos fiscales completos, (c) cuenta de cobro subida, (d) comprobante de retención si el
     * régimen lo exige. Cualquier falla lanza `ArchitectCommissionStateException` con un mensaje
     * específico — el frontend ya intentó este mismo chequeo antes de ofrecer el botón (mismos
     * campos ya expuestos en `summary()`), esto es la autoridad final, nunca confía en el cliente.
     * RN5: `fecha_pago` queda en el mes calendario actual (usado por "Pagado este mes", REQ-508).
     */
    public function marcarComoPagada(int $pipelineCardId): ArchitectCommission
    {
        $project = $this->allProjects()->firstWhere('pipeline_card_id', $pipelineCardId);
        if ($project === null) {
            throw new ArchitectCommissionStateException('Proyecto no encontrado.');
        }
        if ($project['estado'] !== self::ESTADO_PENDIENTE_FACTURA) {
            throw new ArchitectCommissionStateException('Este proyecto no está en estado "Pendiente de factura del arquitecto".');
        }
        if ($project['porcentaje_pendiente'] !== null) {
            throw new ArchitectCommissionStateException('Hay un cambio de % pendiente de aprobación del aprobador configurado en este proyecto — resuélvelo primero.');
        }

        $profile = ArchitectFiscalProfile::where('architect_id', $project['architect_id'])->first();
        if ($profile === null) {
            throw new ArchitectCommissionStateException('Faltan los datos fiscales del arquitecto — complétalos primero.');
        }
        if ($project['cuenta_cobro_nombre_archivo'] === null) {
            throw new ArchitectCommissionStateException('Falta subir la cuenta de cobro de este proyecto.');
        }
        if ($profile->regimen_fiscal === ArchitectFiscalProfile::REGIMEN_RETENCION_50
            && $project['comprobante_retencion_nombre_archivo'] === null
        ) {
            throw new ArchitectCommissionStateException('Falta subir el comprobante de retención de este proyecto.');
        }

        return DB::transaction(function () use ($pipelineCardId, $project, $profile): ArchitectCommission {
            $commission = ArchitectCommission::updateOrCreate(
                ['pipeline_card_id' => $pipelineCardId],
                ['architect_id' => $project['architect_id'], 'fecha_pago' => now()],
            );

            // Batch 18 (SCRUM-597→601) — ver ADR-SCRUM597-601, reconciliación punto 5: a
            // diferencia de Comisiones Internas (liquidación automática por cierre de mes, sin
            // cuenta bancaria capturada), acá SÍ hay una cuenta de pago real (REQ-517) y una acción
            // explícita de pago — es lo único de este servicio que alimenta la Vista real
            // (histórico) del flujo de caja. `bank_account_id` puede seguir null si nunca se
            // configuró la cuenta de pago (REQ-517 no la exige para llegar a "Pagada").
            if ($commission->bank_account_id !== null) {
                $montos = $this->montoFor($project['monto_proyecto'], $profile->regimen_fiscal, $project['porcentaje_aprobado']);
                $architect = Architect::find($project['architect_id']);

                AdminContBankMovement::create([
                    'bank_account_id' => $commission->bank_account_id,
                    'fecha' => now()->toDateString(),
                    'tipo' => AdminContBankMovement::TIPO_COMISION,
                    'concepto' => "Comisión arquitecto {$architect?->name} — {$project['numero_pedido']}",
                    'monto' => $montos['total'] ?? $montos['comision'],
                    'source_type' => AdminContBankMovement::SOURCE_ARCHITECT_COMMISSION,
                    'source_id' => $commission->id,
                ]);
            }

            return $commission->refresh();
        });
    }

    /**
     * REQ-518 RN1/RN2 — solo tiene sentido en proyectos "Pendiente de factura del arquitecto"
     * (validado acá, no solo ocultando el botón). Despacha un Job async (nunca síncrono desde el
     * controller, mismo patrón de resiliencia del resto del proyecto) al correo registrado del
     * arquitecto en su ficha de Ventas & Diseño.
     */
    public function enviarRecordatorio(int $pipelineCardId): void
    {
        $project = $this->allProjects()->firstWhere('pipeline_card_id', $pipelineCardId);
        if ($project === null) {
            throw new ArchitectCommissionStateException('Proyecto no encontrado.');
        }
        if ($project['estado'] !== self::ESTADO_PENDIENTE_FACTURA) {
            throw new ArchitectCommissionStateException('Este proyecto no está en estado "Pendiente de factura del arquitecto".');
        }

        $architect = Architect::find($project['architect_id']);
        if ($architect === null || $architect->email === null) {
            throw new ArchitectCommissionStateException('Este arquitecto no tiene un correo registrado en su ficha (Ventas & Diseño).');
        }

        SendArchitectReminderEmailJob::dispatch(
            $project['architect_id'],
            (string) ($project['numero_pedido'] ?? ''),
            (string) ($project['cliente'] ?? ''),
        );
    }
}
