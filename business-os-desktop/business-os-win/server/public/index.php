<?php
/**
 * Business OS Desktop — PHP Entry Point
 * Replaces WordPress entirely. Handles:
 *   /bos/v1/*       → API routes (Slim 4)
 *   /business/*     → React SPA (inject BOS_CONFIG, serve index.html)
 *   /app/assets/*   → Static JS/CSS assets
 */

define('BOS_ROOT',       dirname(__DIR__));
define('BOS_APP_DIR',    BOS_ROOT . '/../app');
define('BOS_DATA_DIR',   getenv('BOS_DATA_DIR') ?: (PHP_OS_FAMILY === 'Windows'
    ? getenv('APPDATA') . '/BusinessOS'
    : getenv('HOME') . '/.businessos'));
define('BOS_PORT',       getenv('BOS_PORT') ?: '9753');
define('BOS_API_BASE',   'http://127.0.0.1:' . BOS_PORT . '/bos/v1');

// ── Ensure data directories exist ────────────────────────────────────────────
foreach ([BOS_DATA_DIR, BOS_DATA_DIR . '/logs', BOS_DATA_DIR . '/uploads'] as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

// ── Error logging to file, never to screen ───────────────────────────────────
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', BOS_DATA_DIR . '/logs/php_error.log');

// ── Autoload composer dependencies ───────────────────────────────────────────
require_once BOS_ROOT . '/vendor/autoload.php';

// ── Load core classes ─────────────────────────────────────────────────────────
require_once BOS_ROOT . '/src/Helpers.php';
require_once BOS_ROOT . '/src/Database.php';
require_once BOS_ROOT . '/src/Auth.php';
require_once BOS_ROOT . '/src/Installer.php';
require_once BOS_ROOT . '/src/Email.php';

// ── Run DB install/upgrade on every boot (cheap after first install) ──────────
BOS_Installer::run();

// ── CORS headers for all requests ────────────────────────────────────────────
header('Access-Control-Allow-Origin: http://127.0.0.1:' . BOS_PORT);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-BOS-Token, X-BOS-Version');
header('Access-Control-Allow-Credentials: true');

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = strtok($uri, '?');

// ── OPTIONS preflight ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Static assets: /app/assets/* ─────────────────────────────────────────────
if (preg_match('#^/app/assets/(.+)$#', $path, $m)) {
    $file = BOS_APP_DIR . '/assets/' . basename($m[1]);
    if (file_exists($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mime = [
            'js'  => 'application/javascript',
            'css' => 'text/css',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'ico' => 'image/x-icon',
        ][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=31536000');
        readfile($file);
    } else {
        http_response_code(404);
        echo 'Asset not found';
    }
    exit;
}

// ── favicon ───────────────────────────────────────────────────────────────────
if ($path === '/favicon.svg' || $path === '/favicon.ico') {
    $fav = BOS_APP_DIR . '/favicon.svg';
    if (file_exists($fav)) { header('Content-Type: image/svg+xml'); readfile($fav); }
    exit;
}

// ── API routes: /bos/v1/* ─────────────────────────────────────────────────────
if (preg_match('#^/bos/v1(/.*)?$#', $path)) {
    require_once BOS_ROOT . '/src/api/router.php';
    exit;
}

// ── SPA: /business/* and / → serve index.html with injected config ────────────
$spa_html_file = BOS_APP_DIR . '/index.html';
if (!file_exists($spa_html_file)) {
    http_response_code(500);
    echo '<h1>Business OS</h1><p>App assets not found. Reinstall the application.</p>';
    exit;
}

$html = file_get_contents($spa_html_file);

// Rewrite asset paths to absolute URLs (index.html uses relative ./assets/...)
// In the Electron context, all paths must go through our PHP server
$html = str_replace('src="./assets/', 'src="/app/assets/', $html);
$html = str_replace('href="./assets/', 'href="/app/assets/', $html);

// Inject BOS_CONFIG before </head>
$config_script = '<script>window.BOS_CONFIG={"apiBase":"http://127.0.0.1:' . BOS_PORT . '/bos/v1"};</script>';
$html = str_replace('</head>', $config_script . '</head>', $html);

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo $html;
exit;
