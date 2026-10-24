<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MensajeSeguroDeExcepcion;
use App\Modules\Legacy\Services\ClientesPedidosGemelosCreator;
use App\Modules\Legacy\Services\PedidosGemelosLoader;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pedidos gemelos de ICG — crea los clientes de AtlanticERP que faltan para los pedidos del Excel de Atlantic (ver `ClientesPedidosGemelosCreator`).
 * ESPECIALIZADO y de una sola vez: no forma parte de la extracción recurrente ni se agenda. DRY-RUN por defecto (solo escribe el CSV de revisión en el
 * disco privado). `--apply` exige `--operador`, `--esperado` (huella del dry-run revisado) y la frase de confirmación. Los casos de posible duplicado
 * NO se crean: salen en el informe para resolverlos a mano.
 */
final class LegacyCrearClientesPedidosGemelosCommand extends Command
{
    use MensajeSeguroDeExcepcion;

    /** @var string */
    protected $signature = 'legacy:crear-clientes-pedidos-gemelos
        {--tenant=atlantic : Slug del tenant}
        {--excel= : Ruta del Excel de Atlantic, relativa a storage/app/private. OBLIGATORIA}
        {--apply : Crea los clientes. Sin esta opción es un dry-run que no escribe en la BD}
        {--esperado= : Huella del plan revisado en el dry-run. OBLIGATORIA con --apply}
        {--confirmar= : Frase exacta «APLICAR <entorno> <tenant>». OBLIGATORIA con --apply}
        {--yes : Reemplaza --confirmar. SOLO en local y testing}
        {--operador= : Quién ejecuta (queda en el log). OBLIGATORIO con --apply}';

    /** @var string */
    protected $description = 'Pedidos gemelos de ICG — crea los clientes que faltan para los pedidos del Excel de Atlantic (una sola vez; dry-run por defecto)';

    public function __construct(private readonly ClientesPedidosGemelosCreator $creator, private readonly PedidosGemelosLoader $loader)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        if (trim((string) $this->option('excel')) === '') {
            $this->error('--excel=<ruta en storage/app/private> es obligatorio.');

            return self::FAILURE;
        }
        $relativa = trim((string) $this->option('excel'));
        if (str_contains($relativa, '..') || str_starts_with($relativa, '/') || ! is_file(Storage::disk('local')->path($relativa))) {
            $this->error('--excel debe ser un archivo dentro de storage/app/private (sin "..").');

            return self::FAILURE;
        }

        if ($apply) {
            if (trim((string) $this->option('operador')) === '' || trim((string) $this->option('esperado')) === '') {
                $this->error('--operador y --esperado=<huella> son obligatorios con --apply.');

                return self::FAILURE;
            }
            $frase = 'APLICAR '.config('app.env').' '.$this->option('tenant');
            if ((bool) $this->option('yes')) {
                if (! in_array((string) config('app.env'), ['local', 'testing'], true)) {
                    $this->error('--yes no se acepta en el entorno "'.config('app.env').'": usa --confirmar="'.$frase.'".');

                    return self::FAILURE;
                }
            } elseif (trim((string) $this->option('confirmar')) !== $frase) {
                $this->error('Falta la confirmación: --confirmar="'.$frase.'".');

                return self::FAILURE;
            }
        }

        $tenant = Tenant::where('slug', (string) $this->option('tenant'))->first();
        if ($tenant === null) {
            $this->error("Tenant '{$this->option('tenant')}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();
        try {
            return $this->planYAplicar(Storage::disk('local')->path($relativa), $apply);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function planYAplicar(string $ruta, bool $apply): int
    {
        try {
            $plan = $this->creator->plan($this->loader->leerExcel($ruta));
        } catch (Throwable $e) {
            $this->error('No se pudo calcular el plan: '.$this->mensajeSeguro($e));

            return self::FAILURE;
        }

        $crear = array_values(array_filter($plan, static fn (array $f): bool => $f['accion'] === ClientesPedidosGemelosCreator::ACCION_CREAR));
        $this->info('Tenant: '.$this->option('tenant').' — entorno: '.config('app.env'));
        $this->table(['Medida', 'Valor'], [
            ['Clientes de ICG sin SubCliente en los pedidos del Excel', count($plan)],
            ['A crear', count($crear)],
            ['No se crean (revisar a mano)', count($plan) - count($crear)],
            ['  de los que se crean, con DV de ICG', count(array_filter($crear, static fn (array $f): bool => $f['dv'] !== ''))],
        ]);
        $this->table(['Cód. ICG', 'Cliente', 'RUC/cédula', 'DV', 'Pedidos', 'Pendiente', 'Acción', 'Motivo / existentes'], array_map(
            static fn (array $f): array => [$f['icg_codcliente'], mb_substr((string) $f['nombre'], 0, 32), $f['tax_id'], $f['dv'], mb_substr((string) $f['pedidos'], 0, 18), number_format((float) $f['pendiente'], 2), $f['accion'], mb_substr((string) ($f['motivo'] ? $f['motivo'].' '.$f['existentes'] : ''), 0, 70)],
            $plan,
        ));

        $huella = $this->creator->huella($plan);
        $csv    = $this->escribirCsv($plan);
        $this->line("CSV de revisión: {$csv}");

        if (! $apply) {
            $this->info("Huella del plan: {$huella} (usar --esperado={$huella} con --apply).");
            $this->comment('Modo dry-run (por defecto): NO se escribió nada en la BD.');

            return self::SUCCESS;
        }

        if (trim((string) $this->option('esperado')) !== $huella) {
            $this->error("El plan recalculado (huella {$huella}, ".count($crear).' a crear) no coincide con --esperado: repite el dry-run y vuelve a confirmar. No se creó nada.');

            return self::FAILURE;
        }

        $res = $this->creator->aplicar($plan);
        \Illuminate\Support\Facades\Log::info('legacy.clientes-pedidos-gemelos.creados', ['operador' => trim((string) $this->option('operador')), 'creados' => $res['creados'], 'huella' => $huella]);
        $this->info("Clientes creados: {$res['creados']}.");
        if ($res['errores'] !== []) {
            $this->warn('ERRORES (cada cliente en su propia transacción): '.count($res['errores']));
            $this->table(['Cód. ICG', 'Error'], array_map(static fn (array $e): array => [$e['icg_codcliente'], $e['error']], $res['errores']));
        }

        return $res['errores'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<array<string, mixed>> $plan */
    private function escribirCsv(array $plan): string
    {
        $relativo = 'legacy/clientes-pedidos-gemelos-'.now()->format('Ymd-His').'.csv';
        $filas    = [implode(',', ['icg_codcliente', 'nombre', 'tax_id', 'dv', 'pedidos', 'pendiente', 'accion', 'motivo', 'existentes'])];
        foreach ($plan as $f) {
            $filas[] = implode(',', array_map(
                static fn (mixed $v): string => '"'.str_replace('"', '""', (string) $v).'"',
                [$f['icg_codcliente'], $f['nombre'], $f['tax_id'], $f['dv'], $f['pedidos'], $f['pendiente'], $f['accion'], $f['motivo'], $f['existentes']],
            ));
        }
        Storage::disk('local')->put($relativo, implode("\n", $filas)."\n");

        return 'storage/app/private/'.$relativo;
    }
}
