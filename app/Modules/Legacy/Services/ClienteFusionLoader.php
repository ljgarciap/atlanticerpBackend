<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use Illuminate\Support\Facades\DB;

/**
 * Épica SCRUM-749 (Migración ICG→AtlanticERP) — "Loader-B" de Clientes, decisión explícita de Luis
 * 2026-08-21: reabre la decisión original de `legacy.clientes` (sin fusión, ver
 * `Illumination/migracion-icg-diseno.md` §5) — clientes recurrentes se convierten en `sub_clients`
 * reales de AtlanticERP, no solo referencia de solo lectura.
 *
 * Alcance (decidido con Luis, ver hilo de la sesión 2026-08-21):
 *  - Candidato: `legacy.clientes` con `sub_client_id IS NULL` (nunca toca uno ya vinculado — ni
 *    matched por CIF/email por `ClienteMatcher`, ni ya promovido por este mismo Loader en una
 *    corrida anterior — idempotencia real, no solo declarada) Y `match_status IN ('unmatched',
 *    'ambiguous')` Y con AL MENOS 1 transacción real (`legacy.cotizaciones` o `legacy.pedidos` con
 *    `legacy_cliente_id` = este cliente) — umbral ≥1, Luis lo prefirió sobre alternativas más
 *    estrictas (≥3/≥5) para no dejar afuera negocio real.
 *  - Sin CIF real: se genera un placeholder `SIN-CIF-{icg_codcliente}` para `sub_clients.tax_id`
 *    (NOT NULL en el schema) — 95.6% de los clientes sin CIF de ICG SÍ tienen transacciones reales
 *    (1.474 de 1.542), excluirlos hubiera cortado negocio real, decisión explícita de Luis.
 *  - Estructura: ICG es plano (sin concepto de empresa matriz) — 1 cliente ICG = 1 `master_client`
 *    + 1 `sub_client` nuevos, mismo nombre en ambos. Excepciones de agrupación bajo una misma
 *    matriz se resuelven ad-hoc si aparecen (decisión de Luis: "si encontramos excepciones las
 *    tratamos en su momento", no se diseña de antemano).
 *  - `sub_clients.category` queda `null` — ICG no da ninguna señal para elegir uno de los 4 valores
 *    fijos del CHECK constraint, no se inventa uno.
 *
 * Tras crear el par, `legacy.clientes.sub_client_id` apunta al `sub_client` nuevo y
 * `match_status` pasa a `Cliente::MATCH_PROMOTED` — distingue "matched a un subcliente YA
 * EXISTENTE" (obra de `ClienteMatcher`) de "subcliente creado por este pipeline", trazabilidad
 * pedida explícitamente por Luis ("dejemos una traza").
 */
final class ClienteFusionLoader
{
    private const CHUNK_SIZE = 500;

    /**
     * @return array{candidatos: int, promovidos: int, errores: int}
     */
    public function fuse(): array
    {
        $candidateIds = $this->candidateIds();

        $promovidos = 0;
        $errores    = 0;

        foreach (array_chunk($candidateIds, self::CHUNK_SIZE) as $chunk) {
            $clientes = Cliente::query()->whereIn('id', $chunk)->get();

            foreach ($clientes as $cliente) {
                try {
                    $this->promoteOne($cliente);
                    $promovidos++;
                } catch (\Throwable) {
                    $errores++;
                }
            }
        }

        return [
            'candidatos' => count($candidateIds),
            'promovidos' => $promovidos,
            'errores'    => $errores,
        ];
    }

    /**
     * Ids de `legacy.clientes` que cumplen el criterio completo — sin vincular todavía, con al
     * menos 1 transacción real. `whereRaw` con subquery (no `whereIn` con un array PHP de miles de
     * ids) para no repetir el gotcha de escala de §13.3 (límite de 65535 parámetros) sobre una
     * lista que, a diferencia de las de Cotizaciones/Pedidos, ni siquiera necesitamos materializar
     * en PHP para construir el filtro.
     *
     * @return list<int>
     */
    private function candidateIds(): array
    {
        $table            = (new Cliente())->getTable();
        $cotizacionesTable = (new Cotizacion())->getTable();
        $pedidosTable      = (new Pedido())->getTable();

        $ids = Cliente::query()
            ->whereNull('sub_client_id')
            ->whereIn('match_status', [Cliente::MATCH_UNMATCHED, Cliente::MATCH_AMBIGUOUS])
            ->where(function ($query) use ($table, $cotizacionesTable, $pedidosTable): void {
                $query->whereExists(function ($sub) use ($table, $cotizacionesTable): void {
                    $sub->selectRaw('1')
                        ->from($cotizacionesTable)
                        ->whereColumn("{$cotizacionesTable}.legacy_cliente_id", "{$table}.id");
                })->orWhereExists(function ($sub) use ($table, $pedidosTable): void {
                    $sub->selectRaw('1')
                        ->from($pedidosTable)
                        ->whereColumn("{$pedidosTable}.legacy_cliente_id", "{$table}.id");
                });
            })
            ->pluck('id')
            ->all();

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }

    public function promoteOne(Cliente $cliente): void
    {
        DB::transaction(function () use ($cliente): void {
            $name = $cliente->nombre_comercial ?: $cliente->razon_social;

            $masterClient = MasterClient::query()->create(['name' => $name]);

            // SCRUM-1137: RUC completo y DV de NIF20 (solo si es confiable: no `revisar` y sin contradecir al CIF, ver
            // `ClienteMatcher::rucUsable`; si no, se usa el CIF como antes). El DV solo se guarda si ICG lo trajo; nunca se inventa. Sin nada: placeholder.
            $usaRuc = ClienteMatcher::rucUsable($cliente);
            $cif    = $cliente->cif !== null ? trim($cliente->cif) : '';

            $taxId = match (true) {
                $usaRuc     => trim((string) $cliente->ruc),
                $cif !== '' => $cliente->cif,
                default     => "SIN-CIF-{$cliente->icg_codcliente}",
            };

            $subClient = SubClient::query()->create([
                'master_client_id' => $masterClient->id,
                'business_name'    => $name,
                'tax_id'           => $taxId,
                'tax_id_dv'        => $usaRuc ? $cliente->ruc_dv : null,
            ]);

            $cliente->sub_client_id = $subClient->id;
            $cliente->match_status  = Cliente::MATCH_PROMOTED;
            $cliente->save();
        });
    }
}
