<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Models\User;
use App\Modules\AdminCont\Services\PaymentMethods;
use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Catalogs\CatalogService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 14i (docs/specs/fase14i-etiquetas-catalogo-multiidioma.md) — cada entrada de catálogo puede llevar su etiqueta en cada idioma; la API y las
 * fachadas la resuelven en el idioma del usuario con respaldo a la base, y los documentos del servidor siguen la misma regla.
 */
final class CatalogLabelsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activarTenant();
    }

    private function activarTenant(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    private function idiomaDe(string $email, string $language): void
    {
        DB::table('atlantic_auth.users')->where('email', $email)->update(['preferences' => json_encode(['language' => $language])]);
    }

    /** @return array<string, string> */
    private function superadmin(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!'));
    }

    private function etiqueta(array $h, string $domain, string $key): string
    {
        $rows = collect($this->withHeaders($h)->getJson("/api/catalogs/{$domain}")->assertOk()->json());

        return (string) $rows->firstWhere('key', $key)['label'];
    }

    public function test_el_sembrado_trae_el_ingles_de_las_entradas_que_ya_lo_tenian(): void
    {
        $this->assertSame(['en' => 'Bank deposit'], CatalogEntry::query()->where('domain', 'payment_method')->where('key', 'deposito')->value('labels'));
        $this->assertSame('Depósito bancario', CatalogEntry::query()->where('domain', 'payment_method')->where('key', 'deposito')->value('label'), 'la base no cambia');
        $this->assertSame(['es', 'en'], CatalogService::languages());
    }

    public function test_la_api_resuelve_la_etiqueta_en_el_idioma_del_usuario_con_respaldo_a_la_base(): void
    {
        $h = $this->superadmin();
        $this->withHeaders($h)->postJson('/api/admin/catalogs/payment_method', ['key' => 'ventanilla', 'label' => 'Ventanilla bancaria'])->assertCreated();

        $this->idiomaDe('superadmin@atlantic.test', 'es');
        $this->assertSame('Depósito bancario', $this->etiqueta($h, 'payment_method', 'deposito'));

        $this->activarTenant();
        $this->idiomaDe('superadmin@atlantic.test', 'en');
        $this->assertSame('Bank deposit', $this->etiqueta($h, 'payment_method', 'deposito'));
        $this->assertSame('Ventanilla bancaria', $this->etiqueta($h, 'payment_method', 'ventanilla'), 'sin traducción: la base en español');
    }

    public function test_el_admin_escribe_borra_y_valida_las_traducciones(): void
    {
        $h = $this->superadmin();
        $creada = $this->withHeaders($h)->postJson('/api/admin/catalogs/payment_method', [
            'key' => 'ventanilla', 'label' => 'Ventanilla bancaria', 'labels' => ['en' => 'Bank counter'],
        ])->assertCreated();
        $this->assertSame(['en' => 'Bank counter'], $creada->json('labels'));
        $id = (int) $creada->json('id');

        // El listado de administración devuelve la BASE y todas las traducciones.
        $this->activarTenant();
        $fila = collect($this->withHeaders($h)->getJson('/api/admin/catalogs/payment_method')->assertOk()->json())->firstWhere('id', $id);
        $this->assertSame('Ventanilla bancaria', $fila['label']);
        $this->assertSame(['en' => 'Bank counter'], $fila['labels']);

        // Vaciar la traducción vuelve al respaldo.
        $this->withHeaders($h)->putJson("/api/admin/catalogs/payment_method/{$id}", ['labels' => ['en' => '']])->assertOk();
        $this->activarTenant();
        $this->assertNull(CatalogEntry::findOrFail($id)->labels);

        // Un idioma sin soporte (o la propia base) se rechaza.
        $this->withHeaders($h)->putJson("/api/admin/catalogs/payment_method/{$id}", ['labels' => ['fr' => 'Guichet']])->assertStatus(422);
        $this->withHeaders($h)->putJson("/api/admin/catalogs/payment_method/{$id}", ['labels' => ['es' => 'otra']])->assertStatus(422);
    }

    public function test_la_lista_de_dominios_informa_los_idiomas(): void
    {
        $h = $this->superadmin();
        $dominios = $this->withHeaders($h)->getJson('/api/catalogs/domains')->assertOk()->json();
        $this->assertSame(['es', 'en'], $dominios[0]['languages']);
    }

    public function test_las_fachadas_del_servidor_usan_el_idioma_del_usuario_y_la_base_sin_usuario(): void
    {
        // Sin usuario (trabajo o correo automático): idioma por defecto de la empresa (es) -> la base.
        $this->assertSame('Depósito bancario', PaymentMethods::label('deposito'));

        // Con un usuario cuyo idioma es inglés (la petición lleva el JWT con su `sub`): la traducción, y la base donde no hay.
        $usuario = User::query()->where('email', 'superadmin@atlantic.test')->firstOrFail();
        $this->idiomaDe('superadmin@atlantic.test', 'en');
        request()->attributes->remove('catalog.language');
        request()->attributes->set('jwt', ['sub' => $usuario->id]);

        $this->assertSame('Bank deposit', PaymentMethods::label('deposito'));
        $this->assertSame('Bank deposit', PaymentMethods::labels()['deposito']);
        $this->assertSame('Yappy', PaymentMethods::label('yappy'), 'sin traducción distinta: la base');
    }
}
