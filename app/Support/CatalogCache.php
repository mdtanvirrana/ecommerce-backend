<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

class CatalogCache
{
    private const string VERSION_KEY = 'catalog:cache_version';

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    /**
     * Cache public catalog read payloads under a versioned key so any admin mutation
     * or inventory change invalidates all cached views immediately.
     *
     * @template T
     *
     * @param  Closure(): T  $resolver
     * @return T
     */
    public static function remember(string $key, int $ttlSeconds, Closure $resolver): mixed
    {
        $versionedKey = sprintf('catalog:v%d:%s', self::version(), $key);

        return Cache::remember($versionedKey, now()->addSeconds($ttlSeconds), $resolver);
    }

    public static function invalidate(): void
    {
        $next = self::version() + 1;
        Cache::forever(self::VERSION_KEY, $next);
    }
}
