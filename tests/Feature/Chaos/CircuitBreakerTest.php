<?php

declare(strict_types=1);

namespace Tests\Feature\Chaos;

use App\Shared\Support\CircuitBreaker;
use App\Shared\Support\CircuitOpenException;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

/**
 * SCRUM-965 — contrato del CircuitBreaker contra el Redis REAL del stack (no mocks): estado en `cb:{name}:failures` y
 * `cb:{name}:opened_at`. Cada test usa un nombre único y limpia sus claves; no toca claves de servicios reales.
 *
 * El paso del tiempo se simula moviendo `opened_at` hacia atrás (no hay `sleep`), así la suite sigue siendo rápida.
 * Los defectos CONOCIDOS (Redis caído, semi-abierto concurrente, contador expirado) están en CircuitBreakerKnownDefectsTest.
 */
final class CircuitBreakerTest extends TestCase
{
    private string $name;

    protected function setUp(): void
    {
        parent::setUp();
        $this->name = 'chaos-' . uniqid();
    }

    protected function tearDown(): void
    {
        Redis::del("cb:{$this->name}:failures", "cb:{$this->name}:opened_at");
        parent::tearDown();
    }

    private function breaker(int $threshold = 3, int $window = 60, int $openTimeout = 30): CircuitBreaker
    {
        return new CircuitBreaker($this->name, $threshold, $window, $openTimeout);
    }

    /** Llama al breaker con un servicio que falla; devuelve true si el servicio fue llamado, false si el circuito lo impidió. */
    private function callFailing(CircuitBreaker $cb, int &$serviceCalls): bool
    {
        try {
            $cb->call(function () use (&$serviceCalls): void {
                $serviceCalls++;
                throw new RuntimeException('servicio caído');
            });
        } catch (CircuitOpenException) {
            return false;
        } catch (RuntimeException) {
            // el fallo del servicio se relanza: es lo esperado
        }

        return true;
    }

    /** Simula que ya pasaron `$seconds` desde que el circuito se abrió. */
    private function ageOpenCircuit(int $seconds): void
    {
        Redis::set("cb:{$this->name}:opened_at", (string) (time() - $seconds));
    }

    public function test_abre_al_llegar_al_umbral_y_deja_de_llamar_al_servicio(): void
    {
        $cb = $this->breaker(threshold: 3);
        $calls = 0;

        $this->assertTrue($this->callFailing($cb, $calls));
        $this->assertTrue($this->callFailing($cb, $calls));
        $this->assertTrue($this->callFailing($cb, $calls));
        $this->assertSame(3, $calls);

        $this->assertFalse($this->callFailing($cb, $calls), 'con el circuito abierto no se debe llamar al servicio');
        $this->assertSame(3, $calls);
    }

    public function test_circuito_abierto_lanza_circuit_open_exception_sin_ejecutar_el_callback(): void
    {
        Redis::set("cb:{$this->name}:opened_at", (string) time());
        $ran = false;

        $this->expectException(CircuitOpenException::class);
        try {
            $this->breaker()->call(function () use (&$ran): string {
                $ran = true;

                return 'no debería ejecutarse';
            });
        } finally {
            $this->assertFalse($ran);
        }
    }

    public function test_por_debajo_del_umbral_no_abre_y_un_exito_reinicia_el_contador(): void
    {
        $cb = $this->breaker(threshold: 3);
        $calls = 0;

        $this->callFailing($cb, $calls);
        $this->callFailing($cb, $calls);
        $this->assertSame('ok', $cb->call(fn () => 'ok'));
        $this->assertNull(Redis::get("cb:{$this->name}:failures"), 'un éxito borra el contador de fallos');

        // tras el éxito hacen falta 3 fallos NUEVOS para abrir
        $this->callFailing($cb, $calls);
        $this->callFailing($cb, $calls);
        $this->assertTrue($this->callFailing($cb, $calls), 'el tercer fallo todavía llama al servicio');
        $this->assertFalse($this->callFailing($cb, $calls), 'ahora sí está abierto');
    }

    public function test_semiabierto_cierra_el_circuito_cuando_la_sonda_tiene_exito(): void
    {
        $cb = $this->breaker(threshold: 3, openTimeout: 30);
        $calls = 0;
        for ($i = 0; $i < 3; $i++) {
            $this->callFailing($cb, $calls);
        }
        $this->ageOpenCircuit(31);

        $this->assertSame('recuperado', $cb->call(fn () => 'recuperado'));
        $this->assertNull(Redis::get("cb:{$this->name}:opened_at"), 'la sonda exitosa cierra el circuito');
        $this->assertNull(Redis::get("cb:{$this->name}:failures"));
        $this->assertSame('ok', $cb->call(fn () => 'ok'));
    }

    public function test_semiabierto_vuelve_a_abrir_cuando_la_sonda_falla_con_el_contador_vigente(): void
    {
        $cb = $this->breaker(threshold: 3, openTimeout: 30);
        $calls = 0;
        for ($i = 0; $i < 3; $i++) {
            $this->callFailing($cb, $calls);
        }
        $this->ageOpenCircuit(31);

        $this->assertTrue($this->callFailing($cb, $calls), 'la sonda sí llega al servicio');
        $this->assertSame(4, $calls);
        $this->assertFalse($this->callFailing($cb, $calls), 'la sonda fallida reabre el circuito');
        $this->assertSame(4, $calls);
    }

    public function test_el_contador_de_fallos_tiene_ttl_igual_a_la_ventana(): void
    {
        $cb = $this->breaker(threshold: 5, window: 60);
        $calls = 0;
        $this->callFailing($cb, $calls);

        $ttl = (int) Redis::ttl("cb:{$this->name}:failures");
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
    }

    public function test_relanza_la_excepcion_original_del_servicio(): void
    {
        $original = new RuntimeException('timeout contra el tercero');

        try {
            $this->breaker()->call(function () use ($original): void {
                throw $original;
            });
            $this->fail('debió relanzar');
        } catch (RuntimeException $e) {
            $this->assertSame($original, $e);
        }
    }

    public function test_un_circuit_open_exception_anidado_no_cuenta_como_fallo_propio(): void
    {
        $inner = $this->name . '-inner';
        Redis::set("cb:{$inner}:opened_at", (string) time());

        try {
            for ($i = 0; $i < 5; $i++) {
                try {
                    $this->breaker(threshold: 3)->call(fn () => (new CircuitBreaker($inner))->call(fn () => 'x'));
                } catch (CircuitOpenException) {
                }
            }
            $this->assertNull(Redis::get("cb:{$this->name}:failures"), 'el circuito abierto de otro servicio no debe contarse como fallo de este');
        } finally {
            Redis::del("cb:{$inner}:opened_at", "cb:{$inner}:failures");
        }
    }
}
