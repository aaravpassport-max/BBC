<?php
/**
 * NAS Portal Login — standalone page served by Router::serve_login()
 * Provides full HTML shell and includes login-form.php for content.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$cfg   = \NAS\Core\Config::instance();
$brand = $cfg->get( 'brand_name', get_bloginfo('name') );
$color = $cfg->get( 'brand_primary_color', '#6c47ff' );
$step  = sanitize_key( $_GET['step'] ?? 'login' );
$title_map = ['forgot' => 'Reset Password', 'reset' => 'New Password'];
$page_title = ($title_map[$step] ?? 'Sign In') . ' — ' . $brand;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html($page_title); ?></title>
<meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<?php wp_head(); ?>
<style>
html{margin-top:0!important}
body{margin:0;padding:0}
#wpadminbar,.wpadminbar{display:none!important}
</style>
</head>
<body>
<?php include NAS_DIR . 'templates/public/login-form.php'; ?>
<?php wp_footer(); ?>
</body>
</html>
