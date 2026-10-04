<?php
declare(strict_types=1);

function sanitize_key($key) {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
}

function sanitize_title($title) {
    return sanitize_key(str_replace(' ', '-', (string) $title));
}

function esc_attr($t) { return (string) $t; }
function esc_url($u) { return (string) $u; }
function sanitize_hex_color($color) {
    $color = trim((string) $color);
    if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color)) {
        return $color;
    }
    return '';
}
function wp_json_encode($d) { return json_encode($d); }
function current_time($type) { return date('Y-m-d H:i:s'); }
