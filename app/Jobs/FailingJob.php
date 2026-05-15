<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Bus\Batchable;

class FailingJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        throw new \RuntimeException('intentional failure');
    }
}
