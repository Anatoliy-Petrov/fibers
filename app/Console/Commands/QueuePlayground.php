<?php

namespace App\Console\Commands;

use App\Jobs\FailingJob;
use App\Jobs\FetchUserDataJob;
use App\Jobs\SampleJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class QueuePlayground extends Command
{
    protected $signature = 'queue:playground';
    protected $description = 'Phase 4 — Bus::batch(), jobs with internal concurrency, Concurrency::defer()';

    public function handle(): void
    {
        $this->info('=== Phase 4: Queue + Jobs ===');
        $this->newLine();

        $this->experiment1_concurrencyInsideJob();
        $this->experiment2_basicBatch();
        $this->experiment3_batchCallbacks();
        $this->experiment4_allowFailures();
        $this->experiment5_dispatchAfterResponse();
    }

    // -----------------------------------------------------------------------

    private function experiment1_concurrencyInsideJob(): void
    {
        $this->comment('--- Experiment 1: Concurrency::run() inside a queued job ---');
        $this->line('dispatchSync() runs the job in the current process (no worker needed).');
        $this->newLine();

        $start = hrtime(true);
        FetchUserDataJob::dispatchSync(userId: 1);
        $ms = (hrtime(true) - $start) / 1e6;

        $this->line(sprintf('  FetchUserDataJob ran in %.0fms', $ms));
        $this->line('  Internally used Http::pool() for HTTP fan-out (see storage/logs/laravel.log).');
        $this->line('  Concurrency::run(sync) for the result assembly step.');
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function experiment2_basicBatch(): void
    {
        $this->comment('--- Experiment 2: Bus::batch() — 5 jobs, observe state transitions ---');
        $this->newLine();

        $batch = Bus::batch([
            new SampleJob('alpha',   50),
            new SampleJob('bravo',   50),
            new SampleJob('charlie', 50),
            new SampleJob('delta',   50),
            new SampleJob('echo',    50),
        ])->name('phase4-basic')->dispatch();

        $this->line("Batch dispatched  id={$batch->id} total={$batch->totalJobs} pending={$batch->pendingJobs}");

        $this->processQueue();

        $batch = $batch->fresh();
        $this->line("Batch after work  finished={$this->bool($batch->finished())} failed={$batch->failedJobs} pending={$batch->pendingJobs}");
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function experiment3_batchCallbacks(): void
    {
        $this->comment('--- Experiment 3: then() / catch() / finally() callbacks ---');
        $this->line('Callbacks run inside the worker process when the batch state changes.');
        $this->newLine();

        $log = [];

        $batch = Bus::batch([
            new SampleJob('job-1'),
            new SampleJob('job-2'),
            new SampleJob('job-3'),
        ])
        ->name('phase4-callbacks')
        ->then(function ($b) use (&$log) {
            $log[] = "then()    fired — all {$b->totalJobs} jobs succeeded";
        })
        ->catch(function ($b, $e) use (&$log) {
            $log[] = 'catch()   fired — ' . $e->getMessage();
        })
        ->finally(function ($b) use (&$log) {
            $log[] = "finally() fired — pending={$b->pendingJobs} failed={$b->failedJobs}";
        })
        ->dispatch();

        $this->processQueue();

        // Callbacks ran inside the worker; read their output from the batch record.
        $batch = $batch->fresh();
        $this->line("  Batch finished: {$this->bool($batch->finished())}  failed: {$batch->failedJobs}");
        $this->line('  (Callback log is written inside the worker process — see notes below)');
        $this->newLine();
        $this->line('  then()    → runs when ALL jobs in the batch succeed');
        $this->line('  catch()   → runs on the FIRST job failure (once)');
        $this->line('  finally() → always runs when the batch finishes or is cancelled');
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function experiment4_allowFailures(): void
    {
        $this->comment('--- Experiment 4: allowFailures() — batch survives partial failure ---');
        $this->newLine();

        // Without allowFailures: one failure cancels the whole batch.
        $strict = Bus::batch([
            new SampleJob('ok-1'),
            new FailingJob(),
            new SampleJob('ok-2'),
        ])->name('phase4-strict')->dispatch();

        $this->processQueue();
        $strict = $strict->fresh();

        // With allowFailures: other jobs still run.
        $tolerant = Bus::batch([
            new SampleJob('ok-1'),
            new FailingJob(),
            new SampleJob('ok-2'),
        ])->name('phase4-tolerant')->allowFailures()->dispatch();

        $this->processQueue();
        $tolerant = $tolerant->fresh();

        $allRan = fn($b) => $this->bool($b->pendingJobs === $b->failedJobs);

        $this->table(
            ['Batch', 'allow\nFailures', 'finished()', 'cancelled()', 'failed', 'pending', 'all ran?'],
            [
                ['strict',   'false', $this->bool($strict->finished()),   $this->bool($strict->cancelled()),   $strict->failedJobs,   $strict->pendingJobs,   $allRan($strict)],
                ['tolerant', 'true',  $this->bool($tolerant->finished()), $this->bool($tolerant->cancelled()), $tolerant->failedJobs, $tolerant->pendingJobs, $allRan($tolerant)],
            ]
        );

        $this->line('  strict  → first failure cancels batch; remaining queued jobs are silently deleted.');
        $this->line('  tolerant→ batch NOT cancelled; all 3 jobs ran. But finished()=false because');
        $this->line('            failed jobs do NOT decrement pending_jobs — they only increment failed_jobs.');
        $this->line('            "all ran" = pendingJobs === failedJobs (both 1). finished()=true only when ALL succeed.');
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function experiment5_dispatchAfterResponse(): void
    {
        $this->comment('--- Experiment 5: dispatchAfterResponse() vs Concurrency::defer() ---');
        $this->newLine();

        $this->line('Both patterns dispatch non-critical work after the response is sent:');
        $this->newLine();

        $this->line('  1. Job::dispatchAfterResponse()');
        $this->line('     Queues a job to run after the HTTP response terminates.');
        $this->line('     Uses the configured queue driver (database, redis, etc.).');
        $this->line('     Survives process restart — job is durable in the queue.');
        $this->newLine();

        $this->line('  2. Concurrency::defer(fn() => ...)');
        $this->line('     Runs a closure in a background process after the response.');
        $this->line('     Not durable — if the process dies, the work is lost.');
        $this->line('     Best for cache warming, fire-and-forget analytics.');
        $this->newLine();

        $this->line('  DashboardController already uses Concurrency::defer() to warm the cache');
        $this->line('  post-response. See app/Http/Controllers/DashboardController.php.');
        $this->newLine();

        // Quick demo: dispatch a sample job after response in CLI context
        SampleJob::dispatchAfterResponse('post-response-demo');

        // Force the after-response queue to flush (normally triggered by kernel termination)
        app(\Illuminate\Contracts\Http\Kernel::class);
        $this->line('  SampleJob::dispatchAfterResponse() registered — will run at process shutdown.');
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    /**
     * Drive the queue worker until the jobs table is empty.
     *
     * SQLite WAL-mode locking: a single `queue:work --stop-when-empty` run
     * can exit early if it can't reserve a job due to a write-lock race.
     * We loop — disconnect parent connection each time so the worker subprocess
     * gets exclusive write access, then reconnect to check whether jobs remain.
     */
    private function processQueue(): void
    {
        for ($i = 0; $i < 10; $i++) {
            DB::disconnect();

            Process::path(base_path())
                ->timeout(30)
                ->run('php artisan queue:work --stop-when-empty --tries=1 --no-interaction 2>&1');

            DB::reconnect();

            if (! DB::table('jobs')->exists()) {
                break;
            }
        }
    }

    private function bool(bool $v): string
    {
        return $v ? 'true' : 'false';
    }
}
