<?php

namespace CacheWerk\Relay\Benchmarks\Support;

use ReflectionClass;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Output\StreamOutput;

class CliReporter extends Reporter
{
    public function startingBenchmark(Benchmark $benchmark): void
    {
        printf(
            "\n\033[30;42m %s \033[0m Executing %d iterations (%d warmup, %d revs) of %s %s operations...\n\n",
            $benchmark->getName(),
            $benchmark->its(),
            $benchmark::Warmup ?? 'no',
            $benchmark->revs(),
            number_format($benchmark->opsTotal()),
            $benchmark->getName(),
        );
    }

    public function startingTimedBenchmark(Benchmark $benchmark, int $workers, float $duration) {
        printf(
            "\n\033[30;42m %s \033[0m Executing operations for %2.2fs using %d workers (warmup: %s)...\n\n",
            $benchmark->getName(),
            $duration,
            $workers,
            $benchmark::Warmup ?? 'no',
        );
    }

    public function finishedIteration(Iteration $iteration, string $operation, string $client): void
    {
        if (! $this->verbose) {
            return;
        }

        echo $iteration->finishedIterationMsg($operation, $client);
    }

    public function finishedSubject(Subject $subject): void
    {
        if (! $this->verbose) {
            return;
        }

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
            self::humanNumber($ops_sec),
            self::humanMemory($memory_median),
            self::humanMemory($bytes_median * $its)
        );
    }

    public function finishedTimedSubject(Subject $subject, int $operations, float $millis): void {
        if (! $this->verbose)
            return;

        printf("Executed %s %s using %s in %sms (%s/sec)\n",
               self::humanNumber($operations),
               $subject->benchmark->getName(),
               $subject->client(),
               number_format($millis, 2),
               self::humanNumber($operations / ($millis / 1000.00), 2)
        );
    }

    public function finishedSubjectsConcurrent(Subjects $subjects, int $workers) {
        $output = new StreamOutput(fopen('php://stdout', 'w')); // @phpstan-ignore-line

        $table = new Table($output);

        $table->setHeaders([
            'Workers', 'Client', 'Memory', 'Network', 'IOPS', 'IOPS/Worker', 'Change', 'Factor',
        ]);

        $subjects = $subjects->sortByOpsPerSec();
        $baseOpsPerSec = $subjects[0]->opsMedian();

        $style_right = ['style' => new TableCellStyle(['align' => 'right'])];

        foreach ($subjects as $i => $subject) {
            $opsPerWorker = $subject->opsMedian() / $workers;
            $diff = -(1 - ($subject->opsMedian() / $baseOpsPerSec)) * 100;

            $factor = $i === 0 ? 1 : number_format($subject->opsMedian() / $baseOpsPerSec, 2);
            $change = $i === 0 ? 0 : number_format($diff, 2);

            $table->addRow([
                new TableCell($workers, ['style' => new TableCellStyle(['align' => 'right'])]),
                $subject->client(),
                new TableCell(self::humanMemory($subject->memoryMedian()), $style_right),
                new TableCell(self::humanMemory($subject->bytesMedian()), $style_right),
                new TableCell(self::humanNumber($subject->opsMedian()), $style_right),
                new TableCell(self::humanNumber($opsPerWorker), $style_right),
                new TableCell("{$change}%", $style_right),
                new TableCell("{$factor}", $style_right),
            ]);
        }

        $table->render();
    }

    public function finishedSubjects(Subjects $subjects): void
    {
        $output = new StreamOutput(fopen('php://stdout', 'w')); // @phpstan-ignore-line

        $table = new Table($output);

        $table->setHeaders([
            'Client', 'Memory', 'Network',
            'IOPS', 'rstdev', 'Time',
            'Change', 'Factor',
        ]);

        $subjects = $subjects->sortByTime();
        $baseMsMedian = $subjects[0]->msMedian();

        $i = 0;

        foreach ($subjects as $subject) {
            $msMedian = $subject->msMedian();
            $memoryMedian = $subject->memoryMedian();
            $bytesMedian = $subject->bytesMedian();
            $diff = -(1 - ($msMedian / $baseMsMedian)) * 100;
            $multiple = 1 / ($msMedian / $baseMsMedian);
            $rstdev = number_format($subject->msRstDev(), 2);
            $opsMedian = $subject->opsMedian();

            $time = number_format($msMedian, 0);
            $factor = $i === 0 ? 1 : number_format($multiple, 2);
            $change = $i === 0 ? 0 : number_format($diff, 1);

            $table->addRow([
                $subject->client(),
                new TableCell(self::humanMemory($memoryMedian), ['style' => new TableCellStyle(['align' => 'right'])]),
                new TableCell(self::humanMemory($bytesMedian), ['style' => new TableCellStyle(['align' => 'right'])]),
                new TableCell(self::humanNumber($opsMedian), ['style' => new TableCellStyle(['align' => 'right'])]),
                new TableCell("±{$rstdev}%", ['style' => new TableCellStyle(['align' => 'right'])]),
                new TableCell("{$time}ms", ['style' => new TableCellStyle(['align' => 'right'])]),
                new TableCell("{$change}%", ['style' => new TableCellStyle(['align' => 'right'])]),
                new TableCell("{$factor}x", ['style' => new TableCellStyle(['align' => 'right'])]),
            ]);

            $i++;
        }

        $table->render();
    }
}
