<?php
/**
 * Lightweight sanity check for default-admin login helpers (no database required).
 */
require_once __DIR__ . '/../business-os-win/server/src/Helpers.php';

// Minimal stubs so Installer constants/methods can be tested in isolation
class BOS_DB {
    public static string $prefix = 'bos_';
    public static function get_setting(string $key, string $default = ''): string { return $default; }
    public static function set_setting(string $key, string $value): void {}
}

require_once __DIR__ . '/../business-os-win/server/src/Installer.php';

$tests = [
    ['admin@businessos.local', 'changeme123', true],
    ['admin@localhost', 'changeme123', true],
    ['admin', 'changeme123', true],
    ['admin@businessos.local', 'wrong', false],
    ['user@example.com', 'changeme123', false],
];

foreach ($tests as [$id, $pass, $expected]) {
    $actual = BOS_Installer::is_default_admin_login($id, $pass);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL is_default_admin_login($id): expected " . json_encode($expected) . ", got " . json_encode($actual) . PHP_EOL);
        exit(1);
    }
}

$hash = password_hash('changeme123', PASSWORD_BCRYPT, ['cost' => 12]);
if (!password_verify('changeme123', $hash)) {
    fwrite(STDERR, "FAIL password_verify on fresh bcrypt hash\n");
    exit(1);
}

echo "OK login logic checks passed\n";
