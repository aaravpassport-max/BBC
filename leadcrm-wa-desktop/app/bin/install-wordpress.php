<?php
/**
 * One-time WordPress + LeadCRM WA plugin setup for the desktop bundle.
 */
if (php_sapi_name() !== 'cli') {
    exit(1);
}

$wpRoot = getenv('LCRM_WP_ROOT') ?: dirname(__DIR__) . '/wordpress';
$siteUrl = rtrim(getenv('LCRM_SITE_URL') ?: 'http://127.0.0.1:9780', '/');
$adminUser = getenv('LCRM_ADMIN_USER') ?: 'admin';
$adminEmail = getenv('LCRM_ADMIN_EMAIL') ?: 'admin@leadcrm.local';
$adminPass = getenv('LCRM_ADMIN_PASS') ?: '';
$host = parse_url($siteUrl, PHP_URL_HOST) ?: '127.0.0.1';
$port = parse_url($siteUrl, PHP_URL_PORT);

$_SERVER['HTTP_HOST'] = $port ? "{$host}:{$port}" : $host;
$_SERVER['SERVER_NAME'] = $host;
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_USER_AGENT'] = 'LeadCRM-Desktop-Installer';

if ($adminPass === '') {
    fwrite(STDERR, "LCRM_ADMIN_PASS required\n");
    exit(1);
}

define('WP_USE_THEMES', false);
define('WP_INSTALLING', true);

require_once $wpRoot . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if (!get_option('blogname')) {
    wp_install('LeadCRM', $adminUser, $adminEmail, true, '', $adminPass);
    echo "WordPress installed.\n";
} else {
    echo "WordPress already installed.\n";
}

$plugin = 'leadcrm-wa-build/leadcrm.php';
if (!is_plugin_active($plugin)) {
    activate_plugin($plugin);
    echo "LeadCRM plugin activated.\n";
}

if (function_exists('lcrm_activate')) {
    lcrm_activate();
    echo "LeadCRM tables seeded.\n";
}

update_option('siteurl', $siteUrl);
update_option('home', $siteUrl);

if (function_exists('lcrm_spa_setup_page')) {
    lcrm_spa_setup_page();
}

update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules(true);

$loginFile = getenv('LCRM_LOGIN_FILE');
if ($loginFile) {
    $text = "LeadCRM Desktop (leadcrm-wa-build v5.1.4)\n"
        . "CRM: {$siteUrl}/crm/\n"
        . "WordPress admin: {$siteUrl}/wp-admin/\n"
        . "Username: {$adminUser}\n"
        . "Password: {$adminPass}\n";
    file_put_contents($loginFile, $text);
    @chmod($loginFile, 0600);
}

echo "Done.\n";
