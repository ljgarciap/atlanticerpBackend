<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * SCRUM-928 (D-6) — las cuentas bancarias de la empresa, con el número COMPLETO de la principal.
 * Reemplaza al `AdminContBankAccountNumberSeeder` (número por variable de entorno): mismo criterio
 * que `AdminContFiscalIdentitySeeder` — son datos de la EMPRESA, no data demo, los aportó Luis
 * (2026-09-18) y tienen que existir en cada entorno sin pasos manuales ni `.env` por servidor.
 *
 * Estado de los entornos al escribirlo (2026-09-18): `test` ya tenía las 4 cuentas de abajo (cargadas
 * el 2026-09-07) pero ninguna con número completo; `dev` se alineó con `test` a mano; `prod` no tenía
 * NINGUNA cuenta bancaria, por lo que la Factura y el Estado de Cuenta mostraban "—". Luis pidió
 * sembrar las 4 de `test` (2026-09-18), con la misma actividad que tienen ahí.
 *
 * ORDEN: importa. `InvoiceService::paymentBlock()` usa la cuenta ACTIVA MÁS ANTIGUA (menor id), así
 * que Banco General va primero para ser la de la Factura y el Estado de Cuenta, igual que en `test`.
 *
 * Solo Banco General tiene número completo (el que aportó Luis); de las demás no se conoce y no se
 * inventa: quedan sin número, que es exactamente como están en `test`.
 *
 * Idempotente y conservador (Senior Review MEDIO-2/3):
 *  - CREA cuentas SOLO si la tabla `bank_accounts` está VACÍA (el caso de `prod`). En un ambiente que
 *    ya tiene cuentas no crea nada: banco, tipo y terminación se pueden editar desde la pantalla y
 *    no hay endpoint para borrar, así que reconocer una cuenta por esos campos y crear otra "si no
 *    la encuentra" dejaría un duplicado activo imposible de eliminar tras cualquier corrección de
 *    nombre. Lo peor que puede pasar con la regla estricta es que un número no se estampe (la Factura
 *    muestra "—", visible); con una regla laxa sería estampar el número en una cuenta ajena (invisible).
 *  - Si ya existe una cuenta con el mismo banco (sin distinguir mayúsculas ni espacios), tipo y
 *    últimos 4 dígitos — activa o no —, solo se rellena `numero_cuenta_completo` si la definición
 *    trae uno y la cuenta lo tiene vacío. Nunca pisa un número cargado desde Cuentas Bancarias, ni
 *    cambia `activa` (no reactiva una cuenta que alguien desactivó a propósito). Un vaciado
 *    deliberado del número se vuelve a rellenar en el siguiente deploy (igual que la identidad
 *    fiscal): para cambiar de cuenta hay que cambiar este seeder.
 *  - Corre con `WithoutModelEvents` (vía `DatabaseSeeder`): no deja fila en `audit_logs`.
 *
 * Corre en todos los entornos como parte de `db:seed --force` en cada deploy, incluida producción —
 * mismo patrón ya aprobado por Luis para `AdminContFiscalIdentitySeeder`.
 */
class AdminContBankAccountSeeder extends Seeder
{
    /**
     * Público para que el test verifique el invariante "últimos 4 == final del número completo".
     *
     * @var list<array{banco: string, tipo_cuenta: string, ultimos_4_digitos: string, numero_cuenta_completo: ?string, moneda: string, activa: bool}>
     */
    public const ACCOUNTS = [
        [
            'banco'                  => 'Banco General',
            'tipo_cuenta'            => AdminContBankAccount::TIPO_CORRIENTE,
            'ultimos_4_digitos'      => '4554',
            'numero_cuenta_completo' => '03-01-01-074455-4',
            'moneda'                 => 'USD',
            'activa'                 => true,
        ],
        [
            'banco'                  => 'Banistmo',
            'tipo_cuenta'            => AdminContBankAccount::TIPO_TARJETA_CREDITO,
            'ultimos_4_digitos'      => '9034',
            'numero_cuenta_completo' => null,
            'moneda'                 => 'USD',
            'activa'                 => true,
        ],
        [
            'banco'                  => 'Bancolombia',
            'tipo_cuenta'            => AdminContBankAccount::TIPO_AHORRO,
            'ultimos_4_digitos'      => '4534',
            'numero_cuenta_completo' => null,
            'moneda'                 => 'USD',
            'activa'                 => false,
        ],
        [
            'banco'                  => 'Davivienda',
            'tipo_cuenta'            => AdminContBankAccount::TIPO_AHORRO,
            'ultimos_4_digitos'      => '0032',
            'numero_cuenta_completo' => null,
            'moneda'                 => 'USD',
            'activa'                 => false,
        ],
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();
        if ($tenant === null) {
            $this->command?->warn('Tenant "atlantic" no encontrado — AdminContBankAccountSeeder omitido.');

            return;
        }

        $tenant->makeCurrent();

        try {
            // Calculado UNA vez antes del loop: si la tabla está vacía se crean las 4 en orden.
            $tablaVacia = ! AdminContBankAccount::query()->exists();

            foreach (self::ACCOUNTS as $account) {
                $existing = AdminContBankAccount::query()
                    ->whereRaw('lower(trim(banco)) = ?', [mb_strtolower(trim($account['banco']))])
                    ->where('tipo_cuenta', $account['tipo_cuenta'])
                    ->where('ultimos_4_digitos', $account['ultimos_4_digitos'])
                    ->oldest('id')
                    ->first();

                if ($existing === null) {
                    if ($tablaVacia) {
                        AdminContBankAccount::create($account);
                    }

                    continue;
                }

                if ($account['numero_cuenta_completo'] !== null && trim((string) $existing->numero_cuenta_completo) === '') {
                    $existing->update(['numero_cuenta_completo' => $account['numero_cuenta_completo']]);
                }
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
