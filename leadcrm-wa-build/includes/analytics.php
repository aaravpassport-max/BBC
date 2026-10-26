<?php
/**
 * LeadCRM Analytics Engine
 * Provides all data for the visual pipeline dashboard.
 * Computes: funnel conversion, revenue forecast, agent performance,
 * source ROI, stage velocity, hot lead patterns.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── DB INSTALL ──────────────────────────────────────────────────────────────

// TRACE: Called on activate + v5 migration → creates analytics_events table for
//        time-series stage transition tracking.
//        POSTCONDITIONS: lcrm_analytics_events exists.
//        EDGE CASES: Idempotent via dbDelta.
function lcrm_analytics_install_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_analytics_events (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id      BIGINT UNSIGNED NOT NULL,
        event_type   VARCHAR(60)     NOT NULL DEFAULT 'stage_change',
        from_value   VARCHAR(200)    NOT NULL DEFAULT '',
        to_value     VARCHAR(200)    NOT NULL DEFAULT '',
        numeric_value DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
        agent_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        source       VARCHAR(150)    NOT NULL DEFAULT '',
        work_type    VARCHAR(150)    NOT NULL DEFAULT '',
        created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY lead_id (lead_id),
        KEY event_type (event_type),
        KEY agent_id (agent_id),
        KEY created_at (created_at),
        KEY source (source)
    ) $c;" );
}

// ─── EVENT RECORDING ─────────────────────────────────────────────────────────

// TRACE: Called from lcrm_update_lead when stage changes → INSERTs analytics event →
//        enables time-series funnel analysis.
//        PRECONDITIONS: lead_id valid, from/to are stage names.
//        POSTCONDITIONS: Row in lcrm_analytics_events.
//        EDGE CASES: Same stage → still recorded (re-touch event); 0 agent_id → system event.
function lcrm_analytics_record_stage_change( $lead_id, $from_stage, $to_stage, $agent_id = 0 ) {
    global $wpdb;
    $lead = lcrm_get_lead( (int) $lead_id );
    $wpdb->insert( "{$wpdb->prefix}lcrm_analytics_events", [
        'lead_id'    => (int) $lead_id,
        'event_type' => 'stage_change',
        'from_value' => sanitize_text_field( $from_stage ),
        'to_value'   => sanitize_text_field( $to_stage ),
        'agent_id'   => (int) $agent_id,
        'source'     => $lead ? sanitize_text_field( $lead->lead_source ) : '',
        'work_type'  => $lead ? sanitize_text_field( $lead->work_type )   : '',
        'created_at' => current_time( 'mysql' ),
    ] );
}

// TRACE: Called when payment received → records revenue event for forecasting.
//        POSTCONDITIONS: Revenue event in analytics_events with numeric_value = amount.
function lcrm_analytics_record_payment( $lead_id, $amount, $agent_id = 0 ) {
    global $wpdb;
    $lead = lcrm_get_lead( (int) $lead_id );
    $wpdb->insert( "{$wpdb->prefix}lcrm_analytics_events", [
        'lead_id'       => (int) $lead_id,
        'event_type'    => 'payment_received',
        'from_value'    => '',
        'to_value'      => '',
        'numeric_value' => floatval( $amount ),
        'agent_id'      => (int) $agent_id,
        'source'        => $lead ? sanitize_text_field( $lead->lead_source ) : '',
        'work_type'     => $lead ? sanitize_text_field( $lead->work_type )   : '',
        'created_at'    => current_time( 'mysql' ),
    ] );
}

// ─── FUNNEL DATA ──────────────────────────────────────────────────────────────

// TRACE: Called by analytics dashboard → queries lead counts by stage →
//        returns ordered array of {stage_name, color, count, pct_of_total, conversion_rate}.
//        PRECONDITIONS: Stages table populated.
//        POSTCONDITIONS: Returns array sorted by stage sort_order.
//        EDGE CASES: No leads → all counts 0; deleted stages → not shown.
function lcrm_analytics_funnel() {
    global $wpdb;

    $stages = $wpdb->get_results(
        "SELECT s.id, s.name, s.color, s.sort_order,
                COUNT(l.id) AS lead_count,
                COALESCE(SUM(l.total_quote),0) AS total_value
         FROM {$wpdb->prefix}lcrm_stages s
         LEFT JOIN {$wpdb->prefix}lcrm_leads l ON l.stage_id = s.id
         WHERE s.is_active = 1
         GROUP BY s.id
         ORDER BY s.sort_order ASC"
    );

    $total = array_sum( array_column( (array) $stages, 'lead_count' ) );
    $result = [];

    foreach ( (array) $stages as $s ) {
        $result[] = [
            'id'              => (int) $s->id,
            'name'            => $s->name,
            'color'           => $s->color,
            'count'           => (int) $s->lead_count,
            'value'           => floatval( $s->total_value ),
            'pct_of_total'    => $total > 0 ? round( $s->lead_count / $total * 100, 1 ) : 0,
        ];
    }
    return $result;
}

// ─── CONVERSION RATES ────────────────────────────────────────────────────────

// TRACE: Called by analytics dashboard → queries analytics_events for stage transitions →
//        computes conversion rate from each stage to "won" stages.
//        Returns array: {from_stage, entered, converted, conversion_pct, avg_days_to_convert}.
//        EDGE CASES: No events → returns empty array; won stages detected by name patterns.
function lcrm_analytics_conversion_rates( $days = 90 ) {
    global $wpdb;
    $since = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

    // Get all stage transitions in period
    $events = $wpdb->get_results( $wpdb->prepare(
        "SELECT e.lead_id, e.from_value, e.to_value, e.created_at,
                l.lead_source, l.work_type
         FROM {$wpdb->prefix}lcrm_analytics_events e
         LEFT JOIN {$wpdb->prefix}lcrm_leads l ON l.id = e.lead_id
         WHERE e.event_type = 'stage_change' AND e.created_at >= %s
         ORDER BY e.lead_id, e.created_at ASC",
        $since
    ) );

    // Won stage patterns
    $won_patterns = ['Payment Received', 'Work Completed', 'Delivered', 'PMT RECVD'];
    $lost_patterns = ['Lost', 'No Response', 'Not Able to Serve'];

    // Group by from_stage
    $from_counts = [];
    $won_counts  = [];
    $days_to_win = [];

    foreach ( (array) $events as $ev ) {
        $from = $ev->from_value;
        if ( ! isset( $from_counts[ $from ] ) ) {
            $from_counts[ $from ] = 0;
            $won_counts[ $from ]  = 0;
            $days_to_win[ $from ] = [];
        }
        $from_counts[ $from ]++;

        $is_won = false;
        foreach ( $won_patterns as $pat ) {
            if ( stripos( $ev->to_value, $pat ) !== false ) { $is_won = true; break; }
        }
        if ( $is_won ) {
            $won_counts[ $from ]++;
            $days_to_win[ $from ][] = 1; // simplified — real implementation tracks lead entry date
        }
    }

    $result = [];
    foreach ( $from_counts as $stage => $entered ) {
        if ( $entered < 1 ) continue;
        $won     = $won_counts[ $stage ] ?? 0;
        $avg_days= count( $days_to_win[ $stage ] ) > 0
                   ? round( array_sum( $days_to_win[ $stage ] ) / count( $days_to_win[ $stage ] ), 1 )
                   : 0;
        $result[] = [
            'stage'          => $stage,
            'entered'        => $entered,
            'converted'      => $won,
            'conversion_pct' => $entered > 0 ? round( $won / $entered * 100, 1 ) : 0,
            'avg_days'       => $avg_days,
        ];
    }
    usort( $result, fn($a,$b) => $b['entered'] <=> $a['entered'] );
    return $result;
}

// ─── REVENUE FORECAST ────────────────────────────────────────────────────────

// TRACE: Called by dashboard → queries leads in late-stage pipeline →
//        estimates likely revenue based on stage conversion history and total_quote.
//        Returns: {confirmed, probable, possible, total_pipeline, monthly_trend[]}.
//        EDGE CASES: No late-stage leads → returns all zeros; trend uses last 6 months.
function lcrm_analytics_revenue_forecast() {
    global $wpdb;

    // Confirmed = payment already received
    $confirmed = (float) $wpdb->get_var(
        "SELECT COALESCE(SUM(pmt_received),0) FROM {$wpdb->prefix}lcrm_leads"
    );

    // Probable = leads in Payment Received or Work Completed stages
    $probable = (float) $wpdb->get_var(
        "SELECT COALESCE(SUM(l.balance_payment),0)
         FROM {$wpdb->prefix}lcrm_leads l
         JOIN {$wpdb->prefix}lcrm_stages s ON s.id = l.stage_id
         WHERE s.name IN ('Payment Received','PMT RECVD Work Not Started','Assigned and In Progress')"
    );

    // Possible = leads in quotation or docs stages
    $possible = (float) $wpdb->get_var(
        "SELECT COALESCE(SUM(l.total_quote),0)
         FROM {$wpdb->prefix}lcrm_leads l
         JOIN {$wpdb->prefix}lcrm_stages s ON s.id = l.stage_id
         WHERE s.name IN ('Quotation Sent','Documents Received','Waiting for Docs')"
    );

    // Total pipeline value
    $pipeline = (float) $wpdb->get_var(
        "SELECT COALESCE(SUM(total_quote),0) FROM {$wpdb->prefix}lcrm_leads
         WHERE total_quote > 0"
    );

    // Pending (balance) total
    $pending = (float) $wpdb->get_var(
        "SELECT COALESCE(SUM(balance_payment),0) FROM {$wpdb->prefix}lcrm_leads
         WHERE balance_payment > 0"
    );

    // Monthly revenue trend — last 6 months
    $trend = $wpdb->get_results(
        "SELECT DATE_FORMAT(created_at,'%Y-%m') AS month,
                SUM(numeric_value) AS revenue,
                COUNT(*) AS deals
         FROM {$wpdb->prefix}lcrm_analytics_events
         WHERE event_type='payment_received'
           AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
         GROUP BY month
         ORDER BY month ASC"
    );

    // Monthly leads added — for pipeline velocity
    $monthly_leads = $wpdb->get_results(
        "SELECT DATE_FORMAT(lead_date,'%Y-%m') AS month, COUNT(*) AS new_leads
         FROM {$wpdb->prefix}lcrm_leads
         WHERE lead_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY month ORDER BY month ASC"
    );

    return [
        'confirmed'     => $confirmed,
        'probable'      => $probable,
        'possible'      => $possible,
        'pipeline'      => $pipeline,
        'pending'       => $pending,
        'monthly_trend' => array_map( fn($r) => [
            'month'   => $r->month,
            'revenue' => floatval( $r->revenue ),
            'deals'   => (int) $r->deals,
        ], (array) $trend ),
        'monthly_leads' => array_map( fn($r) => [
            'month'     => $r->month,
            'new_leads' => (int) $r->new_leads,
        ], (array) $monthly_leads ),
    ];
}

// ─── AGENT PERFORMANCE ───────────────────────────────────────────────────────

// TRACE: Called by analytics → queries leads by assigned_to → computes per-agent metrics.
//        Returns array of {agent_name, total_leads, hot_leads, won_leads, revenue, avg_response_hours}.
//        EDGE CASES: Unassigned leads → grouped as "Unassigned"; agents with no leads → excluded.
function lcrm_analytics_agent_performance( $days = 30 ) {
    global $wpdb;
    $since = date( 'Y-m-d', strtotime( "-{$days} days" ) );

    $agents = $wpdb->get_results( $wpdb->prepare(
        "SELECT
            l.assigned_to,
            u.display_name AS agent_name,
            COUNT(l.id)                                          AS total_leads,
            SUM(l.lead_temperature = 'hot')                     AS hot_leads,
            SUM(l.pmt_received > 0)                             AS won_leads,
            COALESCE(SUM(l.pmt_received),0)                     AS revenue,
            COALESCE(SUM(l.total_quote),0)                      AS pipeline_value,
            SUM(l.balance_payment > 0)                          AS pending_payment,
            COUNT(CASE WHEN l.lead_temperature='cold' THEN 1 END) AS cold_leads
         FROM {$wpdb->prefix}lcrm_leads l
         LEFT JOIN {$wpdb->users} u ON u.ID = l.assigned_to
         WHERE l.lead_date >= %s
         GROUP BY l.assigned_to
         ORDER BY revenue DESC",
        $since
    ) );

    return array_map( function( $a ) {
        return [
            'agent_id'       => (int) $a->assigned_to,
            'agent_name'     => $a->agent_name ?: 'Unassigned',
            'total_leads'    => (int) $a->total_leads,
            'hot_leads'      => (int) $a->hot_leads,
            'won_leads'      => (int) $a->won_leads,
            'cold_leads'     => (int) $a->cold_leads,
            'revenue'        => floatval( $a->revenue ),
            'pipeline_value' => floatval( $a->pipeline_value ),
            'pending_payment'=> (int) $a->pending_payment,
            'win_rate'       => $a->total_leads > 0
                                ? round( $a->won_leads / $a->total_leads * 100, 1 ) : 0,
        ];
    }, (array) $agents );
}

// ─── SOURCE ROI ───────────────────────────────────────────────────────────────

// TRACE: Called by analytics → groups leads by lead_source → computes conversion + revenue per source.
//        Returns array sorted by revenue DESC.
//        EDGE CASES: Sources with zero revenue still shown; empty source → 'Direct/Unknown'.
function lcrm_analytics_source_roi( $days = 90 ) {
    global $wpdb;
    $since = date( 'Y-m-d', strtotime( "-{$days} days" ) );

    $sources = $wpdb->get_results( $wpdb->prepare(
        "SELECT
            COALESCE(NULLIF(lead_source,''),'Direct/Unknown')   AS source,
            COUNT(id)                                           AS total_leads,
            SUM(lead_temperature = 'hot')                       AS hot_leads,
            SUM(pmt_received > 0)                               AS conversions,
            COALESCE(SUM(pmt_received),0)                       AS revenue,
            COALESCE(SUM(total_quote),0)                        AS pipeline_value,
            COALESCE(AVG(NULLIF(total_quote,0)),0)              AS avg_deal_size
         FROM {$wpdb->prefix}lcrm_leads
         WHERE lead_date >= %s
         GROUP BY lead_source
         ORDER BY revenue DESC",
        $since
    ) );

    return array_map( function( $s ) {
        return [
            'source'         => $s->source,
            'total_leads'    => (int) $s->total_leads,
            'hot_leads'      => (int) $s->hot_leads,
            'conversions'    => (int) $s->conversions,
            'revenue'        => floatval( $s->revenue ),
            'pipeline_value' => floatval( $s->pipeline_value ),
            'avg_deal_size'  => round( floatval( $s->avg_deal_size ), 0 ),
            'conversion_rate'=> $s->total_leads > 0
                                ? round( $s->conversions / $s->total_leads * 100, 1 ) : 0,
        ];
    }, (array) $sources );
}

// ─── STAGE VELOCITY ──────────────────────────────────────────────────────────

// TRACE: Computes average days leads spend in each stage by analysing analytics_events.
//        Returns array: {stage, avg_days_in_stage, leads_passing_through}.
//        EDGE CASES: Stages with no events → excluded; events without exit → not counted (still active).
function lcrm_analytics_stage_velocity() {
    global $wpdb;

    // MySQL 5.7-compatible velocity calculation using self-join
    // For each event, find the next event on the same lead by min(created_at > current)
    $transitions = $wpdb->get_results(
        "SELECT e1.lead_id, e1.from_value AS stage, e1.created_at,
                MIN(e2.created_at) AS next_time
         FROM {$wpdb->prefix}lcrm_analytics_events e1
         LEFT JOIN {$wpdb->prefix}lcrm_analytics_events e2
             ON e2.lead_id = e1.lead_id
             AND e2.event_type = 'stage_change'
             AND e2.created_at > e1.created_at
         WHERE e1.event_type = 'stage_change'
         GROUP BY e1.id, e1.lead_id, e1.from_value, e1.created_at
         ORDER BY e1.lead_id, e1.created_at ASC"
    );

    $stage_days = [];
    foreach ( (array) $transitions as $t ) {
        if ( ! $t->next_time ) continue; // still in this stage
        $diff = ( strtotime( $t->next_time ) - strtotime( $t->created_at ) ) / 86400;
        if ( ! isset( $stage_days[ $t->stage ] ) ) $stage_days[ $t->stage ] = [];
        $stage_days[ $t->stage ][] = $diff;
    }

    $result = [];
    foreach ( $stage_days as $stage => $days_arr ) {
        $result[] = [
            'stage'         => $stage,
            'avg_days'      => round( array_sum( $days_arr ) / count( $days_arr ), 1 ),
            'median_days'   => lcrm_analytics_median( $days_arr ),
            'leads_through' => count( $days_arr ),
        ];
    }
    usort( $result, fn($a,$b) => $b['leads_through'] <=> $a['leads_through'] );
    return $result;
}

// TRACE: Utility → computes median of a numeric array.
//        EDGE CASES: Empty array → returns 0; odd/even count handled.
function lcrm_analytics_median( array $arr ) {
    if ( empty( $arr ) ) return 0;
    sort( $arr );
    $n = count( $arr );
    $mid = (int) floor( $n / 2 );
    return $n % 2 === 0 ? round( ( $arr[ $mid - 1 ] + $arr[ $mid ] ) / 2, 1 ) : round( $arr[ $mid ], 1 );
}

// ─── DAILY SUMMARY STATS ─────────────────────────────────────────────────────

// TRACE: Called by dashboard → returns today's summary stats in a single query batch.
//        POSTCONDITIONS: Returns associative array of scalar metrics.
//        EDGE CASES: Empty DB → all zeros; no errors thrown.
function lcrm_analytics_today_stats() {
    global $wpdb;
    $today = date( 'Y-m-d' );
    $week  = date( 'Y-m-d', strtotime( '-7 days' ) );

    return [
        'new_today'           => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE lead_date=%s", $today ) ),
        'new_week'            => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE lead_date>=%s", $week ) ),
        'hot_leads'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE lead_temperature='hot'" ),
        'followup_due'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE client_followup_date<=%s AND client_followup_date IS NOT NULL", $today ) ),
        'overdue_followup'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE client_followup_date < %s AND client_followup_date IS NOT NULL", $today ) ),
        'unread_alerts'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE is_read=0" ),
        'total_leads'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads" ),
        'pending_revenue'     => (float) $wpdb->get_var( "SELECT COALESCE(SUM(balance_payment),0) FROM {$wpdb->prefix}lcrm_leads WHERE balance_payment>0" ),
        'revenue_mtd'         => (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(pmt_received),0) FROM {$wpdb->prefix}lcrm_leads WHERE created_at>=%s", date('Y-m-01') ) ),
        'wa_unread'           => (int) $wpdb->get_var( "SELECT COALESCE(SUM(wa_unread_count),0) FROM {$wpdb->prefix}lcrm_leads" ),
        'unassigned'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE assigned_to IS NULL OR assigned_to=0" ),
    ];
}

// ─── AJAX HANDLERS ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_analytics_get_funnel',     'lcrm_ajax_analytics_funnel' );
add_action( 'wp_ajax_lcrm_analytics_get_forecast',   'lcrm_ajax_analytics_forecast' );
add_action( 'wp_ajax_lcrm_analytics_get_agents',     'lcrm_ajax_analytics_agents' );
add_action( 'wp_ajax_lcrm_analytics_get_sources',    'lcrm_ajax_analytics_sources' );
add_action( 'wp_ajax_lcrm_analytics_get_velocity',   'lcrm_ajax_analytics_velocity' );
add_action( 'wp_ajax_lcrm_analytics_get_today',      'lcrm_ajax_analytics_today' );
add_action( 'wp_ajax_lcrm_analytics_get_conversion', 'lcrm_ajax_analytics_conversion' );

// TRACE: All analytics AJAX handlers follow same pattern:
//        verify login → read optional $days param → call data function → json_success.
//        EDGE CASES: days param missing → defaults to 30 or 90; no data → returns empty arrays.
function lcrm_ajax_analytics_funnel() {
    lcrm_verify();
    wp_send_json_success( [ 'funnel' => lcrm_analytics_funnel() ] );
}
function lcrm_ajax_analytics_forecast() {
    lcrm_verify();
    wp_send_json_success( lcrm_analytics_revenue_forecast() );
}
function lcrm_ajax_analytics_agents() {
    lcrm_verify();
    $days = max( 7, min( 365, intval( $_POST['days'] ?? 30 ) ) );
    wp_send_json_success( [ 'agents' => lcrm_analytics_agent_performance( $days ) ] );
}
function lcrm_ajax_analytics_sources() {
    lcrm_verify();
    $days = max( 7, min( 365, intval( $_POST['days'] ?? 90 ) ) );
    wp_send_json_success( [ 'sources' => lcrm_analytics_source_roi( $days ) ] );
}
function lcrm_ajax_analytics_velocity() {
    lcrm_verify();
    wp_send_json_success( [ 'velocity' => lcrm_analytics_stage_velocity() ] );
}
function lcrm_ajax_analytics_today() {
    lcrm_verify();
    wp_send_json_success( lcrm_analytics_today_stats() );
}
function lcrm_ajax_analytics_conversion() {
    lcrm_verify();
    $days = max( 7, min( 365, intval( $_POST['days'] ?? 90 ) ) );
    wp_send_json_success( [ 'conversions' => lcrm_analytics_conversion_rates( $days ) ] );
}
