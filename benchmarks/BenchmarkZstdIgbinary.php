<?php

namespace CacheWerk\Relay\Benchmarks;

use Redis;
use Relay\Relay;

class BenchmarkZstdIgbinary extends Support\Benchmark
{
    const Operations = 1000;

    const Iterations = 5;

    const Revolutions = 25;

    const Warmup = 1;

    protected int $chunkSize = 10;

    /**
     * @var array<int, object{id: string}>
     */
    protected array $data;

    /**
     * @var array<int, string>
     */
    protected array $keys;

    public function getName(): string {
        return 'GET (Serialized)';
    }

    public function setUp(): void
    {
        $this->flush();
        $this->setUpClients();

        $json = file_get_contents(__DIR__ . '/Support/data/meteorites.json');

        $this->data = json_decode($json, false, 512, JSON_THROW_ON_ERROR); // @phpstan-ignore-line
        $this->keys = array_map(fn ($item) => $item->id, $this->data); // @phpstan-ignore-line

        $this->seedRelay();
        $this->seedPredis();
        $this->seedPhpRedis();
    }

    protected function doBenchmark($client, $name): int {
        foreach ($this->keys as $key) {
            $client->get("$name:$key");
        }
        return count($this->keys);
    }

    public function benchmarkPredis(): int {
        return $this->doBenchmark($this->predis, "predis");
    }

    public function benchmarkPhpRedis(): int {
        return $this->doBenchmark($this->phpredis, "phpredis");
    }

    public function benchmarkRelayNoCache(): int {
        return $this->doBenchmark($this->relayNoCache, "relay");
    }

    public function benchmarkRelay(): int {
        return $this->doBenchmark($this->relay, "relay");
    }

    protected function seedClient($client, $name, $items) {
        foreach ($this->data as $item) {
            $client->set("$name:{$item->id}", $items);
        }
    }

    protected function seedPredis(): void {
        $this->seedClient($this->predis, "predis", serialize($this->randomItems()));
    }

    protected function seedPhpRedis(): void {
        $this->phpredis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
        $this->phpredis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_ZSTD);
        $this->seedClient($this->phpredis, "phpredis", $this->randomItems());
    }

    protected function seedRelay(): void
    {
        $this->relayNoCache->setOption(Relay::OPT_SERIALIZER, Relay::SERIALIZER_IGBINARY);
        $this->relayNoCache->setOption(Relay::OPT_COMPRESSION, Relay::COMPRESSION_ZSTD);
        $this->relay->setOption(Relay::OPT_SERIALIZER, Relay::SERIALIZER_IGBINARY);
        $this->relay->setOption(Relay::OPT_COMPRESSION, Relay::COMPRESSION_ZSTD);

        $this->seedClient($this->relayNoCache, "relay", $this->randomItems());
    }

    /**
     * @return array<int, object>
     */
    protected function randomItems()
    {
        return array_values(
            array_intersect_key(
                $this->data,
                array_flip(array_rand($this->data, $this->chunkSize)) // @phpstan-ignore-line
            )
        );
    }
}
