<?php

namespace App\Http\Controllers;

use App\Services\AggregatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;

class DashboardController extends Controller
{
    private const CACHE_KEY = 'dashboard_data';
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(private AggregatorService $aggregator) {}

    /**
     * Stale-while-revalidate:
     *   1. Serve cached data immediately (or fetch fresh on first hit).
     *   2. Defer a cache refresh to run AFTER the response is sent (non-blocking).
     */
    public function index(): JsonResponse
    {
        $start  = hrtime(true);
        $cached = Cache::get(self::CACHE_KEY);
        $fresh  = $cached === null;

        $data = $cached ?? $this->aggregator->aggregate();

        if ($fresh) {
            Cache::put(self::CACHE_KEY, $data, self::CACHE_TTL);
        }

        // Post-response: refresh cache in a background process without blocking the client.
        // Requires nginx + php-fpm or Octane — does nothing useful with `php artisan serve`.
        Concurrency::defer(function () {
            Cache::put(self::CACHE_KEY, $this->aggregator->aggregate(), self::CACHE_TTL);
        });

        $ms = round((hrtime(true) - $start) / 1e6);

        return response()->json([
            ...$data,
            'meta' => [
                ...$data['meta'],
                'wall_ms' => $ms,
                'cache'   => $fresh ? 'miss' : 'hit',
            ],
        ]);
    }
}
