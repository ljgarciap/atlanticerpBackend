<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SCRUM-1113 — reenlaza `legacy.clientes.sub_client_id` de los clientes que `ClienteFusionLoader` ya promovió a `sub_clients` y cuyo enlace se perdió
 * (causa: `ClienteMatcher` los re-evaluaba y los dejaba `unmatched`/`ambiguous`; ya corregido). Una sola vez por entorno; el sub_client existe, solo falta el enlace.
 *
 * Candidatos: `legacy.clientes` con `sub_client_id` nulo y `match_status` unmatched/ambiguous. Un candidato se reenlaza solo si su sub_client se identifica
 * SIN ambigüedad, en este orden de reglas (la fusión copió el CIF verbatim o generó `SIN-CIF-<icg_codcliente>`):
 *  1. CÓDIGO: `sub_clients.tax_id = 'SIN-CIF-' || icg_codcliente` (único por cliente).
 *  2. CIF: mismo CIF normalizado (`ClienteMatcher::normalizeCif`). Si el CIF lo comparten varios candidatos y/o varios sub_clients (la fusión creó un
 *     sub_client por cliente ICG aunque compartan CIF), se empareja por NOMBRE exacto (sin mayúsculas) y solo si el par es único en ambos lados.
 * Lo que no se identifica con certeza NO se toca: queda en `ambiguos` para revisión manual. Nunca se enlaza un sub_client que ya tiene otro cliente ICG enlazado
 * ni dos candidatos al mismo sub_client. El emparejamiento solo por nombre (sin CIF ni código) se reporta aparte y NUNCA se aplica.
 *
 * `plan()` solo lee. `aplicar()` actualiza en una transacción (`match_status = promoted`), guarda el valor anterior en el evento de auditoría (reversa) y verifica.
 *
 * @phpstan-type Par array{cliente_id: int, icg_codcliente: int, sub_client_id: int, regla: string, anterior_sub_client_id: int|null, anterior_match_status: string}
 */
final class ClientePromovidoRelinker
{
    /**
     * @return array{candidatos: int, pares: list<Par>, por_regla: array<string, int>, ambiguos: list<array{icg_codcliente: int, motivo: string}>, solo_por_nombre: list<array{icg_codcliente: int, sub_client_id: int}>}
     */
    public function plan(): array
    {
        /** @var list<Cliente> $candidatos */
        $candidatos = Cliente::query()
            ->whereNull('sub_client_id')
            ->whereIn('match_status', [Cliente::MATCH_UNMATCHED, Cliente::MATCH_AMBIGUOUS])
            ->orderBy('icg_codcliente')
            ->get()
            ->all();

        $yaEnlazados = array_fill_keys(
            Cliente::query()->whereNotNull('sub_client_id')->pluck('sub_client_id')->map(static fn (mixed $v): int => (int) $v)->all(),
            true,
        );

        $porTaxId = []; // tax_id exacto => list<SubClient id>
        $porCif   = []; // CIF normalizado => list<array{id, nombre}>
        foreach (SubClient::query()->select(['id', 'tax_id', 'business_name'])->cursor() as $sub) {
            if (isset($yaEnlazados[$sub->id])) {
                continue;
            }
            $porTaxId[$sub->tax_id][] = $sub->id;
            $cif = ClienteMatcher::normalizeCif($sub->tax_id);
            if ($cif !== null && ! str_starts_with($sub->tax_id, 'SIN-CIF-')) {
                $porCif[$cif][] = ['id' => $sub->id, 'nombre' => self::nombre($sub->business_name)];
            }
        }

        $pares      = [];
        $ambiguos   = [];
        $tomados    = []; // sub_client_id ya asignado a un candidato en este plan
        $pendientes = [];

        // Regla 1: código.
        foreach ($candidatos as $c) {
            $subs = $porTaxId['SIN-CIF-'.$c->icg_codcliente] ?? [];
            if (count($subs) === 1 && ! isset($tomados[$subs[0]])) {
                $pares[] = $this->par($c, $subs[0], 'codigo');
                $tomados[$subs[0]] = true;
                continue;
            }
            if (count($subs) > 1) {
                $ambiguos[] = ['icg_codcliente' => (int) $c->icg_codcliente, 'motivo' => 'varios sub_clients con el mismo SIN-CIF-'.$c->icg_codcliente];
                continue;
            }
            $pendientes[] = $c;
        }

        // Regla 2: CIF (y nombre cuando el CIF se repite).
        $candPorCif = [];
        foreach ($pendientes as $c) {
            $cif = ClienteMatcher::normalizeCif($c->cif);
            if ($cif !== null && isset($porCif[$cif])) {
                $candPorCif[$cif][] = $c;
            }
        }
        $restantes = [];
        foreach ($pendientes as $c) {
            $cif = ClienteMatcher::normalizeCif($c->cif);
            if ($cif === null || ! isset($candPorCif[$cif])) {
                $restantes[] = $c;
            }
        }
        foreach ($candPorCif as $cif => $grupo) {
            $subs = array_values(array_filter($porCif[$cif], static fn (array $s): bool => ! isset($tomados[$s['id']])));
            if (count($grupo) === 1 && count($subs) === 1) {
                $pares[] = $this->par($grupo[0], $subs[0]['id'], 'cif');
                $tomados[$subs[0]['id']] = true;
                continue;
            }
            foreach ($grupo as $c) {
                $nombre = self::nombre($c->nombre_comercial ?: $c->razon_social);
                $mismosC = array_filter($grupo, static fn (Cliente $o): bool => self::nombre($o->nombre_comercial ?: $o->razon_social) === $nombre);
                $mismosS = array_values(array_filter($subs, static fn (array $s): bool => $s['nombre'] === $nombre));
                if (count($mismosC) === 1 && count($mismosS) === 1 && ! isset($tomados[$mismosS[0]['id']])) {
                    $pares[] = $this->par($c, $mismosS[0]['id'], 'cif+nombre');
                    $tomados[$mismosS[0]['id']] = true;
                } else {
                    $ambiguos[] = ['icg_codcliente' => (int) $c->icg_codcliente, 'motivo' => "CIF {$cif} compartido y el nombre no identifica un único par (".count($grupo).' clientes, '.count($subs).' sub_clients)'];
                }
            }
        }

        // Solo por nombre: se reporta, no se aplica.
        $porNombre = [];
        foreach (SubClient::query()->select(['id', 'business_name'])->cursor() as $sub) {
            if (! isset($yaEnlazados[$sub->id]) && ! isset($tomados[$sub->id])) {
                $porNombre[self::nombre($sub->business_name)][] = $sub->id;
            }
        }
        $soloNombre = [];
        foreach ($restantes as $c) {
            $subs = $porNombre[self::nombre($c->nombre_comercial ?: $c->razon_social)] ?? [];
            if (count($subs) === 1) {
                $soloNombre[] = ['icg_codcliente' => (int) $c->icg_codcliente, 'sub_client_id' => $subs[0]];
            }
        }

        $porRegla = [];
        foreach ($pares as $p) {
            $porRegla[$p['regla']] = ($porRegla[$p['regla']] ?? 0) + 1;
        }

        return [
            'candidatos'      => count($candidatos),
            'pares'           => $pares,
            'por_regla'       => $porRegla,
            'ambiguos'        => $ambiguos,
            'solo_por_nombre' => $soloNombre,
        ];
    }

    /**
     * @param array{candidatos: int, pares: list<Par>, por_regla: array<string, int>, ambiguos: list<array{icg_codcliente: int, motivo: string}>, solo_por_nombre: list<array{icg_codcliente: int, sub_client_id: int}>} $plan
     * @return array{reenlazados: int, por_regla: array<string, int>}
     */
    public function aplicar(array $plan, string $operador, string $entorno): array
    {
        return DB::transaction(function () use ($plan, $operador, $entorno): array {
            // Se recalcula bajo la transacción: si cambió el estado desde el dry-run, aborta.
            $actual = $this->plan();
            if ($this->huella($actual) !== $this->huella($plan)) {
                throw new RuntimeException('El estado de legacy.clientes cambió desde el dry-run (huella distinta): repite el dry-run y vuelve a confirmar.');
            }

            $tabla = (new Cliente())->getTable();
            foreach ($plan['pares'] as $p) {
                $n = DB::affectingStatement(
                    "UPDATE {$tabla} SET sub_client_id = ?, match_status = ?, updated_at = now() WHERE id = ? AND sub_client_id IS NULL",
                    [$p['sub_client_id'], Cliente::MATCH_PROMOTED, $p['cliente_id']],
                );
                if ($n !== 1) {
                    throw new RuntimeException("No se pudo reenlazar el cliente {$p['cliente_id']} (filas afectadas: {$n}).");
                }
            }

            // Verificación: cada par quedó enlazado y ningún sub_client del plan lo comparten dos clientes ICG.
            $ids = array_map(static fn (array $p): int => $p['cliente_id'], $plan['pares']);
            if ($ids !== []) {
                $ok = Cliente::query()->whereIn('id', $ids)->where('match_status', Cliente::MATCH_PROMOTED)->whereNotNull('sub_client_id')->count();
                if ($ok !== count($ids)) {
                    throw new RuntimeException("Verificación fallida: {$ok} de ".count($ids).' clientes quedaron enlazados.');
                }
                $dup = DB::selectOne(
                    "SELECT count(*) AS n FROM (SELECT sub_client_id FROM {$tabla} WHERE sub_client_id = ANY (?::bigint[]) GROUP BY sub_client_id HAVING count(*) > 1) d",
                    ['{'.implode(',', array_map(static fn (array $p): int => $p['sub_client_id'], $plan['pares'])).'}'],
                );
                if ((int) ($dup->n ?? 0) !== 0) {
                    throw new RuntimeException('Verificación fallida: un sub_client quedó enlazado a más de un cliente ICG.');
                }
            }

            AuditLog::query()->create([
                'user_id'     => null,
                'action'      => AuditService::LEGACY_RELINK_CLIENTES_PROMOVIDOS,
                'entity_type' => 'legacy_clientes',
                'metadata'    => [
                    'operador'   => $operador,
                    'entorno'    => $entorno,
                    'usuario_so' => get_current_user(),
                    'host'       => (string) gethostname(),
                    'candidatos' => $plan['candidatos'],
                    'reenlazados' => count($plan['pares']),
                    'por_regla'  => $plan['por_regla'],
                    'ambiguos'   => count($plan['ambiguos']),
                    // Reversa: valor anterior de cada fila tocada.
                    'anteriores' => array_map(static fn (array $p): array => [$p['cliente_id'], $p['anterior_sub_client_id'], $p['anterior_match_status']], $plan['pares']),
                ],
            ]);

            return ['reenlazados' => count($plan['pares']), 'por_regla' => $plan['por_regla']];
        });
    }

    /**
     * @param array{candidatos: int, pares: list<Par>, por_regla: array<string, int>, ambiguos: list<array{icg_codcliente: int, motivo: string}>, solo_por_nombre: list<array{icg_codcliente: int, sub_client_id: int}>} $plan
     */
    public function huella(array $plan): string
    {
        return hash('sha256', (string) json_encode([$plan['candidatos'], $plan['pares'], $plan['ambiguos']], JSON_UNESCAPED_UNICODE));
    }

    /** @return Par */
    private function par(Cliente $c, int $subClientId, string $regla): array
    {
        return [
            'cliente_id'             => (int) $c->id,
            'icg_codcliente'         => (int) $c->icg_codcliente,
            'sub_client_id'          => $subClientId,
            'regla'                  => $regla,
            'anterior_sub_client_id' => $c->sub_client_id === null ? null : (int) $c->sub_client_id,
            'anterior_match_status'  => (string) $c->match_status,
        ];
    }

    private static function nombre(?string $n): string
    {
        return mb_strtoupper(trim((string) $n));
    }
}
