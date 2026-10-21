<?php
/**
 * Sync WordPress DB host + site URL to match the desktop app's live ports.
 */
if (php_sapi_name() !== 'cli') {
    exit(1);
}

$wpRoot = getenv('LCRM_WP_ROOT') ?: dirname(__DIR__) . '/wordpress';
$siteUrl = rtrim(getenv('LCRM_SITE_URL') ?: 'http://127.0.0.1:9780', '/');
$dbHost = getenv('LCRM_DB_HOST') ?: '127.0.0.1:3308';
$dbPass = getenv('LCRM_DB_PASSWORD') ?: '';

$_SERVER['HTTP_HOST'] = parse_url($siteUrl, PHP_URL_HOST) . (
    ($p = parse_url($siteUrl, PHP_URL_PORT)) ? ":{$p}" : ''
);
$_SERVER['REQUEST_URI'] = '/';

define('WP_USE_THEMES', false);
require $wpRoot . '/wp-load.php';

$configFile = $wpRoot . '/wp-config.php';
if (is_readable($configFile)) {
    $cfg = file_get_contents($configFile);
    $escapedHost = str_replace("'", "\\'", $dbHost);
    $cfg = preg_replace(
        "/define\\(\\s*'DB_HOST'\\s*,\\s*'[^']*'\\s*\\)/",
        "define('DB_HOST', '{$escapedHost}')",
        $cfg,
        1,
        $count
    );
    $passCount = 0;
    if ($dbPass !== '') {
        $escapedPass = str_replace("'", "\\'", $dbPass);
        $cfg = preg_replace(
            "/define\\(\\s*'DB_PASSWORD'\\s*,\\s*'[^']*'\\s*\\)/",
            "define('DB_PASSWORD', '{$escapedPass}')",
            $cfg,
            1,
            $passCount
        );
    }
    if ($count || $passCount) {
        file_put_contents($configFile, $cfg);
    }
}

update_option('siteurl', $siteUrl);
update_option('home', $siteUrl);
echo "Synced site URL and DB host.\n";
