# PHP Fibers + Laravel — Project Context

## Goal
Learn PHP 8.1+ Fibers through Laravel 11's concurrency abstractions. Understand how the
Concurrency facade, Http::pool(), and deferred work are backed by Fibers. No extensions
required — pure PHP 8.3 + Laravel 11.

## Stack
- PHP 8.3
- Laravel 11
- MySQL 8 + Redis 7 (via Docker)
- No Swoole, no ReactPHP, no Amp — vanilla PHP Fibers only

## Relationship to the Swoole project
This is a companion project to the PHP + Swoole + Laravel project. The goal is to
understand what Laravel gives you out of the box with Fibers, and feel the gap that
Swoole fills (persistent connections, coroutine-native DB/Redis clients, a production
event loop, worker management).

## Project structure
```
.
├── CLAUDE.md
├── docker-compose.yml
├── app/
│   ├── Console/Commands/
│   │   └── FiberPlayground.php      # Phase 1 raw Fiber experiments
│   ├── Http/Controllers/
│   │   └── DashboardController.php  # Phase 5 aggregation endpoint
│   └── Services/
│       └── AggregatorService.php    # Concurrency logic
├── benchmarks/                      # timing scripts, results
└── notes/                           # findings per phase
```

## The plan (5 phases)

### Phase 1 — Orientation: Fibers inside Laravel
- Write a raw `Fiber` in an Artisan command — suspend, resume, inspect states
- Understand the stack: Fiber (PHP) → Promise/Future → Concurrency facade (Laravel)
- No I/O yet — just mechanics and mental model

### Phase 2 — The Concurrency facade
- Use `Concurrency::run()` to execute multiple closures concurrently
- Fan out 5 Eloquent queries concurrently, compare timing to sequential
- Use `Concurrency::defer()` for post-response work
- Swap between `process` and `fiber` drivers — observe the behavioral difference

### Phase 3 — HTTP concurrency
- Use `Http::pool()` to fan out concurrent external API calls
- Build an endpoint aggregating 3 external APIs simultaneously
- Handle per-request timeouts and partial failures
- Measure wall time: pool vs sequential

### Phase 4 — Queue + jobs
- Dispatch job batches with `Bus::batch()`, observe internal concurrency
- Write a job that uses `Concurrency::run()` internally
- Use `Concurrency::defer()` inside a controller for non-critical post-response work
- Explore `SyncDriver` vs `FiberDriver` for testing

### Phase 5 — Real feature: data aggregation dashboard
- Dashboard that fetches DB + 2 external APIs concurrently on load
- Each source has independent timeout + fallback — page always renders
- Stale-while-revalidate: serve cached data instantly, refresh post-response
- Benchmark with and without concurrency, document findings

## Key concepts

### The Fiber primitive
```php
$fiber = new Fiber(function(): void {
    $value = Fiber::suspend('first suspend');
    echo "Resumed with: $value\n";
});

$result = $fiber->start();    // runs until suspend — $result = 'first suspend'
$fiber->resume('hello');      // resumes — prints "Resumed with: hello"
```
- `start()` runs the fiber until first `Fiber::suspend()` or completion
- `suspend($value)` pauses execution, passes value to caller
- `resume($value)` continues execution, passes value into the fiber
- `throw($e)` resumes by throwing an exception inside the fiber

### Fiber lifecycle states
```
created → (start) → running → (suspend) → suspended → (resume) → running → terminated
```
- `$fiber->isStarted()`, `->isSuspended()`, `->isRunning()`, `->isTerminated()`

### Concurrency facade drivers

| Driver    | How it works                         | Best for               |
|-----------|--------------------------------------|------------------------|
| `fiber`   | Cooperative, single process          | I/O-bound work         |
| `process` | Forks child processes, true parallel | CPU-bound work         |

Switch driver in config or per-call:
```php
Concurrency::driver('fiber')->run([...]);
Concurrency::driver('process')->run([...]);
```

### Concurrency::run() — parallel tasks
```php
[$users, $posts, $stats] = Concurrency::run([
    fn() => User::count(),
    fn() => Post::latest()->limit(10)->get(),
    fn() => DB::table('events')->selectRaw('count(*) as total')->first(),
]);
```

### Concurrency::defer() — post-response work
```php
// In a controller — work runs after response is sent to client
Concurrency::defer(fn() => Cache::put('last_visit', now()));
```

### Http::pool() — concurrent HTTP
```php
$responses = Http::pool(fn(Pool $pool) => [
    $pool->as('github')->get('https://api.github.com/users/me'),
    $pool->as('weather')->get('https://api.weather.com/current'),
    $pool->as('news')->timeout(2)->get('https://api.news.com/top'),
]);

$github  = $responses['github']->json();
$weather = $responses['weather']->json();
```
Backed by Guzzle promises which integrate with Fibers in Laravel 11.

### Stale-while-revalidate pattern (Phase 5)
```php
public function dashboard(): JsonResponse
{
    $data = Cache::get('dashboard_data') ?? $this->fetchFresh();

    // Refresh cache after response is sent — non-blocking
    Concurrency::defer(function () {
        Cache::put('dashboard_data', $this->fetchFresh(), now()->addMinutes(5));
    });

    return response()->json($data);
}
```

## Important distinctions vs Swoole

| Concern        | Laravel Fibers                | Swoole coroutines              |
|----------------|-------------------------------|--------------------------------|
| DB client      | PDO (blocking)                | Coroutine\MySQL (non-blocking) |
| Redis client   | Predis/PhpRedis (blocking)    | Coroutine\Redis (non-blocking) |
| Event loop     | None (FPM lifecycle)          | Built-in, persistent           |
| Worker model   | PHP-FPM (process per req)     | Persistent workers             |
| Best for       | I/O fan-out, post-response    | High-throughput servers        |

With Laravel Fibers + FPM, blocking I/O (DB, Redis) still blocks the process. Fibers help
with HTTP fan-out via Http::pool() and offloading work post-response, but they do not make
MySQL non-blocking. That is Swoole's territory.

## Commands

```bash
# Artisan command for Phase 1 experiments
php artisan fiber:playground

# Start dev server (use nginx+fpm in Docker for Concurrency::defer() to work)
php artisan serve

# Run queue worker (Phase 4)
php artisan queue:work

# Run a specific benchmark
php benchmarks/compare_sequential_vs_pool.php
```

## Testing Fiber-backed code

Use the `Concurrency` facade's `fake()` in tests:
```php
Concurrency::fake();

// Tasks run sequentially in tests — no real concurrency
Concurrency::run([fn() => doSomething()]);
```

For `Http::pool()`, use `Http::fake()` as normal — pool respects fakes.

## What to watch out for
- `Concurrency::defer()` does NOT work with `php artisan serve` — use nginx + php-fpm
  in Docker or Octane for deferred work to actually execute post-response
- The `fiber` driver does NOT make DB queries non-blocking — PDO still blocks the process
- Exceptions inside `Concurrency::run()` are re-thrown in the caller — wrap individual
  closures if you want partial failure tolerance
- `Http::pool()` partial failures: always check `$response->failed()` per response,
  do not assume all succeeded because the pool resolved
