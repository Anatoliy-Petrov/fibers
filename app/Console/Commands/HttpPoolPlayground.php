<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class HttpPoolPlayground extends Command
{
    protected $signature = 'http:pool-playground';
    protected $description = 'Phase 3 — Http::pool(): concurrent HTTP, timeouts, partial failure';

    // Public APIs used throughout
    private const DELAY_URL  = 'https://httpbin.org/delay';
    private const USER_URL   = 'https://jsonplaceholder.typicode.com/users/1';
    private const POSTS_URL  = 'https://jsonplaceholder.typicode.com/posts?userId=1&_limit=3';
    private const TODOS_URL  = 'https://jsonplaceholder.typicode.com/todos?userId=1&_limit=3';

    public function handle(): void
    {
        $this->info('=== Phase 3: Http::pool() ===');
        $this->newLine();

        $this->noteMechanism();
        $this->experiment1_basicPool();
        $this->experiment2_sequentialVsPool();
        $this->experiment3_perRequestTimeout();
        $this->experiment4_partialFailure();
        $this->experiment5_concurrencyLimit();
    }

    // -----------------------------------------------------------------------

    private function noteMechanism(): void
    {
        $this->comment('--- How Http::pool() achieves concurrency ---');
        $this->line('  Http::pool() → Guzzle EachPromise → curl_multi_exec event loop');
        $this->line('  NOT backed by PHP Fibers in Laravel 13 (correcting the CLAUDE.md).');
        $this->line('  Each request is a LazyPromise; curl_multi drives all sockets concurrently.');
        $this->line('  PHP Fibers show up in Http::pool() only if you wrap it in Concurrency::run().');
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function experiment1_basicPool(): void
    {
        $this->comment('--- Experiment 1: Basic pool — 3 named requests ---');

        $responses = Http::pool(fn(Pool $pool) => [
            $pool->as('user') ->get(self::USER_URL),
            $pool->as('posts')->get(self::POSTS_URL),
            $pool->as('todos')->get(self::TODOS_URL),
        ]);

        $user  = $responses['user']->json();
        $posts = $responses['posts']->json();
        $todos = $responses['todos']->json();

        $this->line("user  : {$user['name']} <{$user['email']}>");
        $this->line('posts : ' . count($posts) . ' items — first: "' . $posts[0]['title'] . '"');
        $this->line('todos : ' . count($todos) . ' items — first: "' . $todos[0]['title'] . '"');
        $this->line('All three requests fired simultaneously; results keyed by pool->as() name.');
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function experiment2_sequentialVsPool(): void
    {
        $this->comment('--- Experiment 2: Sequential vs pool — wall-clock timing ---');
        $this->line('3 requests × 1s artificial delay (httpbin.org/delay/1)');
        $this->newLine();

        // Sequential
        $start = hrtime(true);
        Http::get(self::DELAY_URL . '/1');
        Http::get(self::DELAY_URL . '/1');
        Http::get(self::DELAY_URL . '/1');
        $seqMs = (hrtime(true) - $start) / 1e6;

        // Pool
        $start = hrtime(true);
        Http::pool(fn(Pool $pool) => [
            $pool->get(self::DELAY_URL . '/1'),
            $pool->get(self::DELAY_URL . '/1'),
            $pool->get(self::DELAY_URL . '/1'),
        ]);
        $poolMs = (hrtime(true) - $start) / 1e6;

        $this->table(
            ['Approach', 'Wall time', 'Speedup'],
            [
                ['sequential', sprintf('%5.0fms', $seqMs), '1.0×'],
                ['Http::pool()', sprintf('%5.0fms', $poolMs), sprintf('%.1f×', $seqMs / max($poolMs, 1))],
            ]
        );
        $this->line('  → curl_multi fires all sockets at once; wall time ≈ slowest single request.');
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function experiment3_perRequestTimeout(): void
    {
        $this->comment('--- Experiment 3: Per-request timeout ---');
        $this->line('Request A: 0.5s timeout against a 1s delay endpoint → should fail');
        $this->line('Request B: 3s timeout against a 0.5s delay endpoint → should succeed');
        $this->newLine();

        $start = hrtime(true);
        $responses = Http::pool(fn(Pool $pool) => [
            $pool->as('slow')->timeout(1)->get(self::DELAY_URL . '/2'),   // 2s delay, 1s timeout → fail
            $pool->as('fast')->timeout(3)->get(self::DELAY_URL . '/0'),   // instant,  3s timeout → pass
        ]);
        $ms = (hrtime(true) - $start) / 1e6;

        foreach (['slow', 'fast'] as $key) {
            $r = $responses[$key];
            if ($r instanceof Throwable) {
                $this->line("  $key → FAILED  (" . class_basename($r) . ': ' . $r->getMessage() . ')');
            } else {
                $status = $r->status();
                $this->line("  $key → OK      (HTTP $status)");
            }
        }

        $this->line(sprintf('  Total wall time: %.0fms', $ms));
        $this->line('  → Failed request stored as Throwable; successful request unaffected.');
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function experiment4_partialFailure(): void
    {
        $this->comment('--- Experiment 4: Partial failure handling pattern ---');
        $this->line('One URL is invalid — robust pattern using withFallback()');
        $this->newLine();

        $sources = [
            'user'  => self::USER_URL,
            'posts' => self::POSTS_URL,
            'oops'  => 'https://does-not-exist.example.invalid/api',
        ];

        $responses = Http::pool(fn(Pool $pool) => array_map(
            fn($key, $url) => $pool->as($key)->timeout(3)->get($url),
            array_keys($sources),
            $sources,
        ));

        $results = [];
        foreach ($responses as $key => $response) {
            if ($response instanceof Throwable) {
                $results[$key] = ['ok' => false, 'error' => class_basename($response)];
            } elseif ($response->failed()) {
                $results[$key] = ['ok' => false, 'error' => "HTTP {$response->status()}"];
            } else {
                $data = $response->json();
                $results[$key] = ['ok' => true, 'count' => is_array($data) ? count($data) : 1];
            }
        }

        foreach ($results as $key => $r) {
            $icon   = $r['ok'] ? '✓' : '✗';
            $detail = $r['ok'] ? "{$r['count']} item(s)" : "failed: {$r['error']}";
            $this->line("  $icon $key: $detail");
        }

        $this->line('');
        $this->line('  → Always check instanceof Throwable AND ->failed() per response.');
        $this->line('  → Pool always resolves; it never throws for individual request failures.');
        $this->newLine();
    }

    // -----------------------------------------------------------------------

    private function experiment5_concurrencyLimit(): void
    {
        $this->comment('--- Experiment 5: Concurrency limit (second argument) ---');
        $this->line('6 requests, concurrency=2 → at most 2 in-flight at once');
        $this->newLine();

        $start = hrtime(true);
        Http::pool(fn(Pool $pool) => array_map(
            fn($i) => $pool->as("r$i")->get(self::DELAY_URL . '/0'),
            range(1, 6)
        ), concurrency: 2);
        $limitedMs = (hrtime(true) - $start) / 1e6;

        $start = hrtime(true);
        Http::pool(fn(Pool $pool) => array_map(
            fn($i) => $pool->as("r$i")->get(self::DELAY_URL . '/0'),
            range(1, 6)
        ));
        $unlimitedMs = (hrtime(true) - $start) / 1e6;

        $this->table(
            ['concurrency arg', 'Wall time', 'In-flight at once'],
            [
                ['2 (limited)',   sprintf('%4.0fms', $limitedMs),   '2'],
                ['0 (unlimited)', sprintf('%4.0fms', $unlimitedMs), '6'],
            ]
        );
        $this->line('  → Use concurrency limit to respect API rate limits or server capacity.');
        $this->newLine();
    }
}
