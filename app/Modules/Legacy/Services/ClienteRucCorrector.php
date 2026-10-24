<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\ClienteRucCorreccion;
use App\Modules\Legacy\Models\ClienteRucCotizacionCorreccion;
use App\Modules\Legacy\Support\OperatorContext;
use App\Modules\Legacy\Support\TaxIdNormalization;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\SubClientTaxDataService;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SCRUM-1137 F3 — corrige `ventas_diseno.sub_clients.tax_id` (y `tax_id_dv`) de los clientes de ICG ya promovidos/enlazados cuyo RUC quedó truncado a 12
 * caracteres (el `CIF` de ICG) cuando el RUC completo vive en `NIF20` (`legacy.clientes.ruc`). Toca datos vivos, por eso las precondiciones son estrictas
 * y nunca se pisa un valor que no las cumpla:
 *
 *  (a) el `tax_id` actual del sub_client, normalizado (`ClienteMatcher::normalizeCif`), es igual al CIF normalizado del cliente de ICG: así se detecta que
 *      NO fue editado a mano ni es otro RUC;
 *  (b) el RUC completo normalizado EMPIEZA con ese CIF y es más largo (una extensión, no otro valor);
 *  (c) el sub_client está enlazado a UN solo cliente de ICG (o a varios con el mismo RUC): si dos clientes comparten sub_client con RUC distintos, se omite.
 *
 * Además: si el nuevo `tax_id` ya lo tiene OTRO sub_client (o dos propuestas apuntan al mismo valor) es un conflicto y se omite (no se crean duplicados).
 * El DV solo se escribe si ICG lo trajo (`ruc_dv`) y el `tax_id_dv` actual está vacío; nunca se inventa ni se pisa.
 *
 * Aplica con `SubClient::save()` (dispara `LogsAuditTrail`; prohibido el update masivo sobre `sub_clients`), una transacción por sub_client, y deja el registro
 * de reversa en `legacy.cliente_ruc_correcciones`. `plan()` solo lee.
 *
 * @phpstan-type Fila array{icg_codcliente: int, sub_client_id: ?int, tax_id_actual: ?string, tax_id_propuesto: ?string, dv_actual: ?string, dv_propuesto: ?string, accion: string, motivo: string}
 */
final class ClienteRucCorrector
{
    public const ACCION_CORREGIR = 'corregir';
    public const ACCION_OMITIR   = 'omitir';

    /** Ancho de `CLIENTES.CIF` en ICG (nvarchar(12)): solo un CIF de exactamente este largo puede estar truncado. */
    public const CIF_ANCHO_ICG = 12;

    /** Solo para pruebas: se invoca dentro de la transacción de cada sub_client justo después del save() y antes de verificar. Nunca se asigna en producción. */
    public static ?\Closure $despuesDeGuardar = null;

    /**
     * SOLO LECTURA.
     *
     * @return list<Fila>
     */
    public function plan(): array
    {
        $clientes = Cliente::query()
            ->whereNotNull('sub_client_id')
            ->whereIn('match_status', [Cliente::MATCH_MATCHED, Cliente::MATCH_PROMOTED])
            ->where(function ($q): void {
                $q->whereIn('ruc_estado', [TaxIdNormalization::ESTADO_REVISAR, TaxIdNormalization::ESTADO_TRUNCADO_POSIBLE])
                    ->orWhere(function ($q2): void {
                        $q2->where('ruc_estado', TaxIdNormalization::ESTADO_OK)
                            ->where('ruc_origen', TaxIdNormalization::ORIGEN_NIF20)
                            ->whereNotNull('ruc');
                    });
            })
            ->orderBy('icg_codcliente')
            ->get();

        if ($clientes->isEmpty()) {
            return [];
        }

        $subIds = $clientes->pluck('sub_client_id')->unique()->values()->all();

        // (c) todos los clientes de ICG (cualquier estado) enlazados a esos sub_clients.
        /** @var array<int, array<string, true>> $rucsPorSub  sub_client_id => conjunto de RUC normalizados distintos */
        $rucsPorSub = [];
        foreach (Cliente::query()->whereIn('sub_client_id', $subIds)->get(['id', 'sub_client_id', 'ruc', 'ruc_estado']) as $c) {
            $sid = (int) $c->sub_client_id;
            $rucsPorSub[$sid][(string) ClienteMatcher::normalizeCif($c->ruc)] = true;
        }

        // Documentos fiscales (facturas/NC certificado o pendiente): el receptor se lee en vivo de sub_clients, no se puede cambiar su RUC/DV.
        $bloqueados = [];
        foreach (array_chunk($subIds, 1000) as $lote) {
            foreach ((new SubClientTaxDataService())->conDocumentosFiscales(array_map('intval', $lote)) as $id) {
                $bloqueados[$id] = true;
            }
        }

        /** @var array<int, SubClient> $subs */
        $subs = SubClient::query()->whereIn('id', $subIds)->get()->keyBy('id')->all();

        // Índice de tax_id normalizados de TODOS los sub_clients, para detectar conflictos.
        /** @var array<string, list<int>> $indice */
        $indice = [];
        foreach (SubClient::query()->select(['id', 'tax_id'])->cursor() as $s) {
            $k = ClienteMatcher::normalizeCif($s->tax_id);
            if ($k !== null) {
                $indice[$k][] = (int) $s->id;
            }
        }

        $filas = [];
        $tratados = [];          // sub_client_id => true (ya hay una fila 'corregir' o 'omitir' principal)
        $propuestas = [];        // ruc normalizado => sub_client_id => true (propuestas 'corregir')

        foreach ($clientes as $c) {
            $sid = (int) $c->sub_client_id;
            $sub = $subs[$sid] ?? null;

            $base = [
                'icg_codcliente'   => (int) $c->icg_codcliente,
                'sub_client_id'    => $sid,
                'tax_id_actual'    => $sub?->tax_id,
                'tax_id_propuesto' => $c->ruc,
                'dv_actual'        => $sub?->tax_id_dv,
                'dv_propuesto'     => $c->ruc_dv,
            ];

            if ($c->ruc_estado !== TaxIdNormalization::ESTADO_OK) {
                $filas[] = $base + ['accion' => self::ACCION_OMITIR, 'motivo' => 'ruc_estado_'.$c->ruc_estado];

                continue;
            }
            if ($sub === null) {
                $filas[] = $base + ['accion' => self::ACCION_OMITIR, 'motivo' => 'sub_client_inexistente'];

                continue;
            }
            if (count($rucsPorSub[$sid] ?? []) > 1) {
                $filas[] = $base + ['accion' => self::ACCION_OMITIR, 'motivo' => 'sub_client_compartido_con_ruc_distinto'];

                continue;
            }
            if (isset($tratados[$sid])) {
                $filas[] = $base + ['accion' => self::ACCION_OMITIR, 'motivo' => 'sub_client_ya_tratado_por_otro_cliente_icg'];

                continue;
            }
            $tratados[$sid] = true;

            $rucN    = ClienteMatcher::normalizeCif($c->ruc);
            $cifN    = ClienteMatcher::normalizeCif($c->cif);
            $actualN = ClienteMatcher::normalizeCif($sub->tax_id);

            if ($rucN === null) {
                $filas[] = $base + ['accion' => self::ACCION_OMITIR, 'motivo' => 'sin_ruc'];

                continue;
            }
            if ($actualN === $rucN) {
                $filas[] = $base + ['accion' => self::ACCION_OMITIR, 'motivo' => 'ya_igual_al_ruc_completo'];

                continue;
            }
            if ($cifN === null || $actualN !== $cifN) {
                $filas[] = $base + ['accion' => self::ACCION_OMITIR, 'motivo' => $cifN === null ? 'sin_cif_en_icg' : 'tax_id_distinto_del_cif'];

                continue;
            }
            if (strlen(trim((string) $c->cif)) !== self::CIF_ANCHO_ICG) {
                $filas[] = $base + ['accion' => self::ACCION_OMITIR, 'motivo' => 'cif_no_truncado'];

                continue;
            }
            if (! str_starts_with($rucN, $cifN) || strlen($rucN) <= strlen($cifN)) {
                $filas[] = $base + ['accion' => self::ACCION_OMITIR, 'motivo' => 'ruc_no_extiende_al_cif'];

                continue;
            }
            if (isset($bloqueados[$sid])) {
                $filas[] = $base + ['accion' => self::ACCION_OMITIR, 'motivo' => 'tiene_documentos_fiscales'];

                continue;
            }
            $otros = array_values(array_filter($indice[$rucN] ?? [], static fn (int $id): bool => $id !== $sid));
            if ($otros !== []) {
                $filas[] = $base + ['accion' => self::ACCION_OMITIR, 'motivo' => 'conflicto_otro_sub_client_tiene_ese_tax_id'];

                continue;
            }

            $dvActualVacio = trim((string) $sub->tax_id_dv) === '';
            $dvIcg         = trim((string) $c->ruc_dv);
            if (! $dvActualVacio && $dvIcg !== '' && trim((string) $sub->tax_id_dv) !== $dvIcg) {
                $filas[] = $base + ['accion' => self::ACCION_OMITIR, 'motivo' => 'dv_distinto'];

                continue;
            }
            $dvNuevo       = ($dvIcg !== '' && $dvActualVacio) ? $dvIcg : $sub->tax_id_dv;

            $filas[] = array_merge($base, ['dv_propuesto' => $dvNuevo, 'accion' => self::ACCION_CORREGIR, 'motivo' => 'ok']);
            $propuestas[$rucN][$sid] = true;
        }

        // Dos sub_clients distintos que terminarían con el mismo tax_id: se omiten todos (crearía un duplicado).
        $enConflicto = [];
        foreach ($propuestas as $subs2) {
            if (count($subs2) > 1) {
                $enConflicto += $subs2;
            }
        }
        if ($enConflicto !== []) {
            $filas = array_map(static fn (array $f): array => ($f['accion'] === self::ACCION_CORREGIR && isset($enConflicto[$f['sub_client_id']]))
                ? array_merge($f, ['accion' => self::ACCION_OMITIR, 'motivo' => 'conflicto_otra_propuesta_con_el_mismo_tax_id'])
                : $f, $filas);
        }

        return $filas;
    }

    /**
     * @param list<Fila> $plan
     * @return array{run_id: string, corregidos: int, omitidos_en_apply: list<array{icg_codcliente: int, sub_client_id: int, motivo: string}>, errores: list<array{icg_codcliente: int, sub_client_id: int, motivo: string}>}
     */
    public function aplicar(array $plan, string $operador, string $entorno, string $runId): array
    {
        $corregidos = 0;
        $omitidos   = [];
        $errores    = [];

        try {
            foreach ($plan as $f) {
                if ($f['accion'] !== self::ACCION_CORREGIR) {
                    continue;
                }
                $sid = (int) $f['sub_client_id'];
                try {
                    $ok = DB::transaction(function () use ($f, $sid, $runId, $operador): bool {
                        /** @var SubClient|null $sub */
                        $sub = SubClient::query()->lockForUpdate()->find($sid);
                        // Idempotencia / cambio concurrente: el tax_id tiene que seguir siendo exactamente el planeado.
                        if ($sub === null || $sub->tax_id !== $f['tax_id_actual'] || (string) $sub->tax_id_dv !== (string) $f['dv_actual']) {
                            return false;
                        }
                        if ($this->otroSubClientTiene((string) $f['tax_id_propuesto'], $sid)) {
                            return false;
                        }
                        if ((new SubClientTaxDataService())->conDocumentosFiscales([$sid]) !== []) {
                            return false;
                        }

                        $sub->tax_id    = (string) $f['tax_id_propuesto'];
                        $sub->tax_id_dv = $f['dv_propuesto'];
                        $sub->save();

                        if (self::$despuesDeGuardar !== null) {
                            (self::$despuesDeGuardar)($sub);
                        }

                        ClienteRucCorreccion::query()->create([
                            'run_id'             => $runId,
                            'sub_client_id'      => $sid,
                            'icg_codcliente'     => $f['icg_codcliente'],
                            'tax_id_anterior'    => (string) $f['tax_id_actual'],
                            'tax_id_dv_anterior' => $f['dv_actual'],
                            'tax_id_nuevo'       => (string) $f['tax_id_propuesto'],
                            'tax_id_dv_nuevo'    => $f['dv_propuesto'],
                            'operador'           => $operador,
                            'aplicada_at'        => now(),
                        ]);

                        // Verificación releyendo de la BD.
                        $rel = SubClient::query()->find($sid);
                        if ($rel === null || $rel->tax_id !== $f['tax_id_propuesto'] || (string) $rel->tax_id_dv !== (string) $f['dv_propuesto']) {
                            throw new RuntimeException('Verificación fallida: lo releído no coincide con lo aplicado.');
                        }

                        return true;
                    });
                } catch (\Throwable $e) {
                    $errores[] = ['icg_codcliente' => $f['icg_codcliente'], 'sub_client_id' => $sid, 'motivo' => class_basename($e)];

                    continue;
                }

                if ($ok) {
                    $corregidos++;
                } else {
                    $omitidos[] = ['icg_codcliente' => $f['icg_codcliente'], 'sub_client_id' => $sid, 'motivo' => 'cambio_desde_el_plan'];
                }
            }
        } finally {
            $this->auditar('aplicar', $runId, $operador, $entorno, ['corregidos' => $corregidos, 'omitidos' => count($omitidos), 'errores' => count($errores)]);
        }


        return ['run_id' => $runId, 'corregidos' => $corregidos, 'omitidos_en_apply' => $omitidos, 'errores' => $errores];
    }

    /**
     * @return array{existe: bool, revertidos: int, ya_revertidos: int, omitidos: list<array{icg_codcliente: int, sub_client_id: int, motivo: string}>, errores: list<array{icg_codcliente: int, sub_client_id: int, motivo: string}>}
     */
    public function revertir(string $runId, string $operador, string $entorno): array
    {
        $registros = ClienteRucCorreccion::query()->where('run_id', $runId)->orderBy('id')->get();
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
            $ref = ['icg_codcliente' => (int) $r->icg_codcliente, 'sub_client_id' => (int) $r->sub_client_id];
            try {
                $res = DB::transaction(function () use ($r): string {
                    $sub = SubClient::query()->lockForUpdate()->find($r->sub_client_id);
                    if ($sub === null) {
                        return 'sub_client_inexistente';
                    }
                    if ((new SubClientTaxDataService())->conDocumentosFiscales([(int) $r->sub_client_id]) !== []) {
                        return 'tiene_documentos_fiscales';
                    }
                    // Las cotizaciones ajustadas a este RUC (`--cotizaciones`) se revierten primero: si no, quedarían con el RUC completo y su cliente con el truncado.
                    if (ClienteRucCotizacionCorreccion::query()->where('sub_client_id', $r->sub_client_id)->whereNull('revertida_at')->exists()) {
                        return 'cotizaciones_sin_revertir';
                    }
                    // Solo se revierte lo que sigue siendo exactamente el valor aplicado.
                    if ($sub->tax_id !== $r->tax_id_nuevo || (string) $sub->tax_id_dv !== (string) $r->tax_id_dv_nuevo) {
                        return 'editado_despues_de_la_correccion';
                    }

                    $sub->tax_id    = $r->tax_id_anterior;
                    $sub->tax_id_dv = $r->tax_id_dv_anterior;
                    $sub->save();

                    $rel = SubClient::query()->find($r->sub_client_id);
                    if ($rel === null || $rel->tax_id !== $r->tax_id_anterior || (string) $rel->tax_id_dv !== (string) $r->tax_id_dv_anterior) {
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

        $this->auditar('revertir', $runId, $operador, $entorno, ['revertidos' => $revertidos, 'omitidos' => count($omitidos), 'errores' => count($errores)]);

        return ['existe' => true, 'revertidos' => $revertidos, 'ya_revertidos' => $ya, 'omitidos' => $omitidos, 'errores' => $errores];
    }

    /**
     * Huella corta (12 hex) de lo que `--apply` hace: listado ordenado de `sub_client_id:tax_id_actual:dv_actual:tax_id_nuevo:dv_nuevo` de las filas a corregir (incluye los valores actuales, que son los que guarda la reversa).
     *
     * @param list<Fila> $plan
     */
    public function huella(array $plan): string
    {
        $lineas = [];
        foreach ($plan as $f) {
            if ($f['accion'] === self::ACCION_CORREGIR) {
                $lineas[] = $f['sub_client_id'].':'.$f['tax_id_actual'].':'.$f['dv_actual'].':'.$f['tax_id_propuesto'].':'.$f['dv_propuesto'];
            }
        }
        sort($lineas, SORT_STRING);

        return substr(hash('sha256', implode("\n", $lineas)), 0, 12);
    }

    private function otroSubClientTiene(string $taxId, int $exceptoId): bool
    {
        $tabla = (new SubClient())->getTable();
        $norm  = ClienteMatcher::normalizeCif($taxId);

        return DB::selectOne(
            "SELECT 1 AS x FROM {$tabla} WHERE id <> ? AND upper(replace(replace(trim(tax_id), '.', ''), '-', '')) = ? LIMIT 1",
            [$exceptoId, $norm],
        ) !== null;
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
