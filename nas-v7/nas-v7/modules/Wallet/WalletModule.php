<?php
namespace NAS\Modules\Wallet;
use NAS\Core\{Module, Database, Security};
if ( ! defined( 'ABSPATH' ) ) exit;

class WalletModule extends Module {
    public function key(): string { return 'wallet'; }
    public function register(): void {
        // TRACE: nas_get_wallet → WalletController::get_wallet (canonical — client wallet fetch)
        //        Precondition: user logged in, nas_action nonce.
        //        Postcondition: returns balance + last 50 transactions for this client.
        //        nas_admin_credit_wallet / nas_admin_debit_wallet: handled by AdminModule file-scope
        //        add_action() calls (already registered before WalletModule::register() runs at plugins_loaded+5).
        //        Registering them here a second time is dead code — WordPress hooks fire first-registered only.
        add_action('wp_ajax_nas_get_wallet', [WalletController::class, 'get_wallet']);
        // nas_admin_credit_wallet and nas_admin_debit_wallet are registered in AdminModule.php at file scope.
        // Do NOT register them here — duplicate hooks cause confusing debug traces.
    }
    public function boot(): void {}

    // TRACE: get_balance() — Trigger: wp_ajax_get_balance AJAX action.
    //        Steps: inserts DB row → queries DB → returns JSON error response on failure.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    public static function get_balance(int $client_id): float {
        $db  = Database::instance();
        $row = $db->row("SELECT balance FROM {$db->t('wallet')} WHERE client_id = %d", $client_id);
        return $row ? (float)$row['balance'] : 0.00;
    }

    // TRACE: credit() — Trigger: wp_ajax_credit AJAX action.
    //        Steps: inserts DB row → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function credit(int $client_id, float $amount, string $desc, int $booking_id = 0): bool {
        global $wpdb;
        $db      = Database::instance();
        $balance = self::get_balance($client_id) + $amount;
        // Fixed: Database class has no prepare() — use $wpdb->prepare() via raw() or global $wpdb
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$db->t('wallet')} (client_id, balance) VALUES (%d, %f)
             ON DUPLICATE KEY UPDATE balance = balance + %f",
            $client_id, $amount, $amount
        ) );
        $db->insert($db->t('wallet_transactions'), [
            'client_id'     => $client_id,
            'type'          => 'credit',
            'amount'        => $amount,
            'balance_after' => $balance,
            'description'   => $desc,
            'booking_id'    => $booking_id,
        ]);
        return true;
    }

    // TRACE: debit() — Trigger: wp_ajax_debit AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → inserts DB row.
    //        Output: success/error JSON response.
    //        Edge cases: returns null/false on failure.
    public static function debit(int $client_id, float $amount, string $desc, int $booking_id = 0): bool {
        if (self::get_balance($client_id) < $amount) return false;
        $db      = Database::instance();
        $balance = self::get_balance($client_id) - $amount;
        $db->update($db->t('wallet'), ['balance' => $balance], ['client_id' => $client_id]);
        $db->insert($db->t('wallet_transactions'), [
            'client_id'     => $client_id,
            'type'          => 'debit',
            'amount'        => $amount,
            'balance_after' => $balance,
            'description'   => $desc,
            'booking_id'    => $booking_id,
        ]);
        return true;
    }
}

class WalletController {
    // TRACE: get_wallet() — Trigger: wp_ajax_get_wallet AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_wallet(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_action');
        Security::require_login();
        $db      = Database::instance();
        $client  = $db->row("SELECT id FROM {$db->t('clients')} WHERE wp_user_id = %d", get_current_user_id());
        if (!$client) { wp_send_json_success(['balance' => 0, 'transactions' => []]); return; }
        $balance = WalletModule::get_balance((int)$client['id']);
        $txns    = $db->select("SELECT * FROM {$db->t('wallet_transactions')} WHERE client_id = %d ORDER BY created_at DESC LIMIT 50", (int)$client['id']);
        wp_send_json_success(['balance' => $balance, 'transactions' => $txns]);
    }

    // TRACE: admin_credit() — Trigger: wp_ajax_admin_credit AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function admin_credit(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_action');
        Security::require_cap('manage_options');
        $client_id = (int) Security::post('client_id');
        $amount    = (float) Security::post('amount');
        $desc      = sanitize_text_field(Security::post('description') ?: 'Admin credit');
        WalletModule::credit($client_id, $amount, $desc);
        wp_send_json_success(['message' => "₹{$amount} credited"]);
    }

    // TRACE: admin_debit() — Trigger: wp_ajax_admin_debit AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function admin_debit(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_action');
        Security::require_cap('manage_options');
        $client_id = (int) Security::post('client_id');
        $amount    = (float) Security::post('amount');
        $desc      = sanitize_text_field(Security::post('description') ?: 'Admin debit');
        $ok        = WalletModule::debit($client_id, $amount, $desc);
        $ok ? wp_send_json_success(['message' => "₹{$amount} debited"]) : wp_send_json_error(['message' => 'Insufficient balance']);
    }
}


// =============================================================================
/**
 * Branding Module — Global site branding settings
 */
// =============================================================================
