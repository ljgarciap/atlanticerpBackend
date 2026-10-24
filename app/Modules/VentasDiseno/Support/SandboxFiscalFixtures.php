<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Support;

/**
 * RUC que el sandbox de MSL SÍ conoce (docs/architecture/facturacion-electronica-conocimiento.md, regla 18) con el DV y la
 * razón social que devuelve `validar-ruc`. La guarda de RUC al facturar (SCRUM-1117) bloquea cualquier cliente que la DGI
 * no reconozca, así que los clientes de demo/QA que deben poder facturar en dev y test usan estos datos, sin bypass.
 * La razón social debe coincidir con la de la DGI una vez normalizada (mayúsculas, sin puntuación ni espacios extra).
 */
final class SandboxFiscalFixtures
{
    public const ATLANTIC = ['tax_id' => '1838853-1-711641', 'tax_id_dv' => '81', 'business_name' => 'ATLANTIC U S A CORP'];
    public const DIGIFACT      = ['tax_id' => '155704849-2-2021', 'tax_id_dv' => '32', 'business_name' => 'DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA'];
}
