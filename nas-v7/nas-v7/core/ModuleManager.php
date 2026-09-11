<?php
namespace NAS\Core;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ModuleManager — boots all modules respecting feature flags.
 * Each module is independent: isolated services, routes, logic.
 */
class ModuleManager {

    private static ?ModuleManager $instance = null;
    private array $loaded = [];

    // TRACE: instance() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function instance(): ModuleManager {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    public function boot( array $module_classes ): void {
        $config = Config::instance();
        foreach ( $module_classes as $class ) {
            if ( ! class_exists( $class ) ) continue;
            $module = new $class();
            if ( ! ( $module instanceof Module ) ) continue;

            $key = $module->key();
            // Check feature flag
            if ( ! $config->feature_enabled( $key ) ) continue;

            $module->register();
            $module->boot();
            $this->loaded[ $key ] = $module;
        }
    }

    // TRACE: get() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: array (empty on no results).
    //        Edge cases: invalid input → error returned.
    public function get( string $key ): ?Module {
        return $this->loaded[ $key ] ?? null;
    }

    // TRACE: loaded() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function loaded(): array {
        return array_keys( $this->loaded );
    }
}

/**
 * Abstract Module base class.
 * Each module must implement key(), register(), boot().
 */
abstract class Module {

    /** Unique slug for this module (used as feature flag key) */
    abstract public function key(): string;

    /** Register services, hooks (called even if booted) */
    abstract public function register(): void;

    /** Boot the module — only called when enabled */
    abstract public function boot(): void;

    // TRACE: config() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure → emits EventBus event.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    protected function config(): Config {
        return Config::instance();
    }

    // TRACE: db() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure → emits EventBus event.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    protected function db(): Database {
        return Database::instance();
    }

    // TRACE: on() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure → emits EventBus event.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    protected function on( string $event, callable $listener, int $priority = 10 ): void {
        EventBus::on( $event, $listener, $priority );
    }

    // TRACE: emit() — Called internally or via AJAX action.
    //        Steps: emits EventBus event.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    protected function emit( string $event, array $payload = [] ): void {
        EventBus::emit( $event, $payload );
    }
}
