<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Support;

use App\Shared\Fiscal\TaxId\ValidTaxId;
use Illuminate\Validation\Rule;

/**
 * SCRUM-1003 — "Tipo de cliente ante la DGI" de un SubCliente (`sub_clients.receptor_tipo`). Alcance acordado con Luis
 * (2026-09-29): solo Contribuyente (`01`, el default) y Consumidor Final (`02`); Gobierno (`03`) y Extranjero (`04`) quedan
 * fuera hasta saber si Atlantic los factura. Las filas existentes siguen en NULL y se tratan como `01` (no se
 * reclasifican solas).
 *
 * Con `02` no hay RUC ni DV: el documento enviado a la DGI/MSL lleva `CONSUMIDOR FINAL` sin identificación, y `tax_id`
 * (columna NOT NULL) se guarda como cadena vacía.
 */
final class ReceptorTipo
{
    public const CONTRIBUYENTE = '01';
    public const CONSUMIDOR_FINAL = '02';

    /**
     * Decisión de Luis (2026-09-30): marcar un cliente como Consumidor Final (facturar a la DGI sin identificación) exige el
     * permiso angosto de Gerencia y Contabilidad. Quien solo tiene `ventas_diseno.write`/`.edit` puede dar de alta y editar clientes
     * como Contribuyente, pero no pasarlos a Consumidor Final.
     */
    public const PERMISO_CONSUMIDOR_FINAL = 'ventas_diseno.edit.client_tax_data';
    public const MENSAJE_SIN_PERMISO = 'Solo Gerencia y Contabilidad pueden marcar un cliente como Consumidor final.';

    /** @return list<string> */
    public static function permitidos(): array
    {
        return [self::CONTRIBUYENTE, self::CONSUMIDOR_FINAL];
    }

    /**
     * Reglas de validación compartidas por todos los requests que capturan estos datos (alta de cliente/subcliente,
     * vinculación desde Pipeline y edición de datos fiscales). El RUC sigue obligatorio salvo Consumidor Final; el DV es opcional (SCRUM-1140).
     *
     * @return array<string, list<mixed>>
     */
    public static function reglas(): array
    {
        return [
            'receptor_tipo' => ['sometimes', 'nullable', 'string', Rule::in(self::permitidos())],
            // Fase 6c: formato de la identificación y DV según el país de la empresa (RUC en Panamá, NIT en Colombia, EIN en EE. UU.).
            'tax_id'        => ['required_unless:receptor_tipo,'.self::CONSUMIDOR_FINAL, 'nullable', 'string', 'max:100', new ValidTaxId()],
            // SCRUM-1140: el DV ya no es obligatorio al capturar — una cédula puede no tenerlo. Lo exige la guarda al facturar,
            // y solo si el validador de la DGI devuelve uno (antes SCRUM-928: `required_unless` Consumidor Final).
            'tax_id_dv'     => ['nullable', 'string', 'regex:/^\d{1,2}$/'],
        ];
    }

    /** @return array<string, string> */
    public static function mensajes(): array
    {
        return [
            'receptor_tipo.in'           => 'El tipo de cliente ante la DGI debe ser Contribuyente o Consumidor Final.',
            'tax_id.required_unless'     => 'El RUC o cédula es obligatorio.',
        ];
    }

    /**
     * Deja los datos listos para persistir: tipo explícito (`01` si no vino) y, para Consumidor Final, sin RUC ni DV aunque
     * el cliente los haya mandado ("se ignoran").
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalizar(array $data): array
    {
        $tipo = isset($data['receptor_tipo']) && $data['receptor_tipo'] !== '' ? (string) $data['receptor_tipo'] : self::CONTRIBUYENTE;
        $data['receptor_tipo'] = $tipo;

        if ($tipo === self::CONSUMIDOR_FINAL) {
            $data['tax_id'] = '';
            $data['tax_id_dv'] = null;
        }

        return $data;
    }
}
