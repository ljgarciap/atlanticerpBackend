<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Legacy\Services\StockBodegaLoader;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * SCRUM-1136 F6 — verificación de SOLO LECTURA de `legacy:cargar-stock-bodegas`: por almacén confirmado compara lo esperado (todas las filas elegibles de
 * `legacy.stock_almacen` tras exclusiones y redondeo) con `product_warehouse_stock` de la bodega equivalente. Sale con 1 si hay diferencias. Una diferencia después de que Bodega
 * movió stock es normal: se interpreta con la fecha de la carga (evento `legacy.cargar_stock_bodegas`).
 */
final class LegacyVerifyStockBodegasCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:verify-stock-bodegas
        {--tenant=atlantic : Slug del tenant}
        {--almacen=* : Código(s) de almacén ICG (vacío = todos los confirmados)}';

    /** @var string */
    protected $description = 'SCRUM-1136 F6 — verifica (solo lectura) el stock cargado en las bodegas contra legacy.stock_almacen';

    public function __construct(private readonly StockBodegaLoader $loader)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $slug = (string) $this->option('tenant');
        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$slug}' no existe.");

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();
        try {
            return $this->ejecutar();
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function ejecutar(): int
    {
        try {
            $destinos = $this->loader->destinos(array_values(array_map('strval', (array) $this->option('almacen'))));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($destinos === []) {
            $this->warn('No hay equivalencias CONFIRMADAS: nada que verificar.');

            return self::SUCCESS;
        }

        $filas = [];
        $mal = 0;
        foreach ($destinos as $codigo => $d) {
            $plan = $this->loader->planear($codigo, $d['warehouse_id']);
            $v = $this->loader->verificar($d['warehouse_id'], $plan);
            $ok = $v['distintas'] === 0 && $v['esperado_suma'] === $v['real_suma'];
            $mal += $ok ? 0 : 1;
            $filas[] = [
                "{$codigo} → {$d['warehouse']}", $v['esperado_filas'], $v['real_filas'], $v['esperado_suma'], $v['real_suma'], $v['distintas'],
                count($plan['sin_catalogo']), count($plan['excluidos']), $ok ? 'OK' : 'DIFERENCIA',
            ];
        }
        $this->table(['Almacén → bodega', 'Filas esperadas', 'Filas en bodega', 'Σ esperada', 'Σ en bodega', 'Productos distintos', 'Sin catálogo (no cargados)', 'Excluidos', 'Estado'], $filas);

        if ($mal > 0) {
            $this->error("{$mal} almacén(es) con diferencias.");

            return self::FAILURE;
        }
        $this->info('Verificación OK (salvo los artículos sin catálogo y excluidos listados arriba).');

        return self::SUCCESS;
    }
}
