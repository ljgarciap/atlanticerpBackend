<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\AdminCont\FacturacionElectronica\Adapters\DigifactPacAdapter;
use App\Modules\AdminCont\FacturacionElectronica\Mappers\NucJsonMapper;
use App\Modules\AdminCont\FacturacionElectronica\Services\DigifactTokenService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fase 24 (D) — verificación de SOLO LECTURA de las credenciales de Digifact: pide un token nuevo y, si se pasa `--ruc`, consulta
 * ese receptor. No certifica ni emite nada. Por defecto usa el ambiente de pruebas; el de producción exige `--allow-production`.
 * Nunca imprime el token ni la clave.
 */
final class FiscalValidateDigifactCommand extends Command
{
    protected $signature = 'fiscal:validate-digifact {--ambiente=pruebas : pruebas|produccion} {--ruc= : RUC/cédula a consultar (opcional)} {--allow-production : permite el ambiente de producción}';

    protected $description = 'Verifica las credenciales de Digifact (token y consulta de RUC), sin certificar nada';

    public function handle(DigifactTokenService $tokens, NucJsonMapper $mapper): int
    {
        $ambiente = (string) $this->option('ambiente');
        if (! in_array($ambiente, ['pruebas', 'produccion'], true)) {
            $this->error("Ambiente inválido '{$ambiente}': use pruebas o produccion.");

            return self::INVALID;
        }
        if ($ambiente === 'produccion' && ! $this->option('allow-production')) {
            $this->error('El ambiente de producción exige --allow-production.');

            return self::INVALID;
        }

        $faltan = array_filter(
            ['ruc' => 'DIGIFACT_RUC', 'username' => 'DIGIFACT_USERNAME', 'password' => 'DIGIFACT_PASSWORD'],
            static fn (string $env, string $key): bool => ! is_string(config("services.digifact.{$key}")) || config("services.digifact.{$key}") === '',
            ARRAY_FILTER_USE_BOTH,
        );
        if ($faltan !== []) {
            $this->error('Faltan variables en el .env: '.implode(', ', $faltan));

            return self::FAILURE;
        }

        $this->info("Ambiente: {$ambiente}");
        $this->info('Base:     '.config($ambiente === 'produccion' ? 'services.digifact.base_url_produccion' : 'services.digifact.base_url_pruebas'));

        try {
            $tokens->forget($ambiente);
            $tokens->token($ambiente);
            $this->info('✅ Token obtenido.');
        } catch (Throwable $e) {
            $this->error('❌ No se obtuvo el token: '.$e->getMessage());

            return self::FAILURE;
        }

        $ruc = (string) $this->option('ruc');
        if ($ruc === '') {
            return self::SUCCESS;
        }

        $resultado = (new DigifactPacAdapter($mapper, $tokens, $ambiente))->validarReceptor($ruc);
        if (! $resultado->disponible) {
            $this->warn('⚠️  El servicio no está disponible (circuito abierto).');

            return self::FAILURE;
        }

        $this->line('Receptor válido: '.($resultado->valido ? 'sí' : 'no'));
        $this->line('Nombre: '.($resultado->nombre ?? '—').' · DV: '.($resultado->dv ?? '—').' · Tipo: '.($resultado->tipo ?? '—'));

        return $resultado->valido ? self::SUCCESS : self::FAILURE;
    }
}
