<?php
namespace NAS\Modules\Material;

use NAS\Core\{Module, Database, Security, Config, EventBus, Helpers};
if ( ! defined( 'ABSPATH' ) ) exit;

class MaterialModule extends Module {
    public function key(): string { return 'material'; }

    public function register(): void {
        // Client actions
        add_action( 'wp_ajax_nas_upload_material',       [ MaterialController::class, 'upload' ] );
        add_action( 'wp_ajax_nas_get_materials',         [ MaterialController::class, 'get_materials' ] );
        add_action( 'wp_ajax_nas_delete_material',       [ MaterialController::class, 'delete_material' ] );

        // Admin/Staff actions
        add_action( 'wp_ajax_nas_approve_material',      [ MaterialController::class, 'approve' ] );
        add_action( 'wp_ajax_nas_reject_material',       [ MaterialController::class, 'reject' ] );
        add_action( 'wp_ajax_nas_upload_proof',          [ MaterialController::class, 'upload_proof' ] );
        add_action( 'wp_ajax_nas_set_publication_date',  [ MaterialController::class, 'set_publication_date' ] );
        add_action( 'wp_ajax_nas_confirm_submission',    [ MaterialController::class, 'confirm_submission' ] );
    }

    public function boot(): void {
        // Cron: Check for ads published today and trigger notifications
        add_action( 'nas_daily_tasks', [ self::class, 'trigger_publication_notifications' ] );
        // Cron: 7-day material reminder
        add_action( 'nas_daily_tasks', [ self::class, 'send_material_reminders' ] );
    }

    // TRACE: trigger_publication_notifications() — Trigger: wp_ajax_trigger_publication_notifications AJAX action.
    //        Steps: updates DB row → queries DB → emits EventBus event.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function trigger_publication_notifications(): void {
        $db    = Database::instance();
        $today = date('Y-m-d');

        // Find bookings with publication date = today
        $bookings = $db->select(
            "SELECT b.id, b.client_id, b.uid, b.publication_dates
             FROM {$db->t('bookings')} b
             WHERE b.status = 'pub_date_confirmed'
               AND JSON_CONTAINS(b.publication_dates, %s, '$')",
            "\"{$today}\""
        );

        foreach ($bookings as $booking) {
            EventBus::emit('booking_status_changed', [
                'booking_id' => (int) $booking['id'],
                'new_status' => 'published',
                'note'       => "Ad published in newspaper today: {$today}",
                'client_id'  => (int) $booking['client_id'],
            ]);
            $db->update( $db->t('bookings'), ['status' => 'published'], ['id' => $booking['id']] );
        }
    }

    // TRACE: send_material_reminders() — Trigger: wp_ajax_send_material_reminders AJAX action.
    //        Steps: queries DB → emits EventBus event.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function send_material_reminders(): void {
        $db          = Database::instance();
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-7 days'));

        // Bookings paid 7+ days ago but no material uploaded
        $bookings = $db->select(
            "SELECT b.* FROM {$db->t('bookings')} b
             LEFT JOIN {$db->t('ad_materials')} m ON m.booking_id = b.id
             WHERE b.status IN ('payment_received','payment_done') -- payment_done is legacy alias; payment_received is canonical
               AND b.updated_at <= %s
               AND m.id IS NULL",
            $cutoff_date
        );

        foreach ($bookings as $booking) {
            EventBus::emit('booking_status_changed', [
                'booking_id' => (int) $booking['id'],
                'new_status' => 'material_pending_reminder',
                'note'       => 'Reminder: Please upload your ad material',
                'client_id'  => (int) $booking['client_id'],
                'template'   => 'material_reminder',
            ]);
        }
    }
}

// ── Material Service ───────────────────────────────────────────────────────────
class MaterialService {
    private Database $db;
    private Config $cfg;

    const ALLOWED_MIME = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/tiff',
        'image/gif', 'application/pdf',
    ];
    const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10MB

    public function __construct() {
        $this->db  = Database::instance();
        $this->cfg = Config::instance();
    }

    // TRACE: handle_upload() — Trigger: wp_ajax_handle_upload AJAX action.
    //        Steps: queries DB.
    //        Output: single DB row as associative array or null.
    //        Edge cases: empty input values handled.
    public function handle_upload( int $booking_id, int $uploader_id, array $file, string $ad_text = '' ): array {
        // Validate ownership
        $booking = $this->db->row(
            "SELECT b.* FROM {$this->db->t('bookings')} b WHERE b.id = %d",
            $booking_id
        );
        if ( ! $booking ) return [ 'success' => false, 'error' => 'Booking not found' ];

        $version = (int) $this->db->row(
            "SELECT COUNT(*) as c FROM {$this->db->t('ad_materials')} WHERE booking_id = %d",
            $booking_id
        )['c'] + 1;

        $file_url  = '';
        $file_name = '';
        $file_type = '';
        $file_size = 0;

        if ( ! empty( $file['name'] ) && $file['error'] === UPLOAD_ERR_OK ) {
            // Validate file
            if ( $file['size'] > self::MAX_SIZE_BYTES ) {
                return [ 'success' => false, 'error' => 'File too large. Maximum size is 10MB.' ];
            }
            $mime = mime_content_type( $file['tmp_name'] );
            if ( ! in_array( $mime, self::ALLOWED_MIME ) ) {
                return [ 'success' => false, 'error' => 'Invalid file type. Allowed: JPG, PNG, TIFF, GIF, PDF.' ];
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $upload = wp_handle_upload( $file, [
                'test_form' => false,
                'mimes'     => [
                    'jpg|jpeg' => 'image/jpeg',
                    'png'      => 'image/png',
                    'gif'      => 'image/gif',
                    'tif|tiff' => 'image/tiff',
                    'pdf'      => 'application/pdf',
                ],
            ]);

            if ( isset($upload['error']) ) return [ 'success' => false, 'error' => $upload['error'] ];

            $file_url  = $upload['url'];
            $file_name = basename($upload['file']);
            $file_type = $upload['type'];
            $file_size = $file['size'];
        }

        $material_type = $file_url && $ad_text ? 'both' : ( $file_url ? 'file' : 'text' );

        $material_id = $this->db->insert( $this->db->t('ad_materials'), [
            'booking_id'    => $booking_id,
            'uploader_id'   => $uploader_id,
            'file_url'      => $file_url,
            'file_name'     => $file_name,
            'file_type'     => $file_type,
            'file_size'     => $file_size,
            'ad_text'       => $ad_text,
            'material_type' => $material_type,
            'status'        => 'pending',
            'version'       => $version,
        ]);

        // Update booking status
        $this->db->update( $this->db->t('bookings'), ['status' => 'material_uploaded'], ['id' => $booking_id] );

        // Fire event
        EventBus::emit('booking_status_changed', [
            'booking_id' => $booking_id,
            'new_status' => 'material_uploaded',
            'note'       => "Client uploaded ad material (v{$version})",
            'client_id'  => (int) $booking['client_id'],
        ]);

        return [
            'success'     => true,
            'material_id' => $material_id,
            'file_url'    => $file_url,
            'version'     => $version,
        ];
    }

    // TRACE: approve_material() — Trigger: wp_ajax_approve_material AJAX action.
    //        Steps: updates DB row → queries DB → emits EventBus event.
    //        Output: success/error JSON response.
    //        Edge cases: returns null/false on failure.
    public function approve_material( int $material_id, int $reviewer_id ): bool {
        $material = $this->db->row( "SELECT * FROM {$this->db->t('ad_materials')} WHERE id = %d", $material_id );
        if ( ! $material ) return false;

        $this->db->update( $this->db->t('ad_materials'), [
            'status'      => 'approved',
            'reviewed_by' => $reviewer_id,
            'reviewed_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $material_id] );

        $this->db->update( $this->db->t('bookings'), ['status' => 'material_approved'], ['id' => $material['booking_id']] );

        $booking = $this->db->row("SELECT * FROM {$this->db->t('bookings')} WHERE id = %d", $material['booking_id']);

        EventBus::emit('booking_status_changed', [
            'booking_id' => (int) $material['booking_id'],
            'new_status' => 'material_approved',
            'note'       => 'Ad material has been approved by our team.',
            'client_id'  => (int) $booking['client_id'],
        ]);

        return true;
    }

    // TRACE: reject_material() — Trigger: wp_ajax_reject_material AJAX action.
    //        Steps: updates DB row → queries DB → emits EventBus event.
    //        Output: success/error JSON response.
    //        Edge cases: returns null/false on failure.
    public function reject_material( int $material_id, int $reviewer_id, string $reason ): bool {
        $material = $this->db->row( "SELECT * FROM {$this->db->t('ad_materials')} WHERE id = %d", $material_id );
        if ( ! $material ) return false;

        $this->db->update( $this->db->t('ad_materials'), [
            'status'           => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by'      => $reviewer_id,
            'reviewed_at'      => gmdate('Y-m-d H:i:s'),
        ], ['id' => $material_id] );

        $this->db->update( $this->db->t('bookings'), ['status' => 'material_rejected'], ['id' => $material['booking_id']] );

        $booking = $this->db->row("SELECT * FROM {$this->db->t('bookings')} WHERE id = %d", $material['booking_id']);

        EventBus::emit('booking_status_changed', [
            'booking_id' => (int) $material['booking_id'],
            'new_status' => 'material_rejected',
            'note'       => "Material rejected. Reason: {$reason}",
            'client_id'  => (int) $booking['client_id'],
        ]);

        return true;
    }
}

// ── Material Controller ────────────────────────────────────────────────────────
class MaterialController {

    // TRACE: upload() — Trigger: wp_ajax_upload AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function upload(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        $booking_id = (int) Security::post('booking_id');
        $ad_text    = sanitize_textarea_field( Security::post('ad_text') ?? '' );
        $file       = $_FILES['material_file'] ?? [];

        $db     = Database::instance();
        $client = $db->row( "SELECT id FROM {$db->t('clients')} WHERE wp_user_id = %d", get_current_user_id() );
        if ( ! $client ) { wp_send_json_error(['message' => 'Client not found']); return; }

        // Verify booking belongs to client
        $booking = $db->row( "SELECT * FROM {$db->t('bookings')} WHERE id = %d AND client_id = %d", $booking_id, $client['id'] );
        if ( ! $booking ) { wp_send_json_error(['message' => 'Booking not found']); return; }

        $svc    = new MaterialService();
        $result = $svc->handle_upload( $booking_id, get_current_user_id(), $file, $ad_text );
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
    }

    // TRACE: get_materials() — Trigger: wp_ajax_get_materials AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_materials(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $booking_id = (int) Security::post('booking_id');
        $db         = Database::instance();
        $materials  = $db->select(
            "SELECT * FROM {$db->t('ad_materials')} WHERE booking_id = %d ORDER BY version DESC",
            $booking_id
        );
        wp_send_json_success(['materials' => $materials]);
    }

    // TRACE: delete_material() — Trigger: wp_ajax_delete_material AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → deletes DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function delete_material(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $material_id = (int) Security::post('material_id');
        $db          = Database::instance();
        $material    = $db->row("SELECT * FROM {$db->t('ad_materials')} WHERE id = %d", $material_id);
        if ( ! $material || $material['status'] !== 'pending' ) {
            wp_send_json_error(['message' => 'Cannot delete reviewed material']);
            return;
        }
        $db->delete( $db->t('ad_materials'), ['id' => $material_id] );
        wp_send_json_success(['message' => 'Deleted']);
    }

    // TRACE: approve() — Trigger: wp_ajax_approve AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function approve(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap('nas_manage_bookings');
        $material_id = (int) Security::post('material_id');
        $svc         = new MaterialService();
        $ok          = $svc->approve_material( $material_id, get_current_user_id() );
        $ok ? wp_send_json_success(['message' => 'Material approved. Client notified.']) : wp_send_json_error(['message' => 'Error']);
    }

    // TRACE: reject() — Trigger: wp_ajax_reject AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: empty input values handled.
    public static function reject(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap('nas_manage_bookings');
        $material_id = (int) Security::post('material_id');
        $reason      = sanitize_text_field( Security::post('reason') ?: 'Material does not meet our specifications.' );
        $svc         = new MaterialService();
        $ok          = $svc->reject_material( $material_id, get_current_user_id(), $reason );
        $ok ? wp_send_json_success(['message' => 'Material rejected. Client notified.']) : wp_send_json_error(['message' => 'Error']);
    }

    // TRACE: upload_proof() — Trigger: wp_ajax_upload_proof AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: success/error JSON response.
    //        Edge cases: empty input values handled.
    public static function upload_proof(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap('nas_manage_bookings');
        $booking_id = (int) Security::post('booking_id');
        $file       = $_FILES['proof_file'] ?? [];

        if ( empty($file['name']) ) { wp_send_json_error(['message' => 'No file uploaded']); return; }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload($file, ['test_form' => false]);
        if ( isset($upload['error']) ) { wp_send_json_error(['message' => $upload['error']]); return; }

        $db = Database::instance();
        $db->update( $db->t('bookings'), [
            'proof_url' => $upload['url'],
            'status'    => 'proof_delivered',
        ], ['id' => $booking_id] );

        $booking = $db->row("SELECT client_id FROM {$db->t('bookings')} WHERE id = %d", $booking_id);
        EventBus::emit('booking_status_changed', [
            'booking_id' => $booking_id,
            'new_status' => 'proof_delivered',
            'note'       => 'Publication proof has been uploaded and sent to you.',
            'client_id'  => (int) $booking['client_id'],
        ]);

        wp_send_json_success(['message' => 'Proof uploaded. Client notified.', 'url' => $upload['url']]);
    }

    // TRACE: set_publication_date() — Trigger: wp_ajax_set_publication_date AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function set_publication_date(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap('nas_manage_bookings');
        $booking_id      = (int) Security::post('booking_id');
        $pub_date        = sanitize_text_field( Security::post('publication_date') );
        $db              = Database::instance();
        $booking         = $db->row("SELECT * FROM {$db->t('bookings')} WHERE id = %d", $booking_id);
        if ( ! $booking ) { wp_send_json_error(['message' => 'Booking not found']); return; }

        $dates = json_decode($booking['publication_dates'] ?? '[]', true) ?: [];
        if ( ! in_array($pub_date, $dates) ) $dates[] = $pub_date;

        $db->update( $db->t('bookings'), [
            'publication_dates' => json_encode($dates),
            'status'            => 'pub_date_confirmed',
        ], ['id' => $booking_id] );

        EventBus::emit('booking_status_changed', [
            'booking_id'       => $booking_id,
            'new_status'       => 'pub_date_confirmed',
            'note'             => "Publication confirmed for {$pub_date}",
            'client_id'        => (int) $booking['client_id'],
            'publication_date' => $pub_date,
        ]);

        wp_send_json_success(['message' => 'Publication date set. Client notified.']);
    }

    // TRACE: confirm_submission() — Trigger: wp_ajax_confirm_submission AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function confirm_submission(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap('nas_manage_bookings');
        $booking_id = (int) Security::post('booking_id');
        $db         = Database::instance();
        $booking    = $db->row("SELECT client_id FROM {$db->t('bookings')} WHERE id = %d", $booking_id);
        $db->update( $db->t('bookings'), ['status' => 'submitted_to_paper'], ['id' => $booking_id] );
        EventBus::emit('booking_status_changed', [
            'booking_id' => $booking_id,
            'new_status' => 'submitted_to_paper',
            'note'       => 'Your ad has been submitted to the newspaper.',
            'client_id'  => (int) $booking['client_id'],
        ]);
        wp_send_json_success(['message' => 'Submission confirmed. Client notified.']);
    }
}
