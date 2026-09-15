<?php

namespace RTOFLOW\Support;

if (!defined('ABSPATH')) exit;

/**
 * DependentSourceRegistry
 *
 * Named data sources that a `dependent_select` form field can be bound to
 * in the Form Builder v2 schema (see FormEngineService). A dependent field
 * declares `dependent_source = ['source' => <name>, 'parent_field' => <key>]`;
 * at render/validation time the parent's chosen value is looked up as a key
 * into the source's option map to produce that field's option list — e.g.
 * State -> RTO Office, the exact example named in the Form Builder brief.
 *
 * Sources are registered as callables so a new one (RTO -> Sub-office,
 * Service -> Requirement, etc.) can be added later without touching
 * FormEngineService itself: call self::register() once, from Bootstrap or
 * anywhere loaded before first use.
 *
 * TRACE: registered at class load (self::boot() called once from Bootstrap)
 *        -> has()/keys()/optionsFor() read from the static $sources map
 *        -> no mutation, no side effects, no DB or network calls for the
 *           built-in 'state_rto' source (flat PHP array, already shipped
 *           with the plugin for the static apply form).
 */
class DependentSourceRegistry
{
    /** @var array<string, callable():array<string,array<int,string>>> */
    private static array $sources = [];

    private static bool $booted = false;

    /**
     * Register (or override) a named dependent-source resolver. The
     * resolver must return an associative array: parent value => list of
     * child option labels/values available for that parent.
     */
    public static function register(string $name, callable $resolver): void
    {
        self::$sources[$name] = $resolver;
    }

    public static function has(string $name): bool
    {
        self::boot();
        return isset(self::$sources[$name]);
    }

    /** All parent keys available for a source (e.g. every state name). */
    public static function keys(string $name): array
    {
        self::boot();
        if (!isset(self::$sources[$name])) return [];
        $map = (self::$sources[$name])();
        return array_keys($map);
    }

    /** Child options for one parent value (e.g. RTO offices for a state). */
    public static function optionsFor(string $name, string $parentValue): array
    {
        self::boot();
        if (!isset(self::$sources[$name])) return [];
        $map = (self::$sources[$name])();
        return $map[$parentValue] ?? [];
    }

    /** Full parent=>children map for a source, e.g. building a static JS payload. */
    public static function all(string $name): array
    {
        self::boot();
        if (!isset(self::$sources[$name])) return [];
        return (self::$sources[$name])();
    }

    /** Human labels for admin UI dropdowns listing available sources. */
    public static function registeredNames(): array
    {
        self::boot();
        return array_keys(self::$sources);
    }

    /**
     * Register the built-in sources shipped with the plugin. Idempotent —
     * safe to call multiple times (e.g. once per request bootstrap).
     */
    public static function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;

        // state_rto: State -> list of RTO offices in that state. Backed by
        // the same $rtos_by_state array the static apply form already uses
        // (resources/views/public/apply-rto-data.php), so both the legacy
        // static form and any v2 schema-driven form read one source of
        // truth rather than maintaining two copies of 700+ RTO codes.
        self::register('state_rto', function (): array {
            static $data = null;
            if ($data === null) {
                $file = dirname(__DIR__, 2) . '/resources/views/public/apply-rto-data.php';
                if (is_readable($file)) {
                    // The file only defines $rtos_by_state and exits early
                    // if ABSPATH is undefined; ABSPATH is always defined
                    // by the time this runs inside WordPress.
                    include $file;
                    $data = $rtos_by_state ?? [];
                } else {
                    $data = [];
                }
            }
            return $data;
        });
    }
}
