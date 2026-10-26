<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Console\Commands\Concerns\MensajeSeguroDeExcepcion;
use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Legacy\Services\PedidosGemelosLoader;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pedidos gemelos de ICG (spec `docs/specs/icg-pedidos-gemelos.md`, T4) — carga como pedidos gemelos los pedidos pendientes por facturar del
 * Excel de Atlantic (ver `PedidosGemelosLoader`). DRY-RUN por defecto: no escribe en la BD, solo el CSV de revisión en el disco local PRIVADO.
 * `--apply` exige `--operador` (email de un usuario), `--esperado` (huella del dry-run revisado) y la frase de confirmación. Es idempotente por
 * (serie, número): repetir no duplica. La consola muestra conteos y claves de pedido; el CSV, con nombres, queda en `storage/app/private`.
 */
final class LegacyLoadPedidosGemelosCommand extends Command
{
    use MensajeSeguroDeExcepcion;

    /** @var string */
    protected $signature = 'legacy:load-pedidos-gemelos
        {--tenant=atlantic : Slug del tenant}
        {--excel= : Ruta del Excel de Atlantic, relativa a storage/app/private. OBLIGATORIA}
        {--tolerancia= : Diferencia máxima en B/. aceptada entre el Excel y ICG (total y pendiente). OBLIGATORIA: no hay valor por defecto}
        {--dry-run : Explícito: es el comportamiento por defecto (no se combina con --apply)}
        {--apply : Carga los pedidos. Sin esta opción es un dry-run que no escribe en la BD}
        {--esperado= : Huella del plan revisado en el dry-run. OBLIGATORIA con --apply}
        {--confirmar= : Frase exacta «APLICAR <entorno> <tenant>». OBLIGATORIA con --apply}
        {--yes : Reemplaza --confirmar. SOLO en local y testing}
        {--operador= : Email del usuario que ejecuta (dueño de las cotizaciones gemelas). OBLIGATORIO con --apply}';

    /** @var string */
    protected $description = 'Pedidos gemelos de ICG — carga los pedidos pendientes por facturar del Excel de Atlantic (dry-run por defecto)';

    public function __construct(private readonly PedidosGemelosLoader $loader)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if ((bool) $this->option('dry-run') && $apply) {
            $this->error('--dry-run no se combina con --apply.');

            return self::FAILURE;
        }
        if (trim((string) $this->option('excel')) === '' || ! is_numeric($this->option('tolerancia'))) {
            $this->error('--excel=<ruta en storage/app/private> y --tolerancia=<B/.> son obligatorios.');

            return self::FAILURE;
        }
        $ruta = $this->rutaPrivada(trim((string) $this->option('excel')));
        if ($ruta === null || ! is_file($ruta)) {
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
            return $this->planYAplicar($ruta, $apply);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function planYAplicar(string $ruta, bool $apply): int
    {
        try {
            $plan = $this->loader->plan($this->loader->leerExcel($ruta), (float) $this->option('tolerancia'));
        } catch (Throwable $e) {
            $this->error('No se pudo calcular el plan: '.$this->mensajeSeguro($e));

            return self::FAILURE;
        }

        $cargar    = array_values(array_filter($plan, static fn (array $f): bool => $f['accion'] === PedidosGemelosLoader::ACCION_CARGAR));
        $porMotivo = [];
        foreach ($plan as $f) {
            if ($f['accion'] !== PedidosGemelosLoader::ACCION_CARGAR) {
                $m = $f['accion'] === PedidosGemelosLoader::ACCION_YA_CARGADO ? 'ya cargado' : (string) $f['motivo'];
                $porMotivo[$m] = ($porMotivo[$m] ?? 0) + 1;
            }
        }
        $informe = $this->loader->informe($plan);
        $porHoja = $informe['por_hoja'];
        ksort($porMotivo);

        $this->info('Tenant: '.$this->option('tenant').' — entorno: '.config('app.env'));
        $this->table(['Medida', 'Valor'], [
            ['Filas del Excel', count($plan)],
            ['A cargar', count($cargar)],
            ['No se cargan', count($plan) - count($cargar)],
            ['  de los que se cargan, sin DV (no facturables hasta completarlo)', count(array_filter($cargar, static fn (array $f): bool => $f['advertencia'] !== null))],
            ...array_map(static fn (string $m, int $n): array => ["  por «{$m}»", $n], array_keys($porMotivo), array_values($porMotivo)),
        ]);
        $this->table(['Hoja', 'A cargar', 'Pendiente a cargar (ICG)', 'Pendiente de toda la hoja (Excel)'], array_map(
            static fn (string $h, array $v): array => [$h, $v['n'], number_format($v['cargar'], 2), number_format($v['excel'], 2)],
            array_keys($porHoja),
            array_values($porHoja),
        ));

        $huella = $this->loader->huella($plan);
        $csv    = $this->escribirCsv($plan);
        $this->line("CSV de revisión: {$csv}");

        if (! $apply) {
            $this->info("Huella del plan: {$huella} (usar --esperado={$huella} con --apply).");
            $this->comment('Modo dry-run (por defecto): NO se escribió nada en la BD.');

            return self::SUCCESS;
        }

        if (trim((string) $this->option('esperado')) !== $huella) {
            $this->error("El plan recalculado (huella {$huella}, ".count($cargar).' a cargar) no coincide con --esperado: repite el dry-run, revisa el CSV y vuelve a confirmar. No se cargó nada.');

            return self::FAILURE;
        }

        $operador = User::query()->where('email', trim((string) $this->option('operador')))->first();
        if ($operador === null) {
            $this->error('--operador no corresponde a ningún usuario.');

            return self::FAILURE;
        }

        $res = $this->loader->aplicar($plan, $operador);
        $this->info("Pedidos gemelos cargados: {$res['cargados']}.");
        $this->registrarEvento($informe, $res, $huella);
        if ($res['errores'] !== []) {
            $this->warn('ERRORES (cada pedido en su propia transacción, los demás siguen cargados): '.count($res['errores']));
            $this->table(['Pedido', 'Error'], array_map(static fn (array $e): array => [$e['clave'], $e['error']], $res['errores']));
        }

        return $res['errores'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Un evento de auditoría por corrida con el informe que la vista de validación necesita (omitidos con su motivo, advertencias
     * y totales por hoja).
     *
     * @param array{filas: int, por_hoja: array<string, array{excel: float, cargar: float, n: int}>, omitidos: list<array<string, mixed>>, sin_dv: list<string>} $informe
     * @param array{cargados: int, errores: list<array{clave: string, error: string}>} $res
     */
    private function registrarEvento(array $informe, array $res, string $huella): void
    {
        AuditLog::query()->create([
            'user_id'     => null,
            'action'      => AuditService::LEGACY_CARGAR_PEDIDOS_GEMELOS,
            'entity_type' => 'order',
            'metadata'    => [
                'operador'   => (string) $this->option('operador'),
                'entorno'    => (string) config('app.env'),
                'tenant'     => (string) $this->option('tenant'),
                'huella'     => $huella,
                'tolerancia' => (float) $this->option('tolerancia'),
                'filas'      => $informe['filas'],
                'cargados'   => $res['cargados'],
                'errores'    => $res['errores'],
                'por_hoja'   => $informe['por_hoja'],
                'omitidos'   => $informe['omitidos'],
                'sin_dv'     => $informe['sin_dv'],
            ],
        ]);
    }

    /** @param list<array<string, mixed>> $plan */
    private function escribirCsv(array $plan): string
    {
        $relativo = 'legacy/pedidos-gemelos-'.now()->format('Ymd-His').'.csv';
        $filas    = [implode(',', ['hoja', 'pedido', 'accion', 'motivo', 'vendedor', 'cliente', 'total_excel', 'total_icg', 'pendiente_excel', 'pendiente_icg', 'fecha_icg', 'advertencia'])];
        foreach ($plan as $f) {
            $filas[] = implode(',', array_map(
                static fn (mixed $v): string => '"'.str_replace('"', '""', (string) $v).'"',
                [$f['hoja'], $f['clave'], $f['accion'], $f['motivo'], $f['vendedor'], $f['cliente'], $f['total'], $f['total_icg'], $f['pendiente'], $f['pendiente_icg'], $f['fecha'], $f['advertencia']],
            ));
        }
        Storage::disk('local')->put($relativo, implode("\n", $filas)."\n");

        return 'storage/app/private/'.$relativo;
    }

    private function rutaPrivada(string $relativa): ?string
    {
        if (str_contains($relativa, '..') || str_starts_with($relativa, '/')) {
            return null;
        }

        return Storage::disk('local')->path($relativa);
    }
}
