<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MensajeSeguroDeExcepcion;
use App\Modules\Legacy\Services\ClientePromovidoRelinker;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * SCRUM-1113 — reenlaza `legacy.clientes.sub_client_id` de los clientes ya promovidos a `sub_clients` cuyo enlace se perdió (ver `ClientePromovidoRelinker`).
 * Una sola vez por entorno. Dry-run por defecto (solo lee); `--apply` exige `--operador` y confirmación escrita `APLICAR <entorno> <tenant>`
 * (`--yes` solo en local/testing). Solo toca `legacy.clientes` (no escribe en `ventas_diseno`); el valor anterior de cada fila queda en el evento de auditoría.
 */
final class LegacyRelinkClientesPromovidosCommand extends Command
{
    use MensajeSeguroDeExcepcion;

    private const ENTORNOS_SIN_CONFIRMACION = ['local', 'testing'];

    /** @var string */
    protected $signature = 'legacy:relink-clientes-promovidos
        {--tenant=atlantic : Slug del tenant}
        {--apply : Aplica el reenlace. Sin esta opción es un dry-run que solo lee}
        {--yes : Omite la confirmación interactiva. SOLO en local y testing}
        {--operador= : Quién ejecuta (queda en el evento de auditoría). OBLIGATORIO con --apply}';

    /** @var string */
    protected $description = 'SCRUM-1113 (una sola vez) — reenlaza legacy.clientes.sub_client_id de los clientes ICG ya promovidos cuyo enlace se perdió';

    public function __construct(private readonly ClientePromovidoRelinker $relinker)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && (bool) $this->option('yes') && ! $this->entornoSinConfirmacion()) {
            $this->error('--yes no se acepta en el entorno "'.$this->entorno().'": la confirmación debe ser interactiva.');

            return self::FAILURE;
        }
        if ($apply && trim((string) $this->option('operador')) === '') {
            $this->error('--operador es obligatorio con --apply: queda en el evento de auditoría.');

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', (string) $this->option('tenant'))->first();
        if ($tenant === null) {
            $this->error("Tenant '{$this->option('tenant')}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();
        try {
            return $this->ejecutar($apply, $tenant->slug);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function ejecutar(bool $apply, string $slug): int
    {
        $plan = $this->relinker->plan();

        $this->info("Tenant: {$slug} — entorno: ".$this->entorno());
        $this->table(['Medida', 'Valor'], [
            ['Candidatos (sub_client_id nulo, unmatched/ambiguous)', $plan['candidatos']],
            ['A reenlazar', count($plan['pares'])],
            ...array_map(static fn (string $r, int $n): array => ["  por regla «{$r}»", $n], array_keys($plan['por_regla']), array_values($plan['por_regla'])),
            ['Ambiguos (revisión manual, NO se tocan)', count($plan['ambiguos'])],
            ['Coinciden solo por nombre (NO se aplican)', count($plan['solo_por_nombre'])],
        ]);

        if ($plan['ambiguos'] !== []) {
            $this->newLine();
            $this->line('<comment>Ambiguos (primeros 15)</comment>');
            $this->table(['icg_codcliente', 'Motivo'], array_map(static fn (array $a): array => [$a['icg_codcliente'], $a['motivo']], array_slice($plan['ambiguos'], 0, 15)));
        }
        if ($plan['solo_por_nombre'] !== []) {
            $this->newLine();
            $this->line('<comment>Solo por nombre (primeros 15) — revisar a mano</comment>');
            $this->table(['icg_codcliente', 'sub_client_id'], array_map(static fn (array $a): array => [$a['icg_codcliente'], $a['sub_client_id']], array_slice($plan['solo_por_nombre'], 0, 15)));
        }

        $huella = $this->relinker->huella($plan);
        if (! $apply) {
            $this->newLine();
            $this->line("Huella del plan: <info>{$huella}</info> (--apply la recalcula y aborta si cambió).");
            $this->comment('Modo dry-run (por defecto): NO se escribió nada.');

            return self::SUCCESS;
        }

        if ($plan['pares'] === []) {
            $this->info('Nada que reenlazar.');

            return self::SUCCESS;
        }

        if (! $this->confirmar($slug, $huella)) {
            $this->warn('Confirmación no recibida: no se aplicó nada.');

            return self::FAILURE;
        }

        try {
            $res = $this->relinker->aplicar($plan, trim((string) $this->option('operador')), $this->entorno());
        } catch (Throwable $e) {
            $this->error('ROLLBACK: no se aplicó nada. '.$this->mensajeSeguro($e));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Reenlazados y verificados: {$res['reenlazados']}.");

        return self::SUCCESS;
    }

    private function confirmar(string $slug, string $huella): bool
    {
        if ((bool) $this->option('yes')) {
            return true;
        }
        $esperado = 'APLICAR '.$this->entorno().' '.$slug;
        $this->newLine();
        $this->line("Huella del plan que vas a confirmar: {$huella}");

        return trim((string) $this->ask("Para continuar escribe exactamente: {$esperado}")) === $esperado;
    }

    private function entorno(): string
    {
        return (string) config('app.env');
    }

    private function entornoSinConfirmacion(): bool
    {
        return in_array($this->entorno(), self::ENTORNOS_SIN_CONFIRMACION, true);
    }
}
