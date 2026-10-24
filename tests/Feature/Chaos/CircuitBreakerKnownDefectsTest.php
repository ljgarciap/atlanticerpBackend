<?php

declare(strict_types=1);

namespace Tests\Feature\Chaos;

use App\Shared\Support\CircuitBreaker;
use App\Shared\Support\CircuitOpenException;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\Feature\Concerns\InjectsFaults;
use Tests\TestCase;

/**
 * SCRUM-1030 — los tres defectos del CircuitBreaker que SCRUM-965 fijó como tests de caracterización (R-01, R-02, R-03) ya están
 * corregidos: estos tests afirman el comportamiento DESEADO. Evidencia original en docs/security/RESILIENCE-FINDINGS.md.
 *
 * Decisión del Arquitecto: el breaker es FAIL-OPEN ante una caída de Redis (protege disponibilidad, no es un control de seguridad).
 */
final class CircuitBreakerKnownDefectsTest extends TestCase
{
    use InjectsFaults;

    private string $name;

    protected function setUp(): void
    {
        parent::setUp();
        $this->name = 'chaos-defect-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->redisUp();   // las claves se borran con la conexión real
        Redis::del("cb:{$this->name}:failures", "cb:{$this->name}:opened_at");
        parent::tearDown();
    }

    /** R-01 · SCRUM-1030 — con Redis caído el servicio externo SANO sí se llama (fail-open) y no se lanza RedisException. */
    public function test_r01_con_redis_caido_el_breaker_es_fail_open_y_ejecuta_el_servicio_externo(): void
    {
        $this->redisDown();
        $ran = false;

        $result = (new CircuitBreaker($this->name))->call(function () use (&$ran): string {
            $ran = true;

            return 'servicio externo OK';
        });

        $this->assertTrue($ran, 'el callback se ejecuta aunque Redis esté caído');
        $this->assertSame('servicio externo OK', $result);
    }

    public function test_r01_con_redis_caido_un_fallo_del_servicio_externo_sigue_propagandose(): void
    {
        $this->redisDown();

        $this->expectException(RuntimeException::class);
        (new CircuitBreaker($this->name))->call(static function (): never {
            throw new RuntimeException('el tercero falló de verdad');
        });
    }

    /** R-02 · SCRUM-1030 — en semi-abierto entra UNA sola sonda; el resto recibe CircuitOpenException. */
    public function test_r02_en_semiabierto_pasa_una_sola_sonda(): void
    {
        Redis::set("cb:{$this->name}:opened_at", (string) (time() - 31));   // circuito abierto hace 31 s (> 30 s)
        $cb = new CircuitBreaker($this->name, 5, 60, 30);
        $entered = 0;
        $rechazados = 0;

        // El llamador A (la sonda) está "en vuelo" cuando llega el llamador B: concurrencia determinista.
        $cb->call(function () use ($cb, &$entered, &$rechazados): string {
            $entered++;
            try {
                $cb->call(function () use (&$entered): string {
                    $entered++;

                    return 'B';
                });
            } catch (CircuitOpenException) {
                $rechazados++;
            }

            return 'A';
        });

        $this->assertSame(1, $entered, 'solo la sonda entra');
        $this->assertSame(1, $rechazados, 'el segundo llamador recibe CircuitOpenException');
        $this->assertNull(Redis::get("cb:{$this->name}:opened_at"), 'la sonda exitosa cierra el circuito');
        $this->assertSame(0, (int) Redis::exists("cb:{$this->name}:probe"), 'el candado de la sonda se libera');
    }

    /** R-02 · una sonda que no termina libera el candado al expirar (no deja el circuito atascado). */
    public function test_r02_el_candado_de_la_sonda_expira_solo(): void
    {
        Redis::set("cb:{$this->name}:opened_at", (string) (time() - 31));
        Redis::set("cb:{$this->name}:probe", '1', 'EX', 1);
        $cb = new CircuitBreaker($this->name, 5, 60, 30);

        $this->expectException(CircuitOpenException::class);
        $cb->call(static fn (): string => 'x');   // con el candado vivo, otro llamador no pasa
    }

    /** R-03 · SCRUM-1030 — una sonda fallida reabre el circuito de inmediato aunque el contador de fallos haya expirado. */
    public function test_r03_una_sonda_fallida_reabre_el_circuito_aunque_el_contador_haya_expirado(): void
    {
        Redis::set("cb:{$this->name}:opened_at", (string) (time() - 31));
        Redis::del("cb:{$this->name}:failures");   // la ventana de fallos ya venció
        $cb = new CircuitBreaker($this->name, 3, 60, 30);
        $entered = 0;
        $fail = function () use (&$entered): void {
            $entered++;
            throw new RuntimeException('caído');
        };

        try {
            $cb->call($fail);   // la sonda
        } catch (RuntimeException) {
        }
        $this->assertSame(1, $entered);
        $this->assertGreaterThanOrEqual(time() - 2, (int) Redis::get("cb:{$this->name}:opened_at"), 'la sonda fallida renueva el instante de apertura');
        $this->assertSame(0, (int) Redis::exists("cb:{$this->name}:probe"), 'y libera el candado de la sonda');

        $this->expectException(CircuitOpenException::class);
        $cb->call($fail);   // el siguiente llamador ya ve el circuito abierto (recién reabierto): no llega al servicio caído
    }
}
