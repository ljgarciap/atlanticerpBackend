<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\ClienteRow;
use App\Modules\Legacy\Data\ContactoClienteRow;
use App\Modules\Legacy\Data\ExtractedClientes;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\ContactoCliente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-755 — "Loader-A" (staging). Upsert idempotente en `legacy.clientes`/`contactos_cliente`
 * por la llave natural de ICG. Re-correr el mismo extracto dos veces nunca duplica ni pisa mal:
 *
 *  - `legacy.clientes`: upsert por `icg_codcliente` (UNIQUE). `sub_client_id`/`match_status`
 *    NUNCA se incluyen en el `$update` de `upsert()` — así una segunda corrida jamás resetea el
 *    link/estado que puso `ClienteMatcher` en una corrida anterior (mismo criterio que
 *    `catalog_product_id` en `ProductoStagingLoader`).
 *  - `legacy.contactos_cliente`: upsert por `icg_id` (UNIQUE). `legacy_cliente_id` se resuelve
 *    DESPUÉS de upsertear `legacy.clientes` (necesita el `id` interno recién generado/existente) —
 *    contactos cuyo `icg_codcliente` no resuelve dentro del extracto de ESTA corrida se descartan
 *    silenciosamente (tolerante a huérfanos, ver docblock de la migración de `contactos_cliente`).
 *
 * Requiere tenant activo (`Tenant::current()`, ver `getTable()` de cada modelo) — el llamador
 * (`App\Console\Commands\LegacySyncClientesCommand`) es responsable de `makeCurrent()` antes de
 * invocar `load()`.
 */
final class ClienteStagingLoader
{
    /**
     * Postgres tiene un límite duro de 65535 parámetros bindeados por statement (ver docblock de
     * `ProductoStagingLoader` — mismo gotcha, confirmado 2026-08-12 contra ICG real con volumen
     * comparable, ~7707 CLIENTES). 500 deja margen de sobra para las columnas de ambas tablas.
     */
    private const CHUNK_SIZE = 500;

    /**
     * Envuelto en su propia transacción — mismo criterio que `ProductoStagingLoader::load()`
     * (hallazgo Pre-QA SCRUM-750): un fallo a mitad de camino deja la conexión Postgres limpia
     * para que `legacy.sync_runs` pueda registrar la corrida fallida.
     *
     * @return array{clientes: int, contactos: int}
     */
    public function load(ExtractedClientes $extracted): array
    {
        $now = now();

        return DB::transaction(function () use ($extracted, $now): array {
            $clientesCount     = $this->upsertClientes($extracted->clientes, $now);
            $clienteIdsByCodigo = $this->resolveClienteIds($extracted->icgCodclientes());
            $contactosCount     = $this->upsertContactos($extracted->contactos, $clienteIdsByCodigo, $now);

            return ['clientes' => $clientesCount, 'contactos' => $contactosCount];
        });
    }

    /** @param list<ClienteRow> $rows */
    private function upsertClientes(array $rows, Carbon $now): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = array_map(static fn (ClienteRow $row): array => [
            'icg_codcliente'    => $row->icgCodcliente,
            'nombre_comercial'  => $row->nombreComercial,
            'razon_social'      => $row->razonSocial,
            'cif'               => $row->cif,
            'direccion'         => $row->direccion,
            'poblacion'         => $row->poblacion,
            'provincia'         => $row->provincia,
            'pais'              => $row->pais,
            'telefono1'         => $row->telefono1,
            'telefono2'         => $row->telefono2,
            'mobil'             => $row->mobil,
            'email'             => $row->email,
            'icg_codvendedor'   => $row->icgCodvendedor,
            'bloqueado'         => $row->bloqueado,
            'fecha_modificado_icg' => $row->fechaModificadoIcg?->format('Y-m-d H:i:s'),
            'created_at'        => $now,
            'updated_at'        => $now,
        ], $rows);

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            Cliente::query()->upsert(
                $chunk,
                ['icg_codcliente'],
                [
                    'nombre_comercial', 'razon_social', 'cif', 'direccion', 'poblacion', 'provincia',
                    'pais', 'telefono1', 'telefono2', 'mobil', 'email', 'icg_codvendedor', 'bloqueado',
                    'fecha_modificado_icg', 'updated_at',
                ],
            );
        }

        return count($values);
    }

    /**
     * @param  list<int>          $icgCodclientes
     * @return array<int, int>    icg_codcliente => id interno
     */
    private function resolveClienteIds(array $icgCodclientes): array
    {
        if ($icgCodclientes === []) {
            return [];
        }

        /** @var array<int, int> $map */
        $map = [];
        foreach (array_chunk($icgCodclientes, self::CHUNK_SIZE) as $chunk) {
            $map += Cliente::query()
                ->whereIn('icg_codcliente', $chunk)
                ->pluck('id', 'icg_codcliente')
                ->all();
        }

        return $map;
    }

    /**
     * @param list<ContactoClienteRow> $rows
     * @param array<int, int>          $clienteIdsByCodigo icg_codcliente => id interno
     */
    private function upsertContactos(array $rows, array $clienteIdsByCodigo, Carbon $now): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = [];
        foreach ($rows as $row) {
            $legacyClienteId = $clienteIdsByCodigo[$row->icgCodcliente] ?? null;
            if ($legacyClienteId === null) {
                // Huérfano dentro de esta corrida (icg_codcliente no está en legacy.clientes tras
                // el staging de arriba) — se descarta, nunca rompe el resto (§13.2 del diseño).
                continue;
            }

            $values[] = [
                'legacy_cliente_id' => $legacyClienteId,
                'icg_id'            => $row->icgId,
                'cargo'             => $row->cargo,
                'nombre'            => $row->nombre,
                'telefono'          => $row->telefono,
                'mobil'             => $row->mobil,
                'email'             => $row->email,
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }

        if ($values === []) {
            return 0;
        }

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            ContactoCliente::query()->upsert(
                $chunk,
                ['icg_id'],
                ['legacy_cliente_id', 'cargo', 'nombre', 'telefono', 'mobil', 'email', 'updated_at'],
            );
        }

        return count($values);
    }
}
