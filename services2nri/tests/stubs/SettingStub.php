<?php
declare(strict_types=1);

namespace S2NRI\Models;

class Setting {
    public static function get(string $k, $default = '') { return $default; }
    public static function set(string $k, string $v, bool $pub = false): void {}
}
