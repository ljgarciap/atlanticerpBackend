<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Services\InvoiceService;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\AdminContBankAccountSeeder;
use Illuminate\Support\Collection;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-928 (D-6) — las cuentas bancarias de la empresa se siembran en todos los entornos. CREA solo
 * si la tabla está vacía (prod no tenía ninguna); en un ambiente que ya tiene cuentas (test, dev) solo
 * rellena el número completo de Banco General. NUNCA pisa un número cargado, reactiva una cuenta
 * desactivada, ni duplica — ni siquiera si alguien renombra la cuenta desde la pantalla.
 *
 * El seeder NO corre en `testing` desde `DatabaseSeeder` (Senior Review ALTO-1): estos tests lo
 * invocan directamente, partiendo de una tabla vacía, que es exactamente el escenario de prod.
 */
class AdminContBankAccountSeederTest extends FeatureTestCase
{
    private const NUMERO = '03-01-01-074455-4';

    /** Terminaciones de las 4 cuentas sembradas. */
    private const ULT4 = ['4554', '9034', '4534', '0032'];

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        // La base de tests no es fresca por test: se parte de una tabla VACÍA (el caso de prod). Las FK a
        // `bank_accounts` son `nullOnDelete`, así que el borrado no falla.
        AdminContBankAccount::query()->delete();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** Sin llamarlo `seed()`: choca con `Illuminate\Foundation\Testing\TestCase::seed()`, que es público. */
    private function runSeeder(): void
    {
        (new AdminContBankAccountSeeder)->run();
        $this->reMakeCurrent();
    }

    private function makeAccount(?string $completo, bool $activa = true, string $banco = 'Banco General', string $tipo = 'corriente', string $ult4 = '4554'): AdminContBankAccount
    {
        return AdminContBankAccount::create([
            'banco' => $banco, 'tipo_cuenta' => $tipo, 'ultimos_4_digitos' => $ult4,
            'numero_cuenta_completo' => $completo, 'moneda' => 'USD', 'activa' => $activa,
        ]);
    }

    /** @return Collection<int, AdminContBankAccount> todas las cuentas de la tabla, por id. */
    private function all(): Collection
    {
        return AdminContBankAccount::query()->orderBy('id')->get();
    }

    public function test_el_numero_completo_declarado_termina_en_los_ultimos_4_declarados(): void
    {
        // Invariante de los datos: la terminación declarada no puede desincronizarse del número.
        foreach (AdminContBankAccountSeeder::ACCOUNTS as $cuenta) {
            if ($cuenta['numero_cuenta_completo'] === null) {
                continue;
            }
            $digitos = (string) preg_replace('/\D/', '', $cuenta['numero_cuenta_completo']);
            $this->assertSame($cuenta['ultimos_4_digitos'], substr($digitos, -4), $cuenta['banco']);
        }
        $this->assertSame(self::ULT4, array_column(AdminContBankAccountSeeder::ACCOUNTS, 'ultimos_4_digitos'));
    }

    public function test_crea_las_4_cuentas_de_test_en_orden_con_la_actividad_correcta_cuando_la_tabla_esta_vacia(): void
    {
        // Caso de `atlanticerp.ai` (prod): ninguna cuenta.
        $this->runSeeder();

        $cuentas = $this->all();
        $this->assertCount(4, $cuentas);
        $this->assertSame(['Banco General', 'Banistmo', 'Bancolombia', 'Davivienda'], $cuentas->pluck('banco')->all(), 'El orden de creación define el id: Banco General primero.');
        $this->assertSame([true, true, false, false], $cuentas->pluck('activa')->all());
        $this->assertSame(['corriente', 'tarjeta_credito', 'ahorro', 'ahorro'], $cuentas->pluck('tipo_cuenta')->all());
        $this->assertSame(self::NUMERO, $cuentas->first()->numero_cuenta_completo);
        $this->assertSame([null, null, null], $cuentas->skip(1)->pluck('numero_cuenta_completo')->all(), 'Solo Banco General tiene número completo.');
    }

    public function test_rellena_solo_el_numero_de_banco_general_en_las_cuentas_existentes_sin_duplicar_ni_tocar_su_actividad(): void
    {
        // Caso de `test.atlanticerp.ai`: las 4 ya cargadas a mano, ninguna con número. Otro casing a propósito.
        $bg = $this->makeAccount(null, banco: 'Banco general');
        $bs = $this->makeAccount(null, banco: 'Banistmo', tipo: 'tarjeta_credito', ult4: '9034');
        $bc = $this->makeAccount(null, activa: false, banco: 'Bancolombia', tipo: 'ahorro', ult4: '4534');
        $dv = $this->makeAccount(null, activa: false, banco: 'Davivienda', tipo: 'ahorro', ult4: '0032');

        $this->runSeeder();

        $this->assertCount(4, $this->all(), 'No debe crear cuentas nuevas.');
        $this->assertSame(self::NUMERO, $bg->fresh()->numero_cuenta_completo);
        $this->assertSame('Banco general', $bg->fresh()->banco, 'No renombra la cuenta existente.');
        foreach ([$bs, $bc, $dv] as $otra) {
            $this->assertNull($otra->fresh()->numero_cuenta_completo);
        }
        $this->assertSame([true, true, false, false], [$bg->fresh()->activa, $bs->fresh()->activa, $bc->fresh()->activa, $dv->fresh()->activa]);
    }

    public function test_no_crea_nada_si_la_tabla_ya_tiene_alguna_cuenta_aunque_falten_las_sembradas(): void
    {
        // Un ambiente con cuentas propias (ej. alguien cargó una a mano antes de promover): no se le agregan otras.
        $propia = $this->makeAccount(null, banco: 'Banco Propio', ult4: '7777');

        $this->runSeeder();

        $this->assertCount(1, $this->all());
        $this->assertNull($propia->fresh()->numero_cuenta_completo);
    }

    public function test_una_cuenta_renombrada_desde_la_pantalla_no_se_duplica_ni_recibe_el_numero(): void
    {
        // Senior Review MEDIO-2: banco/tipo/terminación son editables y no hay endpoint para borrar; crear otra
        // "porque no la encuentra" dejaría un duplicado activo imposible de eliminar.
        $renombrada = $this->makeAccount(null, banco: 'Banco General S.A.');

        $this->runSeeder();
        $this->runSeeder();

        $this->assertCount(1, $this->all(), 'Ni una segunda cuenta activa en el siguiente deploy.');
        $this->assertNull($renombrada->fresh()->numero_cuenta_completo, 'Sin coincidencia exacta no se estampa el número.');
    }

    public function test_normaliza_espacios_y_mayusculas_en_el_nombre_del_banco(): void
    {
        $bg = $this->makeAccount(null, banco: '  BANCO GENERAL ');

        $this->runSeeder();

        $this->assertSame(self::NUMERO, $bg->fresh()->numero_cuenta_completo);
        $this->assertCount(1, $this->all());
    }

    public function test_un_numero_en_blanco_cuenta_como_sin_numero(): void
    {
        $bg = $this->makeAccount('   ');

        $this->runSeeder();

        $this->assertSame(self::NUMERO, $bg->fresh()->numero_cuenta_completo);
    }

    public function test_nunca_pisa_un_numero_ya_cargado_desde_cuentas_bancarias(): void
    {
        $bg = $this->makeAccount('99-99-99-999999-9');

        $this->runSeeder();

        $this->assertSame('99-99-99-999999-9', $bg->fresh()->numero_cuenta_completo);
        $this->assertCount(1, $this->all(), 'La tabla ya tenía una cuenta: no se crean las otras 3.');
    }

    public function test_no_reactiva_una_cuenta_desactivada_ni_crea_otra_activa_en_su_lugar(): void
    {
        // Alguien desactivó Banistmo a propósito: el seeder no puede revertir esa decisión ni duplicarla.
        $bs = $this->makeAccount(null, activa: false, banco: 'Banistmo', tipo: 'tarjeta_credito', ult4: '9034');

        $this->runSeeder();

        $this->assertFalse($bs->fresh()->activa);
        $this->assertCount(1, $this->all());
    }

    public function test_es_idempotente(): void
    {
        $this->runSeeder();
        $this->runSeeder();
        $this->runSeeder();

        $this->assertCount(4, $this->all(), 'Tres corridas = las mismas 4 cuentas.');
        $this->assertSame(self::NUMERO, $this->all()->first()->numero_cuenta_completo);
    }

    public function test_misma_terminacion_pero_otro_banco_o_tipo_no_se_confunde_con_la_cuenta_real(): void
    {
        $otroBanco = $this->makeAccount(null, banco: 'Banistmo');   // 4554 pero otro banco
        $otroTipo  = $this->makeAccount(null, tipo: 'ahorro');      // 4554 pero ahorro

        $this->runSeeder();

        $this->assertNull($otroBanco->fresh()->numero_cuenta_completo);
        $this->assertNull($otroTipo->fresh()->numero_cuenta_completo);
        $this->assertCount(2, $this->all(), 'No se crea nada: la tabla ya tenía cuentas.');
    }

    public function test_banco_general_corriente_con_otra_terminacion_no_recibe_el_numero(): void
    {
        // Senior Review MEDIO-3: sin el filtro por terminación se estamparía el número de la cuenta real en una
        // cuenta ajena, y saldría en las Facturas a clientes.
        $ajena = $this->makeAccount(null, banco: 'Banco General', tipo: 'corriente', ult4: '1111');

        $this->runSeeder();

        $this->assertNull($ajena->fresh()->numero_cuenta_completo);
        $this->assertCount(1, $this->all());
    }

    public function test_con_varias_coincidencias_solo_se_rellena_la_mas_antigua(): void
    {
        $vieja = $this->makeAccount(null);
        $nueva = $this->makeAccount(null);

        $this->runSeeder();

        $this->assertSame(self::NUMERO, $vieja->fresh()->numero_cuenta_completo);
        $this->assertNull($nueva->fresh()->numero_cuenta_completo);
    }

    public function test_con_las_cuentas_sembradas_la_factura_y_el_estado_de_cuenta_muestran_el_numero_de_banco_general(): void
    {
        $this->runSeeder();

        // La Factura usa la cuenta activa más antigua: tiene que ser Banco General, no Banistmo.
        $bloque = InvoiceService::paymentBlock();
        $this->assertSame('Banco General', $bloque['banco']);
        $this->assertSame(self::NUMERO, $bloque['numero_cuenta']);
        $this->assertStringNotContainsString('*', (string) $bloque['cuenta_pago']);
    }
}
