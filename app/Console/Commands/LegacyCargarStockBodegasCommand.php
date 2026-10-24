<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MensajeSeguroDeExcepcion;
use App\Modules\Legacy\Services\StockBodegaLoader;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * SCRUM-1136 F6 — carga ÚNICA del stock de ICG (`legacy.stock_almacen`) a `product_warehouse_stock` + Kardex en las bodegas de AtlanticERP equivalentes a los almacenes
 * CONFIRMADOS en `legacy.almacen_equivalencias`. Ver `StockBodegaLoader` para las reglas. Nunca toca Bodega Central ni una bodega `solo_consulta`, ni pisa filas existentes.
 *
 * DRY-RUN POR DEFECTO. `--apply` exige `--operador=`; una transacción por almacén con verificación antes/después y ROLLBACK ante cualquier discrepancia (un almacén que falla
 * no deshace los ya cargados; el comando termina en error). Idempotente: la segunda corrida no encuentra nada. Orden: antes, `legacy:crear-articulos-sin-catalogo` y
 * `legacy:crear-descatalogados-inactivos` (F5), o esos artículos quedan en la lista de «sin catálogo».
 */
final class LegacyCargarStockBodegasCommand extends Command
{
    use MensajeSeguroDeExcepcion;

    /** @var string */
    protected $signature = 'legacy:cargar-stock-bodegas
        {--tenant=atlantic : Slug del tenant}
        {--almacen=* : Código(s) de almacén ICG (vacío = todos los confirmados en legacy.almacen_equivalencias)}
        {--apply : Escribe stock y Kardex. Sin esta opción es un dry-run que solo lee}
        {--operador= : Quién ejecuta (queda en el evento de auditoría). OBLIGATORIO con --apply}
        {--lote=500 : Filas por INSERT}';

    /** @var string */
    protected $description = 'SCRUM-1136 F6 — carga única del stock de ICG a las bodegas de AtlanticERP (stock + Kardex), por almacén confirmado (dry-run por defecto)';

    public function __construct(private readonly StockBodegaLoader $loader)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && trim((string) $this->option('operador')) === '') {
            $this->error('--operador es obligatorio con --apply: queda en el evento de auditoría.');

            return self::FAILURE;
        }
        $lote = filter_var($this->option('lote'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        if ($lote === false) {
            $this->error('--lote debe ser un entero entre 1 y 5000.');

            return self::FAILURE;
        }

        $slug = (string) $this->option('tenant');
        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$slug}' no existe.");

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();
        try {
            return $this->ejecutar($slug, $apply, $lote);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function ejecutar(string $slug, bool $apply, int $lote): int
    {
        try {
            $destinos = $this->loader->destinos(array_values(array_map('strval', (array) $this->option('almacen'))));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($destinos === []) {
            $this->warn('No hay equivalencias CONFIRMADAS en legacy.almacen_equivalencias: no se escribe nada.');

            return self::SUCCESS;
        }

        $this->info('legacy:cargar-stock-bodegas — ' . ($apply ? 'APLICAR' : 'DRY-RUN'));
        $planes = [];
        $filas = [];
        foreach ($destinos as $codigo => $d) {
            $plan = $this->loader->planear($codigo, $d['warehouse_id']);
            $planes[$codigo] = $plan;
            $filas[] = [
                "{$codigo} → {$d['warehouse']}" . ($d['excluye_disponible'] ? ' (excluye disponible)' : ''),
                count($plan['filas']), $plan['positivas'], "{$plan['negativas']} en {$plan['n_negativos']}", $plan['suma'], number_format($plan['valor_costo'], 2),
                $plan['ya_existian'], count($plan['sin_catalogo']), count($plan['excluidos']),
            ];
        }
        $this->table(['Almacén → bodega', 'A sembrar', 'u. positivas', 'u. negativas (tal cual)', 'Σ filas', 'Valor a costo', 'Ya existían', 'Sin catálogo', 'Excluidos'], $filas);

        foreach ($planes as $codigo => $plan) {
            if ($destinos[$codigo]['excluye_disponible'] && $plan['filas'] !== []) {
                $this->warn("AVISO {$codigo}: la bodega \"{$destinos[$codigo]['warehouse']}\" está marcada excluye_disponible; el agregado de los productos sube y el «Disponible» se descuenta por esa bodega (neto 0).");
            }
            if ($plan['sin_catalogo'] !== []) {
                $this->warn("{$codigo}: " . count($plan['sin_catalogo']) . ' artículos con stock NO se cargan por no resolver a catálogo (correr legacy:crear-articulos-sin-catalogo / F5 antes):');
                $this->table(['codarticulo', 'stock', 'descatalogado', 'en legacy.productos'], array_map(
                    static fn (array $a): array => [$a['codarticulo'], $a['stock'], $a['descatalogado'] === null ? '—' : ($a['descatalogado'] ? 'sí' : 'no'), $a['en_staging'] ? 'sí' : 'no'],
                    array_slice($plan['sin_catalogo'], 0, 60),
                ));
            }
            if ($plan['excluidos'] !== []) {
                $this->warn("{$codigo}: excluidos por configuración (revisión manual): " . implode(', ', array_map(static fn (array $a): string => "{$a['codarticulo']} ({$a['stock']})", $plan['excluidos'])));
            }
        }

        if (! $apply) {
            $this->comment('Modo dry-run (por defecto): NO se escribió nada. Para aplicar: --apply --operador="<nombre>".');

            return self::SUCCESS;
        }

        $fallos = 0;
        $total = 0;
        foreach ($destinos as $codigo => $d) {
            if ($planes[$codigo]['filas'] === []) {
                $this->line("{$codigo}: nada que sembrar.");
                continue;
            }
            try {
                $n = $this->loader->aplicar($codigo, $d['warehouse_id'], $planes[$codigo], $lote, (string) $this->option('operador'), $slug);
            } catch (Throwable $e) {
                $this->error("{$codigo}: ROLLBACK, no se escribió nada de ese almacén. " . $this->mensajeSeguro($e));
                $fallos++;
                continue;
            }
            $total += $n;
            $this->info("{$codigo}: sembradas {$n} filas en {$d['warehouse']} (stock + Kardex + agregado) y verificadas.");
        }

        $this->newLine();
        $this->info("Total sembrado: {$total} filas. Verificar con legacy:verify-stock-bodegas.");

        return $fallos === 0 ? self::SUCCESS : self::FAILURE;
    }
}
