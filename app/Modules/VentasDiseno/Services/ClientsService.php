<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Http\Pagination;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pantalla Clientes (REQ-063 a REQ-070). A diferencia de Pipeline, esta pantalla no
 * tiene alcance Mío/Equipo — REQ-063 pide explícitamente "todos los Clientes Master
 * registrados", sin filtrar por responsable (es un directorio compartido, no un
 * pipeline de ventas individual).
 */
final class ClientsService
{
    private const STALE_CONTACT_DAYS = 60;

    private const ACTIVE_STAGES = ['lead', 'design', 'quote', 'proposal'];

    /**
     * SCRUM-754 (2026-08-18) — paginación real de backend, un Cliente Master (con sus
     * subclientes anidados) por fila. `stale` se calcula en PHP a partir de `PipelineCard`
     * agregado, así que la paginación se aplica AL FINAL, sobre el array ya filtrado por
     * search/categories/stale — mismo patrón que `CatalogService::list()`.
     *
     * @param array{search?: string, categories?: array<int, string>, stale?: bool} $filters
     * @param array{per_page: int, page: int} $pagination
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, per_page: int, current_page: int, last_page: int}}
     */
    public function list(array $filters = [], array $pagination = ['per_page' => 20, 'page' => 1]): array
    {
        $query = MasterClient::with(['subClients']);

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', "%{$term}%")
                  ->orWhereHas('subClients', fn ($sq) => $sq->where('business_name', 'ilike', "%{$term}%")
                      ->orWhere('tax_id', 'ilike', "%{$term}%"));
            });
        }

        if (! empty($filters['categories'])) {
            $categories = $filters['categories'];
            $query->whereHas('subClients', fn ($sq) => $sq->whereIn('category', $categories));
        }

        /** @var EloquentCollection<int, MasterClient> $clients */
        $clients = $query->orderBy('name')->get();

        $masterIds    = $clients->pluck('id');
        $cardsByMaster = PipelineCard::whereIn('master_client_id', $masterIds)->get()->groupBy('master_client_id');

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $clients->map(function (MasterClient $client) use ($cardsByMaster): array {
            /** @var EloquentCollection<int, PipelineCard> $cards */
            $cards = $cardsByMaster->get($client->id) ?? new EloquentCollection();

            return $this->formatListRow($client, $cards);
        })->values();

        if (! empty($filters['stale'])) {
            /** @var Collection<int, array<string, mixed>> $rows */
            $rows = $rows->filter(
                fn (array $r): bool => $r['last_contact_days'] === null || $r['last_contact_days'] > self::STALE_CONTACT_DAYS
            )->values();
        }

        $paginator = Pagination::ofArray(array_values($rows->all()), $pagination['per_page'], $pagination['page']);

        return [
            'data' => array_values($paginator->items()),
            'meta' => Pagination::meta($paginator),
        ];
    }

    /**
     * @param EloquentCollection<int, PipelineCard> $cards
     * @return array<string, mixed>
     */
    private function formatListRow(MasterClient $client, EloquentCollection $cards): array
    {
        $lastContact = $cards->pluck('stage_changed_at')->filter()->sort()->last();

        return [
            'id'                 => $client->id,
            'name'               => $client->name,
            'default_price_type' => $client->default_price_type,
            'categories'         => $client->subClients->pluck('category')->filter()->unique()->values(),
            'active_projects'    => $cards->whereIn('stage', self::ACTIVE_STAGES)->count(),
            'lost_projects'      => $cards->where('stage', 'lost')->count(),
            'total_projects'     => $cards->count(),
            'total_amount'       => (float) $cards->whereIn('stage', array_merge(self::ACTIVE_STAGES, ['approved']))->sum('amount'),
            'closed_amount'      => (float) $cards->where('stage', 'approved')->sum('amount'),
            'last_contact_days'  => $lastContact !== null ? (int) $lastContact->diffInDays(now()) : null,
        ];
    }

    public function findWithHierarchy(int $id): ?MasterClient
    {
        return MasterClient::with(['subClients.contacts'])->find($id);
    }

    /** @return EloquentCollection<int, PipelineCard> */
    public function projectsFor(int $masterClientId): EloquentCollection
    {
        return PipelineCard::with(['subClient', 'salesProject'])
            ->where('master_client_id', $masterClientId)
            ->orderByDesc('stage_changed_at')
            ->get();
    }

    /**
     * REQ-067 (modo "crear cliente") + REQ-068: crea el Cliente Master, su primer
     * Subcliente y sus contactos, y dispara la tarjeta Lead automática en Pipeline.
     *
     * @param array{
     *   name: string, default_price_type?: string|null,
     *   business_name: string, tax_id: string, delivery_address?: string|null, category?: string|null,
     *   contacts: list<array{name: string, role: string, phone?: string|null, email?: string|null}>,
     * } $data
     */
    public function createClient(array $data, int $creatorUserId): MasterClient
    {
        return DB::transaction(function () use ($data, $creatorUserId): MasterClient {
            $master = MasterClient::create([
                'name'               => $data['name'],
                'default_price_type' => $data['default_price_type'] ?? null,
            ]);

            $sub = $master->subClients()->create([
                'business_name'     => $data['business_name'],
                'tax_id'            => $data['tax_id'],
                'delivery_address'  => $data['delivery_address'] ?? null,
                'category'          => $data['category'] ?? null,
            ]);

            foreach ($data['contacts'] as $contactData) {
                $sub->contacts()->create($contactData);
            }

            $this->createLeadCard($master, $sub, $creatorUserId);

            /** @var MasterClient $fresh */
            $fresh = $master->fresh(['subClients.contacts']);
            return $fresh;
        });
    }

    /**
     * REQ-067 (modo "crear subcliente") + REQ-068: Subcliente adicional bajo un
     * Cliente Master ya existente, con sus contactos y su propia tarjeta Lead.
     *
     * @param array{
     *   business_name: string, tax_id: string, delivery_address?: string|null, category?: string|null,
     *   contacts: list<array{name: string, role: string, phone?: string|null, email?: string|null}>,
     * } $data
     */
    public function addSubClient(MasterClient $master, array $data, int $creatorUserId): SubClient
    {
        return DB::transaction(function () use ($master, $data, $creatorUserId): SubClient {
            $sub = $master->subClients()->create([
                'business_name'    => $data['business_name'],
                'tax_id'           => $data['tax_id'],
                'delivery_address' => $data['delivery_address'] ?? null,
                'category'         => $data['category'] ?? null,
            ]);

            foreach ($data['contacts'] as $contactData) {
                $sub->contacts()->create($contactData);
            }

            $this->createLeadCard($master, $sub, $creatorUserId);

            /** @var SubClient $fresh */
            $fresh = $sub->fresh(['contacts']);
            return $fresh;
        });
    }

    public function updateSubClientCategory(SubClient $subClient, ?string $category): SubClient
    {
        $subClient->update(['category' => $category]);
        return $subClient;
    }

    /**
     * REQ-068: toda tarjeta Lead automática nace con los contactos recién cargados
     * del Subcliente que la origina, para no forzar una segunda captura de datos
     * antes de poder avanzarla de etapa (REQ-011 exige al menos 1 contacto en la
     * tarjeta para salir de Lead). Público: también lo llama ClientController
     * cuando un Subcliente nuevo nace desde el flujo de vinculación de Pipeline
     * (REQ-010/068 en sentido inverso, ver SCRUM-79) — REQ-068 no hace excepción
     * para ese caso, aunque quede una tarjeta Lead "extra" además de la que el
     * usuario esté vinculando en ese momento (decisión confirmada con Luis).
     */
    public function createLeadCard(MasterClient $master, SubClient $sub, int $ownerId): PipelineCard
    {
        $project = $sub->salesProjects()->create(['name' => $sub->business_name]);

        $card = PipelineCard::create([
            'sales_project_id' => $project->id,
            'stage'            => 'lead',
            'master_client_id' => $master->id,
            'sub_client_id'    => $sub->id,
            'owner_id'         => $ownerId,
            'stage_changed_at' => now(),
        ]);

        foreach ($sub->contacts as $contact) {
            $card->contacts()->create([
                'name'  => $contact->name,
                'role'  => $contact->role,
                'phone' => $contact->phone,
                'email' => $contact->email,
            ]);
        }

        return $card;
    }
}
