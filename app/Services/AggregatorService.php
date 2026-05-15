<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Three aggregation strategies for the dashboard, each combining
 * the same four data sources (DB stats + 3 external APIs):
 *
 *  sequential()  — one source at a time (baseline / worst case)
 *  pooled()      — DB sequential, external APIs via Http::pool()
 *  concurrent()  — DB + API fan-out via Concurrency::run() (process driver)
 *                  True parallelism: DB runs while HTTP pool runs.
 */
class AggregatorService
{
    private const API_BASE  = 'https://jsonplaceholder.typicode.com';
    private const HTTP_TIMEOUT = 5;

    // -----------------------------------------------------------------------
    // Public strategies
    // -----------------------------------------------------------------------

    /** All four sources fetched one after another. */
    public function sequential(): array
    {
        $db    = $this->dbStats();
        $user  = $this->fetchUser();
        $posts = $this->fetchPosts();
        $todos = $this->fetchTodos();

        return $this->shape($db, $user, $posts, $todos, 'sequential');
    }

    /**
     * DB sequential + external APIs via Http::pool().
     * APIs fire simultaneously; DB still blocks (PDO has no async path).
     */
    public function pooled(): array
    {
        $db = $this->dbStats();

        $responses = Http::pool(fn(Pool $pool) => [
            $pool->as('user') ->timeout(self::HTTP_TIMEOUT)->get(self::API_BASE . '/users/1'),
            $pool->as('posts')->timeout(self::HTTP_TIMEOUT)->get(self::API_BASE . '/posts?userId=1&_limit=5'),
            $pool->as('todos')->timeout(self::HTTP_TIMEOUT)->get(self::API_BASE . '/todos?userId=1&completed=false&_limit=5'),
        ]);

        return $this->shape(
            $db,
            $this->extractHttp($responses['user'],  null),
            $this->extractHttp($responses['posts'], []),
            $this->extractHttp($responses['todos'], []),
            'pooled',
        );
    }

    /**
     * DB + API fan-out fully parallel via Concurrency::run() (process driver).
     *
     * Two child processes start simultaneously:
     *   process A → runs dbStats() (PDO, blocking within that process)
     *   process B → runs Http::pool() for all three APIs (curl_multi concurrent)
     *
     * Wall time ≈ max(db_time, max(api_a, api_b, api_c))
     */
    public function concurrent(): array
    {
        [$db, $apis] = Concurrency::driver('process')->run([
            fn() => $this->dbStats(),
            fn() => $this->pooledApis(),
        ]);

        return $this->shape(
            $db,
            $apis['user'],
            $apis['posts'],
            $apis['todos'],
            'concurrent',
        );
    }

    // -----------------------------------------------------------------------
    // Internal fetchers
    // -----------------------------------------------------------------------

    /** @return array<string, int> */
    private function dbStats(): array
    {
        return [
            'users'      => DB::table('users')->count(),
            'jobs'       => DB::table('jobs')->count(),
            'cache_rows' => DB::table('cache')->count(),
            'migrations' => DB::table('migrations')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function pooledApis(): array
    {
        $responses = Http::pool(fn(Pool $pool) => [
            $pool->as('user') ->timeout(self::HTTP_TIMEOUT)->get(self::API_BASE . '/users/1'),
            $pool->as('posts')->timeout(self::HTTP_TIMEOUT)->get(self::API_BASE . '/posts?userId=1&_limit=5'),
            $pool->as('todos')->timeout(self::HTTP_TIMEOUT)->get(self::API_BASE . '/todos?userId=1&completed=false&_limit=5'),
        ]);

        return [
            'user'  => $this->extractHttp($responses['user'],  null),
            'posts' => $this->extractHttp($responses['posts'], []),
            'todos' => $this->extractHttp($responses['todos'], []),
        ];
    }

    private function fetchUser(): mixed
    {
        try {
            $r = Http::timeout(self::HTTP_TIMEOUT)->get(self::API_BASE . '/users/1');
            return $r->ok() ? $r->json() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<mixed> */
    private function fetchPosts(): array
    {
        try {
            $r = Http::timeout(self::HTTP_TIMEOUT)->get(self::API_BASE . '/posts?userId=1&_limit=5');
            return $r->ok() ? $r->json() : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<mixed> */
    private function fetchTodos(): array
    {
        try {
            $r = Http::timeout(self::HTTP_TIMEOUT)->get(self::API_BASE . '/todos?userId=1&completed=false&_limit=5');
            return $r->ok() ? $r->json() : [];
        } catch (Throwable) {
            return [];
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string, int>  $db
     * @param array<mixed>|null   $user
     * @param array<mixed>        $posts
     * @param array<mixed>        $todos
     * @return array<string, mixed>
     */
    private function shape(array $db, mixed $user, array $posts, array $todos, string $strategy): array
    {
        return [
            'db'       => $db,
            'user'     => $user,
            'posts'    => $posts,
            'todos'    => $todos,
            'strategy' => $strategy,
        ];
    }

    private function extractHttp(mixed $response, mixed $default): mixed
    {
        if ($response instanceof Throwable || $response->failed()) {
            return $default;
        }
        return $response->json();
    }
}
