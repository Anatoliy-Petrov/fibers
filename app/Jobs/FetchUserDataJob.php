<?php

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Demonstrates two patterns for concurrency inside a queued job:
 *  1. Http::pool() — concurrent HTTP fan-out via curl_multi
 *  2. Concurrency::run() — sub-task fan-out via child processes
 */
class FetchUserDataJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(public readonly int $userId = 1) {}

    public function handle(): void
    {
        // Pattern 1: Http::pool() — all three requests fire simultaneously.
        $responses = Http::pool(fn(Pool $pool) => [
            $pool->as('user') ->timeout(5)->get("https://jsonplaceholder.typicode.com/users/{$this->userId}"),
            $pool->as('posts')->timeout(5)->get("https://jsonplaceholder.typicode.com/posts?userId={$this->userId}&_limit=3"),
            $pool->as('todos')->timeout(5)->get("https://jsonplaceholder.typicode.com/todos?userId={$this->userId}&_limit=3"),
        ]);

        $user  = $responses['user']->ok()  ? $responses['user']->json('name')  : 'unavailable';
        $posts = $responses['posts']->ok() ? count($responses['posts']->json()) : 0;
        $todos = $responses['todos']->ok() ? count($responses['todos']->json()) : 0;

        // Pattern 2: Concurrency::run() — CPU-bound or mixed sub-tasks.
        // Uses sync driver here so it works cleanly inside a queue worker process.
        [$summary] = Concurrency::driver('sync')->run([
            fn() => "user={$user} posts={$posts} todos={$todos}",
        ]);

        Log::info("FetchUserDataJob done: $summary");
    }
}
