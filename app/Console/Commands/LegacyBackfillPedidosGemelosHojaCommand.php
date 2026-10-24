<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MensajeSeguroDeExcepcion;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\Order;
use App\Modules\Legacy\Services\PedidosGemelosLoader;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pedidos gemelos de ICG — completa la hoja de origen y el vendedor de los gemelos que se cargaron ANTES de que existieran
 * `bodega.orders.icg_hoja`/`icg_vendedor`, a partir del mismo Excel de Atlantic, y deja el informe de la carga que la vista de
 * validación compara contra el Excel (mismo evento que `legacy:load-pedidos-gemelos --apply`). Solo rellena campos NULOS: nunca pisa un
 * valor existente ni toca importes, etapas, clientes o líneas. DRY-RUN por defecto; `--apply` exige `--operador`, `--esperado` (huella
 * del dry-run revisado) y la frase de confirmación. Repetirlo no cambia nada (ya no quedan nulos).
 */
final class LegacyBackfillPedidosGemelosHojaCommand extends Command
{
    use MensajeSeguroDeExcepcion;

    /** @var string */
    protected $signature = 'legacy:backfill-pedidos-gemelos-hoja
        {--tenant=atlantic : Slug del tenant}
        {--excel= : Ruta del Excel de Atlantic, relativa a storage/app/private. OBLIGATORIA}
        {--tolerancia= : La misma tolerancia (B/.) de la carga; solo afecta al informe de omitidos. OBLIGATORIA}
        {--apply : Rellena hoja y vendedor. Sin esta opción es un dry-run que no escribe en la BD}
        {--esperado= : Huella del plan revisado en el dry-run. OBLIGATORIA con --apply}
        {--confirmar= : Frase exacta «APLICAR <entorno> <tenant>». OBLIGATORIA con --apply}
        {--yes : Reemplaza --confirmar. SOLO en local y testing}
        {--operador= : Nombre de quien ejecuta (queda en el evento de auditoría). OBLIGATORIO con --apply}';

    /** @var string */
    protected $description = 'Pedidos gemelos de ICG — completa hoja y vendedor de los gemelos ya cargados desde el Excel y deja el informe de la carga (dry-run por defecto)';

    public function __construct(private readonly PedidosGemelosLoader $loader)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        if (trim((string) $this->option('excel')) === '' || ! is_numeric($this->option('tolerancia'))) {
            $this->error('--excel=<ruta en storage/app/private> y --tolerancia=<B/.> son obligatorios.');

            return self::FAILURE;
        }
        $relativa = trim((string) $this->option('excel'));
        $base     = realpath(storage_path('app/private'));
        $ruta     = realpath(storage_path('app/private/'.$relativa));
        if ($base === false || $ruta === false || ! is_file($ruta) || ! str_starts_with($ruta, $base.DIRECTORY_SEPARATOR)) {
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
            return $this->ejecutar($ruta, $apply);
        } catch (Throwable $e) {
            $this->error('No se pudo completar: '.$this->mensajeSeguro($e));

            return self::FAILURE;
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function ejecutar(string $ruta, bool $apply): int
    {
        $plan    = $this->loader->plan($this->loader->leerExcel($ruta), (float) $this->option('tolerancia'));
        $informe = $this->loader->informe($plan);

        // Gemelos ya cargados con hoja o vendedor nulos y su fila en el Excel (los demás no se tocan).
        $cambios = [];
        $sinFila = 0;
        $porClave = [];
        foreach ($plan as $f) {
            $porClave[(string) $f['serie'].'|'.(int) $f['numero']] ??= $f;
        }
        $gemelos = Order::query()->where('origin', Order::ORIGIN_ICG_LEGACY)->where(fn ($q) => $q->whereNull('icg_hoja')->orWhereNull('icg_vendedor'))->orderBy('id')->get();
        foreach ($gemelos as $o) {
            $fila = $porClave[$o->icg_serie.'|'.$o->icg_numero] ?? null;
            if ($fila === null) {
                $sinFila++;
                continue;
            }
            $nuevo = [];
            if ($o->icg_hoja === null && trim((string) $fila['hoja']) !== '') {
                $nuevo['icg_hoja'] = (string) $fila['hoja'];
            }
            if ($o->icg_vendedor === null && trim((string) $fila['vendedor']) !== '') {
                $nuevo['icg_vendedor'] = (string) $fila['vendedor'];
            }
            if ($nuevo !== []) {
                $cambios[$o->id] = $nuevo;
            }
        }

        $huella = md5((string) json_encode([$cambios, $informe['omitidos']]));
        $this->info('Tenant: '.$this->option('tenant').' — entorno: '.config('app.env'));
        $this->table(['Medida', 'Valor'], [
            ['Filas del Excel', $informe['filas']],
            ['Gemelos con hoja o vendedor nulos', $gemelos->count()],
            ['  a completar', count($cambios)],
            ['  sin fila en el Excel (no se tocan)', $sinFila],
            ['Filas del Excel sin cargar (omitidas)', count($informe['omitidos'])],
            ['Huella del plan', $huella],
        ]);

        if (! $apply) {
            $this->comment('Modo dry-run (por defecto): NO se escribió nada. Para aplicar: --apply --operador="<nombre>" --confirmar="APLICAR '.config('app.env').' '.$this->option('tenant').'" --esperado='.$huella);

            return self::SUCCESS;
        }
        if (trim((string) $this->option('esperado')) !== $huella) {
            $this->error("El plan recalculado (huella {$huella}) no coincide con --esperado: repite el dry-run y vuelve a confirmar. No se escribió nada.");

            return self::FAILURE;
        }
        if ($cambios === [] && (AuditLog::query()->where('action', AuditService::LEGACY_CARGAR_PEDIDOS_GEMELOS)->exists())) {
            $this->info('Nada que aplicar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($cambios, $informe, $huella): void {
            foreach ($cambios as $id => $nuevo) {
                Order::query()->whereKey($id)->update($nuevo);
            }
            AuditLog::query()->create([
                'user_id'     => null,
                'action'      => AuditService::LEGACY_CARGAR_PEDIDOS_GEMELOS,
                'entity_type' => 'order',
                'metadata'    => [
                    'origen'     => 'backfill_hoja',
                    'operador'   => (string) $this->option('operador'),
                    'entorno'    => (string) config('app.env'),
                    'tenant'     => (string) $this->option('tenant'),
                    'huella'     => $huella,
                    'tolerancia' => (float) $this->option('tolerancia'),
                    'filas'      => $informe['filas'],
                    'cargados'   => Order::query()->where('origin', Order::ORIGIN_ICG_LEGACY)->count(),
                    'errores'    => [],
                    'por_hoja'   => $informe['por_hoja'],
                    'omitidos'   => $informe['omitidos'],
                    'sin_dv'     => $informe['sin_dv'],
                    // id del pedido → campos rellenados (todos estaban en NULL): permite revertirlos a NULL.
                    'completados' => $cambios,
                ],
            ]);
        });

        $this->info('Gemelos completados: '.count($cambios).'. Informe de la carga guardado.');

        return self::SUCCESS;
    }
}
