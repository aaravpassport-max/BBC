<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Services\ExceptionMonitor;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 2, item 6 — structured exception monitoring):
 * see ExceptionMonitor and migration 2024_01_01_000031_create_exception_log
 * for the full problem statement and capture mechanism. This controller is
 * just the admin-only read screen over that table.
 */
class ExceptionsController
{
    public function index(): void
    {
        if (!rto_is_admin()) {
            wp_die('Access denied.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        }

        $showResolved = !empty($_GET['show_resolved']);
        $page = max(1, (int)($_GET['paged'] ?? 1));
        $result = ExceptionMonitor::recent(!$showResolved, $page, 30);

        rto_view('admin.exceptions.index', [
            'exceptions'   => $result['rows'],
            'total'        => $result['total'],
            'page'         => $page,
            'lastPage'     => max(1, (int)ceil($result['total'] / 30)),
            'showResolved' => $showResolved,
        ]);
    }
}
