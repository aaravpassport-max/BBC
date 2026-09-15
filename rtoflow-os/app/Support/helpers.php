<?php

if (!defined('ABSPATH')) exit;

// ── Shorthand accessors ────────────────────────────────────────────────────

if (!function_exists('rto_env')) {
    function rto_env(string $key, mixed $default = null): mixed {
        return \RTOFLOW\Config\Env::get($key, $default);
    }
}

if (!function_exists('rto_encrypt')) {
    function rto_encrypt(string $val): string {
        return \RTOFLOW\Security\Encryption::encrypt($val);
    }
}

if (!function_exists('rto_decrypt')) {
    function rto_decrypt(string $val): string {
        return \RTOFLOW\Security\Encryption::decryptSafe($val);
    }
}

if (!function_exists('rto_san')) {
    function rto_san(mixed $val, string $type = 'text'): mixed {
        return match($type) {
            'email'  => \RTOFLOW\Security\Sanitiser::email($val),
            'int'    => \RTOFLOW\Security\Sanitiser::int($val),
            'amount' => \RTOFLOW\Security\Sanitiser::amount($val),
            'mobile' => \RTOFLOW\Security\Sanitiser::mobile($val),
            'bool'   => \RTOFLOW\Security\Sanitiser::bool($val),
            'slug'   => \RTOFLOW\Security\Sanitiser::slug($val),
            default  => \RTOFLOW\Security\Sanitiser::text($val),
        };
    }
}

if (!function_exists('rto_user_role')) {
    /**
     * Get the RTOFLOW role of the current (or given) user.
     * Returns: 'admin' | 'staff' | 'vendor' | 'client' | null
     */
    function rto_user_role(?int $userId = null): ?string {
        $userId ??= get_current_user_id();
        if (!$userId) return null;

        $user = get_userdata($userId);
        if (!$user) return null;

        $roles = (array)$user->roles;
        foreach (['administrator', 'rto_admin', 'rto_staff', 'rto_vendor', 'rto_client'] as $role) {
            if (in_array($role, $roles, true)) {
                return match($role) {
                    'administrator', 'rto_admin' => 'admin',
                    'rto_staff'  => 'staff',
                    'rto_vendor' => 'vendor',
                    'rto_client' => 'client',
                    default      => null,
                };
            }
        }
        return null;
    }
}

if (!function_exists('rto_is_admin')) {
    function rto_is_admin(?int $uid = null): bool { return rto_user_role($uid) === 'admin'; }
}
if (!function_exists('rto_is_vendor')) {
    function rto_is_vendor(?int $uid = null): bool { return rto_user_role($uid) === 'vendor'; }
}
if (!function_exists('rto_is_client')) {
    function rto_is_client(?int $uid = null): bool { return rto_user_role($uid) === 'client'; }
}
if (!function_exists('rto_is_staff')) {
    function rto_is_staff(?int $uid = null): bool {
        $role = rto_user_role($uid);
        return $role === 'staff' || $role === 'admin';
    }
}

if (!function_exists('rto_json_ok')) {
    /**
     * Send a JSON success response and exit.
     *
     * Known Limitations / common-mistakes audit fix: a codebase-wide grep
     * found the same bug repeated independently across 15+ admin/vendor/
     * client view files — JS handlers reading `r.data.message` (or the
     * guarded `r.data ? r.data.message : ...`) when the human-readable text
     * has only ever lived at the top-level `r.message`. Every one of those
     * call sites either showed "undefined"/a hardcoded fallback instead of
     * the real server message, or — for the unguarded `r.data.message`
     * variants — threw a TypeError and silently aborted the handler
     * whenever $data was null (reading `.message` off `null`). Rather than
     * hunting down and patching every individual call site (a whack-a-mole
     * fix that a 16th, not-yet-written call site would immediately need
     * again), the root cause is fixed once here: every success response's
     * `data` now also carries the same message under `data.message`
     * (without disturbing any real field a caller already put there),
     * and `data` is never null. Existing code that correctly reads the
     * top-level `r.message` is completely unaffected.
     */
    function rto_json_ok(mixed $data = null, string $message = 'OK', int $code = 200): never {
        status_header($code);
        if (is_array($data)) {
            $data['message'] = $data['message'] ?? $message;
        } elseif ($data === null) {
            $data = ['message' => $message];
        }
        wp_send_json(['success' => true, 'message' => $message, 'data' => $data], $code);
    }
}

if (!function_exists('rto_json_err')) {
    /**
     * Send a JSON error response and exit.
     *
     * Known Limitations / common-mistakes audit fix (see rto_json_ok()'s
     * docblock for the full trace): error responses never carried a `data`
     * key at all, so any JS handler doing the unguarded `r.data.message`
     * pattern on an error response threw a TypeError reading `.message`
     * off `undefined` — silently aborting the handler instead of showing
     * the real server-provided error text. `data.message` now mirrors the
     * top-level message here too, for the same reason and with the same
     * "never disturbs existing top-level fields" guarantee.
     */
    function rto_json_err(string $message, int $code = 400, array $errors = []): never {
        status_header($code);
        wp_send_json(['success' => false, 'message' => $message, 'data' => ['message' => $message], 'errors' => $errors], $code);
    }
}

if (!function_exists('rto_view')) {
    /** Render a view file with extracted variables */
    function rto_view(string $view, array $data = []): void {
        $file = RTOFLOW_DIR . 'resources/views/' . str_replace('.', '/', $view) . '.php';
        if (!file_exists($file)) {
            error_log("RTOFLOW: View not found: {$file}");
            return;
        }
        extract($data, EXTR_SKIP);
        require $file;
    }
}

if (!function_exists('rto_format_inr')) {
    function rto_format_inr(float $amount): string {
        return '₹' . number_format($amount, 2);
    }
}

if (!function_exists('rto_date')) {
    function rto_date(string $date, string $format = 'd M Y'): string {
        if (!$date || $date === '0000-00-00 00:00:00') return '—';
        return date($format, strtotime($date));
    }
}

if (!function_exists('rto_status_label')) {
    function rto_status_label(string $status): string {
        return match($status) {
            'created'          => 'Received',
            'payment_pending'  => 'Payment Pending',
            'payment_received' => 'Payment Received',
            'assigned'         => 'Agent Assigned',
            'in_progress'      => 'In Progress',
            'docs_pending'     => 'Documents Pending',
            'docs_verified'    => 'Documents Verified',
            'rto_submitted'    => 'Submitted to RTO',
            'rto_processing'   => 'At RTO',
            'completed'        => 'Completed',
            'cancelled'        => 'Cancelled',
            'on_hold'          => 'On Hold',
            default            => ucwords(str_replace('_', ' ', $status)),
        };
    }
}

if (!function_exists('rto_status_color')) {
    function rto_status_color(string $status): string {
        return match($status) {
            'completed'        => 'success',
            'cancelled'        => 'danger',
            'created',
            'payment_pending'  => 'warning',
            'payment_received',
            'assigned'         => 'info',
            'in_progress',
            'rto_submitted',
            'rto_processing'   => 'primary',
            'on_hold'          => 'secondary',
            default            => 'secondary',
        };
    }
}

if (!function_exists('rto_nonce_field')) {
    function rto_nonce_field(string $action): string {
        return '<input type="hidden" name="rto_nonce" value="' . esc_attr(wp_create_nonce($action)) . '">';
    }
}

if (!function_exists('rto_verify_nonce')) {
    function rto_verify_nonce(string $action): bool {
        $nonce = $_POST['rto_nonce'] ?? $_GET['rto_nonce'] ?? '';
        return wp_verify_nonce($nonce, $action) !== false;
    }
}

if (!function_exists('rto_paginate')) {
    function rto_paginate(int $total, int $perPage, int $currentPage): array {
        $totalPages = (int)ceil($total / max(1, $perPage));
        return [
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $currentPage,
            'total_pages'  => $totalPages,
            'has_prev'     => $currentPage > 1,
            'has_next'     => $currentPage < $totalPages,
            'offset'       => ($currentPage - 1) * $perPage,
        ];
    }
}

if (!function_exists('rtoflow_portal_url_for_user')) {
    /**
     * Returns the correct portal base URL for the current (or given) user.
     */
    function rtoflow_portal_url_for_user(?int $uid = null): string {
        $role = rto_user_role($uid);
        return match($role) {
            'admin', 'staff' => '/rto-admin/',
            'vendor'         => '/rto-vendor/',
            default          => '/rto-dashboard/',
        };
    }
}

if (!function_exists('rto_lead_status_badge')) {
    /** Render an inline status badge span */
    function rto_lead_status_badge(string $status): string {
        $color = match($status) {
            'completed'        => '#16A34A',
            'cancelled'        => '#DC2626',
            'payment_pending',
            'created'          => '#D97706',
            'assigned',
            'in_progress'      => '#2563EB',
            'on_hold'          => '#64748b',
            default            => '#6366F1',
        };
        $label = rto_status_label($status);
        return "<span style=\"background:{$color}20;color:{$color};font-size:10px;font-weight:700;padding:3px 8px;border-radius:10px\">" . esc_html($label) . "</span>";
    }
}

if (!function_exists('rto_login_url')) {
    /** Custom standalone login URL (never wp-login.php) */
    function rto_login_url(string $redirect = ''): string {
        $url = home_url('/rto-login/');
        if ($redirect) $url .= '?redirect=' . urlencode($redirect);
        return $url;
    }
}

if (!function_exists('rto_logout_url')) {
    /** Custom standalone logout URL */
    function rto_logout_url(string $redirect = ''): string {
        $after = $redirect ?: home_url('/rto-login/');
        return wp_logout_url($after);   // uses WP cookie clearing, then redirects to our page
    }
}

if (!function_exists('rto_help_box')) {
    /**
     * TRACE: called from any admin view's own PHP (e.g.
     *        resources/views/admin/leads/index.php) near the bottom of the
     *        page → looks up \RTOFLOW\Support\HelpContent::get($slug)
     *        → passes the result into partials/screen-help.php
     *        → outputs nothing at all if that slug has no article yet, so
     *        adding this call to a not-yet-documented screen is always
     *        safe (never a blank/broken-looking box) and requires no
     *        conditional guard at every call site.
     * PRECONDITION: $slug matches a key in HelpCenterController::MODULE_INDEX
     *        (not enforced here — an unmapped slug simply renders nothing,
     *        same as an unmapped-but-valid slug with no article yet).
     * POSTCONDITION: echoes HTML or nothing; never throws.
     */
    function rto_help_box(string $slug): void {
        $article = \RTOFLOW\Support\HelpContent::get($slug);
        $articleSlugForHelp = $slug;
        rto_view('admin.partials.screen-help', compact('article', 'articleSlugForHelp'));
    }
}

if (!function_exists('rto_field_tooltip')) {
    /**
     * TRACE: called inline, immediately after a <label> on any admin form
     *        field whose purpose is not obvious from its label alone (e.g.
     *        "SLA breach threshold (hrs)", "Match weight", a guard_condition
     *        JSON textarea) → echoes a small "i" icon that is both
     *        mouse-hoverable (title attr + CSS :hover/:focus popover) and
     *        keyboard-focusable (real <button>, tabindex via being a
     *        button, :focus shows the same popover) → screen readers get
     *        the text via aria-label on the button itself.
     * PRECONDITION: none — plain text in, safe HTML out.
     * POSTCONDITION: returns an HTML string (does not echo) so call sites
     *        can either `echo rto_field_tooltip(...)` inline next to a
     *        label or build it into a larger string; $text is escaped via
     *        esc_attr/esc_html so arbitrary text (including text that looks
     *        like markup) can never break out of the attribute or inject
     *        script.
     * SCOPE NOTE: this is the one shared, reusable primitive — it is not
     *        itself a claim that every admin field has a tooltip. Coverage
     *        is applied field-by-field as a separate content task; see the
     *        call sites for the sample this pass added.
     */
    function rto_field_tooltip(string $text): string {
        $safeAttr = esc_attr($text);
        $safeHtml = esc_html($text);
        return '<span class="rto-field-tip">'
            . '<button type="button" class="rto-field-tip__icon" aria-label="' . $safeAttr . '" title="' . $safeAttr . '">?</button>'
            . '<span class="rto-field-tip__bubble" role="tooltip">' . $safeHtml . '</span>'
            . '</span>';
    }

    /**
     * CACHE-BUSTING ROOT-CAUSE FIX: every static asset (admin.css, admin.js,
     * public.css) was cache-busted with `?v=RTOFLOW_VERSION` — a query
     * string tied to the WHOLE PLUGIN's version, not to the individual
     * file's actual content. That means a CSS-only fix that ships without
     * a full plugin version bump reaching the live site (or any layer
     * between the browser and the server that caches by URL and is lax
     * about query strings — a misconfigured CDN edge cache, a browser
     * extension, an overly aggressive object-cache plugin) can keep
     * serving a stale, older copy of the file indefinitely, with no way
     * for the browser to know it's stale, since the URL it's asked for
     * never changed. This is consistent with the exact symptom reported:
     * base layout (sidebar, page wrapper) rendering correctly while newer
     * grid/card rules added in more recent fixes never visibly apply —
     * that is exactly what an old cached admin.css frozen from before
     * those rules existed would look like.
     *
     * Fix: derive the cache-busting value from the file's own real content
     * (filemtime — the file's last-modified time on disk) instead of the
     * plugin-wide version string, so the query string automatically and
     * unconditionally changes the instant the file itself changes, with
     * zero dependency on a human remembering to bump RTOFLOW_VERSION for
     * every CSS/JS edit. Falls back to RTOFLOW_VERSION only if the file is
     * genuinely unreadable (should not happen in a real install, but must
     * never fatal a page render over a cache-busting value).
     */
    function rto_asset_version(string $relativePath): string {
        $absolutePath = rtrim(RTOFLOW_DIR, '/\\') . '/' . ltrim($relativePath, '/\\');
        $mtime = @filemtime($absolutePath);
        return $mtime !== false ? (string) $mtime : RTOFLOW_VERSION;
    }
}
