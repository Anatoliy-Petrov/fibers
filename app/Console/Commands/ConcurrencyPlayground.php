<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;

class ConcurrencyPlayground extends Command
{
    protected $signature = 'concurrency:playground';
    protected $description = 'Phase 2 — Concurrency facade: run(), defer(), driver comparison';

    // Long enough that parallelism beats fork overhead, short enough the suite runs fast
    private const DELAY_MS = 150;

    public function handle(): void
    {
        $this->info('=== Phase 2: Concurrency Facade (Laravel 13) ===');
        $this->newLine();

        $this->noteDrivers();
        $this->experiment1_basicRun();
        $this->experiment2_syncVsProcess();
        $this->experiment3_eloquentFanOut();
        $this->experiment4_namedTasks();
        $this->experiment5_partialFailure();
        $this->experiment6_defer();
    }

    // -----------------------------------------------------------------------

    private function noteDrivers(): void
    {
        $this->comment('--- Available drivers in Laravel 13 ---');
        $this->line('  process  default — spawns child "php artisan invoke-serialized-closure" processes');
        $this->line('  fork     requires spatie/fork — uses pcntl_fork() directly');
        $this->line('  sync     runs tasks sequentially — for tests / local debugging');
        $this->line('');
        $this->warn('  Note: the "fiber" driver from the Laravel 11 docs does NOT exist in Laravel 13.');
        $this->line('  PHP Fibers surface in Http::pool() (Phase 3), not in Concurrency::run().');
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function experiment1_basicRun(): void
    {
        $this->comment('--- Experiment 1: Basic Concurrency::run() ---');

        [$count, $upper, $sum] = Concurrency::run([
            fn() => DB::table('migrations')->count(),
            fn() => strtoupper('concurrency works'),
            fn() => array_sum(range(1, 100)),
        ]);

        $this->line("migrations count : $count");
        $this->line("string task      : $upper");
        $this->line("sum(1..100)      : $sum");
        $this->line('Results come back in the same order as the input array.');
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function experiment2_syncVsProcess(): void
    {
        $this->comment('--- Experiment 2: sync vs process driver — wall-clock timing ---');
        $this->line('5 tasks × ' . self::DELAY_MS . 'ms simulated I/O (usleep)');
        $this->newLine();

        $tasks = $this->sleepTasks(5);

        // sync = sequential baseline
        $syncMs = $this->timeMs(fn() => Concurrency::driver('sync')->run($tasks));

        // process = child processes in parallel
        $processMs = $this->timeMs(fn() => Concurrency::driver('process')->run($tasks));

        $this->table(
            ['Driver', 'Wall time', 'Speedup', 'Mechanism'],
            [
                ['sync',    sprintf('%4.0fms', $syncMs),    '1.0×', 'sequential — runs each closure in turn'],
                ['process', sprintf('%4.0fms', $processMs), sprintf('%.1f×', $syncMs / max($processMs, 1)), 'parallel child processes via Symfony Process'],
            ]
        );

        $this->line('  → process driver overhead: bootstraps a full Laravel app per task (~50ms/process).');
        $this->line('  → Benefit wins once tasks are slower than that bootstrap cost.');
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function experiment3_eloquentFanOut(): void
    {
        $this->comment('--- Experiment 3: Fan-out 5 Eloquent queries ---');

        $queries = [
            'migrations' => fn() => DB::table('migrations')->count(),
            'users'      => fn() => DB::table('users')->count(),
            'cache'      => fn() => DB::table('cache')->count(),
            'jobs'       => fn() => DB::table('jobs')->count(),
            'cache_lock' => fn() => DB::table('cache_locks')->count(),
        ];

        $seqMs = $this->timeMs(function () use ($queries) {
            $r = [];
            foreach ($queries as $k => $fn) {
                $r[$k] = $fn();
            }
            return $r;
        });

        $concMs = $this->timeMs(fn() => Concurrency::driver('process')->run($queries));
        $results = Concurrency::driver('process')->run($queries);

        $this->table(
            ['Table', 'Row count'],
            array_map(fn($k, $v) => [$k, $v], array_keys($results), $results)
        );

        $this->table(
            ['Approach', 'Wall time'],
            [
                ['sequential (5 queries)', sprintf('%.0fms', $seqMs)],
                ['process driver (5 queries)', sprintf('%.0fms', $concMs)],
            ]
        );

        $this->line('  → With SQLite + sub-ms queries the fork overhead dominates.');
        $this->line('  → Real payoff: slow DB (remote MySQL) or queries that take 100ms+ each.');
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function experiment4_namedTasks(): void
    {
        $this->comment('--- Experiment 4: Named tasks — associative keys are preserved ---');

        $results = Concurrency::run([
            'alpha'   => fn() => range(1, 3),
            'beta'    => fn() => ['x' => 1, 'y' => 2],
            'gamma'   => fn() => strtolower('HELLO'),
        ]);

        foreach ($results as $name => $value) {
            $this->line("  $name => " . json_encode($value));
        }

        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function experiment5_partialFailure(): void
    {
        $this->comment('--- Experiment 5: Exception handling — one failing task ---');
        $this->line('Without isolation: the exception propagates and kills all results.');
        $this->newLine();

        // Tolerant pattern: wrap each task individually
        $tasks = [
            'ok1'  => fn() => 'good result',
            'boom' => fn() => throw new \RuntimeException('task exploded'),
            'ok2'  => fn() => 'also good',
        ];

        $results = [];
        foreach ($tasks as $key => $task) {
            $results[$key] = Concurrency::run([
                $key => function () use ($task) {
                    try {
                        return ['ok' => true,  'value' => $task()];
                    } catch (\Throwable $e) {
                        return ['ok' => false, 'error' => $e->getMessage()];
                    }
                },
            ])[$key];
        }

        foreach ($results as $key => $result) {
            $status = $result['ok'] ? '✓' : '✗';
            $detail = $result['ok'] ? $result['value'] : $result['error'];
            $this->line("  $status $key: $detail");
        }

        $this->line('');
        $this->line('  → Wrap individual closures in try/catch for partial-failure tolerance.');
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function experiment6_defer(): void
    {
        $this->comment('--- Experiment 6: defer() — post-response work ---');
        $this->line('The defer() helper (and Concurrency::defer()) schedules work to run AFTER');
        $this->line('the current request/command finishes. Ordering demo:');
        $this->newLine();

        $log = [];

        $log[] = 'step 1 — main flow starts';

        // defer() registers a DeferredCallback in the container's DeferredCallbackCollection.
        // Concurrency::defer() (process driver) wraps this to spawn a background process.
        // Here we use defer() directly so the callback runs in-process and is observable.
        defer(function () use (&$log) {
            $log[] = 'step 3 — deferred work ran (after "response")';
        });

        $log[] = 'step 2 — main flow ends, "response sent to client" here in FPM';

        // Force-flush deferred callbacks (normally triggered by FPM termination handler)
        app(\Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke();

        $log[] = 'step 4 — back in caller (FPM worker loop would continue here)';

        foreach ($log as $line) {
            $this->line("  $line");
        }

        $this->newLine();
        $this->line('  Concurrency::defer() (process driver) does the same but spawns a');
        $this->line('  background "php artisan invoke-serialized-closure" process for the work.');
        $this->line('  ⚠  Does NOT work with "php artisan serve" — needs nginx+fpm or Octane.');
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function sleepTasks(int $count): array
    {
        $us = self::DELAY_MS * 1000;
        $tasks = [];
        for ($i = 1; $i <= $count; $i++) {
            $tasks[] = function () use ($us, $i): string {
                usleep($us);
                return "task $i done";
            };
        }
        return $tasks;
    }

    private function timeMs(callable $fn): float
    {
        $start = hrtime(true);
        $fn();
        return (hrtime(true) - $start) / 1e6;
    }
}