<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\VentasDiseno\Models\Architect;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PipelineCardContact;
use App\Modules\VentasDiseno\Models\PipelineCardFile;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * SCRUM-735 — "Eliminación de información generada durante PM Review en Ventas & Diseño".
 *
 * Identifica (buildReport()) y, solo cuando se le pide explícitamente (execute()), borra
 * la data de prueba que Gerencia Test generó en test.atlanticerp.ai probando manualmente el módulo
 * Ventas & Diseño — sin tocar la data DEMO marcada con [DEMO-711]/[QA-VD]/[QA-VD-409]
 * (Scrum711SalesForceDemoSeeder / QaTicketsBatch20260725Seeder), sin importar quién la creó.
 *
 * DISEÑO (ver docblocks de los métodos privados para el detalle de cada paso):
 *
 * 1. Candidatos DIRECTOS: audit_logs con user_id=Gerencia, action='...created' (nunca
 *    updated/deleted — editar un registro real de otra persona no lo marca), sobre los 10
 *    modelos con LogsAuditTrail (ver AUDITED_ENTITIES).
 * 2. SalesProject NO usa LogsAuditTrail — se infiere: su sub_client_id ya es candidato Y su
 *    created_at cae en la ventana [mínimo, máximo] de los audit_logs de Gerencia.
 * 3. Exclusión por marcador: cualquier candidato cuyo campo de label (o el de cualquier
 *    entidad en su cadena de FKs hacia arriba — ej. un QuoteItem cuyo Quote.description
 *    tiene [QA-VD]) contenga uno de los 3 marcadores queda excluido, pase lo que pase.
 * 4. Guard de referencia activa: MasterClient/SubClient/SalesProject cascadean el borrado
 *    de sus hijos compartidos (sub_clients.master_client_id, sales_projects.sub_client_id,
 *    pipeline_cards.sales_project_id son CASCADE en la BD) — si un hijo vivo NO candidato
 *    todavía los referencia, no se borran, quedan reportados como "active_reference".
 * 5. Mismo guard para CatalogProduct — a diferencia de los otros 3, sus referencias reales
 *    están fuera de Ventas & Diseño (Compras/Bodega/Servicios reutilizan el Catálogo, ver
 *    CROSS_MODULE_CATALOG_PRODUCT_REFERENCES) y varias son nullOnDelete/cascadeOnDelete —
 *    sin este guard, borrar un CatalogProduct de prueba podría nulificar en silencio la
 *    trazabilidad de una línea de orden de compra REAL, o directamente borrarle filas reales
 *    de Kardex/stock.
 *
 * Nunca ejecuta nada por sí sola contra un entorno real — ver VentasDisenoCleanupTestDataCommand
 * para el guard de entorno (`qa` únicamente, salvo --env-override para testing).
 */
final class TestDataCleanupService
{
    /**
     * Marcadores de data DEMO real — protegidos SIEMPRE, sin importar quién los creó.
     * Ver Scrum711SalesForceDemoSeeder::MARKER y QaTicketsBatch20260725Seeder::VD_MARKER/
     * SCRUM409_MARKER.
     *
     * @var list<string>
     */
    public const PROTECTED_MARKERS = ['[DEMO-711]', '[QA-VD]', '[QA-VD-409]'];

    /**
     * Los 10 modelos cubiertos por App\Shared\Support\LogsAuditTrail — entity_type
     * (Str::snake(class_basename)) => clase del modelo.
     *
     * @var array<string, class-string<Model>>
     */
    private const AUDITED_ENTITIES = [
        'master_client' => MasterClient::class,
        'sub_client' => SubClient::class,
        'architect' => Architect::class,
        'pipeline_card' => PipelineCard::class,
        'pipeline_card_contact' => PipelineCardContact::class,
        'pipeline_card_file' => PipelineCardFile::class,
        'quote' => Quote::class,
        'quote_part' => QuotePart::class,
        'quote_item' => QuoteItem::class,
        'catalog_product' => CatalogProduct::class,
    ];

    /**
     * Mismo mapa que arriba + `sales_project` (no auditado, se resuelve por inferencia,
     * ver buildReport()). Usado para resolver modelos padre al caminar la cadena de FKs
     * en labelsFor() y para ejecutar el borrado real en execute().
     *
     * @var array<string, class-string<Model>>
     */
    private const MODEL_MAP = self::AUDITED_ENTITIES + ['sales_project' => SalesProject::class];

    /**
     * Columna de texto que puede llevar un marcador de DEMO, por entity_type. null si la
     * entidad no tiene una columna de label propia (ej. pipeline_card, que se protege solo
     * por su cadena de FKs hacia MasterClient/SubClient/SalesProject).
     *
     * @var array<string, string|null>
     */
    private const LABEL_COLUMNS = [
        'master_client' => 'name',
        'sub_client' => 'business_name',
        'architect' => 'name',
        'pipeline_card' => null,
        'pipeline_card_contact' => 'name',
        'pipeline_card_file' => 'file_name',
        'quote' => 'description',
        'quote_part' => 'name',
        'quote_item' => 'description',
        // SCRUM-237/240 — `name` es el campo identificatorio real desde el split name/description
        // (ver migración 2026_08_15_100000_...); un marcador `[QA-VD]`/`[DEMO-711]` puesto a mano
        // en un producto de prueba vive en el nombre visible, no en `description` (que ahora es
        // texto libre real, casi siempre vacío).
        'catalog_product' => 'name',
        'sales_project' => 'name',
    ];

    /**
     * Cadena de FKs hacia arriba por entity_type — [entityType padre, columna FK en el
     * hijo]. labelsFor() la camina recursivamente para juntar todos los labels que hay
     * que revisar contra PROTECTED_MARKERS (ej. un QuoteItem hereda la protección del
     * Quote/MasterClient/SubClient/SalesProject al que pertenece).
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const PARENT_CHAINS = [
        'master_client' => [],
        'sub_client' => [['master_client', 'master_client_id']],
        'architect' => [['sub_client', 'sub_client_id']],
        'pipeline_card' => [
            ['master_client', 'master_client_id'],
            ['sub_client', 'sub_client_id'],
            ['sales_project', 'sales_project_id'],
        ],
        'pipeline_card_contact' => [['pipeline_card', 'pipeline_card_id']],
        'pipeline_card_file' => [['pipeline_card', 'pipeline_card_id']],
        'quote' => [
            ['master_client', 'master_client_id'],
            ['sub_client', 'sub_client_id'],
            ['sales_project', 'sales_project_id'],
        ],
        'quote_part' => [['quote', 'quote_id']],
        'quote_item' => [['quote_part', 'quote_part_id']],
        'catalog_product' => [],
        'sales_project' => [['sub_client', 'sub_client_id']],
    ];

    /**
     * Tablas de OTROS módulos (Compras/Bodega/Servicios — nunca son "candidatas" en este
     * algoritmo, no pertenecen a Ventas & Diseño) que referencian catalog_products.id vía
     * FK cross-schema — [schema del tenant sin prefijo, tabla, columna]. Grep exhaustivo de
     * `catalog_product_id` en database/migrations/tenant/ (2026-08-07, gap real encontrado
     * en Senior Review del commit c1e1a99: guardCatalogProducts() no existía). `quote_items`
     * (dentro de ventas_diseno) NO está acá a propósito — sí participa del algoritmo de
     * candidatos (es uno de los 10 modelos auditados), se chequea aparte en
     * guardCatalogProducts() excluyendo sus propios candidatos.
     *
     * onDelete real de cada una (documentado para contexto — el chequeo bloquea igual sin
     * importar cómo resolvería la BD la referencia: NULL/CASCADE/RESTRICT/NO ACTION):
     *   - ventas_diseno.catalog_product_family_items: cascadeOnDelete (pivot, sin candidatos propios)
     *   - compras.purchase_order_lines:                nullOnDelete
     *   - compras.replacement_requests.original_*:     restrictOnDelete
     *   - compras.replacement_requests.proposed_*:      nullOnDelete
     *   - compras.purchase_order_receipt_lines:         cascadeOnDelete
     *   - bodega.product_warehouse_stock:               cascadeOnDelete
     *   - bodega.inventory_movements:                   sin onDelete explícito (NO ACTION — bloquea)
     *   - bodega.adjustment_requests:                   sin onDelete explícito (NO ACTION — bloquea)
     *   - bodega.order_items:                           nullOnDelete
     *   - bodega.relocation_requests:                   sin onDelete explícito (NO ACTION — bloquea)
     *   - bodega.general_count_request_lines:           sin onDelete explícito (NO ACTION — bloquea)
     *   - servicios.ticket_products:                    nullOnDelete
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const CROSS_MODULE_CATALOG_PRODUCT_REFERENCES = [
        ['ventas_diseno', 'catalog_product_family_items', 'catalog_product_id'],
        ['compras', 'purchase_order_lines', 'catalog_product_id'],
        ['compras', 'replacement_requests', 'original_catalog_product_id'],
        ['compras', 'replacement_requests', 'proposed_catalog_product_id'],
        ['compras', 'purchase_order_receipt_lines', 'catalog_product_id'],
        ['bodega', 'product_warehouse_stock', 'catalog_product_id'],
        ['bodega', 'inventory_movements', 'catalog_product_id'],
        ['bodega', 'adjustment_requests', 'catalog_product_id'],
        ['bodega', 'order_items', 'catalog_product_id'],
        ['bodega', 'relocation_requests', 'catalog_product_id'],
        ['bodega', 'general_count_request_lines', 'catalog_product_id'],
        ['servicios', 'ticket_products', 'catalog_product_id'],
    ];

    /**
     * Orden de borrado real (execute()) — hijos antes que padres. `catalog_product` va
     * justo después de `quote_item` (que puede referenciarlo dentro de ventas_diseno) y
     * antes de los demás — sin dependencia real con master/sub/sales, pero borrarlo
     * temprano documenta que ya pasó su propio guard de referencia activa cross-módulo.
     * sales_project SIEMPRE al final: pipeline_cards.sales_project_id es CASCADE en la BD,
     * así que borrar un SalesProject antes de sus pipeline_cards/quotes candidatos se lo
     * llevaría puesto; el guard de "active_reference" en buildReport() ya garantiza que,
     * llegado este punto, ningún pipeline_card/quote NO candidato sigue apuntando a un
     * SalesProject que vamos a borrar.
     *
     * @var list<string>
     */
    public const DELETE_ORDER = [
        'quote_item',
        'catalog_product',
        'quote_part',
        'pipeline_card_contact',
        'pipeline_card_file',
        'quote',
        'pipeline_card',
        'architect',
        'sub_client',
        'master_client',
        'sales_project',
    ];

    /**
     * Construye el reporte de candidatos — SOLO LECTURA, no borra nada. Requiere que el
     * tenant correcto ya esté activo (Tenant::makeCurrent()) — no lo resuelve él mismo,
     * eso es responsabilidad del command.
     *
     * @return array{
     *   user: array{id: int, email: string},
     *   audit_window: array{from: ?string, to: ?string, rows_considered: int},
     *   candidates: array<string, list<int>>,
     *   excluded: list<array{entity_type: string, id: int, reason: string, label: ?string}>,
     * }
     */
    public function buildReport(string $userEmail): array
    {
        $userId = User::where('email', $userEmail)->value('id');

        if ($userId === null) {
            throw new RuntimeException("Usuario '{$userEmail}' no existe en el tenant activo.");
        }

        $userId = (int) $userId;

        /** @var Collection<int, AuditLog> $auditRows */
        $auditRows = AuditLog::query()
            ->where('user_id', $userId)
            ->where('action', 'like', 'ventas_diseno.%.created')
            ->whereIn('entity_type', array_keys(self::AUDITED_ENTITIES))
            ->orderBy('created_at')
            ->get(['id', 'entity_type', 'entity_id', 'created_at']);

        $windowFrom = $auditRows->min('created_at');
        $windowTo = $auditRows->max('created_at');

        /** @var array<string, list<int>> $candidateIds */
        $candidateIds = [];
        foreach (array_keys(self::AUDITED_ENTITIES) as $type) {
            $candidateIds[$type] = [];
        }

        /** @var list<array{entity_type: string, id: int, reason: string, label: ?string}> $excluded */
        $excluded = [];

        foreach ($auditRows as $log) {
            $entityType = (string) $log->entity_type;
            $modelClass = self::AUDITED_ENTITIES[$entityType] ?? null;
            if ($modelClass === null) {
                continue;
            }

            $entityId = (int) $log->entity_id;
            /** @var Model|null $row */
            $row = $modelClass::find($entityId);

            if ($row === null) {
                $excluded[] = ['entity_type' => $entityType, 'id' => $entityId, 'reason' => 'already_deleted', 'label' => null];

                continue;
            }

            $labels = $this->labelsFor($entityType, $row);
            if ($this->containsMarker($labels)) {
                $excluded[] = ['entity_type' => $entityType, 'id' => $entityId, 'reason' => 'marker_protected', 'label' => implode(' | ', $labels)];

                continue;
            }

            $candidateIds[$entityType][] = $entityId;
        }

        // SalesProject — inferido (no tiene LogsAuditTrail): sub_client_id ya candidato +
        // created_at dentro de la ventana de audit_logs de Gerencia.
        $candidateIds['sales_project'] = [];
        if ($candidateIds['sub_client'] !== [] && $windowFrom !== null && $windowTo !== null) {
            $rows = SalesProject::whereIn('sub_client_id', $candidateIds['sub_client'])
                ->whereBetween('created_at', [$windowFrom, $windowTo])
                ->get();

            foreach ($rows as $row) {
                $labels = $this->labelsFor('sales_project', $row);
                if ($this->containsMarker($labels)) {
                    $excluded[] = ['entity_type' => 'sales_project', 'id' => $row->id, 'reason' => 'marker_protected', 'label' => implode(' | ', $labels)];

                    continue;
                }
                $candidateIds['sales_project'][] = $row->id;
            }
        }

        // Guard de referencia activa — orden de dependencia: sales_project primero (solo
        // depende de pipeline_card/quote, ya fijos), sub_client después (depende de
        // sales_project ya guardado), master_client al final (depende de sub_client ya
        // guardado).
        [$candidateIds['sales_project'], $excludedSalesProjects] = $this->guardSalesProjects($candidateIds);
        $excluded = array_merge($excluded, $excludedSalesProjects);

        [$candidateIds['sub_client'], $excludedSubClients] = $this->guardSubClients($candidateIds);
        $excluded = array_merge($excluded, $excludedSubClients);

        [$candidateIds['master_client'], $excludedMasterClients] = $this->guardMasterClients($candidateIds);
        $excluded = array_merge($excluded, $excludedMasterClients);

        // Sin dependencia con el trío master/sub/sales_project de arriba — puede correr en
        // cualquier momento después de que candidateIds['quote_item'] esté fijo.
        [$candidateIds['catalog_product'], $excludedCatalogProducts] = $this->guardCatalogProducts($candidateIds);
        $excluded = array_merge($excluded, $excludedCatalogProducts);

        foreach ($candidateIds as $entityType => $ids) {
            Log::info('ventas_diseno.cleanup_test_data.candidate_set', [
                'user_email' => $userEmail,
                'entity_type' => $entityType,
                'ids' => $ids,
            ]);
        }
        foreach ($excluded as $row) {
            Log::info('ventas_diseno.cleanup_test_data.excluded', $row + ['user_email' => $userEmail]);
        }

        return [
            'user' => ['id' => $userId, 'email' => $userEmail],
            'audit_window' => [
                'from' => $windowFrom?->toIso8601String(),
                'to' => $windowTo?->toIso8601String(),
                'rows_considered' => $auditRows->count(),
            ],
            'candidates' => $candidateIds,
            'excluded' => $excluded,
        ];
    }

    /**
     * Ejecuta el borrado real, en DELETE_ORDER, dentro de una transacción. Recibe el
     * reporte YA construido por buildReport() — no vuelve a calcular nada, borra
     * exactamente lo que el reporte aprobó. Delete masivo vía query builder (no
     * `$model->delete()` uno por uno) a propósito: no dispara LogsAuditTrail (no tiene
     * sentido loguear en audit_logs un borrado hecho por esta herramienta operativa, sin
     * usuario real detrás) — el rastro queda en storage/logs vía Log::info() más abajo.
     *
     * @param  array{candidates: array<string, list<int>>, ...}  $report
     * @return array<string, int> entity_type => filas borradas
     */
    public function execute(array $report): array
    {
        /** @var array<string, int> $deletedCounts */
        $deletedCounts = [];

        DB::transaction(function () use ($report, &$deletedCounts): void {
            foreach (self::DELETE_ORDER as $entityType) {
                $ids = $report['candidates'][$entityType] ?? [];

                if ($ids === []) {
                    $deletedCounts[$entityType] = 0;

                    continue;
                }

                $modelClass = self::MODEL_MAP[$entityType];
                $modelClass::query()->whereIn('id', $ids)->delete();

                // count($ids), no el valor de retorno de delete(): un id candidato de ESTE MISMO
                // reporte puede ya no existir en la BD para cuando este paso corre, porque un
                // CASCADE de otro entity_type ya borrado antes en DELETE_ORDER se lo llevó puesto
                // (ej. sales_project, siempre último, cascade-borrado al borrar su sub_client
                // candidato — sales_projects.sub_client_id es CASCADE). delete()->rowCount() da 0
                // en ese caso aunque el id sí terminó borrado; buildReport() ya garantiza que todo
                // id en $ids sale de la BD al cerrar la transacción, así que count($ids) es el
                // número real de filas afectadas por esta corrida, consistente con el Log::info()
                // de abajo (que sí lista el id pase lo que pase). Hallazgo real de Pre-QA
                // (SCRUM-735, 2026-08-07): el resumen de --force mostraba "sales_project: 0" pese
                // a haber borrado 1 fila real, contradiciendo tanto storage/logs como la realidad.
                $deletedCounts[$entityType] = count($ids);

                foreach ($ids as $id) {
                    Log::info('ventas_diseno.cleanup_test_data.deleted', ['entity_type' => $entityType, 'id' => $id]);
                }
            }
        });

        return $deletedCounts;
    }

    /**
     * Junta, recursivamente, todos los labels de texto relevantes de una fila y de toda
     * su cadena de FKs hacia arriba (PARENT_CHAINS) — para que un hijo (ej. QuoteItem)
     * quede protegido si CUALQUIER ancestro (ej. el Quote o el MasterClient del que
     * cuelga) tiene un marcador de DEMO, no solo si el hijo mismo lo tiene.
     *
     * @return list<string>
     */
    private function labelsFor(string $entityType, ?Model $row): array
    {
        if ($row === null) {
            return [];
        }

        /** @var list<string> $labels */
        $labels = [];

        $labelColumn = self::LABEL_COLUMNS[$entityType] ?? null;
        if ($labelColumn !== null) {
            $value = $row->getAttribute($labelColumn);
            if (is_string($value) && $value !== '') {
                $labels[] = $value;
            }
        }

        foreach (self::PARENT_CHAINS[$entityType] as [$parentType, $fkColumn]) {
            $fkValue = $row->getAttribute($fkColumn);
            if ($fkValue === null) {
                continue;
            }

            $parentModelClass = self::MODEL_MAP[$parentType];
            /** @var Model|null $parent */
            $parent = $parentModelClass::find($fkValue);
            $labels = array_merge($labels, $this->labelsFor($parentType, $parent));
        }

        return $labels;
    }

    /** @param list<string> $labels */
    private function containsMarker(array $labels): bool
    {
        foreach ($labels as $label) {
            foreach (self::PROTECTED_MARKERS as $marker) {
                if (str_contains($label, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * sales_projects.sub_client_id es CASCADE — y pipeline_cards.sales_project_id
     * también. Un SalesProject candidato solo es seguro de borrar si NINGÚN
     * PipelineCard/Quote vivo (no candidato) todavía lo referencia.
     *
     * @param  array<string, list<int>>  $candidateIds
     * @return array{0: list<int>, 1: list<array{entity_type: string, id: int, reason: string, label: ?string}>}
     */
    private function guardSalesProjects(array $candidateIds): array
    {
        $ids = $candidateIds['sales_project'];
        if ($ids === []) {
            return [[], []];
        }

        $blocked = PipelineCard::whereIn('sales_project_id', $ids)
            ->whereNotIn('id', $candidateIds['pipeline_card'])
            ->pluck('sales_project_id')
            ->merge(
                Quote::whereIn('sales_project_id', $ids)
                    ->whereNotIn('id', $candidateIds['quote'])
                    ->pluck('sales_project_id')
            );

        return $this->splitByBlocked('sales_project', $ids, $this->normalizeIdList($blocked));
    }

    /**
     * sub_clients.master_client_id es CASCADE. Un SubClient candidato solo es seguro de
     * borrar si ningún SalesProject (ya filtrado por guardSalesProjects)/PipelineCard/
     * Quote/Architect vivo (no candidato) todavía lo referencia.
     *
     * @param  array<string, list<int>>  $candidateIds
     * @return array{0: list<int>, 1: list<array{entity_type: string, id: int, reason: string, label: ?string}>}
     */
    private function guardSubClients(array $candidateIds): array
    {
        $ids = $candidateIds['sub_client'];
        if ($ids === []) {
            return [[], []];
        }

        $blocked = SalesProject::whereIn('sub_client_id', $ids)
            ->whereNotIn('id', $candidateIds['sales_project'])
            ->pluck('sub_client_id')
            ->merge(
                PipelineCard::whereIn('sub_client_id', $ids)
                    ->whereNotIn('id', $candidateIds['pipeline_card'])
                    ->pluck('sub_client_id')
            )
            ->merge(
                Quote::whereIn('sub_client_id', $ids)
                    ->whereNotIn('id', $candidateIds['quote'])
                    ->pluck('sub_client_id')
            )
            ->merge(
                Architect::whereIn('sub_client_id', $ids)
                    ->whereNotIn('id', $candidateIds['architect'])
                    ->pluck('sub_client_id')
            );

        return $this->splitByBlocked('sub_client', $ids, $this->normalizeIdList($blocked));
    }

    /**
     * Un MasterClient candidato solo es seguro de borrar si ningún SubClient (ya
     * filtrado por guardSubClients)/PipelineCard/Quote vivo (no candidato) todavía lo
     * referencia.
     *
     * @param  array<string, list<int>>  $candidateIds
     * @return array{0: list<int>, 1: list<array{entity_type: string, id: int, reason: string, label: ?string}>}
     */
    private function guardMasterClients(array $candidateIds): array
    {
        $ids = $candidateIds['master_client'];
        if ($ids === []) {
            return [[], []];
        }

        $blocked = SubClient::whereIn('master_client_id', $ids)
            ->whereNotIn('id', $candidateIds['sub_client'])
            ->pluck('master_client_id')
            ->merge(
                PipelineCard::whereIn('master_client_id', $ids)
                    ->whereNotIn('id', $candidateIds['pipeline_card'])
                    ->pluck('master_client_id')
            )
            ->merge(
                Quote::whereIn('master_client_id', $ids)
                    ->whereNotIn('id', $candidateIds['quote'])
                    ->pluck('master_client_id')
            );

        return $this->splitByBlocked('master_client', $ids, $this->normalizeIdList($blocked));
    }

    /**
     * CatalogProduct se referencia desde FUERA de Ventas & Diseño (Compras/Bodega/
     * Servicios, ver CROSS_MODULE_CATALOG_PRODUCT_REFERENCES) — ninguna de esas filas
     * participa nunca del algoritmo de candidatos (son de otro módulo), así que CUALQUIER
     * fila que referencie a un CatalogProduct candidato lo bloquea, sin excepción — a
     * diferencia de guardSalesProjects()/guardSubClients()/guardMasterClients(), acá no
     * hace falta filtrar por "no candidato" en las tablas cross-módulo (nunca lo son).
     * Dentro de ventas_diseno, quote_items.catalog_product_id también es nullOnDelete —
     * ahí sí se excluyen los propios candidatos de quote_item (esos se borran junto con
     * el producto, no lo bloquean).
     *
     * @param  array<string, list<int>>  $candidateIds
     * @return array{0: list<int>, 1: list<array{entity_type: string, id: int, reason: string, label: ?string}>}
     */
    private function guardCatalogProducts(array $candidateIds): array
    {
        $ids = $candidateIds['catalog_product'];
        if ($ids === []) {
            return [[], []];
        }

        $blocked = QuoteItem::whereIn('catalog_product_id', $ids)
            ->whereNotIn('id', $candidateIds['quote_item'])
            ->pluck('catalog_product_id');

        $tenantSlug = Tenant::current()?->slug;
        if ($tenantSlug !== null) {
            foreach (self::CROSS_MODULE_CATALOG_PRODUCT_REFERENCES as [$schema, $table, $column]) {
                $blocked = $blocked->merge(
                    DB::table("{$tenantSlug}_{$schema}.{$table}")->whereIn($column, $ids)->pluck($column)
                );
            }
        }

        return $this->splitByBlocked('catalog_product', $ids, $this->normalizeIdList($blocked));
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $blockedIds
     * @return array{0: list<int>, 1: list<array{entity_type: string, id: int, reason: string, label: ?string}>}
     */
    private function splitByBlocked(string $entityType, array $ids, array $blockedIds): array
    {
        $survivors = [];
        $excluded = [];

        foreach ($ids as $id) {
            if (in_array($id, $blockedIds, true)) {
                $excluded[] = ['entity_type' => $entityType, 'id' => $id, 'reason' => 'active_reference', 'label' => null];

                continue;
            }
            $survivors[] = $id;
        }

        return [$survivors, $excluded];
    }

    /**
     * Normaliza un Collection de valores de FK (pluck() de columnas nullable, tipados
     * `mixed` por PHPStan) a una `list<int>` deduplicada — descarta null (columna FK
     * nullable sin valor) antes de castear.
     *
     * @param  Collection<int, mixed>  $values
     * @return list<int>
     */
    private function normalizeIdList(Collection $values): array
    {
        $ids = $values
            ->filter(fn (mixed $value): bool => $value !== null)
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->all();

        return array_values($ids);
    }
}
