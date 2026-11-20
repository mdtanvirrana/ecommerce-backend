<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

class CatalogCache
{
    private const string VERSION_KEY = 'catalog:cache_version_v2';

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    /**
     * Cache public catalog read payloads as pure JSON strings under a versioned key.
     * Storing JSON (instead of raw PHP-serialized JsonResource trees) avoids
     * __PHP_Incomplete_Class corruption when FileStore unserializes with allowed_classes=false,
     * and any admin mutation or inventory update invalidates all cached entries immediately.
     *
     * @param  Closure(): mixed  $resolver
     */
    public static function remember(string $key, int $ttlSeconds, Closure $resolver): mixed
    {
        $versionedKey = sprintf('catalog:json:v%d:%s', self::version(), $key);

        $encoded = Cache::remember($versionedKey, now()->addSeconds($ttlSeconds), function () use ($resolver): string {
            return json_encode($resolver(), JSON_THROW_ON_ERROR);
        });

        return json_decode((string) $encoded, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function invalidate(): void
    {
        $next = self::version() + 1;
        Cache::forever(self::VERSION_KEY, $next);
    }
}
