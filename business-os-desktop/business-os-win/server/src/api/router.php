<?php
/**
 * Business OS Desktop — API Router
 * Dispatches /bos/v1/{resource}/{id}/{action} to the right controller method.
 * No framework dependency — pure PHP routing for maximum speed.
 */

// Load all controllers
foreach (glob(__DIR__ . '/*.php') as $file) {
    if (basename($file) === 'router.php') continue;
    require_once $file;
}

// Parse the route: strip /bos/v1 prefix
$full_path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$route = preg_replace('#^/bos/v1#', '', $full_path); // e.g. /clients or /invoices/uuid/send
$route = rtrim($route, '/') ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD']);

// Route matching helper
function bos_route(string $m, string $pattern, callable $handler): void {
    global $route, $method, $_bos_route_matched;
    if ($_bos_route_matched ?? false) return;
    if ($method !== $m) return;
    if (preg_match('#^' . $pattern . '$#', $route, $matches)) {
        $_bos_route_matched = true;
        // Named captures become route params
        foreach ($matches as $k => $v) {
            if (!is_int($k)) $_GET[$k] = $v;
        }
        $handler($matches);
    }
}

// Auth routes
bos_route('POST', '/auth/login',           fn() => BOS_AuthController::login());
bos_route('GET',  '/auth/health',          fn() => BOS_AuthController::health());
bos_route('POST', '/auth/logout',          fn() => BOS_AuthController::logout());
bos_route('POST', '/auth/refresh',         fn() => BOS_AuthController::refresh());
bos_route('GET',  '/auth/me',              fn() => BOS_AuthController::me());
bos_route('POST', '/auth/change-password', fn() => BOS_AuthController::change_password());
bos_route('POST', '/auth/reset-request',   fn() => BOS_AuthController::reset_request());
bos_route('POST', '/auth/reset-confirm',   fn() => BOS_AuthController::reset_confirm());

// Settings
bos_route('GET',  '/settings',             fn() => BOS_SettingsController::index());
bos_route('POST', '/settings',             fn() => BOS_SettingsController::save());
bos_route('POST', '/settings/smtp-test',   fn() => BOS_SettingsController::smtp_test());

// Brands
bos_route('GET',  '/brands',               fn() => BOS_BrandController::index());
bos_route('POST', '/brands',               fn() => BOS_BrandController::create());
bos_route('PUT',  '/brands/(?P<uuid>[^/]+)',               fn() => BOS_BrandController::update());
bos_route('DELETE', '/brands/(?P<uuid>[^/]+)',             fn() => BOS_BrandController::delete());
bos_route('POST', '/brands/(?P<uuid>[^/]+)/set-primary',   fn() => BOS_BrandController::set_primary());

// Users
bos_route('GET',  '/users',               fn() => BOS_UserController::index());
bos_route('POST', '/users',               fn() => BOS_UserController::create());
bos_route('PUT',  '/users/(?P<uuid>[^/]+)',               fn() => BOS_UserController::update());
bos_route('DELETE', '/users/(?P<uuid>[^/]+)',             fn() => BOS_UserController::delete());
bos_route('POST', '/users/(?P<uuid>[^/]+)/reset-password',fn() => BOS_UserController::reset_password());
bos_route('POST', '/users/(?P<uuid>[^/]+)/terminate-sessions',fn() => BOS_UserController::terminate_sessions());

// Categories
bos_route('GET',  '/categories',               fn() => BOS_CategoryController::index());
bos_route('POST', '/categories',               fn() => BOS_CategoryController::create());
bos_route('PUT',  '/categories/(?P<uuid>[^/]+)', fn() => BOS_CategoryController::update());
bos_route('DELETE', '/categories/(?P<uuid>[^/]+)', fn() => BOS_CategoryController::delete());

// Bank Accounts
bos_route('GET',  '/bank-accounts',               fn() => BOS_BankAccountController::index());
bos_route('POST', '/bank-accounts',               fn() => BOS_BankAccountController::create());
bos_route('PUT',  '/bank-accounts/(?P<uuid>[^/]+)', fn() => BOS_BankAccountController::update());
bos_route('DELETE', '/bank-accounts/(?P<uuid>[^/]+)', fn() => BOS_BankAccountController::delete());

// Clients
bos_route('GET',  '/clients',               fn() => BOS_ClientController::index());
bos_route('GET',  '/clients/(?P<uuid>[^/]+)', fn() => BOS_ClientController::show());
bos_route('POST', '/clients',               fn() => BOS_ClientController::create());
bos_route('PUT',  '/clients/(?P<uuid>[^/]+)', fn() => BOS_ClientController::update());
bos_route('DELETE', '/clients/(?P<uuid>[^/]+)', fn() => BOS_ClientController::delete());
bos_route('POST', '/clients/merge',          fn() => BOS_ClientController::merge());
bos_route('GET',  '/clients/(?P<uuid>[^/]+)/financial-summary', fn() => BOS_ClientController::financial_summary());

// Vendors
bos_route('GET',  '/vendors',               fn() => BOS_VendorController::index());
bos_route('GET',  '/vendors/(?P<uuid>[^/]+)', fn() => BOS_VendorController::show());
bos_route('POST', '/vendors',               fn() => BOS_VendorController::create());
bos_route('PUT',  '/vendors/(?P<uuid>[^/]+)', fn() => BOS_VendorController::update());
bos_route('DELETE', '/vendors/(?P<uuid>[^/]+)', fn() => BOS_VendorController::delete());

// Services
bos_route('GET',  '/services',               fn() => BOS_ServiceController::index());
bos_route('GET',  '/services/(?P<uuid>[^/]+)', fn() => BOS_ServiceController::show());
bos_route('POST', '/services',               fn() => BOS_ServiceController::create());
bos_route('PUT',  '/services/(?P<uuid>[^/]+)', fn() => BOS_ServiceController::update());
bos_route('DELETE', '/services/(?P<uuid>[^/]+)', fn() => BOS_ServiceController::delete());

// Service Rates
bos_route('GET',  '/service-rates/resolve',  fn() => BOS_ServiceRateController::resolve());
bos_route('POST', '/service-rates',          fn() => BOS_ServiceRateController::create());
bos_route('DELETE', '/service-rates/(?P<id>[^/]+)', fn() => BOS_ServiceRateController::delete());

// Service Presets
bos_route('GET',  '/service-presets',               fn() => BOS_ServicePresetController::index());
bos_route('POST', '/service-presets',               fn() => BOS_ServicePresetController::create());
bos_route('PUT',  '/service-presets/(?P<uuid>[^/]+)', fn() => BOS_ServicePresetController::update());
bos_route('DELETE', '/service-presets/(?P<uuid>[^/]+)', fn() => BOS_ServicePresetController::delete());
bos_route('POST', '/service-presets/reorder',        fn() => BOS_ServicePresetController::reorder());

// Invoices
bos_route('GET',  '/invoices',                fn() => BOS_InvoiceController::index());
bos_route('GET',  '/invoices/(?P<uuid>[^/]+)', fn() => BOS_InvoiceController::show());
bos_route('POST', '/invoices',                fn() => BOS_InvoiceController::create());
bos_route('PUT',  '/invoices/(?P<uuid>[^/]+)', fn() => BOS_InvoiceController::update());
bos_route('DELETE', '/invoices/(?P<uuid>[^/]+)', fn() => BOS_InvoiceController::delete());
bos_route('POST', '/invoices/(?P<uuid>[^/]+)/send',       fn() => BOS_InvoiceController::send_email());
bos_route('POST', '/invoices/(?P<uuid>[^/]+)/mark-sent',  fn() => BOS_InvoiceController::mark_sent());
bos_route('POST', '/invoices/(?P<uuid>[^/]+)/cancel',     fn() => BOS_InvoiceController::cancel());
bos_route('POST', '/invoices/(?P<uuid>[^/]+)/clone',      fn() => BOS_InvoiceController::clone_invoice());
bos_route('POST', '/invoices/(?P<uuid>[^/]+)/share-token',fn() => BOS_InvoiceController::share_token());

// Invoice Payments (split payments per invoice)
bos_route('GET',  '/invoices/(?P<inv_uuid>[^/]+)/payments',              fn() => BOS_InvoicePaymentController::index());
bos_route('POST', '/invoices/(?P<inv_uuid>[^/]+)/payments',              fn() => BOS_InvoicePaymentController::create());
bos_route('PUT',  '/invoices/(?P<inv_uuid>[^/]+)/payments/(?P<uuid>[^/]+)', fn() => BOS_InvoicePaymentController::update());
bos_route('DELETE', '/invoices/(?P<inv_uuid>[^/]+)/payments/(?P<uuid>[^/]+)', fn() => BOS_InvoicePaymentController::delete());

// Payments (standalone)
bos_route('GET',  '/payments',               fn() => BOS_PaymentController::index());
bos_route('GET',  '/payments/(?P<uuid>[^/]+)', fn() => BOS_PaymentController::show());
bos_route('POST', '/payments',               fn() => BOS_PaymentController::create());
bos_route('DELETE', '/payments/(?P<uuid>[^/]+)', fn() => BOS_PaymentController::delete());

// Expenses
bos_route('GET',  '/expenses',               fn() => BOS_ExpenseController::index());
bos_route('GET',  '/expenses/(?P<uuid>[^/]+)', fn() => BOS_ExpenseController::show());
bos_route('POST', '/expenses',               fn() => BOS_ExpenseController::create());
bos_route('PUT',  '/expenses/(?P<uuid>[^/]+)', fn() => BOS_ExpenseController::update());
bos_route('DELETE', '/expenses/(?P<uuid>[^/]+)', fn() => BOS_ExpenseController::delete());

// Quotations
bos_route('GET',  '/quotations',               fn() => BOS_QuotationController::index());
bos_route('GET',  '/quotations/(?P<uuid>[^/]+)', fn() => BOS_QuotationController::show());
bos_route('POST', '/quotations',               fn() => BOS_QuotationController::create());
bos_route('PUT',  '/quotations/(?P<uuid>[^/]+)', fn() => BOS_QuotationController::update());
bos_route('DELETE', '/quotations/(?P<uuid>[^/]+)', fn() => BOS_QuotationController::delete());
bos_route('POST', '/quotations/(?P<uuid>[^/]+)/send',      fn() => BOS_QuotationController::send_email());
bos_route('POST', '/quotations/(?P<uuid>[^/]+)/accept',    fn() => BOS_QuotationController::accept());
bos_route('POST', '/quotations/(?P<uuid>[^/]+)/reject',    fn() => BOS_QuotationController::reject());
bos_route('POST', '/quotations/(?P<uuid>[^/]+)/convert',   fn() => BOS_QuotationController::convert());
bos_route('POST', '/quotations/(?P<uuid>[^/]+)/duplicate', fn() => BOS_QuotationController::duplicate());

// Credit Notes
bos_route('GET',  '/credit-notes',               fn() => BOS_CreditNoteController::index());
bos_route('GET',  '/credit-notes/(?P<uuid>[^/]+)', fn() => BOS_CreditNoteController::show());
bos_route('POST', '/credit-notes',               fn() => BOS_CreditNoteController::create());
bos_route('PUT',  '/credit-notes/(?P<uuid>[^/]+)', fn() => BOS_CreditNoteController::update());
bos_route('DELETE', '/credit-notes/(?P<uuid>[^/]+)', fn() => BOS_CreditNoteController::delete());

// Subscriptions
bos_route('GET',  '/subscriptions',               fn() => BOS_SubscriptionController::index());
bos_route('GET',  '/subscriptions/(?P<uuid>[^/]+)', fn() => BOS_SubscriptionController::show());
bos_route('POST', '/subscriptions',               fn() => BOS_SubscriptionController::create());
bos_route('PUT',  '/subscriptions/(?P<uuid>[^/]+)', fn() => BOS_SubscriptionController::update());
bos_route('DELETE', '/subscriptions/(?P<uuid>[^/]+)', fn() => BOS_SubscriptionController::delete());
bos_route('POST', '/subscriptions/(?P<uuid>[^/]+)/cancel',  fn() => BOS_SubscriptionController::cancel());
bos_route('POST', '/subscriptions/(?P<uuid>[^/]+)/pause',   fn() => BOS_SubscriptionController::pause());
bos_route('POST', '/subscriptions/(?P<uuid>[^/]+)/resume',  fn() => BOS_SubscriptionController::resume());
bos_route('GET',  '/subscription-plans',                    fn() => BOS_SubscriptionController::plans_index());
bos_route('POST', '/subscription-plans',                    fn() => BOS_SubscriptionController::plans_create());
bos_route('PUT',  '/subscription-plans/(?P<uuid>[^/]+)',    fn() => BOS_SubscriptionController::plans_update());
bos_route('DELETE', '/subscription-plans/(?P<uuid>[^/]+)', fn() => BOS_SubscriptionController::plans_delete());

// Time Entries
bos_route('GET',  '/time-entries',               fn() => BOS_TimeEntryController::index());
bos_route('GET',  '/time-entries/unbilled',       fn() => BOS_TimeEntryController::unbilled());
bos_route('GET',  '/time-entries/(?P<uuid>[^/]+)', fn() => BOS_TimeEntryController::show());
bos_route('POST', '/time-entries',               fn() => BOS_TimeEntryController::create());
bos_route('PUT',  '/time-entries/(?P<uuid>[^/]+)', fn() => BOS_TimeEntryController::update());
bos_route('DELETE', '/time-entries/(?P<uuid>[^/]+)', fn() => BOS_TimeEntryController::delete());

// Compliance
bos_route('GET',  '/compliance',               fn() => BOS_ComplianceController::index());
bos_route('POST', '/compliance',               fn() => BOS_ComplianceController::create());
bos_route('PUT',  '/compliance/(?P<uuid>[^/]+)', fn() => BOS_ComplianceController::update());
bos_route('DELETE', '/compliance/(?P<uuid>[^/]+)', fn() => BOS_ComplianceController::delete());

// Documents
bos_route('GET',  '/documents',               fn() => BOS_DocumentController::index());
bos_route('GET',  '/documents/(?P<uuid>[^/]+)', fn() => BOS_DocumentController::show());
bos_route('POST', '/documents',               fn() => BOS_DocumentController::create());
bos_route('PUT',  '/documents/(?P<uuid>[^/]+)', fn() => BOS_DocumentController::update());
bos_route('DELETE', '/documents/(?P<uuid>[^/]+)', fn() => BOS_DocumentController::delete());

// Bank Reconciliation
bos_route('GET',  '/bank-statements',                      fn() => BOS_BankReconController::index());
bos_route('POST', '/bank-statements',                      fn() => BOS_BankReconController::import());
bos_route('POST', '/bank-statements/(?P<uuid>[^/]+)/match',   fn() => BOS_BankReconController::match_entry());
bos_route('POST', '/bank-statements/(?P<uuid>[^/]+)/exclude', fn() => BOS_BankReconController::exclude());
bos_route('GET',  '/bank-reconciliation/summary',           fn() => BOS_BankReconController::summary());
bos_route('POST', '/bank-reconciliation/auto-match',        fn() => BOS_BankReconController::auto_match());

// Notifications
bos_route('GET',  '/notifications',                          fn() => BOS_NotificationController::index());
bos_route('GET',  '/notifications/unread-count',             fn() => BOS_NotificationController::unread_count());
bos_route('POST', '/notifications/(?P<id>[^/]+)/read',       fn() => BOS_NotificationController::mark_read());
bos_route('POST', '/notifications/read-all',                 fn() => BOS_NotificationController::mark_all_read());

// Communication Logs
bos_route('GET',  '/communication-logs',  fn() => BOS_CommLogController::index());
bos_route('POST', '/communication-logs',  fn() => BOS_CommLogController::create());

// Dashboard
bos_route('GET', '/dashboard',        fn() => BOS_DashboardController::index());
bos_route('GET', '/dashboard/charts', fn() => BOS_DashboardController::charts());

// Reports
bos_route('GET', '/reports/profit-loss',      fn() => BOS_ReportController::profit_loss());
bos_route('GET', '/reports/gst-summary',      fn() => BOS_ReportController::gst_summary());
bos_route('GET', '/reports/client-statement', fn() => BOS_ReportController::client_statement());
bos_route('GET', '/reports/expense-summary',  fn() => BOS_ReportController::expense_summary());
bos_route('GET', '/reports/aged-receivables', fn() => BOS_ReportController::aged_receivables());

// Audit Logs
bos_route('GET', '/audit-logs', fn() => BOS_AuditController::index());

// Payment Register
bos_route('GET',  '/payment-register',               fn() => BOS_PaymentRegisterController::index());
bos_route('POST', '/payment-register',               fn() => BOS_PaymentRegisterController::create());
bos_route('PUT',  '/payment-register/(?P<uuid>[^/]+)', fn() => BOS_PaymentRegisterController::update());
bos_route('DELETE', '/payment-register/(?P<uuid>[^/]+)', fn() => BOS_PaymentRegisterController::delete());

// Backup
bos_route('GET',  '/backup/export', fn() => BOS_BackupController::export());
bos_route('POST', '/backup/import', fn() => BOS_BackupController::import());

// Public invoice (no auth required)
bos_route('GET', '/public/invoice/(?P<token>[^/]+)', fn() => BOS_PublicController::invoice());

// 404 fallback
if (!($_bos_route_matched ?? false)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Route not found: ' . $method . ' ' . $route]]);
}
