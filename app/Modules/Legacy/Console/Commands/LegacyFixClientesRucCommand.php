<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Console\Commands\Concerns\MensajeSeguroDeExcepcion;
use App\Modules\Legacy\Models\ClienteRucCorreccion;
use App\Modules\Legacy\Models\ClienteRucCotizacionCorreccion;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\ClienteRucCorrector;
use App\Modules\Legacy\Services\ClienteRucCotizacionSync;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * SCRUM-1137 F3 — corrige `sub_clients.tax_id`/`tax_id_dv` truncados de los clientes de ICG ya enlazados (ver `ClienteRucCorrector` para las reglas).
 * DRY-RUN por defecto: no escribe en la BD ni registra la corrida (solo escribe el CSV de revisión en el disco local PRIVADO). `--apply` exige `--operador`;
 * `--revert=<run_id>` restaura los valores anteriores de esa corrida. La consola muestra solo conteos y códigos (nunca RUC completos ni nombres); el CSV, que sí
 * trae los valores, queda bajo `storage/app/private` (ignorado por git, nunca un disco público).
 *
 * `--cotizaciones` cambia el objetivo: en vez de `sub_clients`, ajusta `quotes.ruc` (la copia del RUC que cada cotización guarda al crearse) al RUC ya
 * corregido de su sub_client (ver `ClienteRucCotizacionSync`), con exactamente el mismo protocolo. Se corre DESPUÉS del apply de sub_clients. `--revert=<run_id>`
 * decide solo qué se revierte según dónde esté registrado el run_id: un run de sub_clients nunca toca cotizaciones.
 */
final class LegacyFixClientesRucCommand extends Command
{
    use MensajeSeguroDeExcepcion;

    /** @var string */
    protected $signature = 'legacy:fix-clientes-ruc
        {--tenant=atlantic : Slug del tenant}
        {--dry-run : Explícito: es el comportamiento por defecto (no se combina con --apply o --revert)}
        {--apply : Aplica las correcciones. Sin esta opción es un dry-run que no escribe en la BD}
        {--esperado= : Huella del plan revisado en el dry-run. OBLIGATORIA con --apply; si el plan recalculado no coincide, aborta}
        {--confirmar= : Frase exacta «APLICAR <entorno> <tenant>». OBLIGATORIA con --apply}
        {--yes : Reemplaza --confirmar. SOLO en local y testing}
        {--operador= : Quién ejecuta (queda en la auditoría). OBLIGATORIO con --apply o --revert}
        {--cotizaciones : Objetivo: ajusta quotes.ruc al RUC ya corregido del sub_client (misma mecánica: dry-run, huella, --apply, --revert)}
        {--revert= : run_id de una corrida aplicada que se quiere revertir (de sub_clients o de cotizaciones)}
        {--csv= : Ruta del CSV, relativa a storage/app/private (o absoluta DENTRO de ella). Por defecto legacy/ruc-correcciones-<run_id|dry>.csv}';

    /** @var string */
    protected $description = 'SCRUM-1137 F3 — corrige el tax_id (RUC completo) y el DV de los sub_clients ya enlazados a clientes de ICG (dry-run por defecto, con reversa por corrida)';

    public function __construct(private readonly ClienteRucCorrector $corrector, private readonly ClienteRucCotizacionSync $cotizaciones)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply  = (bool) $this->option('apply');
        $revert = trim((string) $this->option('revert'));

        if ((bool) $this->option('dry-run') && ($apply || $revert !== '')) {
            $this->error('--dry-run no se combina con --apply ni con --revert.');

            return self::FAILURE;
        }
        if ($apply && $revert !== '') {
            $this->error('--apply y --revert no se combinan: elige uno.');

            return self::FAILURE;
        }
        if (($apply || $revert !== '') && trim((string) $this->option('operador')) === '') {
            $this->error('--operador es obligatorio con --apply y con --revert: queda en la auditoría.');

            return self::FAILURE;
        }

        if ($apply) {
            if (trim((string) $this->option('esperado')) === '') {
                $this->error('--esperado=<huella> es obligatorio con --apply: es la huella que imprimió el dry-run revisado.');

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

        if ($revert !== '' && ! \Illuminate\Support\Str::isUuid($revert)) {
            $this->error('--revert debe ser un run_id (uuid) devuelto por una corrida con --apply.');

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', (string) $this->option('tenant'))->first();
        if ($tenant === null) {
            $this->error("Tenant '{$this->option('tenant')}' no existe.");

            return self::FAILURE;
        }

        $csvPersonalizado = trim((string) $this->option('csv'));
        if ($csvPersonalizado !== '' && $this->rutaPrivada($csvPersonalizado) === null) {
            $this->error('--csv debe estar dentro de storage/app/private (sin "..").');

            return self::FAILURE;
        }

        $tenant->makeCurrent();
        try {
            $conCotizaciones = (bool) $this->option('cotizaciones');
            if ($revert !== '') {
                return $conCotizaciones || $this->cotizaciones->existeCorrida($revert) ? $this->revertirCotizaciones($revert) : $this->revertir($revert);
            }

            return $conCotizaciones ? $this->planYAplicarCotizaciones($apply) : $this->planYAplicar($apply);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function planYAplicar(bool $apply): int
    {
        $inicio = now();
        $t0     = microtime(true);

        try {
            $plan = $this->corrector->plan();
        } catch (Throwable $e) {
            $this->error('No se pudo calcular el plan: '.$this->mensajeSeguro($e));

            return self::FAILURE;
        }

        $corregir = array_values(array_filter($plan, static fn (array $f): bool => $f['accion'] === ClienteRucCorrector::ACCION_CORREGIR));
        $porMotivo = [];
        foreach ($plan as $f) {
            if ($f['accion'] === ClienteRucCorrector::ACCION_OMITIR) {
                $porMotivo[$f['motivo']] = ($porMotivo[$f['motivo']] ?? 0) + 1;
            }
        }
        ksort($porMotivo);

        $this->info('Tenant: '.$this->option('tenant').' — entorno: '.config('app.env'));
        $this->table(['Medida', 'Valor'], [
            ['Evaluados', count($plan)],
            ['A corregir', count($corregir)],
            ['Omitidos', count($plan) - count($corregir)],
            ...array_map(static fn (string $m, int $n): array => ["  omitidos por «{$m}»", $n], array_keys($porMotivo), array_values($porMotivo)),
        ]);

        $huella = $this->corrector->huella($plan);
        if (! $apply) {
            $this->newLine();
            $this->info('A corregir: '.count($corregir).". Huella del plan: {$huella} (usar --esperado={$huella} con --apply).");
            $this->comment('Modo dry-run (por defecto): NO se escribió nada en la BD ni se registró la corrida.');
            $this->line('CSV de revisión: '.$this->escribirCsv($plan, null));

            return self::SUCCESS;
        }

        if (trim((string) $this->option('esperado')) !== $huella) {
            $this->error("El plan recalculado (huella {$huella}, ".count($corregir).' a corregir) no coincide con --esperado: repite el dry-run, revisa el CSV y vuelve a confirmar. No se aplicó nada.');

            return self::FAILURE;
        }

        // El run_id se genera e imprime ANTES de escribir nada: si el proceso muere a mitad, ya se sabe qué revertir.
        $runId = (string) \Illuminate\Support\Str::uuid();
        $this->newLine();
        $this->info("run_id: {$runId}");
        $this->line('CSV de revisión: '.$this->escribirCsv($plan, $runId));

        $operador = trim((string) $this->option('operador'));
        $run = SyncRun::query()->create([
            'entidad' => 'clientes_ruc_fix', 'extraidos' => count($plan), 'staged' => count($plan), 'mapeados' => 0, 'cargados' => 0,
            'excluidos' => count($plan) - count($corregir), 'errores' => 0, 'duracion_ms' => 0, 'status' => SyncRun::STATUS_PARTIAL,
            'started_at' => $inicio, 'finished_at' => now(), 'created_at' => now(),
        ]);

        $res = null;
        $status = SyncRun::STATUS_FAILED;
        try {
            $res = $this->corrector->aplicar($plan, $operador, (string) config('app.env'), $runId);
            $status = $res['errores'] !== [] ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS;
        } catch (Throwable $e) {
            $this->error('Corrida interrumpida: '.$this->mensajeSeguro($e)." Lo ya aplicado sigue registrado: revertible con --revert={$runId}.");
        } finally {
            $run->update([
                'mapeados'    => $res['corregidos'] ?? ClienteRucCorreccion::query()->where('run_id', $runId)->count(),
                'cargados'    => $res['corregidos'] ?? ClienteRucCorreccion::query()->where('run_id', $runId)->count(),
                'excluidos'   => count($plan) - count($corregir) + count($res['omitidos_en_apply'] ?? []),
                'errores'     => $res === null ? 1 : count($res['errores']),
                'duracion_ms' => (int) round((microtime(true) - $t0) * 1000),
                'status'      => $status,
                'finished_at' => now(),
            ]);
        }

        if ($res === null) {
            return self::FAILURE;
        }

        $this->info("Corregidos y verificados: {$res['corregidos']}.");
        foreach (['omitidos_en_apply' => 'Omitidos por cambio desde el plan', 'errores' => 'ERRORES (revertidos)'] as $k => $titulo) {
            if ($res[$k] !== []) {
                $this->warn("{$titulo}: ".count($res[$k]));
                $this->table(['icg_codcliente', 'sub_client_id', 'Motivo'], array_map(static fn (array $a): array => [$a['icg_codcliente'], $a['sub_client_id'], $a['motivo']], array_slice($res[$k], 0, 20)));
            }
        }

        return $res['errores'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function revertir(string $runId): int
    {
        $inicio = now();
        $t0     = microtime(true);

        $res = $this->corrector->revertir($runId, trim((string) $this->option('operador')), (string) config('app.env'));
        if (! $res['existe']) {
            $this->error("No hay correcciones registradas para el run_id {$runId}.");

            return self::FAILURE;
        }

        $this->info("Reversa del run_id {$runId}: revertidos {$res['revertidos']}, ya revertidos antes {$res['ya_revertidos']}, omitidos ".count($res['omitidos']).', errores '.count($res['errores']).'.');
        foreach (['omitidos' => 'Omitidos (no se pisa lo editado después)', 'errores' => 'ERRORES'] as $k => $titulo) {
            if ($res[$k] !== []) {
                $this->warn($titulo.':');
                $this->table(['icg_codcliente', 'sub_client_id', 'Motivo'], array_map(static fn (array $a): array => [$a['icg_codcliente'], $a['sub_client_id'], $a['motivo']], array_slice($res[$k], 0, 20)));
            }
        }

        $this->registrarCorrida($inicio, $t0, $res['revertidos'] + $res['ya_revertidos'] + count($res['omitidos']) + count($res['errores']), $res['revertidos'], count($res['errores']), count($res['omitidos']));

        return $res['errores'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function planYAplicarCotizaciones(bool $apply): int
    {
        $inicio = now();
        $t0     = microtime(true);
        $cols   = ['quote_id', 'sub_client_id', 'ruc_actual', 'ruc_propuesto', 'accion', 'motivo'];

        try {
            $plan = $this->cotizaciones->plan();
        } catch (Throwable $e) {
            $this->error('No se pudo calcular el plan de cotizaciones: '.$this->mensajeSeguro($e));

            return self::FAILURE;
        }

        $corregir  = array_values(array_filter($plan, static fn (array $f): bool => $f['accion'] === ClienteRucCotizacionSync::ACCION_CORREGIR));
        $porMotivo = [];
        foreach ($plan as $f) {
            if ($f['accion'] === ClienteRucCotizacionSync::ACCION_OMITIR) {
                $porMotivo[$f['motivo']] = ($porMotivo[$f['motivo']] ?? 0) + 1;
            }
        }
        ksort($porMotivo);

        $this->info('Cotizaciones — tenant: '.$this->option('tenant').' — entorno: '.config('app.env'));
        $this->table(['Medida', 'Valor'], [
            ['Evaluadas', count($plan)],
            ['A corregir', count($corregir)],
            ['Omitidas', count($plan) - count($corregir)],
            ...array_map(static fn (string $m, int $n): array => ["  omitidas por «{$m}»", $n], array_keys($porMotivo), array_values($porMotivo)),
        ]);

        $huella = $this->cotizaciones->huella($plan);
        if (! $apply) {
            $this->newLine();
            $this->info('A corregir: '.count($corregir).". Huella del plan: {$huella} (usar --esperado={$huella} con --apply --cotizaciones).");
            $this->comment('Modo dry-run (por defecto): NO se escribió nada en la BD ni se registró la corrida.');
            $this->line('CSV de revisión: '.$this->escribirCsv($plan, null, 'cotizaciones-ruc', $cols));

            return self::SUCCESS;
        }

        if (trim((string) $this->option('esperado')) !== $huella) {
            $this->error("El plan recalculado (huella {$huella}, ".count($corregir).' a corregir) no coincide con --esperado: repite el dry-run, revisa el CSV y vuelve a confirmar. No se aplicó nada.');

            return self::FAILURE;
        }

        // El run_id se genera e imprime ANTES de escribir nada: si el proceso muere a mitad, ya se sabe qué revertir.
        $runId = (string) \Illuminate\Support\Str::uuid();
        $this->newLine();
        $this->info("run_id: {$runId}");
        $this->line('CSV de revisión: '.$this->escribirCsv($plan, $runId, 'cotizaciones-ruc', $cols));

        $operador = trim((string) $this->option('operador'));
        $run = SyncRun::query()->create([
            'entidad' => 'cotizaciones_ruc_fix', 'extraidos' => count($plan), 'staged' => count($plan), 'mapeados' => 0, 'cargados' => 0,
            'excluidos' => count($plan) - count($corregir), 'errores' => 0, 'duracion_ms' => 0, 'status' => SyncRun::STATUS_PARTIAL,
            'started_at' => $inicio, 'finished_at' => now(), 'created_at' => now(),
        ]);

        $res    = null;
        $status = SyncRun::STATUS_FAILED;
        try {
            $res    = $this->cotizaciones->aplicar($plan, $operador, (string) config('app.env'), $runId);
            $status = $res['errores'] !== [] ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS;
        } catch (Throwable $e) {
            $this->error('Corrida interrumpida: '.$this->mensajeSeguro($e)." Lo ya aplicado sigue registrado: revertible con --revert={$runId}.");
        } finally {
            $aplicadas = $res['corregidos'] ?? ClienteRucCotizacionCorreccion::query()->where('run_id', $runId)->count();
            $run->update([
                'mapeados'    => $aplicadas,
                'cargados'    => $aplicadas,
                'excluidos'   => count($plan) - count($corregir) + count($res['omitidos_en_apply'] ?? []),
                'errores'     => $res === null ? 1 : count($res['errores']),
                'duracion_ms' => (int) round((microtime(true) - $t0) * 1000),
                'status'      => $status,
                'finished_at' => now(),
            ]);
        }

        if ($res === null) {
            return self::FAILURE;
        }

        $this->info("Cotizaciones corregidas y verificadas: {$res['corregidos']}.");
        foreach (['omitidos_en_apply' => 'Omitidas por cambio desde el plan', 'errores' => 'ERRORES (revertidos)'] as $k => $titulo) {
            if ($res[$k] !== []) {
                $this->warn("{$titulo}: ".count($res[$k]));
                $this->table(['quote_id', 'sub_client_id', 'Motivo'], array_map(static fn (array $a): array => [$a['quote_id'], $a['sub_client_id'], $a['motivo']], array_slice($res[$k], 0, 20)));
            }
        }

        return $res['errores'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function revertirCotizaciones(string $runId): int
    {
        $inicio = now();
        $t0     = microtime(true);

        $res = $this->cotizaciones->revertir($runId, trim((string) $this->option('operador')), (string) config('app.env'));
        if (! $res['existe']) {
            $this->error("No hay correcciones de cotizaciones registradas para el run_id {$runId}.");

            return self::FAILURE;
        }

        $this->info("Reversa de cotizaciones del run_id {$runId}: revertidas {$res['revertidos']}, ya revertidas antes {$res['ya_revertidos']}, omitidas ".count($res['omitidos']).', errores '.count($res['errores']).'.');
        foreach (['omitidos' => 'Omitidas (no se pisa lo editado después)', 'errores' => 'ERRORES'] as $k => $titulo) {
            if ($res[$k] !== []) {
                $this->warn($titulo.':');
                $this->table(['quote_id', 'sub_client_id', 'Motivo'], array_map(static fn (array $a): array => [$a['quote_id'], $a['sub_client_id'], $a['motivo']], array_slice($res[$k], 0, 20)));
            }
        }

        $this->registrarCorrida($inicio, $t0, $res['revertidos'] + $res['ya_revertidos'] + count($res['omitidos']) + count($res['errores']), $res['revertidos'], count($res['errores']), count($res['omitidos']), 'cotizaciones_ruc_fix');

        return $res['errores'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param list<array<string, mixed>> $plan
     * @param list<string>|null $cols
     * @return string ruta relativa a storage/app/private (sin valores sensibles en el nombre)
     */
    private function escribirCsv(array $plan, ?string $runId, string $prefijo = 'ruc-correcciones', ?array $cols = null): string
    {
        $personalizado = trim((string) $this->option('csv'));
        $rel = $personalizado !== '' ? (string) $this->rutaPrivada($personalizado) : 'legacy/'.$prefijo.'-'.($runId ?? 'dry').'.csv';

        $cols ??= ['icg_codcliente', 'sub_client_id', 'tax_id_actual', 'tax_id_propuesto', 'dv_actual', 'dv_propuesto', 'accion', 'motivo'];
        $lineas = [self::lineaCsv($cols)];
        foreach ($plan as $f) {
            $lineas[] = self::lineaCsv(array_map(static fn (string $c): string => self::neutralizar((string) ($f[$c] ?? '')), $cols));
        }
        Storage::disk('local')->put($rel, implode("\n", $lineas)."\n");

        return 'storage/app/private/'.$rel;
    }

    /** Neutraliza inyección de fórmulas al abrir el CSV en Excel/Sheets: prefija una comilla simple a toda celda que empiece por = + - @ tab o retorno. */
    private static function neutralizar(string $v): string
    {
        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }

    /** @param list<string> $valores */
    private static function lineaCsv(array $valores): string
    {
        return implode(',', array_map(static fn (string $v): string => preg_match('/[",\r\n]/', $v) === 1 ? '"'.str_replace('"', '""', $v).'"' : $v, $valores));
    }

    /** Devuelve la ruta relativa al disco privado, o null si sale de él. */
    private function rutaPrivada(string $ruta): ?string
    {
        $raiz = rtrim(str_replace('\\', '/', Storage::disk('local')->path('')), '/').'/';
        $ruta = str_replace('\\', '/', $ruta);
        if (str_starts_with($ruta, '/')) {
            if (! str_starts_with($ruta, $raiz)) {
                return null;
            }
            $ruta = substr($ruta, strlen($raiz));
        }
        if ($ruta === '' || in_array('..', explode('/', $ruta), true)) {
            return null;
        }

        return $ruta;
    }

    private function registrarCorrida(\Illuminate\Support\Carbon $inicio, float $t0, int $extraidos, int $cargados, int $errores, int $excluidos, string $entidad = 'clientes_ruc_fix'): void
    {
        SyncRun::query()->create([
            'entidad'     => $entidad,
            'extraidos'   => $extraidos,
            'staged'      => $extraidos,
            'mapeados'    => $cargados,
            'cargados'    => $cargados,
            'excluidos'   => $excluidos,
            'errores'     => $errores,
            'duracion_ms' => (int) round((microtime(true) - $t0) * 1000),
            'status'      => $errores > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS,
            'started_at'  => $inicio,
            'finished_at' => now(),
            'created_at'  => now(),
        ]);
    }
}
