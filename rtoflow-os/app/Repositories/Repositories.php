<?php
// ============================================================
//  RTOFLOW OS — All Repository Classes
// ============================================================

namespace RTOFLOW\Repositories;

use RTOFLOW\Database\QueryBuilder;
use RTOFLOW\Support\Cache;
use RTOFLOW\Config\MatchingConfig;

if (!defined('ABSPATH')) exit;

// ── Lead Repository ──────────────────────────────────────────────────────────

class LeadRepository
{
    const STATUSES = [
        'created'           => ['label' => 'Received',          'color' => '#6B7280'],
        'payment_pending'   => ['label' => 'Payment Pending',   'color' => '#EF4444'],
        'payment_received'  => ['label' => 'Payment Received',  'color' => '#10B981'],
        'assigned'          => ['label' => 'Agent Assigned',    'color' => '#6366F1'],
        'in_progress'       => ['label' => 'In Progress',       'color' => '#06B6D4'],
        'docs_pending'      => ['label' => 'Documents Pending', 'color' => '#F59E0B'],
        'docs_verified'     => ['label' => 'Documents Verified','color' => '#3B82F6'],
        'rto_submitted'     => ['label' => 'Submitted to RTO',  'color' => '#2563EB'],
        'rto_processing'    => ['label' => 'At RTO',            'color' => '#1D4ED8'],
        'completed'         => ['label' => 'Completed',         'color' => '#166534'],
        'cancelled'         => ['label' => 'Cancelled',         'color' => '#991B1B'],
        'on_hold'           => ['label' => 'On Hold',           'color' => '#92400E'],
    ];

    const PRIORITIES = [
        1 => ['label' => 'Low',      'color' => '#6B7280'],
        2 => ['label' => 'Normal',   'color' => '#3B82F6'],
        3 => ['label' => 'High',     'color' => '#F59E0B'],
        4 => ['label' => 'Urgent',   'color' => '#EF4444'],
        5 => ['label' => 'Critical', 'color' => '#991B1B'],
    ];

    public function __construct(private QueryBuilder $db, private Cache $cache) {}

    public function find(int $id): ?array
    {
        return $this->cache->remember("lead_{$id}", 300, function () use ($id) {
            $lead = $this->db->table('rto_leads l')
                ->select('l.*', 's.name as service_name', 'c.name as city_name',
                         'u.display_name as client_name', 'u.user_email as client_email',
                         'v.full_name as vendor_name')
                ->left_join('rto_services s', 's.id=l.service_id')
                ->left_join('rto_cities c', 'c.id=l.city_id')
                ->left_join('users u', 'u.ID=l.client_id')
                ->left_join('rto_vendors v', 'v.id=l.vendor_id')
                ->where('l.id', '=', $id)
                ->where_raw('l.deleted_at IS NULL')
                ->first();
            if (!$lead) return null;
            $lead['meta']          = $this->get_meta($id);
            $lead['status_info']   = self::STATUSES[$lead['status']]   ?? [];
            $lead['priority_info'] = self::PRIORITIES[$lead['priority']] ?? [];
            return $lead;
        });
    }

    public function find_by_number(string $num): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM {$wpdb->prefix}rto_leads WHERE lead_number=%s AND deleted_at IS NULL", strtoupper($num)),
            ARRAY_A
        );
        return $row ? $this->find((int)$row['id']) : null;
    }

    public function paginate(array $f, int $per, int $page): array
    {
        $q = $this->db->table('rto_leads l')
            ->select('l.*', 's.name as service_name', 'c.name as city_name',
                     'u.display_name as client_name', 'v.full_name as vendor_name')
            ->left_join('rto_services s', 's.id=l.service_id')
            ->left_join('rto_cities c', 'c.id=l.city_id')
            ->left_join('users u', 'u.ID=l.client_id')
            ->left_join('rto_vendors v', 'v.id=l.vendor_id')
            ->where_raw('l.deleted_at IS NULL')
            ->order_by('l.created_at', 'DESC');
        if (!empty($f['status']))     $q->where('l.status',     '=', $f['status']);
        if (!empty($f['service_id'])) $q->where('l.service_id', '=', $f['service_id']);
        if (!empty($f['city_id']))    $q->where('l.city_id',    '=', $f['city_id']);
        if (!empty($f['vendor_id']))  $q->where('l.vendor_id',  '=', $f['vendor_id']);
        if (!empty($f['client_id']))  $q->where('l.client_id',  '=', $f['client_id']);
        if (!empty($f['priority']))   $q->where('l.priority',   '=', $f['priority']);
        $res = $q->paginate($per, $page);
        foreach ($res['data'] as &$l) {
            $l['status_info']   = self::STATUSES[$l['status']]    ?? [];
            $l['priority_info'] = self::PRIORITIES[$l['priority']] ?? [];
        }
        return $res;
    }

    public function create(array $data): int
    {
        $id = $this->db->table('rto_leads')->insert($data);
        $this->cache->flush_prefix('lead_stats');
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $ok = $this->db->table('rto_leads')->update($data, ['id' => $id]);
        $this->cache->forget("lead_{$id}");
        $this->cache->flush_prefix('lead_stats');
        return $ok;
    }

    public function get_meta(int $lead_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->prefix}rto_lead_meta WHERE lead_id=%d", $lead_id),
            ARRAY_A
        ) ?: [];
        $meta = [];
        foreach ($rows as $r) $meta[$r['meta_key']] = $r['meta_value'];
        return $meta;
    }

    public function set_meta(int $lead_id, string $key, mixed $value): void
    {
        global $wpdb;
        $t = $wpdb->prefix . 'rto_lead_meta';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE lead_id=%d AND meta_key=%s", $lead_id, $key));
        if ($exists) {
            $wpdb->update($t, ['meta_value' => is_array($value) ? wp_json_encode($value) : $value], ['lead_id' => $lead_id, 'meta_key' => $key]);
        } else {
            $wpdb->insert($t, ['lead_id' => $lead_id, 'meta_key' => $key, 'meta_value' => is_array($value) ? wp_json_encode($value) : $value]);
        }
        $this->cache->forget("lead_{$lead_id}");
    }

    public function log(int $lead_id, string $action, mixed $old = null, mixed $new = null): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'rto_logs', [
            'lead_id'    => $lead_id,
            'user_id'    => get_current_user_id() ?: null,
            'action'     => $action,
            'old_value'  => $old !== null ? wp_json_encode($old) : null,
            'new_value'  => $new !== null ? wp_json_encode($new) : null,
            'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
    }

    public function get_messages(int $lead_id): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.*, u.display_name as sender_name FROM {$wpdb->prefix}rto_messages m
                 LEFT JOIN {$wpdb->prefix}users u ON u.ID=m.user_id
                 WHERE m.lead_id=%d ORDER BY m.created_at ASC",
                $lead_id
            ),
            ARRAY_A
        ) ?: [];
    }

    public function next_number(): string
    {
        global $wpdb;
        $n = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rto_leads") + 1;
        return 'RTO-' . date('Y') . '-' . str_pad($n, 6, '0', STR_PAD_LEFT);
    }

    public function check_sla_breaches(): void
    {
        global $wpdb;
        // ENTERPRISE GAP FIX (Phase 9, item — "raw, unparameterized queries
        // in a handful of internal paths"): no user input reaches this
        // UPDATE — every value is a literal or a NOW()/status constant —
        // but it's still flagged by tools/check-raw-queries.php's blanket
        // rule unless explicitly annotated, matching the phpcs:ignore
        // convention already used elsewhere in this codebase for the same
        // internally-derived-SQL reason.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query(
            "UPDATE {$wpdb->prefix}rto_leads SET sla_breached=1
             WHERE sla_breached=0 AND sla_deadline < NOW()
             AND status NOT IN ('completed','cancelled','closed')"
        );
    }
}

// ── Vendor Repository ────────────────────────────────────────────────────────

class VendorRepository
{
    public function __construct(private QueryBuilder $db, private Cache $cache) {}

    public function find(int $id): ?array
    {
        $v = $this->db->table('rto_vendors v')
            ->select('v.*', 'u.user_email')
            ->left_join('users u', 'u.ID=v.user_id')
            ->where('v.id', '=', $id)
            ->first();
        if (!$v) return null;
        $v['cities']       = json_decode($v['cities'] ?? '[]', true);
        $v['services']     = json_decode($v['services'] ?? '[]', true);
        $v['bank_details'] = json_decode($v['bank_details'] ?? '{}', true);
        return $v;
    }

    public function find_by_user(int $uid): ?array
    {
        $row = $this->db->table('rto_vendors')->where('user_id', '=', $uid)->where('status', '=', 'active')->first();
        return $row ? $this->find((int)$row['id']) : null;
    }

    public function paginate(array $f, int $per, int $page): array
    {
        $q = $this->db->table('rto_vendors')->order_by('rating', 'DESC');
        if (!empty($f['status'])) $q->where('status', '=', $f['status']);
        return $q->paginate($per, $page);
    }

    public function create(array $data): int { return $this->db->table('rto_vendors')->insert($data); }

    public function update(int $id, array $data): bool
    {
        $this->cache->forget("vendor_{$id}");
        return $this->db->table('rto_vendors')->update($data, ['id' => $id]);
    }

    public function get_eligible(int $city_id, int $service_id): array
    {
        global $wpdb;
        // FIX P1: previously this omitted the kyc_status='verified' check that
        // LeadService::assignVendor() (the manual-assignment path) enforces
        // separately. The two assignment paths — VendorService::auto_assign()
        // (uses this method) and LeadService::assignVendor() (checks kyc_status
        // inline) — could therefore disagree about which vendors were eligible
        // for the same lead, depending only on which path handled it. Adding
        // the check here makes eligibility a single, shared definition both
        // paths draw from.
        //
        // FIX P1 (Matching/Lead-Allocation configurability): the minimum
        // rating gate (previously hard-coded 3.0) and candidate pool size
        // (previously hard-coded LIMIT 10) now come from the same
        // MatchingConfig an admin edits at Settings → Matching — the same
        // single source VendorService::auto_assign() reads its scoring
        // weights from, so eligibility and scoring can be tuned together
        // without a deployment.
        $minRating  = MatchingConfig::get('min_rating');
        $poolSize   = (int)MatchingConfig::get('candidate_pool_size');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, rating, completion_rate, acceptance_rate, total_jobs
             FROM {$wpdb->prefix}rto_vendors
             WHERE status='active' AND kyc_status='verified' AND rating>=%f
             AND JSON_CONTAINS(cities,%s) AND JSON_CONTAINS(services,%s)
             ORDER BY rating DESC, completion_rate DESC LIMIT %d",
            $minRating,
            json_encode($city_id),
            json_encode($service_id),
            $poolSize
        ), ARRAY_A) ?: [];
    }

    public function get_stats(int $vendor_id): array
    {
        global $wpdb;
        $p = $wpdb->prefix;
        // FIX P0-7: previously hard-coded SUM(paid_amount*0.45) regardless of the
        // per-service vendor_share column (rto_services.vendor_share, admin-editable,
        // default 45%). Any service configured with a different commission split
        // reported the wrong "earnings" figure here even though PayoutService —
        // the actual payout-generation code — already used the real per-service
        // rate. Joining rto_services makes this figure agree with what the vendor
        // is actually paid.
        $earnings = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(l.paid_amount * COALESCE(s.vendor_share, 45) / 100), 0)
             FROM {$p}rto_leads l
             LEFT JOIN {$p}rto_services s ON s.id = l.service_id
             WHERE l.vendor_id=%d AND l.status='completed'",
            $vendor_id
        ));
        return [
            'active'    => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}rto_leads WHERE vendor_id=%d AND status NOT IN ('completed','cancelled')", $vendor_id)),
            'completed' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}rto_leads WHERE vendor_id=%d AND status='completed'", $vendor_id)),
            'earnings'  => $earnings,
        ];
    }

    public function update_rating(int $vendor_id): void
    {
        global $wpdb;
        // Known Limitations audit fix (global consistency pass): a rating
        // soft-deleted from the admin Ratings screen must not count towards
        // this vendor's live average — this is the same recalculation the
        // Ratings screen itself does via RatingsController::recalcVendorAverage(),
        // just reached from a different call site (vendor stats refresh).
        $avg = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT AVG(score) FROM {$wpdb->prefix}rto_ratings WHERE vendor_id=%d AND deleted_at IS NULL", $vendor_id
        ));
        $this->update($vendor_id, ['rating' => round($avg, 2)]);
    }
}

// ── Payment Repository ───────────────────────────────────────────────────────

class PaymentRepository
{
    public function __construct(private QueryBuilder $db) {}

    public function find(int $id): ?array
    {
        return $this->db->table('rto_payments p')
            ->select('p.*', 'l.lead_number', 'u.display_name as client_name')
            ->left_join('rto_leads l', 'l.id=p.lead_id')
            ->left_join('users u', 'u.ID=l.client_id')
            ->where('p.id', '=', $id)
            ->first();
    }

    public function create(array $data): int { return $this->db->table('rto_payments')->insert($data); }

    public function for_lead(int $lead_id): array
    {
        return $this->db->table('rto_payments')
            ->where('lead_id', '=', $lead_id)
            ->order_by('created_at', 'DESC')
            ->get();
    }
}

// ── Document Repository ──────────────────────────────────────────────────────

class DocumentRepository
{
    public function __construct(private QueryBuilder $db) {}

    public function find(int $id): ?array { return $this->db->table('rto_documents')->where('id', '=', $id)->first(); }

    public function for_lead(int $lead_id): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT d.*, t.name as doc_type_name FROM {$wpdb->prefix}rto_documents d
                 LEFT JOIN {$wpdb->prefix}rto_doc_types t ON t.id=d.doc_type_id
                 WHERE d.lead_id=%d ORDER BY d.created_at DESC",
                $lead_id
            ),
            ARRAY_A
        ) ?: [];
    }

    public function create(array $data): int { return $this->db->table('rto_documents')->insert($data); }

    public function update(int $id, array $data): bool { return $this->db->table('rto_documents')->update($data, ['id' => $id]); }
}

// ── Form Repository ──────────────────────────────────────────────────────────

class FormRepository
{
    public function __construct(private QueryBuilder $db) {}

    public function find(int $id): ?array { return $this->db->table('rto_form_schemas')->where('id', '=', $id)->first(); }

    public function find_by_service(int $service_id): ?array
    {
        return $this->db->table('rto_form_schemas')
            ->where('service_id', '=', $service_id)
            ->where('is_active', '=', 1)
            ->first();
    }

    public function all(): array { return $this->db->table('rto_form_schemas f')
        ->select('f.*', 's.name as service_name')
        ->left_join('rto_services s', 's.id=f.service_id')
        ->order_by('f.created_at', 'DESC')
        ->get(); }

    public function create(array $data): int { return $this->db->table('rto_form_schemas')->insert($data); }
    public function update(int $id, array $data): bool { return $this->db->table('rto_form_schemas')->update($data, ['id' => $id]); }

    /**
     * All schema versions ever saved for one service, newest first — the
     * version-history feed for FormEngineService::historyForService(),
     * mirroring find_by_service() but without the is_active=1 filter and
     * without the cross-service join used by all().
     */
    public function all_for_service(int $service_id): array
    {
        return $this->db->table('rto_form_schemas')
            ->where('service_id', '=', $service_id)
            ->order_by('version', 'DESC')
            ->get();
    }

    // ── Category-level lookups (Part 4.10) ──────────────────────────────
    // A "form" in this engine is now one CATEGORY (e.g. "Driving License"),
    // covering every real service in it via a service-picker field plus
    // per-field visible_if conditions — matching the reference plugin's
    // "one category = one form, N services inside it" pattern, replacing
    // the earlier one-schema-per-service design. See migration 12
    // (2024_01_01_000012_add_form_category_column.php) for the column this
    // reads/writes, and FormEngineService::getForCategory()/saveForCategory().

    public function find_by_category(string $category): ?array
    {
        return $this->db->table('rto_form_schemas')
            ->where('category', '=', $category)
            ->where('is_active', '=', 1)
            ->where_raw('deleted_at IS NULL')
            ->first();
    }

    /** History feed excludes soft-deleted versions — a deleted version should disappear from the panel, not just stop being restorable. */
    public function all_for_category(string $category): array
    {
        return $this->db->table('rto_form_schemas')
            ->where('category', '=', $category)
            ->where_raw('deleted_at IS NULL')
            ->order_by('version', 'DESC')
            ->get();
    }

    /** Soft-delete one specific schema VERSION (not the whole category) — see migration 18. */
    public function soft_delete(int $id): bool
    {
        global $wpdb;
        return (bool)$wpdb->update(
            $wpdb->prefix . 'rto_form_schemas',
            ['deleted_at' => current_time('mysql')],
            ['id' => $id]
        );
    }

    /** Every category that currently has at least one saved schema (any version, active or not) — filtered in PHP rather than a not-null WHERE, since QueryBuilder's where() is built for equality comparisons, not NULL-safety operators. */
    public function all_categories_with_schemas(): array
    {
        $rows = $this->db->table('rto_form_schemas')->get();
        $cats = [];
        foreach ($rows as $r) { if (!empty($r['category'])) $cats[$r['category']] = true; }
        return array_keys($cats);
    }
}

// ── Grievance Repository ─────────────────────────────────────────────────────

class GrievanceRepository
{
    const TYPES = [
        'delay'       => 'Service Delay',
        'misconduct'  => 'Vendor Misconduct',
        'payment'     => 'Payment Issue',
        'document'    => 'Document Issue',
        'staff'       => 'Staff Complaint',
        'fraud'       => 'Fraud Alert',
        'refund'      => 'Refund Dispute',
        'general'     => 'General Grievance',
    ];
    const SLA = ['fraud' => 6, 'payment' => 24, 'delay' => 48, 'general' => 72];

    public function __construct(private QueryBuilder $db) {}

    public function find(int $id): ?array
    {
        $c = $this->db->table('rto_complaints c')
            ->select('c.*', 'u.display_name as complainant_name')
            ->left_join('users u', 'u.ID=c.complainant_id')
            ->where('c.id', '=', $id)
            ->first();
        if (!$c) return null;
        $c['type_label'] = self::TYPES[$c['complaint_type']] ?? $c['complaint_type'];
        return $c;
    }

    public function paginate(array $f, int $per, int $page): array
    {
        $q = $this->db->table('rto_complaints c')
            ->select('c.*', 'u.display_name as complainant_name')
            ->left_join('users u', 'u.ID=c.complainant_id')
            ->order_by('c.created_at', 'DESC');
        if (!empty($f['status']))         $q->where('c.status',          '=', $f['status']);
        if (!empty($f['complainant_id'])) $q->where('c.complainant_id',  '=', $f['complainant_id']);
        return $q->paginate($per, $page);
    }

    public function create(array $data): int { return $this->db->table('rto_complaints')->insert($data); }
    public function update(int $id, array $data): bool { return $this->db->table('rto_complaints')->update($data, ['id' => $id]); }

    public function next_number(): string
    {
        global $wpdb;
        $n = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rto_complaints") + 1;
        return 'CMP-' . date('Y-m') . '-' . str_pad($n, 6, '0', STR_PAD_LEFT);
    }

    public function get_stats(): array
    {
        global $wpdb;
        $p = $wpdb->prefix;
        return [
            'total_open'  => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_complaints WHERE status NOT IN ('closed','resolved')"),
            'sla_breached'=> (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_complaints WHERE sla_breached=1"),
        ];
    }
}

// ── Notification Repository ──────────────────────────────────────────────────

class NotificationRepository
{
    public function __construct(private QueryBuilder $db) {}

    public function find_template(string $slug, string $channel): ?array
    {
        return $this->db->table('rto_notification_templates')
            ->where('slug', '=', $slug)
            ->where('channel', '=', $channel)
            ->where('is_active', '=', 1)
            ->first();
    }

    public function all_templates(): array { return $this->db->table('rto_notification_templates')->order_by('slug')->get(); }

    public function log_notification(array $data): int { return $this->db->table('rto_notifications')->insert($data); }

    public function update_template(int $id, array $data): bool { return $this->db->table('rto_notification_templates')->update($data, ['id' => $id]); }
}

// ── Report Repository ────────────────────────────────────────────────────────

class ReportRepository
{
    public function __construct(private QueryBuilder $db, private Cache $cache) {}

    public function pipeline(): array
    {
        return $this->cache->remember('rpt_pipeline', 300, function () {
            global $wpdb;
            $p = $wpdb->prefix;
            return [
                'by_status'  => $wpdb->get_results("SELECT status, COUNT(*) as count FROM {$p}rto_leads WHERE deleted_at IS NULL GROUP BY status", ARRAY_A) ?: [],
                'by_service' => $wpdb->get_results("SELECT s.name, COUNT(l.id) as count FROM {$p}rto_leads l LEFT JOIN {$p}rto_services s ON s.id=l.service_id WHERE l.deleted_at IS NULL GROUP BY l.service_id ORDER BY count DESC LIMIT 10", ARRAY_A) ?: [],
            ];
        });
    }

    public function revenue(string $period = 'month'): array
    {
        return $this->cache->remember("rpt_rev_{$period}", 300, function () use ($period) {
            global $wpdb;
            $p = $wpdb->prefix;
            $since_sql = match($period) {
                'today' => 'DATE(created_at) = CURDATE()',
                'week'  => 'created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)',
                'year'  => 'created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)',
                default => 'created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)',
            };
            return [
                'total'  => (float)$wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$p}rto_payments WHERE {$since_sql} AND status='completed'"),
                'period' => $period,
                'count'  => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_payments WHERE {$since_sql} AND status='completed'"),
            ];
        });
    }

    public function vendor_performance(): array
    {
        return $this->cache->remember('rpt_vendor', 300, function () {
            global $wpdb;
            return $wpdb->get_results(
                "SELECT full_name, rating, total_jobs, completion_rate
                 FROM {$wpdb->prefix}rto_vendors WHERE status='active' ORDER BY rating DESC LIMIT 20",
                ARRAY_A
            ) ?: [];
        });
    }

    public function sla(): array
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $t = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_leads WHERE deleted_at IS NULL");
        $b = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_leads WHERE sla_breached=1");
        return ['total' => $t, 'breached' => $b, 'rate' => $t > 0 ? round($b / $t * 100, 2) : 0];
    }
}
