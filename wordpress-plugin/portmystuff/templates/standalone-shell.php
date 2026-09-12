<?php
/**
 * Standalone app HTML shell — no theme header/footer.
 *
 * @var array  $config
 * @var string $asset_js
 * @var string $asset_css
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="theme-color" content="#0d9f4f">
	<meta name="robots" content="noindex, nofollow">
	<title>PORTMYSTUFF</title>
	<style>
		html, body { margin: 0; padding: 0; min-height: 100%; background: #f8fafc; }
		#root { min-height: 100vh; }
	</style>
	<?php if ( ! empty( $asset_css ) ) : ?>
	<link rel="stylesheet" href="<?php echo esc_url( $asset_css ); ?>">
	<?php endif; ?>
	<script>window.PORTMYSTUFF_CONFIG=<?php echo wp_json_encode( $config ); ?>;</script>
</head>
<body class="portmystuff-standalone">
	<div id="root"></div>
	<?php if ( ! empty( $asset_js ) ) : ?>
	<script type="module" src="<?php echo esc_url( $asset_js ); ?>"></script>
	<?php else : ?>
	<div style="font-family:system-ui,sans-serif;padding:40px;text-align:center">
		<h1>PORTMYSTUFF</h1>
		<p>React build missing. Re-upload the plugin zip or run <code>npm run build</code> in <code>web/</code>.</p>
	</div>
	<?php endif; ?>
</body>
</html>
