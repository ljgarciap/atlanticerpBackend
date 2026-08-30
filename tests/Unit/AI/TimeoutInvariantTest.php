<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use PHPUnit\Framework\TestCase;

class TimeoutInvariantTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $horizonConfig;

    protected function setUp(): void
    {
        parent::setUp();
        // Read config file directly — unit tests have no Laravel container
        $this->horizonConfig = require __DIR__ . '/../../../config/horizon.php';
    }

    public function test_horizon_supervisor_timeout_supera_http_client_timeout(): void
    {
        $httpTimeout       = 90;  // AnthropicClient::DEFAULT_TIMEOUT
        /** @var array<string, mixed> $supervisorDefaults */
        $supervisorDefaults = $this->horizonConfig['defaults']['supervisor-1'] ?? [];
        $supervisorTimeout  = (int) ($supervisorDefaults['timeout'] ?? 0);

        $this->assertGreaterThan(
            $httpTimeout,
            $supervisorTimeout,
            "Horizon supervisor timeout ({$supervisorTimeout}s) debe superar el HTTP timeout ({$httpTimeout}s). " .
            "Ajusta 'timeout' en config/horizon.php defaults.supervisor-1."
        );
    }

    public function test_horizon_environments_incluyen_entornos_de_servidor(): void
    {
        /** @var array<string, mixed> $environments */
        $environments = array_keys((array) ($this->horizonConfig['environments'] ?? []));

        foreach (['qa', 'test', 'production'] as $required) {
            $this->assertContains(
                $required,
                $environments,
                "config/horizon.php environments debe incluir '{$required}'. " .
                "Sin esto, Horizon arranca sin supervisores en ese entorno y no procesa jobs."
            );
        }
    }
}
