<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Bus\Batchable;

class SampleJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(
        public readonly string $label,
        public readonly int $sleepMs = 0,
    ) {}

    public function handle(): void
    {
        if ($this->sleepMs > 0) {
            usleep($this->sleepMs * 1000);
        }
        // Intentionally no output — side effects go through the batch record.
    }
}
