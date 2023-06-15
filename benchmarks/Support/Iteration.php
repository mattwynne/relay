<?php

namespace CacheWerk\Relay\Benchmarks\Support;

use CacheWerk\Relay\Benchmarks\Support\CliReporter;

class Iteration
{
    public float $ms;

    public int $memory;

    public int $bytesIn;

    public int $bytesOut;

    public Subject $subject;

    public function __construct(float $ms, int $memory, int $bytesIn, int $bytesOut, Subject $subject)
    {
        $this->ms = $ms;
        $this->memory = $memory;
        $this->bytesIn = $bytesIn;
        $this->bytesOut = $bytesOut;
        $this->subject = $subject;
    }

    /**
     * @return int|float
     */
    public function opsPerSec()
    {
        $benchmark = $this->subject->benchmark;

        return $benchmark->opsTotal() / ($this->ms / 1000);
    }

    public function finishedIterationMsg(string $operation, string $client): string {
        return sprintf("Executed %s %s using %s in %sms (%s ops/s) [memory:%s, network:%s]\n",
            number_format($this->subject->benchmark->opsTotal()),
            $operation,
            $client,
            number_format($this->ms, 2),
            CliReporter::humanNumber($this->opsPerSec()),
            CliReporter::humanMemory($this->memory),
            CliReporter::humanMemory($this->bytesIn + $this->bytesOut)
        );
    }

    public function finishedSubjectMsg(Subject $subject) {
        $benchmark = $subject->benchmark;

        $ops = $benchmark->ops();
        $its = $benchmark->its();
        $revs = $benchmark->revs();
        $name = $benchmark->getName();

        $ms_median = $subject->msMedian();
        $memory_median = $subject->memoryMedian();
        $bytes_median = $subject->bytesMedian();
        $rstdev = $subject->msRstDev();

        $ops_sec = ($ops * $revs) / ($ms_median / 1000);

        printf(
            "Executed %d iterations of %s %s using %s in ~%sms [±%.2f%%] (~%s ops/s) [memory:%s, network:%s]\n\n",
            count($subject->iterations),
            number_format($benchmark->opsTotal()),
            $name,
            $subject->client(),
            number_format($ms_median, 2),
            $rstdev,
            CliReporter::humanNumber($ops_sec),
            CliReporter::humanMemory($memory_median),
            CliReporter::humanMemory($bytes_median * $its)
        );
    }
}
