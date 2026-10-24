<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Legacy\Models\ClienteRucCorreccion;
use App\Modules\Legacy\Models\ClienteRucCotizacionCorreccion;
use App\Modules\Legacy\Support\OperatorContext;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SCRUM-1137 F3 (cotizaciones) — decisión de Luis sobre el hallazgo M1 del Pre-QA: cada cotización guarda su propia copia del RUC al crearse (`quotes.ruc`),
 * así que las de un cliente cuyo RUC se corrigió con `ClienteRucCorrector` siguen mostrando el RUC truncado. Este servicio ajusta esa copia al RUC corregido.
 *
 * Candidatas: para cada corrección de sub_client NO revertida (cualquier corrida), si el sub_client TODAVÍA tiene exactamente el `tax_id_nuevo`, sus cotizaciones
 * cuyo `ruc` normalizado (`ClienteMatcher::normalizeCif`) es igual al `tax_id_anterior` normalizado. Una cotización con otro `ruc` (editado a mano, vacío, de otra
 * empresa) NUNCA se toca. Se consulta sin scopes globales: los de origen (pedidos gemelos) ocultarían cotizaciones que también llevan el RUC viejo.
 *
 * Aplica con `Quote::save()` (dispara `LogsAuditTrail`; sin update masivo), una transacción por cotización, y deja el registro de reversa en
 * `legacy.cotizacion_ruc_correcciones`. `plan()` solo lee. Mismas garantías que `ClienteRucCorrector`: idempotente, reversible por corrida, nunca pisa una
 * edición posterior.
 *
 * @phpstan-type Fila array{quote_id: int, sub_client_id: int, ruc_actual: ?string, ruc_propuesto: string, accion: string, motivo: string}
 */
final class ClienteRucCotizacionSync
{
    public const ACCION_CORREGIR = 'corregir';
    public const ACCION_OMITIR   = 'omitir';

    /** Solo para pruebas: se invoca dentro de la transacción de cada cotización justo después del save() y antes de verificar. Nunca se asigna en producción. */
    public static ?\Closure $despuesDeGuardar = null;

    /**
     * SOLO LECTURA.
     *
     * @return list<Fila>
     */
    public function plan(): array
    {
        // Una corrección por sub_client: si hubo varias no revertidas, importa la que dejó el valor que el sub_client tiene hoy.
        /** @var array<int, ClienteRucCorreccion> $correcciones */
        $correcciones = [];
        foreach (ClienteRucCorreccion::query()->whereNull('revertida_at')->orderBy('id')->get() as $c) {
            $correcciones[(int) $c->sub_client_id] = $c;
        }
        if ($correcciones === []) {
            return [];
        }

        $subIds = array_keys($correcciones);
        $taxIds = [];
        foreach (array_chunk($subIds, 1000) as $lote) {
            foreach (SubClient::query()->whereIn('id', $lote)->get(['id', 'tax_id']) as $s) {
                $taxIds[(int) $s->id] = $s->tax_id;
            }
        }

        $filas = [];
        foreach (array_chunk($subIds, 1000) as $lote) {
            $quotes = Quote::query()->withoutGlobalScopes()->whereIn('sub_client_id', $lote)->orderBy('id')->get(['id', 'sub_client_id', 'ruc']);
            foreach ($quotes as $q) {
                $sid  = (int) $q->sub_client_id;
                $corr = $correcciones[$sid];
                // El sub_client ya no tiene el valor corregido (editado a mano después): no hay un RUC bueno al que alinear la cotización.
                if (($taxIds[$sid] ?? null) !== $corr->tax_id_nuevo) {
                    continue;
                }

                $base = ['quote_id' => (int) $q->id, 'sub_client_id' => $sid, 'ruc_actual' => $q->ruc, 'ruc_propuesto' => $corr->tax_id_nuevo];
                $actualN = ClienteMatcher::normalizeCif($q->ruc);
                if ($actualN !== null && $actualN === ClienteMatcher::normalizeCif($corr->tax_id_nuevo)) {
                    $filas[] = $base + ['accion' => self::ACCION_OMITIR, 'motivo' => 'ya_igual_al_ruc_completo'];
                } elseif ($actualN !== null && $actualN === ClienteMatcher::normalizeCif($corr->tax_id_anterior)) {
                    $filas[] = $base + ['accion' => self::ACCION_CORREGIR, 'motivo' => 'ok'];
                } else {
                    $filas[] = $base + ['accion' => self::ACCION_OMITIR, 'motivo' => 'ruc_distinto_del_truncado'];
                }
            }
        }

        return $filas;
    }

    /**
     * @param list<Fila> $plan
     * @return array{run_id: string, corregidos: int, omitidos_en_apply: list<array{quote_id: int, sub_client_id: int, motivo: string}>, errores: list<array{quote_id: int, sub_client_id: int, motivo: string}>}
     */
    public function aplicar(array $plan, string $operador, string $entorno, string $runId): array
    {
        $corregidos = 0;
        $omitidos   = [];
        $errores    = [];
        /** @var array<int, string|null> $subTax sub_client_id => tax_id leído una vez */
        $subTax = [];

        try {
            foreach ($plan as $f) {
                if ($f['accion'] !== self::ACCION_CORREGIR) {
                    continue;
                }
                $qid = $f['quote_id'];
                $sid = $f['sub_client_id'];
                try {
                    $ok = DB::transaction(function () use ($f, $qid, $sid, $runId, $operador, &$subTax): bool {
                        /** @var Quote|null $quote */
                        $quote = Quote::query()->withoutGlobalScopes()->lockForUpdate()->find($qid);
                        // Idempotencia / cambio concurrente: el ruc tiene que seguir siendo exactamente el planeado y el sub_client el corregido.
                        if ($quote === null || $quote->ruc !== $f['ruc_actual'] || (int) $quote->sub_client_id !== $sid) {
                            return false;
                        }
                        // El tax_id del sub_client se lee una vez por sub_client en la corrida (y no por cotización).
                        $subTax[$sid] ??= SubClient::query()->whereKey($sid)->value('tax_id');
                        if ($subTax[$sid] !== $f['ruc_propuesto']) {
                            return false;
                        }

                        // Corregir el RUC no es una edición de la cotización: no mueve su `updated_at` (la auditoría ya lo excluye).
                        $quote->timestamps = false;
                        $quote->ruc        = $f['ruc_propuesto'];
                        $quote->save();

                        if (self::$despuesDeGuardar !== null) {
                            (self::$despuesDeGuardar)($quote);
                        }

                        ClienteRucCotizacionCorreccion::query()->create([
                            'run_id'        => $runId,
                            'quote_id'      => $qid,
                            'sub_client_id' => $sid,
                            'ruc_anterior'  => (string) $f['ruc_actual'],
                            'ruc_nuevo'     => $f['ruc_propuesto'],
                            'operador'      => $operador,
                            'aplicada_at'   => now(),
                        ]);

                        // Verificación releyendo de la BD.
                        $rel = Quote::query()->withoutGlobalScopes()->find($qid);
                        if ($rel === null || $rel->ruc !== $f['ruc_propuesto']) {
                            throw new RuntimeException('Verificación fallida: lo releído no coincide con lo aplicado.');
                        }

                        return true;
                    });
                } catch (\Throwable $e) {
                    $errores[] = ['quote_id' => $qid, 'sub_client_id' => $sid, 'motivo' => class_basename($e)];

                    continue;
                }

                if ($ok) {
                    $corregidos++;
                } else {
                    $omitidos[] = ['quote_id' => $qid, 'sub_client_id' => $sid, 'motivo' => 'cambio_desde_el_plan'];
                }
            }
        } finally {
            $this->auditar('cotizaciones_aplicar', $runId, $operador, $entorno, ['corregidos' => $corregidos, 'omitidos' => count($omitidos), 'errores' => count($errores)]);
        }

        return ['run_id' => $runId, 'corregidos' => $corregidos, 'omitidos_en_apply' => $omitidos, 'errores' => $errores];
    }

    /** ¿Existe alguna corrección de cotizaciones registrada bajo ese run_id? */
    public function existeCorrida(string $runId): bool
    {
        return ClienteRucCotizacionCorreccion::query()->where('run_id', $runId)->exists();
    }

    /**
     * @return array{existe: bool, revertidos: int, ya_revertidos: int, omitidos: list<array{quote_id: int, sub_client_id: int, motivo: string}>, errores: list<array{quote_id: int, sub_client_id: int, motivo: string}>}
     */
    public function revertir(string $runId, string $operador, string $entorno): array
    {
        $registros = ClienteRucCotizacionCorreccion::query()->where('run_id', $runId)->orderBy('id')->get();
        if ($registros->isEmpty()) {
            return ['existe' => false, 'revertidos' => 0, 'ya_revertidos' => 0, 'omitidos' => [], 'errores' => []];
        }

        $revertidos = 0;
        $ya         = 0;
        $omitidos   = [];
        $errores    = [];

        foreach ($registros as $r) {
            if ($r->revertida_at !== null) {
                $ya++;

                continue;
            }
            $ref = ['quote_id' => (int) $r->quote_id, 'sub_client_id' => (int) $r->sub_client_id];
            try {
                $res = DB::transaction(function () use ($r): string {
                    $quote = Quote::query()->withoutGlobalScopes()->lockForUpdate()->find($r->quote_id);
                    if ($quote === null) {
                        return 'cotizacion_inexistente';
                    }
                    // Solo se revierte lo que sigue siendo exactamente el valor aplicado.
                    if ($quote->ruc !== $r->ruc_nuevo) {
                        return 'editada_despues_de_la_correccion';
                    }

                    $quote->timestamps = false;
                    $quote->ruc        = $r->ruc_anterior;
                    $quote->save();

                    $rel = Quote::query()->withoutGlobalScopes()->find($r->quote_id);
                    if ($rel === null || $rel->ruc !== $r->ruc_anterior) {
                        throw new RuntimeException('Verificación fallida: lo releído no coincide con lo restaurado.');
                    }

                    $r->revertida_at = now();
                    $r->save();

                    return 'ok';
                });
            } catch (\Throwable $e) {
                $errores[] = $ref + ['motivo' => class_basename($e)];

                continue;
            }

            if ($res === 'ok') {
                $revertidos++;
            } else {
                $omitidos[] = $ref + ['motivo' => $res];
            }
        }

        $this->auditar('cotizaciones_revertir', $runId, $operador, $entorno, ['revertidos' => $revertidos, 'omitidos' => count($omitidos), 'errores' => count($errores)]);

        return ['existe' => true, 'revertidos' => $revertidos, 'ya_revertidos' => $ya, 'omitidos' => $omitidos, 'errores' => $errores];
    }

    /**
     * Huella corta (12 hex) de lo que `--apply --cotizaciones` hace: listado ordenado de `quote_id:ruc_actual:ruc_nuevo` de las filas a corregir.
     *
     * @param list<Fila> $plan
     */
    public function huella(array $plan): string
    {
        $lineas = [];
        foreach ($plan as $f) {
            if ($f['accion'] === self::ACCION_CORREGIR) {
                $lineas[] = $f['quote_id'].':'.$f['ruc_actual'].':'.$f['ruc_propuesto'];
            }
        }
        sort($lineas, SORT_STRING);

        return substr(hash('sha256', implode("\n", $lineas)), 0, 12);
    }

    /** @param array<string, int> $conteos */
    private function auditar(string $modo, string $runId, string $operador, string $entorno, array $conteos): void
    {
        AuditLog::query()->create([
            'user_id'     => null,
            'action'      => AuditService::LEGACY_FIX_CLIENTES_RUC,
            'entity_type' => 'legacy_clientes',
            'metadata'    => OperatorContext::describe($operador) + ['modo' => $modo, 'run_id' => $runId, 'entorno' => $entorno] + $conteos,
        ]);
    }
}
