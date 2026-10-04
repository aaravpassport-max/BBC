<?php
namespace S2NRI\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Request — wraps HTTP input (body JSON, query, URL params, files).
 */
class Request {

    private array  $urlParams;
    private ?array $body  = null;
    private array  $query;

    public function __construct( array $urlParams = [] ) {
        $this->urlParams = $urlParams;
        $this->query     = $_GET;
    }

    public function body(): array {
        if ( $this->body === null ) {
            $raw = file_get_contents( 'php://input' );
            $this->body = $raw ? ( json_decode( $raw, true ) ?? [] ) : [];
            $this->body = array_merge( $this->body, $_POST );
        }
        return $this->body;
    }

    public function input( string $key, mixed $default = null ): mixed {
        return $this->body()[$key] ?? $this->query[$key] ?? $this->urlParams[$key] ?? $default;
    }

    public function query( string $key, mixed $default = null ): mixed {
        return $this->query[$key] ?? $default;
    }

    public function param( string $key, mixed $default = null ): mixed {
        return $this->urlParams[$key] ?? $default;
    }

    public function file( string $key ): ?array {
        return $_FILES[$key] ?? null;
    }

    public function all(): array {
        return array_merge( $this->query, $this->body(), $this->urlParams );
    }

    public function has( string $key ): bool {
        $all = $this->all();
        return isset( $all[$key] ) && $all[$key] !== '';
    }

    public function method(): string {
        return strtoupper( $_SERVER['REQUEST_METHOD'] );
    }

    public function ip(): string {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

/**
 * Response — static JSON output helper.
 */
class Response {

    public static function json( mixed $data, int $status = 200 ): void {
        status_header( $status );
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'X-S2NRI-Response: 1' ); // marker so we can confirm our code ran
        echo wp_json_encode( $data );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// S2NRI_SESSION — PHP session wrapper
// TRACE: Wraps $_SESSION for typed S2NRI data. Used by Auth middleware and
//        autoRegister to persist user across API calls within the same session.
// ══════════════════════════════════════════════════════════════════════════════

class S2NRI_SESSION {

    public static function start(): void {
        if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
            session_start();
        }
    }

    // TRACE: set(key, value) → starts session if not started → writes to $_SESSION['s2nri'][key]
    public static function set( string $key, mixed $value ): void {
        self::start();
        $_SESSION['s2nri'][ $key ] = $value;
    }

    // TRACE: get(key, default) → returns $_SESSION['s2nri'][key] or default
    public static function get( string $key, mixed $default = null ): mixed {
        self::start();
        return $_SESSION['s2nri'][ $key ] ?? $default;
    }

    // TRACE: destroy() → clears S2NRI session data without touching the WP session
    public static function destroy(): void {
        if ( session_status() === PHP_SESSION_ACTIVE ) {
            unset( $_SESSION['s2nri'] );
        }
    }
}

