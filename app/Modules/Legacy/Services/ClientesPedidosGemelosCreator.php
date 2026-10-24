<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\VentasDiseno\Models\SubClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pedidos gemelos de ICG — creación ESPECIALIZADA y de una sola vez de los clientes de AtlanticERP que faltan para los pedidos del Excel de Atlantic.
 * No es una extracción recurrente: solo mira los clientes de ICG de los pedidos de ese Excel que todavía no tienen SubCliente, y nunca crea uno si hay
 * duda de duplicado (mismo nombre, o el mismo RUC —incluido un RUC cortado— en un cliente existente: ese caso se corrige a mano, no se duplica) ni
 * sin RUC o cédula reales en ICG (no se crea con placeholder: un pedido sin receptor fiscal no se puede facturar).
 * Reusa `ClienteFusionLoader::promoteOne()` (mismo mapeo de RUC/DV y misma traza `promoted` que la promoción general de clientes).
 */
final class ClientesPedidosGemelosCreator
{
    public const ACCION_CREAR = 'crear';
    public const ACCION_OMITIR = 'omitir';

    public const MOTIVO_DUPLICADO_NOMBRE = 'posible duplicado: ya existe un cliente con el mismo nombre';
    public const MOTIVO_DUPLICADO_RUC    = 'posible duplicado: ya existe un cliente con el mismo RUC (o con el RUC cortado)';
    public const MOTIVO_SIN_RUC          = 'sin RUC ni cédula reales en ICG';

    /** Largo mínimo (alfanumérico) del RUC existente para considerarlo prefijo de uno cortado; evita falsos positivos con códigos cortos. */
    private const PREFIJO_MINIMO = 6;

    public function __construct(private readonly ClienteFusionLoader $fusion)
    {
    }

    /**
     * Un registro por cliente de ICG distinto (sin SubCliente) de los pedidos del Excel. No escribe nada.
     *
     * @param list<array{serie: string, numero: int, pendiente: float}> $filas
     * @return list<array<string, mixed>>
     */
    public function plan(array $filas): array
    {
        /** @var array<int, array<string, mixed>> $porCliente */
        $porCliente = [];
        foreach ($filas as $fila) {
            $pedido = Pedido::query()->with('cliente')->where('icg_numserie', $fila['serie'])->where('icg_numpedido', $fila['numero'])->first();
            $cliente = $pedido?->cliente;
            if ($cliente === null || $cliente->sub_client_id !== null) {
                continue;
            }
            $porCliente[$cliente->id] ??= ['cliente' => $cliente, 'pedidos' => [], 'pendiente' => 0.0];
            $porCliente[$cliente->id]['pedidos'][]  = $fila['numero'];
            $porCliente[$cliente->id]['pendiente'] += $fila['pendiente'];
        }

        $existentes = SubClient::query()->get(['id', 'business_name', 'tax_id']);
        $plan = [];
        foreach ($porCliente as $item) {
            /** @var Cliente $c */
            $c       = $item['cliente'];
            $nombre  = (string) ($c->nombre_comercial ?: $c->razon_social);
            $usaRuc  = ClienteMatcher::rucUsable($c);
            $cif     = trim((string) $c->cif);
            $taxId   = $usaRuc ? trim((string) $c->ruc) : $cif;
            $base    = [
                'id' => $c->id, 'icg_codcliente' => $c->icg_codcliente, 'nombre' => $nombre, 'tax_id' => $taxId, 'dv' => $usaRuc ? (string) $c->ruc_dv : '',
                'pedidos' => implode(', ', $item['pedidos']), 'pendiente' => round((float) $item['pendiente'], 2),
                'accion' => self::ACCION_OMITIR, 'motivo' => null, 'existentes' => '',
            ];

            if ($taxId === '') {
                $plan[] = ['motivo' => self::MOTIVO_SIN_RUC] + $base;
                continue;
            }

            $claveNombre = $this->clave($nombre);
            $claveRuc    = $this->clave($taxId);
            $mismoNombre = $existentes->filter(fn (SubClient $s): bool => $claveNombre !== '' && $this->clave((string) $s->business_name) === $claveNombre);
            $mismoRuc    = $existentes->filter(function (SubClient $s) use ($claveRuc): bool {
                $e = $this->clave((string) $s->tax_id);

                return $e !== '' && ($e === $claveRuc || (strlen($e) >= self::PREFIJO_MINIMO && str_starts_with($claveRuc, $e)));
            });

            if ($mismoRuc->isNotEmpty() || $mismoNombre->isNotEmpty()) {
                $coinciden = $mismoRuc->merge($mismoNombre)->unique('id');
                $plan[] = [
                    'motivo'     => $mismoRuc->isNotEmpty() ? self::MOTIVO_DUPLICADO_RUC : self::MOTIVO_DUPLICADO_NOMBRE,
                    'existentes' => $coinciden->map(fn (SubClient $s): string => "{$s->id}: {$s->business_name} ({$s->tax_id})")->implode(' ; '),
                ] + $base;
                continue;
            }

            $plan[] = ['accion' => self::ACCION_CREAR] + $base;
        }

        usort($plan, static fn (array $a, array $b): int => strcmp((string) $a['nombre'], (string) $b['nombre']));

        return $plan;
    }

    /**
     * @param list<array<string, mixed>> $plan
     */
    public function huella(array $plan): string
    {
        $partes = array_map(static fn (array $f): string => implode('|', [$f['id'], $f['accion'], $f['tax_id'], $f['dv']]), $plan);
        sort($partes);

        return substr(hash('sha256', implode("\n", $partes)), 0, 12);
    }

    /**
     * Crea los clientes del plan, cada uno en su transacción. Idempotente: un cliente que ya tiene SubCliente se salta.
     *
     * @param list<array<string, mixed>> $plan
     * @return array{creados: int, errores: list<array{icg_codcliente: mixed, error: string}>}
     */
    public function aplicar(array $plan): array
    {
        $creados = 0;
        $errores = [];
        foreach ($plan as $f) {
            if ($f['accion'] !== self::ACCION_CREAR) {
                continue;
            }
            try {
                DB::transaction(function () use ($f, &$creados): void {
                    $cliente = Cliente::query()->whereKey($f['id'])->lockForUpdate()->first();
                    if ($cliente === null || $cliente->sub_client_id !== null) {
                        return;
                    }
                    $this->fusion->promoteOne($cliente);
                    $creados++;
                });
            } catch (\Throwable $e) {
                $errores[] = ['icg_codcliente' => $f['icg_codcliente'], 'error' => class_basename($e).': '.Str::limit((string) strtok($e->getMessage(), "\n"), 160)];
            }
        }

        return ['creados' => $creados, 'errores' => $errores];
    }

    private function clave(string $texto): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($texto)));
    }
}
