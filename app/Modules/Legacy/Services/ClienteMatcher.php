<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\Cliente;
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
 *   1. CIF normalizado (trim, mayúsculas, sin puntos/guiones) == `sub_clients.tax_id` normalizado.
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
     * @return array{matched: int, unmatched: int, ambiguous: int, errores: int, details: list<array{icg_codcliente: int, cif: ?string, email: ?string, match_status: string, motivo: ?string}>}
     */
    public function match(array $icgCodclientes): array
    {
        if ($icgCodclientes === []) {
            return ['matched' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'errores' => 0, 'details' => []];
        }

        return DB::transaction(function () use ($icgCodclientes): array {
            $clientes   = Cliente::query()->whereIn('icg_codcliente', $icgCodclientes)->get();
            $cifIndex   = $this->buildCifIndex();
            $emailIndex = $this->buildEmailIndex();

            $matched = 0;
            $unmatched = 0;
            $ambiguous = 0;
            $errores = 0;
            $details = [];

            foreach ($clientes as $cliente) {
                [$status, $subClientId, $motivo] = $this->resolveOne($cliente, $cifIndex, $emailIndex);

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
                ];
            }

            return compact('matched', 'unmatched', 'ambiguous', 'errores', 'details');
        });
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
     * @return array{0: string, 1: ?int, 2: ?string}
     */
    private function resolveOne(Cliente $cliente, array $cifIndex, array $emailIndex): array
    {
        $cifKey = self::normalizeCif($cliente->cif);
        if ($cifKey !== null && isset($cifIndex[$cifKey])) {
            $ids = array_values(array_unique($cifIndex[$cifKey]));
            if (count($ids) === 1) {
                return [Cliente::MATCH_MATCHED, $ids[0], null];
            }

            return [Cliente::MATCH_AMBIGUOUS, null, sprintf('CIF "%s" matchea %d sub_clients distintos', $cliente->cif, count($ids))];
        }

        $emailKey = self::normalizeEmail($cliente->email);
        if ($emailKey !== null && isset($emailIndex[$emailKey])) {
            $ids = array_values(array_unique($emailIndex[$emailKey]));
            if (count($ids) === 1) {
                return [Cliente::MATCH_MATCHED, $ids[0], null];
            }

            return [Cliente::MATCH_AMBIGUOUS, null, sprintf('email "%s" matchea %d sub_clients distintos (vía contacto)', $cliente->email, count($ids))];
        }

        $motivo = match (true) {
            $cliente->cif === null && $cliente->email === null => 'sin CIF ni email en ICG',
            default => 'sin match por CIF ni email contra sub_clients/sub_client_contacts',
        };

        return [Cliente::MATCH_UNMATCHED, null, $motivo];
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
