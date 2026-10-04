<?php
declare(strict_types=1);

namespace S2NRI\Services;

class CacheService {
    public static function remember(string $key, int $ttl, callable $cb) { return $cb(); }
    public static function delete(string $key): void {}
    public static function bustPattern(string $p): void {}
}
