<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function lcrm_install_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // LEADS - upgraded with all new columns
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_leads (
        id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_date           DATE            NOT NULL,
        client_name         VARCHAR(150)    NOT NULL,
        client_phone        VARCHAR(30)     NOT NULL DEFAULT '',
        client_email        VARCHAR(150)    NOT NULL DEFAULT '',
        work_type           TEXT            NOT NULL,
        lead_source         VARCHAR(150)    NOT NULL DEFAULT '',
        stage_id            BIGINT UNSIGNED NOT NULL DEFAULT 1,
        brand_id            BIGINT UNSIGNED DEFAULT NULL,
        internal_status     VARCHAR(150)    NOT NULL DEFAULT '',
        work_status         VARCHAR(30)     NOT NULL DEFAULT 'Inquiry',
        next_action_date    DATE            DEFAULT NULL,
        client_followup_date DATE           DEFAULT NULL,
        vendor_followup_date DATE           DEFAULT NULL,
        priority            ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
        lead_temperature    ENUM('cold','warm','hot')   NOT NULL DEFAULT 'warm',
        work_started        TINYINT(1)      NOT NULL DEFAULT 0,
        next_action_type    VARCHAR(100)    NOT NULL DEFAULT '',
        last_vendor_contact DATETIME        DEFAULT NULL,
        last_client_contact DATETIME        DEFAULT NULL,
        remarks             TEXT            NOT NULL DEFAULT '',
        final_remarks       TEXT            NOT NULL DEFAULT '',
        vendor_id           BIGINT UNSIGNED DEFAULT NULL,
        total_quote         DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
        pmt_received        DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
        balance_payment     DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
        vendor_charge       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
        pmt_to_vendor       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
        profit              DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
        custom_fields       LONGTEXT        NOT NULL DEFAULT '{}',
        created_by          BIGINT UNSIGNED NOT NULL DEFAULT 0,
        assigned_to         BIGINT UNSIGNED DEFAULT NULL,
        created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY stage_id (stage_id),
        KEY vendor_id (vendor_id),
        KEY brand_id (brand_id),
        KEY lead_date (lead_date),
        KEY next_action_date (next_action_date),
        KEY client_followup_date (client_followup_date),
        KEY vendor_followup_date (vendor_followup_date),
        KEY priority (priority),
        KEY work_started (work_started),
        KEY assigned_to (assigned_to),
        KEY created_by (created_by)
    ) $c;");

    // PIPELINE STAGES
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_stages (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name       VARCHAR(150)    NOT NULL,
        color      VARCHAR(20)     NOT NULL DEFAULT '#6366f1',
        sort_order INT             NOT NULL DEFAULT 0,
        is_active  TINYINT(1)      NOT NULL DEFAULT 1,
        PRIMARY KEY (id)
    ) $c;");

    // BRANDS
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_brands (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name       VARCHAR(150)    NOT NULL,
        is_active  TINYINT(1)      NOT NULL DEFAULT 1,
        created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $c;");

    // VENDOR CATEGORIES
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_vendor_categories (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name       VARCHAR(150)    NOT NULL,
        PRIMARY KEY (id)
    ) $c;");

    // VENDORS - upgraded
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_vendors (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name        VARCHAR(150)    NOT NULL,
        phone       VARCHAR(30)     NOT NULL DEFAULT '',
        phone2      VARCHAR(30)     NOT NULL DEFAULT '',
        email       VARCHAR(150)    NOT NULL DEFAULT '',
        company     VARCHAR(150)    NOT NULL DEFAULT '',
        category_id BIGINT UNSIGNED DEFAULT NULL,
        address     TEXT            NOT NULL DEFAULT '',
        is_active   TINYINT(1)      NOT NULL DEFAULT 1,
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $c;");

    // VENDOR ACTIVITY LOG
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_vendor_logs (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id      BIGINT UNSIGNED NOT NULL,
        vendor_id    BIGINT UNSIGNED NOT NULL,
        message_sent TEXT            NOT NULL DEFAULT '',
        response     TEXT            NOT NULL DEFAULT '',
        status       ENUM('sent','responded','pending') NOT NULL DEFAULT 'pending',
        created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY lead_id (lead_id),
        KEY vendor_id (vendor_id)
    ) $c;");

    // ALERTS (automation-generated)
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_alerts (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id    BIGINT UNSIGNED NOT NULL,
        type       VARCHAR(100)    NOT NULL,
        priority   ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
        message    TEXT            NOT NULL,
        is_sent    TINYINT(1)      NOT NULL DEFAULT 0,
        is_read    TINYINT(1)      NOT NULL DEFAULT 0,
        created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY lead_id (lead_id),
        KEY is_read (is_read),
        KEY priority (priority)
    ) $c;");

    // REMARKS
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_remarks (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        category   VARCHAR(100)    NOT NULL DEFAULT 'General',
        remark     TEXT            NOT NULL,
        sort_order INT             NOT NULL DEFAULT 0,
        is_active  TINYINT(1)      NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        KEY category (category)
    ) $c;");

    // WORK TYPES
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_work_types (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name       VARCHAR(150)    NOT NULL,
        sort_order INT             NOT NULL DEFAULT 0,
        is_active  TINYINT(1)      NOT NULL DEFAULT 1,
        PRIMARY KEY (id)
    ) $c;");

    // LEAD SOURCES
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_lead_sources (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name       VARCHAR(150)    NOT NULL,
        sort_order INT             NOT NULL DEFAULT 0,
        is_active  TINYINT(1)      NOT NULL DEFAULT 1,
        PRIMARY KEY (id)
    ) $c;");

    // ACTIVITY LOG
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_activity (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id    BIGINT UNSIGNED NOT NULL,
        user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
        type       VARCHAR(50)     NOT NULL DEFAULT 'note',
        message    TEXT            NOT NULL,
        created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY lead_id (lead_id)
    ) $c;");

    // CUSTOM FIELDS
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_fields (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        field_key    VARCHAR(100)    NOT NULL,
        field_label  VARCHAR(150)    NOT NULL,
        field_type   VARCHAR(30)     NOT NULL DEFAULT 'text',
        field_opts   TEXT            NOT NULL DEFAULT '',
        tab_name     VARCHAR(50)     NOT NULL DEFAULT 'basic',
        is_required  TINYINT(1)      NOT NULL DEFAULT 0,
        is_active    TINYINT(1)      NOT NULL DEFAULT 1,
        show_in_list TINYINT(1)      NOT NULL DEFAULT 0,
        sort_order   INT             NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY field_key (field_key)
    ) $c;");

    // REMINDERS
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_reminders (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id      BIGINT UNSIGNED NOT NULL,
        remind_at    DATETIME        NOT NULL,
        note         TEXT            NOT NULL DEFAULT '',
        sent         TINYINT(1)      NOT NULL DEFAULT 0,
        created_by   BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY lead_id (lead_id),
        KEY remind_at (remind_at)
    ) $c;");

    // CONVERSATIONS
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_conversations (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id      BIGINT UNSIGNED NOT NULL,
        user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
        user_name    VARCHAR(200)    NOT NULL DEFAULT '',
        user_role    VARCHAR(50)     NOT NULL DEFAULT 'Staff',
        direction    ENUM('staff','client','system') NOT NULL DEFAULT 'staff',
        message      TEXT            NOT NULL,
        created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY lead_id (lead_id),
        KEY created_at (created_at)
    ) $c;");

    // LEAD TAGS
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_tags (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name       VARCHAR(80)     NOT NULL,
        color      VARCHAR(7)      NOT NULL DEFAULT '#6366f1',
        created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name (name)
    ) $c;");
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_lead_tags (
        lead_id BIGINT UNSIGNED NOT NULL,
        tag_id  BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (lead_id, tag_id),
        KEY tag_id (tag_id)
    ) $c;");

    // LEAD ASSIGNMENT HISTORY
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_lead_assignments (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id       BIGINT UNSIGNED NOT NULL,
        action        ENUM('assigned','reassigned','revoked','taken','transferred') NOT NULL DEFAULT 'assigned',
        from_user_id  BIGINT UNSIGNED DEFAULT NULL,
        to_user_id    BIGINT UNSIGNED DEFAULT NULL,
        done_by       BIGINT UNSIGNED NOT NULL DEFAULT 0,
        note          VARCHAR(255)    NOT NULL DEFAULT '',
        created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY lead_id (lead_id),
        KEY created_at (created_at)
    ) $c;");

    // Run column migration for existing installs
    lcrm_migrate_columns();
    lcrm_migrate_final_remarks();
    update_option( 'lcrm_db_version', LCRM_VERSION );
}

function lcrm_migrate_final_remarks() {
    global $wpdb;
    $ct = $wpdb->prefix . 'lcrm_conversations';
    if ( $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $ct) ) !== $ct ) return;
    $leads = $wpdb->get_results("SELECT id,final_remarks,created_at FROM {$wpdb->prefix}lcrm_leads WHERE final_remarks IS NOT NULL AND final_remarks != ''");
    foreach ( $leads as $lead ) {
        $already = $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $ct WHERE lead_id=%d AND direction='system' AND message=%s", $lead->id, $lead->final_remarks) );
        if ( !$already ) {
            $wpdb->insert($ct, ['lead_id'=>$lead->id,'user_id'=>0,'user_name'=>'System','user_role'=>'Admin','direction'=>'system','message'=>$lead->final_remarks,'created_at'=>$lead->created_at]);
        }
    }
}

function lcrm_migrate_columns() {
    global $wpdb;
    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}lcrm_leads", 0);
    if ( empty($cols) ) return;
    $add = [];
    if ( ! in_array('brand_id',              $cols) ) $add[] = "ADD COLUMN brand_id BIGINT UNSIGNED DEFAULT NULL";
    if ( ! in_array('client_followup_date',  $cols) ) $add[] = "ADD COLUMN client_followup_date DATE DEFAULT NULL";
    if ( ! in_array('vendor_followup_date',  $cols) ) $add[] = "ADD COLUMN vendor_followup_date DATE DEFAULT NULL";
    if ( ! in_array('priority',              $cols) ) $add[] = "ADD COLUMN priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium'";
    if ( ! in_array('lead_temperature',      $cols) ) $add[] = "ADD COLUMN lead_temperature ENUM('cold','warm','hot') NOT NULL DEFAULT 'warm'";
    if ( ! in_array('work_started',          $cols) ) $add[] = "ADD COLUMN work_started TINYINT(1) NOT NULL DEFAULT 0";
    if ( ! in_array('next_action_type',      $cols) ) $add[] = "ADD COLUMN next_action_type VARCHAR(100) NOT NULL DEFAULT ''";
    if ( ! in_array('last_vendor_contact',   $cols) ) $add[] = "ADD COLUMN last_vendor_contact DATETIME DEFAULT NULL";
    if ( ! in_array('last_client_contact',   $cols) ) $add[] = "ADD COLUMN last_client_contact DATETIME DEFAULT NULL";
    if ( ! in_array('custom_fields', $cols) ) $add[] = "ADD COLUMN custom_fields LONGTEXT NOT NULL DEFAULT '{}'";
    if ( ! in_array('assigned_to',        $cols) ) $add[] = "ADD COLUMN assigned_to BIGINT UNSIGNED DEFAULT NULL";
    if ( ! in_array('is_access_revoked',  $cols) ) $add[] = "ADD COLUMN is_access_revoked TINYINT(1) NOT NULL DEFAULT 0";
    if ( ! in_array('work_status',        $cols) ) $add[] = "ADD COLUMN work_status VARCHAR(30) NOT NULL DEFAULT 'Inquiry'";
    if ( ! empty($add) ) $wpdb->query("ALTER TABLE {$wpdb->prefix}lcrm_leads " . implode(', ', $add));

    // Fields table columns
    $fcols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}lcrm_fields", 0);
    if ( ! empty($fcols) && ! in_array('tab_name', $fcols) ) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}lcrm_fields ADD COLUMN tab_name VARCHAR(50) NOT NULL DEFAULT 'basic' AFTER field_opts");
    }

    // Vendor columns
    $vcols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}lcrm_vendors", 0);
    if ( ! empty($vcols) ) {
        $vadd = [];
        if ( ! in_array('category_id', $vcols) ) $vadd[] = "ADD COLUMN category_id BIGINT UNSIGNED DEFAULT NULL";
        if ( ! in_array('phone2',      $vcols) ) $vadd[] = "ADD COLUMN phone2 VARCHAR(30) NOT NULL DEFAULT ''";
        if ( ! in_array('address',     $vcols) ) $vadd[] = "ADD COLUMN address TEXT NOT NULL DEFAULT ''";
        if ( ! empty($vadd) ) $wpdb->query("ALTER TABLE {$wpdb->prefix}lcrm_vendors " . implode(', ', $vadd));
    }
}

function lcrm_seed_defaults() {
    global $wpdb;

    $has_stages = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_stages");
    if ( ! $has_stages ) {
        $stages = [
            ['Inquiry','#6366f1',1],['Follow Up','#f59e0b',2],['High Quality Prospect','#10b981',3],
            ['Need to Work on Query','#f97316',4],['Quotation to be Sent','#3b82f6',5],
            ['Quotation Sent','#8b5cf6',6],['Waiting for Docs','#ec4899',7],
            ['Documents Received','#14b8a6',8],['Payment Received','#22c55e',9],
            ['PMT RECVD Work Not Started','#84cc16',10],['Assigned and In Progress','#06b6d4',11],
            ['Work Completed','#10b981',12],['Delivered','#15803d',13],
            ['No Response','#94a3b8',14],['High Charges - Lost','#ef4444',15],
            ['Not Able to Serve','#dc2626',16],['Late Response - Lost','#b91c1c',17],
        ];
        foreach ($stages as $s) $wpdb->insert("{$wpdb->prefix}lcrm_stages",['name'=>$s[0],'color'=>$s[1],'sort_order'=>$s[2],'is_active'=>1]);
    }

    $has_brands = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_brands");
    if ( ! $has_brands ) {
        foreach (['NRI Services','Visa Assistance','Documentation Hub'] as $b)
            $wpdb->insert("{$wpdb->prefix}lcrm_brands",['name'=>$b,'is_active'=>1]);
    }

    $has_vc = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_vendor_categories");
    if ( ! $has_vc ) {
        foreach (['Legal','Documentation','Courier','Translation','Government'] as $vc)
            $wpdb->insert("{$wpdb->prefix}lcrm_vendor_categories",['name'=>$vc]);
    }

    $has_remarks = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_remarks");
    if ( ! $has_remarks ) {
        lcrm_seed_full_remarks();
    } else {
        // On upgrades: add any new remarks not yet in DB
        lcrm_sync_remarks();
    }

    $has_wt = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_work_types");
    if ( ! $has_wt ) {
        foreach (['NRI Documentation','Visa Services','OCI/PIO Services','Legal Services','Property Services','Tax & Finance','Education Docs','Marriage Certificate','Birth Certificate','Police Clearance'] as $i=>$w)
            $wpdb->insert("{$wpdb->prefix}lcrm_work_types",['name'=>$w,'sort_order'=>$i,'is_active'=>1]);
    }

    $has_ls = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_lead_sources");
    if ( ! $has_ls ) {
        foreach (['Website','WhatsApp','Google Ads','Facebook Ads','Instagram','Referral','Walk-in','Phone Inquiry','Email','LinkedIn'] as $i=>$s)
            $wpdb->insert("{$wpdb->prefix}lcrm_lead_sources",['name'=>$s,'sort_order'=>$i,'is_active'=>1]);
    }
}
function lcrm_get_master_remarks() {
    return [
        // --- Completion ---
        ['Completion','Work completed'],
        ['Completion','Final review done'],
        ['Completion','Ready for delivery'],
        ['Completion','Delivered to client'],
        ['Completion','Client confirmed receipt'],
        ['Completion','Client satisfied'],
        // --- Feasibility ---
        ['Feasibility','Checking feasibility'],
        ['Feasibility','Requirement not feasible'],
        ['Feasibility','Not able to serve'],
        ['Feasibility','Wrong / invalid inquiry'],
        ['Feasibility','Invalid case'],
        // --- Follow-up ---
        ['Follow-up','Call not answered'],
        ['Follow-up','Asked to call later'],
        ['Follow-up','Client requested callback later'],
        ['Follow-up','Client busy – follow-up scheduled'],
        ['Follow-up','Follow-up call done'],
        ['Follow-up','Follow-up message sent'],
        ['Follow-up','Reminder sent'],
        ['Follow-up','Multiple follow-ups done'],
        ['Follow-up','Multiple reminders sent'],
        // --- Inquiry ---
        ['Inquiry','Documents required for evaluation'],
        ['Inquiry','Client exploring options'],
        ['Inquiry','Exploring feasibility'],
        ['Inquiry','Requirements being reviewed'],
        ['Inquiry','Client comparing services'],
        ['Inquiry','Budget not discussed yet'],
        // --- Interest Level ---
        ['Interest Level','Client interested but hesitant'],
        ['Interest Level','Client partially interested'],
        ['Interest Level','Client reviewing details'],
        ['Interest Level','Awaiting decision from client'],
        // --- Lost Cases ---
        ['Lost Cases','Client not interested'],
        ['Lost Cases','Client chose competitor'],
        ['Lost Cases','Lost due to high charges'],
        ['Lost Cases','Lost due to late response'],
        // --- No Response ---
        ['No Response','Waiting for client response'],
        ['No Response','No response from client'],
        ['No Response','No response after follow-ups'],
        // --- Payment ---
        ['Payment','Payment details shared'],
        ['Payment','Payment link sent'],
        ['Payment','Awaiting payment'],
        ['Payment','Partial payment received'],
        ['Payment','Full payment received'],
        ['Payment','Payment confirmation pending'],
        ['Payment','Payment delayed'],
        // --- Quotation ---
        ['Quotation','Client asked for quotation'],
        ['Quotation','Quotation being prepared'],
        ['Quotation','Details being finalized'],
        ['Quotation','Waiting for internal confirmation'],
        ['Quotation','Price discussion ongoing'],
        ['Quotation','Budget issue indicated'],
        ['Quotation','High charges concern'],
        ['Quotation','Negotiation in progress'],
        ['Quotation','Quotation sent to client'],
        ['Quotation','Quotation explained to client'],
        ['Quotation','Client reviewing quotation'],
        ['Quotation','Follow-up on quotation done'],
        ['Quotation','Client negotiating price'],
        ['Quotation','Client requested discount'],
        ['Quotation','Client asked for revision'],
        ['Quotation','Client comparing with others'],
        ['Quotation','Quotation rejected'],
        ['Quotation','Price too high for client'],
        ['Quotation','No response after quotation'],
        ['Quotation','Client dropped after quotation'],
        // --- Special Cases ---
        ['Special Cases','Documents/info pending from client side'],
        ['Special Cases','Name Change – Waiting for Signed docs from Client'],
        ['Special Cases','Waiting For The Documents, Discussion Done'],
        // --- Work Processing ---
        ['Work Processing','Work Need to start – On Priority'],
        ['Work Processing','Work initiated, Few Actions Pending!'],
        ['Work Processing','Case assigned to team'],
        ['Work Processing','Processing started'],
        ['Work Processing','Work in progress'],
        ['Work Processing','Internal processing ongoing'],
        ['Work Processing','Waiting for backend update'],
        ['Work Processing','Delay due to external dependency'],
        ['Work Processing','Issue encountered – resolving'],
        ['Work Processing','Work on hold'],
        ['Work Processing','Waiting for client input'],
        ['Work Processing','Pending clarification'],
        ['Work Processing','Delay due to missing docs'],
    ];
}

function lcrm_seed_full_remarks() {
    global $wpdb;
    $i = 0;
    foreach ( lcrm_get_master_remarks() as $r ) {
        $wpdb->insert( "{$wpdb->prefix}lcrm_remarks", [
            'category'   => $r[0],
            'remark'     => $r[1],
            'sort_order' => $i++,
            'is_active'  => 1,
        ]);
    }
}

/**
 * Called on every activation/update: inserts any master remarks not already in DB.
 * Safe to run repeatedly — won't duplicate existing rows.
 */
function lcrm_sync_remarks() {
    global $wpdb;
    $existing = $wpdb->get_col( "SELECT remark FROM {$wpdb->prefix}lcrm_remarks" );
    $max_order = (int) $wpdb->get_var( "SELECT MAX(sort_order) FROM {$wpdb->prefix}lcrm_remarks" );
    foreach ( lcrm_get_master_remarks() as $r ) {
        if ( ! in_array( $r[1], $existing, true ) ) {
            $wpdb->insert( "{$wpdb->prefix}lcrm_remarks", [
                'category'   => $r[0],
                'remark'     => $r[1],
                'sort_order' => ++$max_order,
                'is_active'  => 1,
            ]);
        }
    }
}


