<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl;

/**
 * Traduce `admin_contab.payments.metodo_pago` (9 valores reales, ver
 * App\Modules\AdminCont\Models\AdminContPayment::METODOS) al código de forma de pago que pide
 * MSL (`pagos[].tipo`).
 *
 * CORREGIDO 2026-09-24 (SCRUM-943) — los códigos originales (01 efectivo · 02 tarjeta crédito ·
 * 03 tarjeta débito · 99 otro) venían de `GUIA_ILUMINATION.md`/`README-ENTREGA.md` (PDFs
 * entregados por el proveedor 2026-09-17) y pasaron Senior Review sin contrastarse contra el
 * catálogo real — verificado hoy contra el HTML servido en vivo por
 * `https://fedigifact.appmsl.com/referencia` §4 "Formas de pago", que es la fuente que la propia
 * guía marca como autoritativa ("catálogo completo"). El catálogo real:
 *   01 crédito (pago a plazo) · 02 efectivo · 03 tarjeta de crédito · 04 tarjeta de débito ·
 *   05 tarjeta de fidelización · 06 vale · 07 tarjeta de regalo ·
 *   08 transferencia/depósito a cuenta bancaria · 09 cheque · 10 punto de pago · 99 otro.
 * Los PDF de entrega y el catálogo en vivo no coinciden entre sí (01/02/03 vienen corridos) — todo
 * lo certificado hasta ahora fue en `ambiente: pruebas` (sin validez fiscal, ver GUIA_ILUMINATION.md
 * §9), así que no hay ningún documento fiscal real afectado por el bug. Antes de `ambiente:
 * produccion` confirmar de nuevo contra `/referencia` (puede haber cambiado otra vez) o directo con
 * soporte de MSL — no asumir que esta corrección sigue vigente indefinidamente.
 *
 * `METODO_INTERNO_CREDITO` ('credito') es un sentinel — NO es un valor real de
 * `AdminContPayment::METODOS` (no hay ninguna fila de pago detrás): `FiscalDocumentBuilder` lo usa
 * para el saldo de una factura sin cobrar todavía (`dias_credito_factura`), y este mapper lo
 * traduce a `TIPO_CREDITO` (01) igual que cualquier otro método — mismo mecanismo de traducción,
 * la única costura son ambos strings viviendo acá.
 *
 * 'tarjeta' es ambiguo entre 03 (crédito) y 04 (débito) — AtlanticERP no distingue el tipo de tarjeta
 * hoy. Este mapper usa 03 (crédito) por default, marcado explícitamente abajo — mismo caveat que
 * antes de la corrección, solo cambió el código.
 *
 * 'retencion_impuestos' y 'ajuste_cuenta' (REQ-466 RN4: "nunca representan dinero entrando a una
 * cuenta bancaria real") probablemente no deberían viajar como "forma de pago" del documento
 * fiscal — sigue siendo una pregunta de negocio sin resolver, no de mapeo de código (caveat
 * original sin cambios).
 */
final class MslPaymentMethodMapper
{
    public const string METODO_INTERNO_CREDITO = 'credito';
    public const string TIPO_CREDITO = '01';
    private const string TIPO_EFECTIVO = '02';
    private const string TIPO_TARJETA_CREDITO = '03';
    private const string TIPO_TRANSFERENCIA_DEPOSITO = '08';
    private const string TIPO_CHEQUE = '09';
    private const string TIPO_OTRO = '99';

    /** @return array{tipo: string, descripcion: ?string} */
    public function map(string $metodoPagoInterno, string $etiquetaSiOtro): array
    {
        return match ($metodoPagoInterno) {
            self::METODO_INTERNO_CREDITO => ['tipo' => self::TIPO_CREDITO, 'descripcion' => null],
            'efectivo' => ['tipo' => self::TIPO_EFECTIVO, 'descripcion' => null],
            // Ambiguo entre 03/04 (ver docblock) — default a crédito hasta confirmar con MSL.
            'tarjeta' => ['tipo' => self::TIPO_TARJETA_CREDITO, 'descripcion' => null],
            // El catálogo real fusiona transferencia y depósito bancario en un solo código.
            'transferencia', 'deposito' => ['tipo' => self::TIPO_TRANSFERENCIA_DEPOSITO, 'descripcion' => null],
            'cheque' => ['tipo' => self::TIPO_CHEQUE, 'descripcion' => null],
            default => ['tipo' => self::TIPO_OTRO, 'descripcion' => $etiquetaSiOtro],
        };
    }
}
