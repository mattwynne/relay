<?php

namespace CacheWerk\Relay\Benchmarks;

class BenchmarkMget extends Support\Benchmark
{
    const Operations = 100;

    const Iterations = 5;

    const Revolutions = 500;

    const Warmup = 1;

    /**
     * @var array<int, array<int, string>>
     */
    protected array $keyChunks;

    public function getName(): string {
        return 'MGET';
    }

    public function setUp(): void
    {
        $this->flush();
        $this->setUpClients();

        $keys = $this->loadJson('meteorites.json');
        $length = count($keys) / self::Operations;

        $this->keyChunks = array_chunk($keys, $length); // @phpstan-ignore-line
    }

    protected function doBenchmark($client): int {
        foreach ($this->keyChunks as $keys) {
            $client->mget($keys);
        }
        return count($this->keyChunks);
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
