<?php

namespace CacheWerk\Relay\Benchmarks\Support;

abstract class Reporter
{
    protected bool $verbose;

    public function __construct(bool $verbose)
    {
        $this->verbose = $verbose;
    }

    abstract function startingBenchmark(Benchmark $benchmark): void;

    abstract function finishedIteration(Iteration $iteration, string $operation, string $client): void;

    abstract function finishedSubject(Subject $subject): void;

    abstract public function finishedTimedSubject(Subject $subject, int $operations, float $millis, int $redisCommands): void;

    abstract function finishedSubjects(Subjects $subjects): void;

    /**
     * @param int|float $bytes
     * @return string
     */
    public static function humanMemory($bytes)
    {
        $i = floor(log($bytes, 1024));

        return number_format(
            $bytes / pow(1024, $i),
            [0, 0, 2, 2][$i]
        ) . ['b', 'kb', 'mb', 'gb'][$i];
    }

    /**
     * @param int|float $number
     * @return string
     */
    public static function humanNumber($number)
    {
        $i = $number > 0 ? floor(log($number, 1000)) : 0;

        return number_format(
            $number / pow(1000, $i),
            [0, 2, 2, 2][$i],
        ) . ['', 'K', 'M', 'B'][$i];
    }
}
