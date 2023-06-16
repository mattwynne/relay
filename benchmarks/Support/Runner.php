<?php

namespace CacheWerk\Relay\Benchmarks\Support;

use Predis\Client as Predis;
use CacheWerk\Relay\Benchmarks\Support\RawIteration;

class Runner
{
    protected string $host;

    protected int $port;

    protected ?string $auth;

    protected bool $verbose = false;

    protected Predis $redis;

    protected string $run_id;

    protected int $workers;

    protected string $filter;

    protected float $duration;

    /**
     * @param string $host
     * @param string|int $port
     * @param ?string $auth
     * @param bool $verbose
     * @return void
     */
    public function __construct($host, $port, $auth, int $workers, float $duration, string $filter, bool $verbose)
    {
        $this->run_id = uniqid();

        $this->verbose = $verbose;
        $this->filter = $filter;

        $this->host = (string) $host;
        $this->port = (int) $port;
        $this->auth = empty($auth) ? null : $auth;

        $this->workers = $workers;
        $this->duration = $duration;

        /** @var object{type: string, cores: int, arch: string} $cpu */
        $cpu = System::cpu();

        printf("Setting up on %s (%s cores, %s)\n", $cpu->type, $cpu->cores, $cpu->arch);

        printf(
            "Using PHP %s (OPcache: %s, Xdebug: %s, New Relic: %s)\n",
            PHP_VERSION,
            $this->opcache() ? "\033[31mOn\033[0m" : "Off",
            $this->xdebug() ? "\033[31mOn\033[0m" : "Off",
            $this->newrelic() ? "\033[31mOn\033[0m" : 'Off'
        );

        $this->setUpRedis();

        printf(
            "Connected to Redis (%s) at %s\n\n",
            $this->redis->info()['Server']['redis_version'],
            $this->port ? "tcp://{$host}:{$port}" : "unix:{$host}",
        );
    }

    protected function setUpRedis(): void
    {
        $parameters = [
            'host' => $this->host,
            'port' => $this->port,
            'password' => $this->auth,
            'timeout' => 0.5,
            'read_write_timeout' => 0.5,
        ];

        if (! $this->port) {
            $parameters['scheme'] = 'unix';
            $parameters['path'] = $this->host;
        }

        $this->redis = new Predis($parameters, [
            'exceptions' => true,
        ]);
    }

    protected function resetStats() {
        $this->redis->config('RESETSTAT');

        if (function_exists('memory_reset_peak_usage')) {
            \memory_reset_peak_usage();
        }
    }

    protected function getNetworkStats() {
        $info = $this->redis->info('STATS')['Stats'];
        return [
            $info['total_net_input_bytes'],
            $info['total_net_output_bytes'],
        ];
    }

    protected function getRedisCommandCount() : array {
        $result = [];

        $stats = $this->redis->info('commandstats')['Commandstats'];

        foreach ($stats as $key => $val) {
            $cmd = strtoupper(str_replace('cmdstat_', '', $key));
            if ( ! preg_match('/calls=([0-9]+).*/', $val, $matches))
                continue;
            $result[$cmd] = $matches[1];
        }

        return $result;
    }

    protected function saveOperations($method, $operations) {
        $this->redis->sadd(
            "benchmark_run:{$this->run_id}:$method",
            serialize([getmypid(), hrtime(true), $operations, \memory_get_peak_usage()])
        );
    }

    protected function loadIterations($method) {
        $res = [];

        foreach ($this->redis->smembers("benchmark_run:{$this->run_id}:$method") as $iteration) {
            $res[] = unserialize($iteration);
        }

        return $res;
    }

    protected function blockForWorkers($timeout = 1.0) {
        $waiting_key = "benchmark:spooling:{$this->run_id}";

        /* Short circuit, if we're the last worker to spawn */
        if ($this->redis->incr($waiting_key) == $this->workers)
            return;

        /* Wait for all of the workers to be ready up to a predefined maximum time.
           Because this is benchmarking code, we don't invoke the time() syscall each
           iteration. */
        $st = microtime(true);
        for ($i = 1; $this->redis->get($waiting_key) < $this->workers; $i++) {
            if ($i % 10000 == 0 && ($et = microtime(true)) - $st >= $timeout) {
                fprintf(STDERR, "Error:  Timed out waiting for %d workers to span (%2.2fs)\n",
                        $this->workers, $et - $st);
                exit(1);
            }
        }
    }

    protected function setConcurrentStart() {
        $this->redis->setnx("benchmark:start:{$this->run_id}", hrtime(true));
    }

    protected function getConcurrentStart() {
        return $this->redis->get("benchmark:start:{$this->run_id}");
    }

    protected function runMethodConcurrent($reporter, $subject, $class, $method) {
        /* Warm up once, outside of the forked workers */
        $benchmark = new $class($this->host, $this->port, $this->auth);
        $benchmark->setUp();

        for ($i = 0; $i < $benchmark::Warmup * $benchmark->revs(); $i++) {
            $benchmark->{$method}();
        }

        $this->getRedisCommandCount();

        $start = hrtime(true);
        list($rx1, $tx1) = $this->getNetworkStats();
        $pids = [];

        for ($i = 0; $i < $this->workers; $i++) {
            $pid = pcntl_fork();
            if ($pid < 0) {
                fprintf(STDERR, "Error:  Cannot execute pcntl_fork()!\n");
                exit(1);
            } else if ($pid) {
                $pids[] = $pid;
            } else {
                /* Refresh clients since we're in a forked child process.
                   NOTE:  Not required for Relay but for the others it is. */
                $this->setUpRedis();
                $benchmark->setUpClients();

                /* Wait for workers to be ready */
                $this->blockForWorkers();
                $this->setConcurrentStart();

                /* Run operations */
                $operations = 0;
                do {
                    $operations += $benchmark->{$method}();
                } while ((hrtime(true) - $start) / 1e+9 < $this->duration);
                $this->saveOperations($method, $operations);

                exit(0);
            }
        }

        /* Wait for workers to finish */
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status, WUNTRACED);
        }

        list($rx2, $tx2) = $this->getNetworkStats();

        $end = $max_mem = $tot_ops = 0;
        foreach ($this->loadIterations($method) as [$pid, $now, $ops, $mem]) {
            $tot_ops += $ops;
            $max_mem = max($max_mem, $mem);
            $end = max($end, $now);
        }

        $start = $this->getConcurrentStart();
        $iteration = new RawIteration($tot_ops, ($end - $start) / 1e+6, $max_mem, $rx2 - $rx1, $tx2 - $tx1);
        $subject->addIterationObject($iteration);
        $reporter->finishedTimedSubject($subject, $tot_ops, ($end - $start) / 1e+6);
    }

    protected function runMethod($reporter, $subject, $benchmark, $method) {
        for ($i = 0; $i < $benchmark::Warmup; $i++) {
            for ($i = 1; $i <= $benchmark::Revolutions; $i++) {
                $benchmark->{$method}();
            }
        }

        for ($i = 0; $i < $benchmark::Iterations; $i++) {
            $this->resetStats();

            usleep(100000); // 100ms

            $start = hrtime(true);

            for ($r = 1; $r <= $benchmark::Revolutions; $r++) {
                $benchmark->{$method}();
            }

            $end = hrtime(true);
            $memory = memory_get_peak_usage();
            $ms = ($end - $start) / 1e+6;

            list ($bytesIn, $bytesOut) = $this->getNetworkStats();

            $iteration = $subject->addIteration($ms, $memory, $bytesIn, $bytesOut);

            $reporter->finishedIteration($iteration, $benchmark->getName(),
                                         $subject->client());
        }

        $reporter->finishedSubject($subject);
    }

    /**
     * @param class-string[] $benchmarks
     * @return void
     */
    public function run(array $benchmarks): void
    {
        foreach ($benchmarks as $class) {
            /** @var Benchmark $benchmark */
            $benchmark = new $class($this->host, $this->port, $this->auth);
            $benchmark->setUp();

            $subjects = new Subjects($benchmark);

            $reporter = new CliReporter($this->verbose);

            if ($this->workers > 1) {
                $reporter->startingTimedBenchmark($benchmark, $this->workers, $this->duration);
            } else {
                $reporter->startingBenchmark($benchmark);
            }

            foreach ($benchmark->getBenchmarkMethods($this->filter) as $method) {
                $subject = $subjects->add($method);

                if ($this->workers > 1) {
                    $this->runMethodConcurrent($reporter, $subject, $class, $method);
                } else {
                    $this->runMethod($reporter, $subject, $benchmark, $method);
                }
            }

            if ($this->workers > 1) {
                $reporter->finishedSubjectsConcurrent($subjects, $this->workers);
            } else {
                $reporter->finishedSubjects($subjects);
            }
        }
    }

    protected function opcache(): bool
    {
        return function_exists('opcache_get_status')
            && opcache_get_status();
    }

    protected function xdebug(): bool
    {
        return function_exists('xdebug_info')
            && ! in_array('off', xdebug_info('mode'));
    }

    protected function newrelic(): bool
    {
        return extension_loaded('newrelic')
            && ini_get('newrelic.enabled');
    }
}
