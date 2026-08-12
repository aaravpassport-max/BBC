<?php
/**
 * Standalone app router — serves React SPA at /portmystuff/*
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Router {

	const QUERY_VAR  = 'portmystuff_app';
	const SLUG       = 'portmystuff';
	const PAGE_TITLE = 'PORTMYSTUFF App';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'init', array( $this, 'register_rewrites' ), 5 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'parse_request' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_app' ), -999 );
		add_filter( 'template_include', array( $this, 'template_include' ), 99 );
		add_action( 'admin_init', array( $this, 'maybe_flush_rewrites' ) );
	}

	public static function register_rewrites() {
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([^&]+)' );

		add_rewrite_rule(
			'^' . self::SLUG . '/?$',
			'index.php?' . self::QUERY_VAR . '=launcher',
			'top'
		);
		add_rewrite_rule(
			'^' . self::SLUG . '/(.+?)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = 'pms_app';
		return $vars;
	}

	public function parse_request( $wp ) {
		$app = $this->resolve_app_route();
		if ( ! $app ) {
			return;
		}

		$wp->query_vars[ self::QUERY_VAR ] = $app;
		unset( $wp->query_vars['error'] );
		$wp->query_vars['pagename'] = '';
		$wp->query_vars['page']     = '';
		$wp->query_vars['name']     = '';
	}

	public function maybe_flush_rewrites() {
		$stored = get_option( 'portmystuff_version', '' );
		if ( $stored !== PORTMYSTUFF_VERSION ) {
			self::register_rewrites();
			flush_rewrite_rules( false );
			update_option( 'portmystuff_version', PORTMYSTUFF_VERSION );
		}
	}

	public static function app_url( $path = '' ) {
		$base = home_url( '/' . self::SLUG . '/' );
		return $path ? trailingslashit( $base ) . ltrim( $path, '/' ) : trailingslashit( $base );
	}

	public function maybe_render_app() {
		if ( ! $this->resolve_app_route() ) {
			return;
		}

		$this->render_standalone_shell();
		exit;
	}

	public function template_include( $template ) {
		if ( $this->resolve_app_route() ) {
			return PORTMYSTUFF_PLUGIN_DIR . 'templates/standalone.php';
		}
		return $template;
	}

	/**
	 * Resolve app route from query var, URI path, or fallback query param.
	 */
	public function resolve_app_route() {
		$app = get_query_var( self::QUERY_VAR );
		if ( is_string( $app ) && $app !== '' ) {
			return sanitize_text_field( $app );
		}

		if ( ! empty( $_GET['pms_app'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['pms_app'] ) );
		}

		$page_id = (int) get_option( 'portmystuff_page_id' );
		if ( $page_id && function_exists( 'is_page' ) && is_page( $page_id ) ) {
			return 'launcher';
		}

		$path = $this->get_request_path();
		if ( $path === self::SLUG ) {
			return 'launcher';
		}

		$prefix = self::SLUG . '/';
		if ( strpos( $path, $prefix ) === 0 ) {
			$rest = trim( substr( $path, strlen( $prefix ) ), '/' );
			return $rest !== '' ? sanitize_text_field( $rest ) : 'launcher';
		}

		return null;
	}

	/**
	 * Path relative to site home, without leading/trailing slashes.
	 */
	private function get_request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = trim( $path, '/' );

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = trim( $home_path, '/' );

		if ( $home_path !== '' && strpos( $path, $home_path ) === 0 ) {
			$path = trim( substr( $path, strlen( $home_path ) ), '/' );
		}

		// Plain permalinks: /index.php/portmystuff/customer
		if ( strpos( $path, 'index.php/' ) === 0 ) {
			$path = trim( substr( $path, strlen( 'index.php/' ) ), '/' );
		}

		return $path;
	}

	public function render_standalone_shell() {
		$dist      = PORTMYSTUFF_PLUGIN_DIR . 'assets/dist/';
		$index     = $dist . 'index.html';
		$asset_js  = '';
		$asset_css = '';

		if ( file_exists( $index ) ) {
			$html = file_get_contents( $index );
			if ( preg_match( '/src="(\.\/assets\/[^"]+\.js)"/', $html, $m ) ) {
				$asset_js = $this->asset_url_from_ref( $m[1] );
			}
			if ( preg_match( '/href="(\.\/assets\/[^"]+\.css)"/', $html, $m ) ) {
				$asset_css = $this->asset_url_from_ref( $m[1] );
			}
		}

		$config = array(
			'appBase'    => esc_url_raw( self::app_url() ),
			'appPath'    => $this->get_app_base_path(),
			'apiBase'    => esc_url_raw( rest_url( 'portmystuff/v1' ) ),
			'adminBase'  => esc_url_raw( rest_url( 'portmystuff/admin/v1' ) ),
			'opsBase'    => esc_url_raw( rest_url( 'portmystuff/ops/v1' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'siteUrl'    => esc_url_raw( home_url( '/' ) ),
			'wpLoginUrl' => esc_url_raw( wp_login_url( self::app_url() ) ),
			'isWpUser'   => is_user_logged_in(),
			'canAdmin'   => Portmystuff_Roles::user_can( 'pms_analytics_view' ),
			'canOps'     => Portmystuff_Roles::user_can( 'pms_ops_sos_respond' ),
		);

		status_header( 200 );
		nocache_headers();

		include PORTMYSTUFF_PLUGIN_DIR . 'templates/standalone-shell.php';
	}

	private function get_app_base_path() {
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = rtrim( $home_path, '/' );
		return $home_path . '/' . self::SLUG;
	}

	private function asset_url_from_ref( $ref ) {
		$ref = ltrim( $ref, './' );
		return PORTMYSTUFF_PLUGIN_URL . 'assets/dist/' . $ref;
	}

	/**
	 * Ensure a WP page exists as permalink fallback (plain permalinks).
	 */
	public static function ensure_app_page() {
		$existing = get_page_by_path( self::SLUG );
		if ( $existing ) {
			update_option( 'portmystuff_page_id', $existing->ID );
			return $existing->ID;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => self::PAGE_TITLE,
				'post_name'    => self::SLUG,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '<!-- wp:shortcode -->[portmystuff_app]<!-- /wp:shortcode -->',
			),
			true
		);

		if ( ! is_wp_error( $page_id ) ) {
			update_option( 'portmystuff_page_id', $page_id );
		}

		return is_wp_error( $page_id ) ? 0 : (int) $page_id;
	}
}
