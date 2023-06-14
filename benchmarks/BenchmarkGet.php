<?php

namespace CacheWerk\Relay\Benchmarks;

class BenchmarkGet extends Support\Benchmark
{
    const Name = 'GET';

    const Operations = 1000;

    const Iterations = 5;

    const Revolutions = 50;

    const Warmup = 0;

    /**
     * @var array<int, string>
     */
    protected array $keys;

    public function setUp(): void
    {
        $this->flush();
        $this->setUpClients();

        $this->keys = $this->loadJson('meteorites.json');
    }

    protected function doBenchmark($client) {
        foreach ($this->keys as $key) {
            $client->get((string)$key);
        }

        return count($this->keys);
    }

    public function benchmarkPredis(): int {
        return $this->doBenchmark($this->predis);
    }

    public function benchmarkPhpRedis(): int {
        return $this->doBenchmark($this->phpredis);
    }

    public function benchmarkRelayNoCache(): int {
        return $this->doBenchmark($this->relayNoCache);
    }

    public function benchmarkRelay(): int {
        return $this->doBenchmark($this->relay);
    }
}
