<?php

namespace YeastarSocket\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base per i test che hanno bisogno di un gateway con cui dialogare.
 *
 * Avvia `fake_gateway.php` come processo separato su una porta effimera e ne
 * garantisce la chiusura a fine test. Nessun test parla col dispositivo vero.
 */
abstract class FakeGatewayTestCase extends TestCase
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    protected int $gatewayPort = 0;

    /**
     * Avvia il gateway finto nello scenario indicato e restituisce la porta.
     */
    protected function startGateway(string $scenario): int
    {
        $script = __DIR__ . '/fake_gateway.php';

        $this->process = proc_open(
            [PHP_BINARY, $script, '0', $scenario],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes
        );

        if (!is_resource($this->process)) {
            $this->fail('Impossibile avviare il gateway finto');
        }

        // La prima riga di stdout e' la porta su cui il finto sta ascoltando.
        $port = fgets($this->pipes[1]);

        if ($port === false || (int) trim($port) === 0) {
            $this->fail('Il gateway finto non ha comunicato una porta: ' . stream_get_contents($this->pipes[2]));
        }

        return $this->gatewayPort = (int) trim($port);
    }

    protected function tearDown(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }

        parent::tearDown();
    }
}
