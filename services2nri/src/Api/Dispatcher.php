<?php
namespace S2NRI\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatcher — routes all /api/v1/* requests to controllers.
 *
 * TRACE: plugins_loaded priority 1 on API requests → parse method+path →
 *        match route → auth check → call controller → JSON output → exit.
 *        Preconditions: $wpdb available.
 *        Postconditions: JSON written, PHP exits.
 *        Edge cases: unknown route → 404, auth fail → 401, exception → 500.
 */
class Dispatcher {

    private array            $routes = [];
    private Middleware\Auth  $auth;

    public function __construct() {
        $this->auth = new Middleware\Auth();
        $this->registerRoutes();
    }

    // ── Route table ───────────────────────────────────────────────────────────

    private function registerRoutes(): void {
        // ── Auth
        $this->add( 'POST', 'auth/auto-register',   Controllers\AuthController::class, 'autoRegister', false );
        $this->add( 'POST', 'auth/send-otp',          Controllers\AuthController::class, 'sendOtp'       );
        $this->add( 'POST', 'auth/verify-otp',         Controllers\AuthController::class, 'verifyOtp'     );
        $this->add( 'POST', 'auth/login',              Controllers\AuthController::class, 'login'         );
        $this->add( 'POST', 'auth/verify-2fa',         Controllers\AuthController::class, 'verify2fa'     );
        $this->add( 'POST', 'auth/toggle-2fa',         Controllers\AuthController::class, 'toggle2fa',    true );
        $this->add( 'POST', 'auth/register',           Controllers\AuthController::class, 'register'      );
        $this->add( 'PUT',  'auth/change-password',    Controllers\AuthController::class, 'changePassword', true );
        $this->add( 'POST', 'auth/logout',             Controllers\AuthController::class, 'logout',   true );
        $this->add( 'GET',  'auth/me',                 Controllers\AuthController::class, 'me',       true );
        $this->add( 'POST', 'auth/forgot-password',    Controllers\AuthController::class, 'forgotPassword' );
        $this->add( 'POST', 'auth/reset-password',     Controllers\AuthController::class, 'resetPassword'  );

        // ── Public data
        // ── Diagnostic quick-run (public, no auth) — for debugging
        // REMOVED (security fix, pre-launch): 'GET debug/diag-run' was
        // registered with requiresAuth=false, contradicting this
        // controller's own class docblock ("All endpoints require
        // admin/staff auth via requireStaff()"). It exposed internal
        // deployment file paths, exact deployed JS bundle filenames, and
        // source-code fingerprinting of which patches are applied — real
        // reconnaissance value for an attacker mapping the deployed
        // version. Its own comment said "Remove this endpoint after
        // confirming the system works" — this is that removal.
        // ── Temporary public debug — remove after diagnosis
        // REMOVED (security fix, pre-launch): 'GET debug/state' was
        // registered with requiresAuth=false — a genuinely unauthenticated
        // endpoint reachable by anyone, dumping real customer emails, WP
        // user emails/registration dates, booking references, session
        // cookie NAMES, raw authenticate() internals, and the full
        // post_content of every published page. Its own comment said
        // "Temporary public debug — remove after diagnosis" — this is
        // that removal. The function definition (SystemController::
        // debugState) is left in place below but is now unreachable via
        // routing; it also now hard-blocks with requireManager() as
        // defense-in-depth in case it is ever accidentally re-registered.

        $this->add( 'GET',  'settings/public',         Controllers\SettingsController::class, 'getPublic'  );
        $this->add( 'GET',  'design/public',           Controllers\DesignSystemController::class, 'getPublic' );
        $this->add( 'GET',  'navigation/public',       Controllers\NavigationController::class, 'getPublic' );
        $this->add( 'GET',  'admin/design',            Controllers\DesignSystemController::class, 'getAdmin', true );
        $this->add( 'PUT',  'admin/design',            Controllers\DesignSystemController::class, 'update', true );
        $this->add( 'POST', 'admin/design/preset',     Controllers\DesignSystemController::class, 'applyPreset', true );
        $this->add( 'GET',  'admin/design/fonts',      Controllers\DesignSystemController::class, 'fonts', true );
        $this->add( 'GET',  'admin/service-registry',  Controllers\ServiceRegistryAdminController::class, 'registry', true );
        $this->add( 'GET',  'admin/services/{id}/visibility-impact', Controllers\ServiceRegistryAdminController::class, 'impact', true );
        $this->add( 'PATCH','admin/services/{id}/visibility', Controllers\ServiceRegistryAdminController::class, 'updateVisibility', true );
        $this->add( 'GET',  'categories',              Controllers\CategoryController::class, 'index'       );
        $this->add( 'GET',  'categories/{slug}',       Controllers\CategoryController::class, 'show'        );
        $this->add( 'GET',  'services',                Controllers\ServiceController::class,  'index'       );
        $this->add( 'GET',  'services/{slug}',         Controllers\ServiceController::class,  'show'        );

        // ── Customer bookings
        $this->add( 'POST', 'bookings',                Controllers\BookingController::class, 'store',   true );
        $this->add( 'GET',  'bookings',                Controllers\BookingController::class, 'myList',  true );
        $this->add( 'GET',  'bookings/{id}',           Controllers\BookingController::class, 'show',    true );
        $this->add( 'POST', 'bookings/{id}/cancel',    Controllers\BookingController::class, 'cancel',  true );
        $this->add( 'POST', 'bookings/{id}/approve-quote', Controllers\BookingController::class, 'approveQuote', true );
        $this->add( 'POST', 'bookings/{id}/reject-quote',  Controllers\BookingController::class, 'rejectQuote',  true );
        $this->add( 'POST', 'bookings/{id}/review',    Controllers\BookingController::class, 'submitReview', true );

        // ── Documents
        $this->add( 'GET',  'bookings/{id}/documents', Controllers\DocumentController::class, 'list',    true );
        $this->add( 'POST', 'bookings/{id}/documents', Controllers\DocumentController::class, 'upload',  true );
        $this->add( 'DELETE','bookings/{id}/documents/{doc_id}', Controllers\DocumentController::class, 'delete', true );

        // ── Messages
        $this->add( 'GET',  'bookings/{id}/messages',  Controllers\MessageController::class, 'list',    true );
        $this->add( 'POST', 'bookings/{id}/messages',  Controllers\MessageController::class, 'send',    true );

        // ── Payments
        $this->add( 'POST', 'bookings/{id}/payment',   Controllers\PaymentController::class, 'submit',  true );
        $this->add( 'POST', 'payments/razorpay/webhook',         Controllers\PaymentController::class, 'razorpayWebhook' );
        $this->add( 'POST', 'bookings/{id}/razorpay-order',      Controllers\PaymentController::class, 'createRazorpayOrder', true );
        $this->add( 'POST', 'bookings/{id}/razorpay-verify',     Controllers\PaymentController::class, 'verifyRazorpayPayment', true );

        // ── Notifications (customer)
        $this->add( 'GET',  'notifications',           Controllers\NotificationController::class, 'index',       true );
        $this->add( 'PATCH','notifications/{id}/read', Controllers\NotificationController::class, 'markRead',    true );
        $this->add( 'PATCH','notifications/read-all',  Controllers\NotificationController::class, 'markAllRead', true );

        // ── Customer profile
        $this->add( 'GET',  'profile',                 Controllers\ProfileController::class, 'show',    true );
        $this->add( 'PUT',  'profile',                 Controllers\ProfileController::class, 'update',  true );
        $this->add( 'PUT',  'profile/password',        Controllers\ProfileController::class, 'changePassword', true );

        // ── Support tickets
        $this->add( 'GET',  'tickets',                 Controllers\TicketController::class, 'index',   true );
        $this->add( 'POST', 'tickets',                 Controllers\TicketController::class, 'store',   true );
        $this->add( 'GET',  'bookings/{id}/tickets',   Controllers\TicketController::class, 'byBooking', true ); // customer portal: tickets linked to a booking
        $this->add( 'POST', 'bookings/{id}/tickets',   Controllers\TicketController::class, 'storeForBooking', true ); // customer portal: create ticket for booking
        $this->add( 'POST', 'tickets/{id}/replies',    Controllers\TicketController::class,  'reply',    true );
        $this->add( 'GET',  'tickets/{id}',            Controllers\TicketController::class, 'show',    true );
        $this->add( 'POST', 'tickets/{id}/messages',   Controllers\TicketController::class, 'sendMessage', true );

        // ── Admin: bookings
        $this->add( 'GET',  'admin/dashboard',         Controllers\Admin\AnalyticsController::class, 'dashboard', true );
        $this->add( 'GET',  'admin/bookings',          Controllers\Admin\BookingAdminController::class, 'index',        true );
        $this->add( 'GET',  'admin/bookings/{id}',     Controllers\Admin\BookingAdminController::class, 'show',         true );
        $this->add( 'PUT',   'admin/bookings/{id}/status', Controllers\Admin\BookingAdminController::class, 'updateStatus', true );
        $this->add( 'PATCH', 'admin/bookings/{id}/status', Controllers\Admin\BookingAdminController::class, 'updateStatus', true ); // alias
        $this->add( 'PUT',   'admin/bookings/{id}/assign',  Controllers\Admin\BookingAdminController::class, 'assign',   true );
        $this->add( 'PATCH', 'admin/bookings/{id}/assign',  Controllers\Admin\BookingAdminController::class, 'assign',   true ); // alias
        $this->add( 'POST', 'admin/bookings/{id}/note',    Controllers\Admin\BookingAdminController::class, 'addNote',  true );
        $this->add( 'POST', 'admin/bookings/{id}/notes',   Controllers\Admin\BookingAdminController::class, 'addNote',  true ); // alias
        $this->add( 'POST', 'admin/bookings/{id}/message', Controllers\Admin\BookingAdminController::class, 'sendMessage', true );

        // ── Admin: quotes
        $this->add( 'POST', 'admin/bookings/{id}/quote',      Controllers\Admin\QuoteAdminController::class, 'send',    true );
        $this->add( 'POST', 'admin/bookings/{id}/send-quote', Controllers\Admin\QuoteAdminController::class, 'send',    true ); // alias
        $this->add( 'GET',  'admin/quotes',              Controllers\Admin\QuoteAdminController::class, 'index',   true );

        // ── Admin: payments
        $this->add( 'GET',  'admin/payments',            Controllers\Admin\PaymentAdminController::class, 'index',  true );
        $this->add( 'POST', 'admin/payments/{id}/verify',Controllers\Admin\PaymentAdminController::class, 'verify', true );
        $this->add( 'POST', 'admin/payments/{id}/reject',Controllers\Admin\PaymentAdminController::class, 'reject', true );
        $this->add( 'POST', 'admin/payments/{id}/refund',Controllers\Admin\PaymentAdminController::class, 'refund', true );

        // ── Admin: customers
        $this->add( 'GET',  'admin/customers',           Controllers\Admin\CustomerAdminController::class, 'index',  true );
        $this->add( 'GET',  'admin/customers/{id}',      Controllers\Admin\CustomerAdminController::class, 'show',   true );
        $this->add( 'PUT',  'admin/customers/{id}',      Controllers\Admin\CustomerAdminController::class, 'update', true );
        $this->add( 'POST', 'admin/customers/{id}/disable', Controllers\Admin\CustomerAdminController::class, 'disable', true );
        $this->add( 'POST', 'admin/customers/{id}/enable',  Controllers\Admin\CustomerAdminController::class, 'enable',  true );

        // ── Admin: staff
        $this->add( 'GET',  'admin/staff',               Controllers\Admin\StaffAdminController::class, 'index',  true );
        $this->add( 'POST', 'admin/staff',               Controllers\Admin\StaffAdminController::class, 'create', true );
        $this->add( 'PUT',  'admin/staff/{id}',          Controllers\Admin\StaffAdminController::class, 'update', true );

        // ── Admin: services
        $this->add( 'GET',  'admin/services',            Controllers\Admin\ServiceAdminController::class, 'index',   true );
        $this->add( 'POST', 'admin/services',            Controllers\Admin\ServiceAdminController::class, 'store',   true );
        $this->add( 'GET',  'admin/services/{id}',       Controllers\Admin\ServiceAdminController::class, 'show',    true );
        $this->add( 'PUT',  'admin/services/{id}',       Controllers\Admin\ServiceAdminController::class, 'update',  true );
        $this->add( 'DELETE','admin/services/{id}',      Controllers\Admin\ServiceAdminController::class, 'destroy', true );
        $this->add( 'PATCH', 'admin/services/{id}/toggle', Controllers\Admin\ServiceAdminController::class, 'toggle',  true );

        // ── Admin: categories
        $this->add( 'GET',  'admin/categories',          Controllers\Admin\CategoryAdminController::class, 'index',  true );
        $this->add( 'POST', 'admin/categories',          Controllers\Admin\CategoryAdminController::class, 'store',  true );
        $this->add( 'PUT',  'admin/categories/{id}',     Controllers\Admin\CategoryAdminController::class, 'update', true );
        $this->add( 'PATCH','admin/categories/{id}/toggle', Controllers\Admin\CategoryAdminController::class, 'toggle', true );

        // ── Admin: documents
        $this->add( 'POST', 'admin/bookings/{id}/documents', Controllers\Admin\DocumentAdminController::class, 'upload', true );
        $this->add( 'DELETE','admin/documents/{doc_id}',     Controllers\Admin\DocumentAdminController::class, 'delete', true );

        // ── Admin: analytics
        $this->add( 'GET',  'admin/analytics/dashboard', Controllers\Admin\AnalyticsController::class, 'dashboard', true );
        $this->add( 'GET',  'admin/analytics/revenue',   Controllers\Admin\AnalyticsController::class, 'revenue',   true );
        $this->add( 'GET',  'admin/analytics/bookings',  Controllers\Admin\AnalyticsController::class, 'bookings',  true );

        // ── Admin: settings
        $this->add( 'GET',  'admin/settings',            Controllers\Admin\SettingsAdminController::class, 'get',    true );
        $this->add( 'PUT',  'admin/settings',            Controllers\Admin\SettingsAdminController::class, 'update', true );
        $this->add( 'POST', 'admin/settings',            Controllers\Admin\SettingsAdminController::class, 'update', true ); // alias: some clients use POST

        // ── Admin: tickets
        $this->add( 'GET',  'admin/tickets',             Controllers\Admin\TicketAdminController::class, 'index',      true );
        $this->add( 'GET',  'admin/tickets/{id}',        Controllers\Admin\TicketAdminController::class, 'show',       true );
        $this->add( 'PATCH','admin/tickets/{id}/status', Controllers\Admin\TicketAdminController::class, 'updateStatus',true );
        $this->add( 'POST', 'admin/tickets/{id}/messages',Controllers\Admin\TicketAdminController::class, 'sendMessage', true );

        // ── Admin: audit log
        // ── Public content endpoints ─────────────────────────────────────────
        $this->add( 'GET',    'testimonials',  Controllers\Admin\ContentController::class, 'listTestimonials', false );
        $this->add( 'GET',    'faqs',                Controllers\Admin\ContentController::class, 'listFaqs',          false );
        $this->add( 'GET',    'pricing-plans',        Controllers\Admin\ContentController::class, 'listPricingPlans',  false );
        $this->add( 'GET',    'blog',                 Controllers\Admin\ContentController::class, 'listPosts',         false );
        $this->add( 'GET',    'blog/{slug}',          Controllers\Admin\ContentController::class, 'getPost',           false );
        $this->add( 'POST',   'contact',              Controllers\Admin\ContentController::class, 'contactForm',       false );

        // ── Admin content management ──────────────────────────────────────────
        $this->add( 'GET',    'admin/faqs',            Controllers\Admin\ContentController::class, 'adminFaqs',           true );
        $this->add( 'POST',   'admin/faqs',            Controllers\Admin\ContentController::class, 'createFaq',           true );
        $this->add( 'PUT',    'admin/faqs/{id}',       Controllers\Admin\ContentController::class, 'updateFaq',           true );
        $this->add( 'DELETE', 'admin/faqs/{id}',       Controllers\Admin\ContentController::class, 'deleteFaq',           true );
        $this->add( 'GET',    'admin/testimonials',    Controllers\Admin\ContentController::class, 'adminTestimonials',   true );
        $this->add( 'POST',   'admin/testimonials',    Controllers\Admin\ContentController::class, 'createTestimonial',   true );
        $this->add( 'PUT',    'admin/testimonials/{id}',    Controllers\Admin\ContentController::class, 'updateTestimonial',   true );
        $this->add( 'DELETE', 'admin/testimonials/{id}',    Controllers\Admin\ContentController::class, 'deleteTestimonial',   true );
        $this->add( 'GET',    'admin/blog',            Controllers\Admin\ContentController::class, 'adminPosts',          true );
        $this->add( 'POST',   'admin/blog',            Controllers\Admin\ContentController::class, 'createPost',          true );
        $this->add( 'PUT',    'admin/blog/{id}',       Controllers\Admin\ContentController::class, 'updatePost',          true );
        $this->add( 'DELETE', 'admin/blog/{id}',       Controllers\Admin\ContentController::class, 'deletePost',          true );
        $this->add( 'GET',    'admin/pricing-plans',   Controllers\Admin\ContentController::class, 'adminPricingPlans',   true );
        $this->add( 'POST',   'admin/pricing-plans',   Controllers\Admin\ContentController::class, 'createPlan',          true );
        $this->add( 'PUT',    'admin/pricing-plans/{id}', Controllers\Admin\ContentController::class, 'updatePlan',       true );
        $this->add( 'DELETE', 'admin/pricing-plans/{id}', Controllers\Admin\ContentController::class, 'deletePlan',       true );
        $this->add( 'POST',   'admin/media/upload',    Controllers\Admin\MediaController::class,   'upload',              true );
        $this->add( 'GET',    'admin/media',            Controllers\Admin\MediaController::class,   'index',               true );
        $this->add( 'DELETE', 'admin/media/{id}',      Controllers\Admin\MediaController::class,   'destroy',             true );

        $this->add( 'GET',  'admin/audit-log',           Controllers\Admin\AuditLogController::class, 'index', true );

        // ── Diagnostics & Error Intelligence System
        $this->add( 'GET',    'admin/diagnostics',                     Controllers\Admin\DiagnosticController::class, 'index',                true  );
        $this->add( 'GET',    'admin/diagnostics/live-errors',         Controllers\Admin\DiagnosticController::class, 'liveErrors',           true  );
        $this->add( 'POST',   'admin/diagnostics/run',                 Controllers\Admin\DiagnosticController::class, 'runScan',              true  );
        $this->add( 'GET',    'admin/diagnostics/{run_id}',            Controllers\Admin\DiagnosticController::class, 'show',                 true  );
        $this->add( 'GET',    'admin/diagnostics/{run_id}/download',   Controllers\Admin\DiagnosticController::class, 'download',             true  );
        $this->add( 'DELETE', 'admin/diagnostics/{run_id}',            Controllers\Admin\DiagnosticController::class, 'deleteRun',            true  );
        $this->add( 'POST',   'diagnostics/frontend-errors',           Controllers\Admin\DiagnosticController::class, 'receiveFrontendErrors', false );

        // ── Admin: notifications broadcast
        $this->add( 'POST', 'admin/notifications/broadcast', Controllers\Admin\NotificationAdminController::class, 'broadcast', true );

        // ── Admin: reviews
        $this->add( 'GET',  'admin/reviews',             Controllers\Admin\ReviewAdminController::class, 'index',   true );
        $this->add( 'PATCH','admin/reviews/{id}/publish',Controllers\Admin\ReviewAdminController::class, 'publish', true );
        $this->add( 'PATCH','admin/reviews/{id}/reject', Controllers\Admin\ReviewAdminController::class, 'reject',  true );

        // ── Admin: cities CRUD
        $this->add( 'GET',    'admin/cities',               Controllers\Admin\CityAdminController::class, 'index',       true );
        $this->add( 'POST',   'admin/cities',               Controllers\Admin\CityAdminController::class, 'store',       true );
        $this->add( 'PUT',    'admin/cities/{id}',          Controllers\Admin\CityAdminController::class, 'update',      true );
        $this->add( 'DELETE', 'admin/cities/{id}',          Controllers\Admin\CityAdminController::class, 'destroy',     true );
        $this->add( 'PATCH',  'admin/cities/{id}/toggle',   Controllers\Admin\CityAdminController::class, 'toggle',      true );

        // ── Public: cities
        $this->add( 'GET',    'cities',                     Controllers\CityController::class, 'index',   false );
        $this->add( 'GET',    'cities/{slug}',              Controllers\CityController::class, 'show',    false );

        // ── City image upload
        $this->add( 'POST', 'admin/cities/{id}/image', Controllers\Admin\CityAdminController::class, 'uploadImage', true );

        // ── Service + category image upload
        $this->add( 'POST',   'admin/services/{id}/image',    Controllers\Admin\ServiceAdminController::class,  'uploadImage',  true );
        $this->add( 'DELETE', 'admin/services/{id}/image',    Controllers\Admin\ServiceAdminController::class,  'deleteImage',  true );
        $this->add( 'POST',   'admin/categories/{id}/image',  Controllers\Admin\CategoryAdminController::class, 'uploadImage',  true );
        $this->add( 'DELETE', 'admin/categories/{id}',        Controllers\Admin\CategoryAdminController::class, 'destroy',      true );

        // ── Admin: service page builder (sections)
        $this->add( 'POST',   'admin/services/reseed',                             Controllers\Admin\ServiceAdminController::class, 'reseed',    true );
        $this->add( 'GET',    'admin/services/{id}/sections',                    Controllers\Admin\ServiceSectionAdminController::class, 'index',     true );
        $this->add( 'POST',   'admin/services/{id}/sections',                    Controllers\Admin\ServiceSectionAdminController::class, 'store',     true );
        $this->add( 'POST',   'admin/services/{id}/sections/dedup',             Controllers\Admin\ServiceSectionAdminController::class, 'dedup',     true );
        $this->add( 'PUT',    'admin/services/{id}/sections/reorder',            Controllers\Admin\ServiceSectionAdminController::class, 'reorder',   true );
        $this->add( 'PUT',    'admin/services/{id}/sections/{section_id}',       Controllers\Admin\ServiceSectionAdminController::class, 'update',    true );
        $this->add( 'DELETE', 'admin/services/{id}/sections/{section_id}',       Controllers\Admin\ServiceSectionAdminController::class, 'destroy',   true );
        $this->add( 'PATCH',  'admin/services/{id}/sections/{section_id}/toggle',Controllers\Admin\ServiceSectionAdminController::class, 'toggle',    true );
        $this->add( 'POST',   'admin/services/{id}/sections/{section_id}/duplicate', Controllers\Admin\ServiceSectionAdminController::class, 'duplicate', true );

        // ── Public: service sections
        $this->add( 'GET',    'services/{slug}/sections',                        Controllers\ServiceSectionController::class, 'index', false );

        // ── Admin: export
        $this->add( 'GET',  'admin/export/bookings',     Controllers\Admin\ExportController::class, 'bookings',  true );
        $this->add( 'GET',  'admin/export/customers',    Controllers\Admin\ExportController::class, 'customers', true );
        $this->add( 'GET',  'admin/export/payments',     Controllers\Admin\ExportController::class, 'payments',  true );

        // ── System health
        $this->add( 'GET',  'system/health',             Controllers\SystemController::class, 'health',  true );
        $this->add( 'GET',  'system/jobs',               Controllers\SystemController::class, 'jobs',    true );

        // ── Phase 1: Magic Link (M-01)
        $this->add( 'POST', 'auth/magic-link/send',   Controllers\AuthController::class, 'sendMagicLink',   false );
        $this->add( 'POST', 'auth/magic-link/verify', Controllers\AuthController::class, 'verifyMagicLink', false );
        $this->add( 'POST', 'auth/magic-link',        Controllers\AuthController::class, 'sendMagicLink',   false ); // alias: portal JS posts without /send suffix

        // ── Phase 1: Email Templates (M-08)
        $this->add( 'GET',    'admin/email-templates',                Controllers\Admin\EmailTemplateAdminController::class, 'index',     true );
        $this->add( 'POST',   'admin/email-templates',                Controllers\Admin\EmailTemplateAdminController::class, 'store',     true );
        $this->add( 'PUT',    'admin/email-templates/{id}',           Controllers\Admin\EmailTemplateAdminController::class, 'update',    true );
        $this->add( 'DELETE', 'admin/email-templates/{id}',           Controllers\Admin\EmailTemplateAdminController::class, 'destroy',   true );
        $this->add( 'POST',   'admin/email-templates/{id}/reset',     Controllers\Admin\EmailTemplateAdminController::class, 'reset',     true );
        $this->add( 'POST',   'admin/email-templates/{id}/duplicate', Controllers\Admin\EmailTemplateAdminController::class, 'duplicate', true );
        $this->add( 'POST',   'admin/email-templates/{id}/test-email',Controllers\Admin\EmailTemplateAdminController::class, 'testEmail', true );

        // ── Phase 1: Holidays + Delivery Calculator (M-09, M-10)
        $this->add( 'GET',    'admin/holidays',           Controllers\Admin\HolidayAdminController::class, 'index',     true );
        $this->add( 'POST',   'admin/holidays',           Controllers\Admin\HolidayAdminController::class, 'store',     true );
        $this->add( 'DELETE', 'admin/holidays/{id}',      Controllers\Admin\HolidayAdminController::class, 'destroy',   true );
        $this->add( 'POST',   'admin/holidays/calculate', Controllers\Admin\HolidayAdminController::class, 'calculate', true );

        // ── Phase 1: Quick Replies (M-07)
        $this->add( 'GET',    'admin/quick-replies',       Controllers\Admin\QuickReplyAdminController::class, 'index',   true );
        $this->add( 'POST',   'admin/quick-replies',       Controllers\Admin\QuickReplyAdminController::class, 'store',   true );
        $this->add( 'PUT',    'admin/quick-replies/{id}',  Controllers\Admin\QuickReplyAdminController::class, 'update',  true );
        $this->add( 'DELETE', 'admin/quick-replies/{id}',  Controllers\Admin\QuickReplyAdminController::class, 'destroy', true );

        // ── Phase 1: Vendors (M-13)
        $this->add( 'GET',    'admin/vendors',       Controllers\Admin\VendorAdminController::class, 'index',   true );
        $this->add( 'GET',    'vendors',              Controllers\Admin\VendorAdminController::class, 'index',   false ); // public alias: portal JS calls /vendors without admin prefix
        $this->add( 'POST',   'admin/vendors',       Controllers\Admin\VendorAdminController::class, 'store',   true );
        $this->add( 'PUT',    'admin/vendors/{id}',  Controllers\Admin\VendorAdminController::class, 'update',  true );
        $this->add( 'DELETE', 'admin/vendors/{id}',  Controllers\Admin\VendorAdminController::class, 'destroy', true );

        // ── Phase 1: Request Types (M-15)
        $this->add( 'GET',    'request-types',                   Controllers\Admin\RequestTypeAdminController::class, 'index',      false );
        $this->add( 'GET',    'admin/request-types',             Controllers\Admin\RequestTypeAdminController::class, 'adminIndex', true );
        $this->add( 'POST',   'admin/request-types',             Controllers\Admin\RequestTypeAdminController::class, 'store',      true );
        $this->add( 'PUT',    'admin/request-types/{id}',        Controllers\Admin\RequestTypeAdminController::class, 'update',     true );
        $this->add( 'PATCH',  'admin/request-types/{id}/toggle', Controllers\Admin\RequestTypeAdminController::class, 'toggle',     true );
        $this->add( 'DELETE', 'admin/request-types/{id}',        Controllers\Admin\RequestTypeAdminController::class, 'destroy',    true );

        // ── Phase 1: Communication Panel (M-06, M-22, M-23)
        $this->add( 'GET',  'admin/bookings/{id}/communications',       Controllers\Admin\CommunicationAdminController::class, 'history',            true );
        $this->add( 'POST', 'admin/bookings/{id}/communications',       Controllers\Admin\CommunicationAdminController::class, 'send',               true );
        $this->add( 'POST', 'admin/bookings/{id}/send-quotation-email', Controllers\Admin\CommunicationAdminController::class, 'sendQuotationEmail', true );
        $this->add( 'POST', 'admin/bookings/{id}/quick-email',          Controllers\Admin\CommunicationAdminController::class, 'quickEmail',         true );

        // ── Phase 1: Booking enhancements (M-02, M-04, M-09, M-11, M-13)
        $this->add( 'PATCH', 'admin/bookings/{id}/secondary-status', Controllers\Admin\BookingPhase1AdminController::class, 'setSecondaryStatus', true );
        $this->add( 'PATCH', 'admin/bookings/{id}/delivery',         Controllers\Admin\BookingPhase1AdminController::class, 'setDelivery',        true );
        $this->add( 'PATCH', 'admin/bookings/{id}/vendor',           Controllers\Admin\BookingPhase1AdminController::class, 'assignVendor',       true );
        $this->add( 'PATCH', 'admin/bookings/{id}/shipping-type',    Controllers\Admin\BookingPhase1AdminController::class, 'setShippingType',    true );

        // ── Phase 2: Form Submissions (M-11, M-18)
        $this->add( 'GET',  'admin/form-submissions',           Controllers\Admin\FormSubmissionsController::class, 'index',  true );
        $this->add( 'GET',  'admin/form-submissions/{id}/export',Controllers\Admin\FormSubmissionsController::class, 'export', true );
        $this->add( 'GET',  'form-submissions',                  Controllers\Admin\FormSubmissionsController::class, 'index',  true ); // alias: portal JS calls without admin prefix

        // ── Phase 2: Bulk Export (M-12)
        $this->add( 'POST', 'admin/export/bookings/bulk',      Controllers\Admin\BulkExportController::class, 'bulkBookings',     true );
        $this->add( 'POST', 'admin/export/bookings/bulk-json', Controllers\Admin\BulkExportController::class, 'bulkBookingsJson', true );

        // ── Phase 2: WhatsApp (M-14)
        $this->add( 'POST', 'admin/whatsapp/test', Controllers\Admin\WhatsAppController::class, 'test', true );
        $this->add( 'POST', 'admin/whatsapp/send', Controllers\Admin\WhatsAppController::class, 'send', true );

        // ── Phase 2: Bulk Document Download (M-17)
        $this->add( 'POST', 'bookings/{id}/documents/download-zip',       Controllers\Admin\DocumentBulkController::class, 'downloadZip', true );
        $this->add( 'POST', 'admin/bookings/{id}/documents/download-zip', Controllers\Admin\DocumentBulkController::class, 'downloadZip', true );

        // ── Phase 2: Form Fields CRUD (M-24, M-25, M-26, M-29)
        // Issue 12 FIX: Import all qualification_schemas into form_fields table at once
        // so Dropdown Data Manager is never empty on first load.
        $this->add( 'POST',  'admin/form-fields/import-all',                              Controllers\Admin\FormFieldAdminController::class, 'importAll', true );
        $this->add( 'GET',   'admin/services/{id}/form-fields',                         Controllers\Admin\FormFieldAdminController::class, 'index',   true );
        $this->add( 'POST',  'admin/services/{id}/form-fields',                         Controllers\Admin\FormFieldAdminController::class, 'store',   true );
        $this->add( 'PATCH', 'admin/services/{id}/form-fields/reorder',                 Controllers\Admin\FormFieldAdminController::class, 'reorder', true );
        $this->add( 'PUT',   'admin/services/{id}/form-fields/reorder',                 Controllers\Admin\FormFieldAdminController::class, 'reorder', true );
        $this->add( 'PUT',   'admin/services/{id}/form-fields/{field_id}',              Controllers\Admin\FormFieldAdminController::class, 'update',  true );
        $this->add( 'DELETE','admin/services/{id}/form-fields/{field_id}',              Controllers\Admin\FormFieldAdminController::class, 'destroy', true );
        $this->add( 'PATCH', 'admin/services/{id}/form-fields/{field_id}/toggle',       Controllers\Admin\FormFieldAdminController::class, 'toggle',  true );

        // ── Phase 2: Dropdown Data Manager (M-27, M-28) — Search (M-25 public)
        $this->add( 'GET',   'admin/form-fields/{field_id}/options',                     Controllers\Admin\DropdownDataController::class, 'index',      true );
        $this->add( 'POST',  'admin/form-fields/{field_id}/options',                     Controllers\Admin\DropdownDataController::class, 'store',      true );
        $this->add( 'PUT',   'admin/form-fields/{field_id}/options/{option_id}',         Controllers\Admin\DropdownDataController::class, 'update',     true );
        $this->add( 'DELETE','admin/form-fields/{field_id}/options/{option_id}',         Controllers\Admin\DropdownDataController::class, 'destroy',    true );
        $this->add( 'POST',  'admin/form-fields/{field_id}/options/bulk-delete',         Controllers\Admin\DropdownDataController::class, 'bulkDelete', true );
        $this->add( 'POST',  'admin/form-fields/{field_id}/options/bulk-import',         Controllers\Admin\DropdownDataController::class, 'bulkImport', true );
        $this->add( 'GET',   'form-fields/{field_id}/options/search',                    Controllers\Admin\DropdownDataController::class, 'search',     false );

        // ── Phase 2: Standalone Quote Widget (M-19)
        $this->add( 'POST', 'public/quote-request', Controllers\Admin\StandaloneQuoteController::class, 'submit',       false );
        $this->add( 'GET',  'public/services-list', Controllers\Admin\StandaloneQuoteController::class, 'servicesList', false );

        // ── Phase 2: Booking UX (M-20, M-21)
        $this->add( 'GET', 'admin/bookings/{id}/assigned-info', Controllers\Admin\BookingPhase2AdminController::class, 'assignedInfo', true );

        // ── Phase 3: Document proxy + AVIF conversion (4.20)
        $this->add( 'GET',  'documents/{doc_id}/download',  Controllers\Admin\DocumentProxyController::class, 'download', true );
        $this->add( 'GET',  'admin/avif-scan',              Controllers\Admin\AvifConversionController::class, 'scan',     true );
        $this->add( 'POST', 'admin/avif-convert/{doc_id}',  Controllers\Admin\AvifConversionController::class, 'convert',  true );

        // ── Phase 3: Dashboard enhancements (5.1, 5.2)
        $this->add( 'GET',  'admin/dashboard/extended',     Controllers\Admin\DashboardEnhancementController::class, 'extendedDashboard', true );
        $this->add( 'GET',  'customer/dashboard-stats',     Controllers\Admin\DashboardEnhancementController::class, 'customerStats',      true );

        // ── Phase 3: Booking enhancements (4.13, 5.3, M-20)
        $this->add( 'POST', 'admin/bookings/{id}/send-message', Controllers\Admin\Phase3BookingController::class, 'sendMessageWithEmailBadge', true );
        $this->add( 'POST', 'admin/bookings/{id}/event-email',  Controllers\Admin\Phase3BookingController::class, 'sendEventEmail',            true );
        $this->add( 'GET',  'admin/bookings/{id}/tickets',      Controllers\Admin\Phase3BookingController::class, 'searchTickets',  true );
        $this->add( 'POST', 'admin/bookings/{id}/tickets',      Controllers\Admin\Phase3BookingController::class, 'createTicket',   true );

        // ── Phase 3: Audit trail (enhanced)
        $this->add( 'GET',  'admin/bookings/{id}/audit-trail', Controllers\Admin\AuditTrailController::class, 'bookingTimeline', true );
        $this->add( 'GET',  'admin/activity-feed',             Controllers\Admin\AuditTrailController::class, 'activityFeed',   true );

        // ── Phase 3: System settings
        $this->add( 'GET',  'admin/settings/phase3',       Controllers\Admin\SystemSettingsController::class, 'getPhase3', true );
        $this->add( 'GET',  'admin/smtp-test',             Controllers\Admin\SystemSettingsController::class, 'smtpTest',  true );

        // ── Phase 4: GDPR (GDPR-01, GDPR-02)
        $this->add( 'GET',    'admin/customers/{id}/data-export', Controllers\Admin\GDPRController::class, 'exportCustomerData', true );
        $this->add( 'DELETE', 'admin/customers/{id}/data',        Controllers\Admin\GDPRController::class, 'eraseCustomerData',  true );
        $this->add( 'GET',    'profile/data-export',              Controllers\Admin\GDPRController::class, 'selfExport',         true );

        // ── Phase 4: Advanced Analytics (ANAL-01 through ANAL-04)
        $this->add( 'GET',  'admin/analytics/services',       Controllers\Admin\AdvancedAnalyticsController::class, 'byService',          true );
        $this->add( 'GET',  'admin/analytics/staff',          Controllers\Admin\AdvancedAnalyticsController::class, 'byStaff',            true );
        $this->add( 'GET',  'admin/analytics/funnel',         Controllers\Admin\AdvancedAnalyticsController::class, 'conversionFunnel',   true );
        $this->add( 'GET',  'admin/analytics/revenue-trend',  Controllers\Admin\AdvancedAnalyticsController::class, 'revenueTrend',       true );
        $this->add( 'GET',  'admin/analytics/customers',      Controllers\Admin\AdvancedAnalyticsController::class, 'customerAnalytics',  true );

        // ── Phase 4: Operations (OPS-01 through OPS-08)
        $this->add( 'DELETE', 'admin/bookings/{id}',               Controllers\Admin\OperationsController::class, 'deleteBooking',               true );
        $this->add( 'GET',    'documents/{doc_id}/view',           Controllers\Admin\OperationsController::class, 'viewDocument',                true );
        $this->add( 'GET',    'profile/notification-preferences',  Controllers\Admin\OperationsController::class, 'getNotificationPreferences',  true );
        $this->add( 'PUT',    'profile/notification-preferences',  Controllers\Admin\OperationsController::class, 'updateNotificationPreferences',true );
        $this->add( 'GET',    'admin/staff/workload',              Controllers\Admin\OperationsController::class, 'staffWorkload',               true );
        $this->add( 'POST',   'admin/bookings/bulk-status',        Controllers\Admin\OperationsController::class, 'bulkStatusUpdate',            true );
        $this->add( 'GET',    'admin/bookings/{id}/notes-history', Controllers\Admin\OperationsController::class, 'notesHistory',                true );
        $this->add( 'GET',    'admin/customers/duplicates',        Controllers\Admin\OperationsController::class, 'findDuplicates',              true );

        // ── Phase 4: Platform Health (OPS-05)
        $this->add( 'GET',  'admin/platform-health', Controllers\Admin\PlatformHealthController::class, 'check', true );
    }

    private function add( string $method, string $path, string $controller, string $action, bool $requiresAuth = false ): void {
        $this->routes[] = compact( 'method', 'path', 'controller', 'action', 'requiresAuth' );
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    public function dispatch(): void {
        $this->setCorsHeaders();

        if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
            status_header( 204 );
            return;
        }

        $method = strtoupper( $_SERVER['REQUEST_METHOD'] );
        $raw    = strtok( $_SERVER['REQUEST_URI'] ?? '', '?' );

        // --- Robust path extraction (4 strategies, first match wins) ---

        // Strategy 0: Path injected by Bootstrap::handleRestRequest() via WP REST API.
        //   This is the most reliable route — WP REST API guarantees the path is correct.
        if ( isset( $GLOBALS['s2nri_rest_path'] ) && $GLOBALS['s2nri_rest_path'] !== '' ) {
            $path = ltrim( $GLOBALS['s2nri_rest_path'], '/' );
        } elseif ( did_action( 'wp' ) && function_exists( 'get_query_var' ) && ( $qv = (string) get_query_var( 's2nri_api', '' ) ) !== '' ) {
            // Strategy 1: WP rewrite query var (only safe after 'wp' hook).
            $path = ltrim( $qv, '/' );
        } else {
            // Strategy 2: Strip WP home path prefix (handles subdirectory installs).
            $home_path = rtrim( parse_url( home_url(), PHP_URL_PATH ) ?? '', '/' );
            $rel       = $home_path ? ltrim( substr( $raw, strlen( $home_path ) ), '/' ) : ltrim( $raw, '/' );
            $prefix    = 'api/v1/';
            if ( strpos( $rel, $prefix ) === 0 ) {
                $path = substr( $rel, strlen( $prefix ) );
            } else {
                // Strategy 3: Find 'api/v1/' anywhere in the raw URI (proxy rewrites).
                $api_pos = strpos( $raw, '/api/v1/' );
                $path    = $api_pos !== false ? ltrim( substr( $raw, $api_pos + 8 ), '/' ) : '';
            }
        }

        foreach ( $this->routes as $route ) {
            $params = $this->matchRoute( $route['method'], $route['path'], $method, $path );
            if ( $params === null ) continue;

            // Auth
            $user = null;
            if ( $route['requiresAuth'] ) {
                $user = $this->auth->authenticate();
                if ( ! $user ) {
                    Response::json( [ 'error' => 'Unauthorised. Please log in.' ], 401 );
                    return;
                }
            } else {
                $user = $this->auth->authenticateOptional();
            }

            try {
                $controller = new $route['controller']( $user );
                $controller->{$route['action']}( new Request( $params ) );
            } catch ( \S2NRI\Exceptions\ValidationException $e ) {
                Response::json( [ 'error' => $e->getMessage(), 'fields' => $e->getErrors() ], 422 );
            } catch ( \S2NRI\Exceptions\NotFoundException $e ) {
                Response::json( [ 'error' => $e->getMessage() ], 404 );
            } catch ( \S2NRI\Exceptions\ForbiddenException $e ) {
                Response::json( [ 'error' => $e->getMessage() ], 403 );
            } catch ( \S2NRI\Exceptions\AuthException $e ) {
                // FIXED: this exception is thrown in 2 real places
                // (Controllers.php:779, 888) with specific, useful
                // messages ("Customer profile not found.") — previously
                // had no dedicated catch clause, so it fell through to
                // the generic \Exception|\Error handler below, which
                // discards the actual message in favor of a hardcoded
                // "An unexpected error occurred" and reports it as a 500
                // server error rather than a 401 auth issue. Both the
                // customer-facing message and the correct status code
                // were wrong for this specific, intentional exception.
                Response::json( [ 'error' => $e->getMessage() ], 401 );
            } catch ( \Exception | \Error $e ) {
                error_log( '[S2NRI API] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
                Response::json( [ 'error' => 'An unexpected error occurred. Please try again.' ], 500 );
            }
            return;
        }

        Response::json( [ 'error' => 'Not found' ], 404 );
    }

    private function matchRoute( string $routeMethod, string $pattern, string $method, string $path ): ?array {
        if ( $routeMethod !== $method ) return null;
        $regex = '#^' . preg_replace( '/\{([^}]+)\}/', '(?P<$1>[^/]+)', $pattern ) . '$#';
        if ( ! preg_match( $regex, $path, $matches ) ) return null;
        return array_filter( $matches, fn( $k ) => ! is_int( $k ), ARRAY_FILTER_USE_KEY );
    }

    private function setCorsHeaders(): void {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        header( 'Access-Control-Allow-Origin: ' . ( $origin ?: '*' ) );
        header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS' );
        header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-WP-Nonce, X-S2NRI-Token' );
        header( 'Access-Control-Allow-Credentials: true' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate' );
        // NOTE: Content-Type is NOT set here — controllers set their own (json, csv, etc.)
        // Response::json() always sets Content-Type: application/json before echoing.
    }
}
