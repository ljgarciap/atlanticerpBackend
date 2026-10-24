<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\Concerns;

use App\Modules\AdminCont\FacturacionElectronica\Services\ReceptorFiscalGuard;
use App\Modules\VentasDiseno\Models\SubClient;

/**
 * Guarda de RUC al facturar (docs/specs/guarda-validacion-ruc-al-facturar.md): en `testing` sin validación disponible la
 * guarda bloquea, así que cada test que crea facturas por la API y NO es de la guarda sustituye `ReceptorFiscalGuard` por
 * un doble que siempre da "ok". Así esos tests no dependen de credenciales de MSL ni de un `validar-ruc`, y conservan el
 * estado del PAC con el que ya estaban escritos. La guarda en sí se prueba en `InvoiceReceptorGuardTest`.
 */
trait StubsReceptorGuard
{
    protected function stubReceptorGuard(): void
    {
        $this->app->instance(ReceptorFiscalGuard::class, new class extends ReceptorFiscalGuard {
            public function __construct()
            {
            }

            /**
             * SCRUM-1140 — el doble devuelve los datos del propio cliente como si la DGI los confirmara (razón social y DV
             * iguales), de modo que la factura congela el mismo receptor que antes se leía en vivo. Consumidor Final (o un
             * cliente sin RUC) sale como `no_aplica`, igual que la guarda real.
             *
             * @return array<string, mixed>
             */
            public function evaluar(?SubClient $subClient): array
            {
                $base = [
                    'bloquea' => false, 'mensaje' => 'stub', 'sub_client_id' => $subClient?->id,
                    'codigo_dgi' => null, 'edicion_bloqueada' => false, 'corregible' => false, 'actualizable' => false,
                ];

                if ($subClient === null || $subClient->receptor_tipo === '02' || trim((string) $subClient->tax_id) === '') {
                    return $base + ['estado' => self::NO_APLICA, 'ruc' => null, 'dv' => null, 'nombre' => null, 'nombre_dgi' => null, 'dv_dgi' => null];
                }

                $dv = $subClient->tax_id_dv !== null ? (string) $subClient->tax_id_dv : null;

                return $base + [
                    'estado' => self::OK, 'ruc' => (string) $subClient->tax_id, 'dv' => $dv,
                    'nombre' => (string) $subClient->business_name, 'nombre_dgi' => (string) $subClient->business_name, 'dv_dgi' => $dv,
                ];
            }
                });
    }
}
