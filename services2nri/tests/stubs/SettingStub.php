<?php
declare(strict_types=1);

namespace S2NRI\Models;

class Setting {
    /** @var array<string, string> */
    private static array $store = [];

    public static function get(string $k, $default = '') {
        return self::$store[$k] ?? $default;
    }

    public static function set(string $k, string $v, bool $pub = false): void {
        self::$store[$k] = $v;
    }

    public static function resetStore(): void {
        self::$store = [];
    }
}
