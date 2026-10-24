<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1034 (A-04): el login ejecuta SIEMPRE un `Hash::check` (contra un hash ficticio del mismo coste si no hay cuenta válida),
 * para que el tiempo de respuesta no revele si el correo existe, está inactivo, bloqueado o es invitado. Se comprueba el número
 * de llamadas al hasher (determinista) y no los milisegundos (en pruebas el coste de bcrypt es mínimo y el ruido lo domina).
 */
class LoginTimingEqualizationTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['throttle.login' => ['ip_max' => 1000, 'ip_decay' => 60, 'email_max' => 1000, 'email_decay' => 60]]);
    }

    /** @return array<string, array{string, string}> */
    public static function casos(): array
    {
        return [
            'correo inexistente'          => ['nadie@atlantic.test', 'inexistente'],
            'cuenta activa, clave mala'   => ['designer@atlantic.test', 'activa'],
            'cuenta inactiva'             => ['inactiva-1034@atlantic.test', 'inactiva'],
        ];
    }

    /** @dataProvider casos */
    public function test_siempre_se_ejecuta_un_hash_check_con_un_hash_bcrypt(string $email, string $caso): void
    {
        if ($caso === 'inactiva') {
            $levelId = (int) DB::table('atlantic_auth.security_levels')->where('level', 1)->value('id');
            DB::table('atlantic_auth.users')->insert([
                'first_name' => 'In', 'last_name' => 'Activa', 'email' => $email, 'password_hash' => Hash::make('Otra123456!'),
                'security_level_id' => $levelId, 'is_active' => false, 'extra_permissions' => '[]',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Decorador del hasher real: cuenta y registra las llamadas a check() sin alterar el resto (make, isHashed...).
        $real = app('hash');
        $spy  = new class($real) {
            /** @var list<string> */
            public array $llamadas = [];

            public function __construct(private readonly object $real) {}

            public function check(string $value, string $hashed, array $options = []): bool
            {
                $this->llamadas[] = $hashed;

                return $this->real->check($value, $hashed, $options);
            }

            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): mixed
            {
                return $this->real->{$name}(...$args);
            }
        };
        app()->instance('hash', $spy);
        \Illuminate\Support\Facades\Hash::clearResolvedInstance('hash');

        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'ClaveIncorrecta1!'])->assertStatus(401);

        $this->assertCount(1, $spy->llamadas, 'el bcrypt debe correr una vez, exista o no la cuenta');
        $this->assertMatchesRegularExpression('/^\$2y\$/', $spy->llamadas[0], 'se compara contra un hash bcrypt (ficticio o real)');
    }

    public function test_el_login_correcto_sigue_funcionando(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'designer@atlantic.test', 'password' => 'Password123!'])
            ->assertStatus(200)->assertJsonStructure(['token', 'refresh_token']);
    }
}
