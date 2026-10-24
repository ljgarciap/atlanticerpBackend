<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\FuzzySearchService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Fase 4 — Servicios, Batch 3 parte 2 (REQ-246) — buscador de solo lectura Cliente Master →
 * Subcliente → Proyecto, endpoints propios de Servicios (mismo motivo que
 * `Compras\ApprovedProjectController`/`CatalogProductSearchController`: un usuario de Servicios
 * no tiene `ventas_diseno.read`, así que no puede colgar de los controllers de Ventas & Diseño).
 * RN1 — sin creación posible desde acá, solo búsqueda/selección sobre lo que ya existe.
 */
final class ClientLookupService
{
    private const MAX_RESULTS = 20;

    public function __construct(private readonly FuzzySearchService $fuzzySearch) {}

    /**
     * RN2 — modal de búsqueda con resultados filtrados en vivo.
     *
     * Rebote SCRUM-917 (Gerencia, 2026-09-14) — el buscador no encontraba clientes que SÍ
     * existían en CRM → Clientes. Causa raíz confirmada contra dev.atlanticerp.ai (7568 Clientes
     * Master reales): la versión anterior pre-cargaba solo los primeros 200 registros
     * ordenados por nombre y recién ahí filtraba en PHP por el término buscado — cualquier
     * cliente cuyo nombre cayera fuera de ese prefijo alfabético de 200 jamás entraba al pool
     * de búsqueda, sin importar qué tan exacto fuera el término (no es un problema de
     * "recientes", es cualquier nombre alfabéticamente después del top 200). Ahora la
     * coincidencia exacta corre en SQL contra toda la tabla; el fallback aproximado (typo)
     * sigue en PHP pero sobre el universo completo, no un prefijo arbitrario.
     *
     * @return list<array{id: int, name: string}>
     */
    public function searchMasterClients(string $search): array
    {
        $term = trim($search);

        if ($term === '') {
            /** @var list<MasterClient> $clients */
            $clients = MasterClient::orderBy('name')->limit(self::MAX_RESULTS)->get()->all();

            return array_map(fn (MasterClient $c): array => ['id' => $c->id, 'name' => $c->name], $clients);
        }

        /** @var list<MasterClient> $exact */
        $exact = MasterClient::where('name', 'ilike', "%{$term}%")->orderBy('name')->limit(self::MAX_RESULTS)->get()->all();
        if ($exact !== []) {
            return array_map(fn (MasterClient $c): array => ['id' => $c->id, 'name' => $c->name], $exact);
        }

        /** @var list<MasterClient> $all */
        $all = MasterClient::orderBy('name')->get()->all();
        [$clients] = $this->fuzzySearch->search($all, $term, fn (MasterClient $c): string => $c->name);

        return array_map(fn (MasterClient $c): array => ['id' => $c->id, 'name' => $c->name], $clients);
    }

    /**
     * RN3 — solo subclientes del Master elegido. RN5 — el llamador distingue "sin resultados
     * porque no hay ninguno" de "sin resultados por el filtro" comparando contra el total.
     *
     * @return list<array{id: int, business_name: string}>
     */
    public function searchSubClients(int $masterClientId, string $search): array
    {
        /** @var EloquentCollection<int, SubClient> $all */
        $all = SubClient::where('master_client_id', $masterClientId)->orderBy('business_name')->get();

        /** @var list<SubClient> $subClients */
        $subClients = $all->all();

        if (trim($search) !== '') {
            [$subClients] = $this->fuzzySearch->search($subClients, $search, fn (SubClient $s): string => $s->business_name);
        }

        return array_map(
            fn (SubClient $s): array => ['id' => $s->id, 'business_name' => $s->business_name],
            $subClients,
        );
    }

    /**
     * RN4 — proyectos ya existentes del Subcliente elegido, sin posibilidad de escribir uno nuevo.
     *
     * @return list<array{id: int, name: string}>
     */
    public function projectsForSubClient(int $subClientId): array
    {
        /** @var list<SalesProject> $projects */
        $projects = SalesProject::where('sub_client_id', $subClientId)->orderBy('name')->get()->all();

        return array_map(fn (SalesProject $p): array => ['id' => $p->id, 'name' => $p->name], $projects);
    }
}
