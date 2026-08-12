<?php
/**
 * Standalone app router — serves React SPA at /portmystuff/*
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Router {

	const QUERY_VAR = 'portmystuff_app';
	const SLUG      = 'portmystuff';

	public function __construct() {
		add_action( 'init', array( $this, 'register_rewrites' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_app' ), 0 );
	}

	public static function register_rewrites() {
		add_rewrite_rule(
			self::SLUG . '/?$',
			'index.php?' . self::QUERY_VAR . '=launcher',
			'top'
		);
		add_rewrite_rule(
			self::SLUG . '/(.+?)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function app_url( $path = '' ) {
		$base = home_url( '/' . self::SLUG . '/' );
		return $path ? trailingslashit( $base ) . ltrim( $path, '/' ) : trailingslashit( $base );
	}

	public function maybe_render_app() {
		$app = get_query_var( self::QUERY_VAR );
		if ( ! $app ) {
			return;
		}

		$this->render_standalone_shell();
		exit;
	}

	private function render_standalone_shell() {
		$dist     = PORTMYSTUFF_PLUGIN_DIR . 'assets/dist/';
		$index    = $dist . 'index.html';
		$asset_js = '';
		$asset_css = '';

		if ( file_exists( $index ) ) {
			$html = file_get_contents( $index );
			if ( preg_match( '/src="([^"]+index[^"]+\.js)"/', $html, $m ) ) {
				$asset_js = $this->asset_url_from_ref( $m[1] );
			}
			if ( preg_match( '/href="([^"]+index[^"]+\.css)"/', $html, $m ) ) {
				$asset_css = $this->asset_url_from_ref( $m[1] );
			}
		}

		$config = array(
			'appBase'     => esc_url_raw( self::app_url() ),
			'appPath'     => '/' . self::SLUG,
			'apiBase'     => esc_url_raw( rest_url( 'portmystuff/v1' ) ),
			'adminBase'   => esc_url_raw( rest_url( 'portmystuff/admin/v1' ) ),
			'opsBase'     => esc_url_raw( rest_url( 'portmystuff/ops/v1' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'siteUrl'     => esc_url_raw( home_url( '/' ) ),
			'wpLoginUrl'  => esc_url_raw( wp_login_url( self::app_url() ) ),
			'isWpUser'    => is_user_logged_in(),
			'canAdmin'    => Portmystuff_Roles::user_can( 'pms_analytics_view' ),
			'canOps'      => Portmystuff_Roles::user_can( 'pms_ops_sos_respond' ),
		);

		status_header( 200 );
		nocache_headers();
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="theme-color" content="#0d9f4f">
	<title>PORTMYSTUFF</title>
	<?php if ( $asset_css ) : ?>
	<link rel="stylesheet" href="<?php echo esc_url( $asset_css ); ?>">
	<?php endif; ?>
	<script>window.PORTMYSTUFF_CONFIG=<?php echo wp_json_encode( $config ); ?>;</script>
</head>
<body class="portmystuff-standalone">
	<div id="root"></div>
	<?php if ( $asset_js ) : ?>
	<script type="module" src="<?php echo esc_url( $asset_js ); ?>"></script>
	<?php else : ?>
	<div style="font-family:system-ui;padding:40px;text-align:center">
		<h1>PORTMYSTUFF</h1>
		<p>React build missing. Run <code>npm run build</code> in <code>web/</code>.</p>
	</div>
	<?php endif; ?>
</body>
</html>
		<?php
	}

	private function asset_url_from_ref( $ref ) {
		$ref = ltrim( $ref, './' );
		return PORTMYSTUFF_PLUGIN_URL . 'assets/dist/' . $ref;
	}
}
