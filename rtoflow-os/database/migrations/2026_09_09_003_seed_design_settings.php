<?php
/**
 * Migration: seed defaults for the new Design & Typography Settings system.
 *
 * USER REQUEST: "Create a centralized, fully functional Design & Typography
 * Settings system... without modifying code." See app/Services/
 * DesignSettingsService.php for the full design (storage split, CSS
 * generation, why each control targets the selector it targets).
 *
 * This migration only add_option()s — it never overwrites an option that
 * already exists, so re-running it (or running it after an admin has
 * already saved real values) is always a safe no-op. The three pre-
 * existing color options (rtoflow_color_primary/secondary/accent) are
 * deliberately NOT touched here — they already have their own defaults
 * from earlier in this codebase and are only ever read, not seeded, by
 * DesignSettingsService.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;
use RTOFLOW\Services\DesignSettingsService;

if (!defined('ABSPATH')) exit;

class SeedDesignSettings extends Migration
{
    public function up(): void
    {
        $defaultColors = DesignSettingsService::defaultColors();
        foreach (DesignSettingsService::COLOR_KEYS as $key => $optionName) {
            // Skip the 3 pre-existing keys (primary/secondary/accent) —
            // never seed over an option another part of this plugin
            // already owns and defaults.
            if (in_array($key, ['primary', 'secondary', 'accent', 'bg_dark'], true)) continue;
            if (get_option($optionName, null) === null) {
                add_option($optionName, $defaultColors[$key], '', false);
            }
        }

        if (get_option(DesignSettingsService::OPTION_DESIGN, null) === null) {
            add_option(
                DesignSettingsService::OPTION_DESIGN,
                wp_json_encode(DesignSettingsService::defaultSettings(), JSON_UNESCAPED_SLASHES),
                '',
                false
            );
        }

        if (get_option('rtoflow_design_updated_at', null) === null) {
            add_option('rtoflow_design_updated_at', (string) time(), '', false);
        }
    }

    public function down(): void
    {
        foreach (DesignSettingsService::COLOR_KEYS as $key => $optionName) {
            if (in_array($key, ['primary', 'secondary', 'accent', 'bg_dark'], true)) continue;
            delete_option($optionName);
        }
        delete_option(DesignSettingsService::OPTION_DESIGN);
        delete_option('rtoflow_design_updated_at');
    }
}
