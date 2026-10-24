<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Support\TaxIdNormalization;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Models\SubClientContact;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-755 — resuelve `legacy.clientes.sub_client_id`/`match_status` contra
 * `ventas_diseno.sub_clients`/`sub_client_contacts` ya vivos en AtlanticERP
 * (Illumination/migracion-icg-diseno.md §5). Solo LEE las tablas de destino — nunca escribe en
 * `ventas_diseno.*` (a diferencia del piloto de Catálogo de SCRUM-750, Clientes no tiene Loader-B,
 * ver runbook §12.1). El resultado se persiste en la propia `legacy.clientes`.
 *
 * Regla de matching (§5, tabla "Estrategia de vinculación"):
 *   1. SCRUM-1137: RUC completo (`legacy.clientes.ruc`, de NIF20; se ignora si `ruc_estado = revisar`) normalizado
 *      (trim, mayúsculas, sin puntos/guiones) == `sub_clients.tax_id` normalizado. Si no hay match, el CIF
 *      (truncado a 12 por ICG) normalizado, como antes: conserva los vínculos con `sub_clients` que ya guardan el
 *      RUC truncado. El campo `via` de los detalles dice cuál de los dos resolvió.
 *   2. Sin match por CIF → intento por email exacto (normalizado: trim, minúsculas) contra
 *      `sub_client_contacts.email` — el subcliente del contacto que matchea.
 *   3. Ninguno de los dos → `match_status = unmatched`. **Nunca se inventa un link.**
 *
 * Si CUALQUIERA de los dos intentos resuelve más de un `sub_client_id` distinto (CIF/email
 * duplicado entre subclientes), el resultado es `ambiguous` — `sub_client_id` queda `null`, nunca
 * se elige una opción al azar.
 *
 * Scopeado a la lista de `icg_codcliente` de LA CORRIDA ACTUAL (no a todo el historial de
 * `legacy.clientes`) — mismo criterio que `ProductoMapper::buildPayloads()`.
 */
final class ClienteMatcher
{
    /**
     * @param  list<int> $icgCodclientes
     * @return array{matched: int, unmatched: int, ambiguous: int, errores: int, details: list<array{icg_codcliente: int, cif: ?string, email: ?string, match_status: string, motivo: ?string, via: ?string}>}
     */
    public function match(array $icgCodclientes): array
    {
        if ($icgCodclientes === []) {
            return ['matched' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'errores' => 0, 'details' => []];
        }

        return DB::transaction(function () use ($icgCodclientes): array {
            // SCRUM-1113: un cliente `promoted` (sub_client creado por `ClienteFusionLoader`) NO se vuelve a resolver. Antes se re-evaluaba por CIF/email
            // y, al no tener CIF real (`SIN-CIF-<código>`) ni email en contactos, quedaba `unmatched` con `sub_client_id` nulo (o `ambiguous` si la fusión
            // creó dos sub_clients con el mismo CIF): el enlace se perdía en el primer sync que recorría todas las filas (bootstrap incremental de
            // septiembre, con `source_hash` nulo) y `legacy:fuse-clientes` los habría vuelto a promover duplicados.
            $clientes   = Cliente::query()
                ->whereIn('icg_codcliente', $icgCodclientes)
                ->where('match_status', '<>', Cliente::MATCH_PROMOTED)
                ->get();
            $cifIndex   = $this->buildCifIndex();
            $emailIndex = $this->buildEmailIndex();

            $matched = 0;
            $unmatched = 0;
            $ambiguous = 0;
            $errores = 0;
            $details = [];

            foreach ($clientes as $cliente) {
                [$status, $subClientId, $motivo, $via] = $this->resolveOne($cliente, $cifIndex, $emailIndex);

                try {
                    $cliente->match_status = $status;
                    $cliente->sub_client_id = $subClientId;
                    $cliente->save();
                } catch (Throwable $e) {
                    $errores++;
                    $details[] = [
                        'icg_codcliente' => $cliente->icg_codcliente,
                        'cif'            => $cliente->cif,
                        'email'          => $cliente->email,
                        'match_status'   => 'error',
                        'motivo'         => $e->getMessage(),
                        'via'            => null,
                    ];

                    continue;
                }

                match ($status) {
                    Cliente::MATCH_MATCHED   => $matched++,
                    Cliente::MATCH_AMBIGUOUS => $ambiguous++,
                    default                  => $unmatched++,
                };

                $details[] = [
                    'icg_codcliente' => $cliente->icg_codcliente,
                    'cif'            => $cliente->cif,
                    'email'          => $cliente->email,
                    'match_status'   => $status,
                    'motivo'         => $motivo,
                    'via'            => $via,
                ];
            }

            return compact('matched', 'unmatched', 'ambiguous', 'errores', 'details');
        });
    }

    /**
     * SCRUM-1137 — informe previo, SOLO LECTURA: qué le pasaría a cada cliente no promovido de `legacy.clientes` con la regla
     * actual (RUC completo, luego CIF, luego email) frente a su `match_status`/`sub_client_id` guardados. No escribe nada.
     *
     * @return array{total: int, transiciones: array<string, int>, via: array<string, int>, cambian_de_sub_client: int, conflictos_ruc_cif: int, muestra: list<array{icg_codcliente: int, antes: string, despues: string, via: ?string, sub_client_antes: ?int, sub_client_despues: ?int}>}
     */
    public function preview(int $maxMuestra = 20): array
    {
        $cifIndex   = $this->buildCifIndex();
        $emailIndex = $this->buildEmailIndex();

        $total = 0;
        $transiciones = [];
        $via = [];
        $cambianDeSubClient = 0;
        $conflictos = 0;
        $muestra = [];

        foreach (Cliente::query()->where('match_status', '<>', Cliente::MATCH_PROMOTED)->orderBy('icg_codcliente')->cursor() as $cliente) {
            $total++;
            if (self::rucContradiceAlCif($cliente)) {
                $conflictos++;
            }
            [$status, $subClientId, , $how] = $this->resolveOne($cliente, $cifIndex, $emailIndex);

            $clave = "{$cliente->match_status} -> {$status}";
            $transiciones[$clave] = ($transiciones[$clave] ?? 0) + 1;
            $via[$how ?? 'ninguna'] = ($via[$how ?? 'ninguna'] ?? 0) + 1;

            $cambia = $status !== $cliente->match_status || $subClientId !== $cliente->sub_client_id;
            if ($cambia && $cliente->sub_client_id !== null && $subClientId !== $cliente->sub_client_id) {
                $cambianDeSubClient++;
            }
            if ($cambia && count($muestra) < $maxMuestra) {
                $muestra[] = [
                    'icg_codcliente'     => (int) $cliente->icg_codcliente,
                    'antes'              => (string) $cliente->match_status,
                    'despues'            => $status,
                    'via'                => $how,
                    'sub_client_antes'   => $cliente->sub_client_id,
                    'sub_client_despues' => $subClientId,
                ];
            }
        }
        ksort($transiciones);
        ksort($via);

        return ['total' => $total, 'transiciones' => $transiciones, 'via' => $via, 'cambian_de_sub_client' => $cambianDeSubClient, 'conflictos_ruc_cif' => $conflictos, 'muestra' => $muestra];
    }

    /** @return array<string, list<int>> CIF normalizado => lista de sub_client_id */
    private function buildCifIndex(): array
    {
        $index = [];
        foreach (SubClient::query()->select(['id', 'tax_id'])->cursor() as $subClient) {
            $key = self::normalizeCif($subClient->tax_id);
            if ($key === null) {
                continue;
            }

            $index[$key][] = $subClient->id;
        }

        return $index;
    }

    /** @return array<string, list<int>> email normalizado => lista de sub_client_id (vía contacto) */
    private function buildEmailIndex(): array
    {
        $index = [];
        foreach (SubClientContact::query()->select(['sub_client_id', 'email'])->whereNotNull('email')->cursor() as $contact) {
            $key = self::normalizeEmail($contact->email);
            if ($key === null) {
                continue;
            }

            $index[$key][] = $contact->sub_client_id;
        }

        return $index;
    }

    /**
     * @param array<string, list<int>> $cifIndex
     * @param array<string, list<int>> $emailIndex
     * @return array{0: string, 1: ?int, 2: ?string, 3: ?string} [status, sub_client_id, motivo, via]
     */
    private function resolveOne(Cliente $cliente, array $cifIndex, array $emailIndex): array
    {
        // 1) RUC completo, 2) CIF (truncado) — el primero que exista en el índice decide.
        foreach (self::taxKeys($cliente) as $via => $key) {
            if (! isset($cifIndex[$key])) {
                continue;
            }

            $ids = array_values(array_unique($cifIndex[$key]));
            if (count($ids) === 1) {
                return [Cliente::MATCH_MATCHED, $ids[0], null, $via];
            }

            return [Cliente::MATCH_AMBIGUOUS, null, sprintf('%s matchea %d sub_clients distintos', strtoupper($via), count($ids)), $via];
        }

        $emailKey = self::normalizeEmail($cliente->email);
        if ($emailKey !== null && isset($emailIndex[$emailKey])) {
            $ids = array_values(array_unique($emailIndex[$emailKey]));
            if (count($ids) === 1) {
                return [Cliente::MATCH_MATCHED, $ids[0], null, 'email'];
            }

            return [Cliente::MATCH_AMBIGUOUS, null, sprintf('email "%s" matchea %d sub_clients distintos (vía contacto)', $cliente->email, count($ids)), 'email'];
        }

        $motivo = match (true) {
            $cliente->cif === null && $cliente->nif20 === null && $cliente->email === null => 'sin CIF ni email en ICG',
            default => 'sin match por RUC, CIF ni email contra sub_clients/sub_client_contacts',
        };

        return [Cliente::MATCH_UNMATCHED, null, $motivo, null];
    }

    /**
     * Claves de búsqueda de un cliente, en orden de prioridad: `ruc` (completo, salvo que la normalización lo
     * marcó `revisar`) y `cif`. Sin duplicados.
     *
     * @return array<string, string> via => clave normalizada
     */
    private static function taxKeys(Cliente $cliente): array
    {
        $keys = [];
        if (self::rucUsable($cliente)) {
            $keys['ruc'] = (string) self::normalizeCif($cliente->ruc);
        }

        $cif = self::normalizeCif($cliente->cif);
        if ($cif !== null && $cif !== ($keys['ruc'] ?? null)) {
            $keys['cif'] = $cif;
        }

        return $keys;
    }

    /**
     * El RUC completo (de NIF20) solo es confiable si no está marcado `revisar` y NO contradice al CIF: o no hay CIF, o el RUC
     * es una extensión del CIF (empieza con él). Si ambos existen y no se relacionan (en dev, clientes cuyo NIF20 traía el RUC
     * de otra empresa), se descarta el RUC y se conserva el comportamiento anterior por CIF.
     *
     * Límite conocido: aquí no se exige que el CIF tenga el ancho de la columna de ICG (12, `ClienteRucCorrector::CIF_ANCHO_ICG`); solo el corrector
     * de sub_clients lo exige, porque es el que escribe. El comportamiento del Matcher no se cambia (verificado en dev con 0 cambios).
     */
    public static function rucUsable(Cliente $cliente): bool
    {
        if ($cliente->ruc_estado === TaxIdNormalization::ESTADO_REVISAR) {
            return false;
        }

        $ruc = self::normalizeCif($cliente->ruc);
        if ($ruc === null) {
            return false;
        }

        $cif = self::normalizeCif($cliente->cif);

        return $cif === null || str_starts_with($ruc, $cif);
    }

    /** true si el cliente trae CIF y RUC no vacíos que se contradicen (el RUC no empieza con el CIF). */
    public static function rucContradiceAlCif(Cliente $cliente): bool
    {
        $ruc = self::normalizeCif($cliente->ruc);
        $cif = self::normalizeCif($cliente->cif);

        return $ruc !== null && $cif !== null && $cliente->ruc_estado !== TaxIdNormalization::ESTADO_REVISAR && ! str_starts_with($ruc, $cif);
    }

    public static function normalizeCif(?string $cif): ?string
    {
        if ($cif === null) {
            return null;
        }

        $normalized = strtoupper(str_replace(['.', '-'], '', trim($cif)));

        return $normalized === '' ? null : $normalized;
    }

    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = strtolower(trim($email));

        return $normalized === '' ? null : $normalized;
    }
}
