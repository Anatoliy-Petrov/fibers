<?php

namespace App\Console\Commands;

use Fiber;
use Illuminate\Console\Command;

class FiberPlayground extends Command
{
    protected $signature = 'fiber:playground';
    protected $description = 'Phase 1 — raw Fiber experiments: lifecycle, suspend/resume, state inspection';

    public function handle(): void
    {
        $this->info('=== Phase 1: Raw Fiber Mechanics ===');
        $this->newLine();

        $this->experiment1_basicLifecycle();
        $this->experiment2_suspendResume();
        $this->experiment3_stateInspection();
        $this->experiment4_returnValue();
        $this->experiment5_exception();
    }

    private function experiment1_basicLifecycle(): void
    {
        $this->comment('--- Experiment 1: Basic lifecycle ---');

        $fiber = new Fiber(function (): string {
            $this->line('  [fiber] started, about to suspend');
            Fiber::suspend('hello from suspend');
            $this->line('  [fiber] resumed, running to completion');
            return 'fiber return value';
        });

        $this->line('Before start — isStarted: ' . var_export($fiber->isStarted(), true));

        $suspended = $fiber->start();
        $this->line('After start  — isSuspended: ' . var_export($fiber->isSuspended(), true));
        $this->line('Suspend value: ' . $suspended);

        $fiber->resume();
        $this->line('After resume — isTerminated: ' . var_export($fiber->isTerminated(), true));
        $this->line('Return value:  ' . $fiber->getReturn());
        $this->newLine();
    }

    private function experiment2_suspendResume(): void
    {
        $this->comment('--- Experiment 2: Passing values through suspend/resume ---');

        $fiber = new Fiber(function (): void {
            $a = Fiber::suspend('want A');
            $this->line("  [fiber] got A = $a");

            $b = Fiber::suspend('want B');
            $this->line("  [fiber] got B = $b");
        });

        $ask = $fiber->start();           // 'want A'
        $this->line("Caller received: $ask");

        $ask = $fiber->resume('apple');   // 'want B'
        $this->line("Caller received: $ask");

        $fiber->resume('banana');
        $this->newLine();
    }

    private function experiment3_stateInspection(): void
    {
        $this->comment('--- Experiment 3: All lifecycle states ---');

        $fiber = new Fiber(function (): void {
            Fiber::suspend();
        });

        $this->line('created    — isStarted=' . var_export($fiber->isStarted(), true)
            . ' isSuspended=' . var_export($fiber->isSuspended(), true)
            . ' isTerminated=' . var_export($fiber->isTerminated(), true));

        $fiber->start();
        $this->line('suspended  — isStarted=' . var_export($fiber->isStarted(), true)
            . ' isSuspended=' . var_export($fiber->isSuspended(), true)
            . ' isTerminated=' . var_export($fiber->isTerminated(), true));

        $fiber->resume();
        $this->line('terminated — isStarted=' . var_export($fiber->isStarted(), true)
            . ' isSuspended=' . var_export($fiber->isSuspended(), true)
            . ' isTerminated=' . var_export($fiber->isTerminated(), true));

        $this->newLine();
    }

    private function experiment4_returnValue(): void
    {
        $this->comment('--- Experiment 4: Return value vs suspend value ---');

        $fiber = new Fiber(function (): int {
            Fiber::suspend(42);   // value goes to caller via start()/resume()
            return 99;             // value retrieved via getReturn() after termination
        });

        $suspendVal = $fiber->start();
        $this->line("suspend value (from start()): $suspendVal");

        $fiber->resume();
        $returnVal = $fiber->getReturn();
        $this->line("return value (from getReturn()): $returnVal");
        $this->newLine();
    }

    private function experiment5_exception(): void
    {
        $this->comment('--- Experiment 5: Throwing into a fiber ---');

        $fiber = new Fiber(function (): void {
            try {
                Fiber::suspend();
            } catch (\RuntimeException $e) {
                $this->line('  [fiber] caught: ' . $e->getMessage());
                // fiber terminates normally after catching
            }
        });

        $fiber->start();
        $fiber->throw(new \RuntimeException('error injected from caller'));
        $this->line('Fiber terminated cleanly: ' . var_export($fiber->isTerminated(), true));
        $this->newLine();
    }
}