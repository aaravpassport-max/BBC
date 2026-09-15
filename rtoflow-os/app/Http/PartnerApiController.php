<?php

namespace RTOFLOW\Http;

use RTOFLOW\Services\ApiKeyService;
use RTOFLOW\Services\LeadService;
use RTOFLOW\Services\EligibilityService;
use RTOFLOW\Http\Middleware\RateLimiter;
use RTOFLOW\Security\Sanitiser;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 2, item 4 — "No partner/integration REST API"):
 * every integration previously had to go through the admin UI or direct
 * database access — there was no way for an external partner (a referral
 * site, a franchise portal, another internal system) to submit a lead or
 * check its status programmatically. This registers a real WordPress REST
 * API namespace, 'rtoflow/v1', authenticated by the X-API-Key header
 * (ApiKeyService — see the Partner API admin screen to issue/revoke keys).
 *
 * Scope, stated honestly: this covers the operations a referral partner
 * actually needs — submit a lead, check its status, look up the active
 * services/cities list to build their own form. It does NOT expose
 * payments, vendor data, or any write operation beyond lead creation —
 * those stay behind the existing admin-authenticated AJAX surface.
 */
class PartnerApiController
{
    public static function register(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('rtoflow/v1', '/leads', [
                'methods'             => 'POST',
                'callback'            => [self::class, 'createLead'],
                'permission_callback' => [self::class, 'authenticate'],
            ]);
            register_rest_route('rtoflow/v1', '/leads/(?P<id>\d+)', [
                'methods'             => 'GET',
                'callback'            => [self::class, 'getLeadStatus'],
                'permission_callback' => [self::class, 'authenticate'],
            ]);
            // ENTERPRISE GAP FIX (Phase 12, item — "Partner REST API is
            // effectively unusable for integration ... no collection
            // endpoints"): getLeadStatus() above only ever supported
            // fetching ONE known lead id — a partner integration syncing its
            // own system had no way to ask "what's changed / what have I
            // submitted" without remembering every id itself. This is a real
            // paginated collection endpoint, scoped (same as getLeadStatus)
            // to leads created by the calling key only.
            register_rest_route('rtoflow/v1', '/leads', [
                'methods'             => 'GET',
                'callback'            => [self::class, 'listLeads'],
                'permission_callback' => [self::class, 'authenticate'],
            ]);
            register_rest_route('rtoflow/v1', '/services', [
                'methods'             => 'GET',
                'callback'            => [self::class, 'listServices'],
                'permission_callback' => [self::class, 'authenticate'],
            ]);
            register_rest_route('rtoflow/v1', '/cities', [
                'methods'             => 'GET',
                'callback'            => [self::class, 'listCities'],
                'permission_callback' => [self::class, 'authenticate'],
            ]);
        });
    }

    /**
     * Shared auth for every route above. WP_REST_Request's headers are
     * lower-cased and hyphen-preserved by WordPress, so X-API-Key arrives
     * as get_header('x-api-key'). Also enforces the same 'api' rate-limit
     * group already defined in RateLimiter, keyed per-key rather than
     * per-IP — a partner integration is one server, not many end-user
     * browsers, so per-key is the meaningful unit to throttle here.
     */
    public static function authenticate(\WP_REST_Request $request)
    {
        $rawKey = $request->get_header('x-api-key') ?: '';
        $keyRow = ApiKeyService::verify($rawKey);
        if (!$keyRow) {
            return new \WP_Error('rtoflow_unauthorized', 'Invalid or revoked API key. Include it in the X-API-Key header.', ['status' => 401]);
        }
        if (!RateLimiter::check('api', 'apikey:' . $keyRow['id'])) {
            return new \WP_Error('rtoflow_rate_limited', 'Too many requests. Please slow down.', ['status' => 429]);
        }
        $request->set_param('_rtoflow_api_key', $keyRow);
        return true;
    }

    public static function createLead(\WP_REST_Request $request)
    {
        $keyRow = $request->get_param('_rtoflow_api_key');
        $body   = $request->get_json_params() ?: [];

        $name    = Sanitiser::text($body['client_name'] ?? '', 150);
        $email   = Sanitiser::email($body['email'] ?? '');
        $mobile  = Sanitiser::mobile($body['mobile'] ?? '');
        $serviceId = Sanitiser::int($body['service_id'] ?? 0, 1);
        $cityId    = Sanitiser::int($body['city_id'] ?? 0, 1);

        if (!$name || !$email || !$mobile) {
            return new \WP_Error('rtoflow_invalid', 'client_name, email, and mobile are required.', ['status' => 422]);
        }
        if (!$serviceId || !$cityId) {
            return new \WP_Error('rtoflow_invalid', 'service_id and city_id are required.', ['status' => 422]);
        }

        // Find-or-create the client account — same pattern as the public
        // web submission path (Router::submitApply()): a partner-submitted
        // lead still needs a real rto_client account behind it so the
        // customer can log in and track it themselves afterwards.
        $userId = email_exists($email) ?: null;
        if ($userId) {
            $user = get_userdata($userId);
            if (!in_array('rto_client', (array)$user->roles, true)) {
                return new \WP_Error('rtoflow_conflict', 'An account with this email already exists under a different role.', ['status' => 409]);
            }
        } else {
            $userId = wp_create_user($email, wp_generate_password(20, true), $email);
            if (is_wp_error($userId)) {
                return new \WP_Error('rtoflow_error', 'Could not create client account: ' . $userId->get_error_message(), ['status' => 500]);
            }
            wp_update_user(['ID' => $userId, 'display_name' => $name, 'role' => 'rto_client']);
            update_user_meta($userId, 'rtoflow_mobile', $mobile);
        }

        $eligibility = \RTOFLOW\Bootstrap::container()->make(EligibilityService::class);
        $elig = $eligibility->evaluate($serviceId, $body, $userId);
        if (!$elig['eligible']) {
            return new \WP_Error('rtoflow_ineligible', $elig['blocking_failures'][0]['message'] ?? 'Not eligible for this service.', [
                'status' => 422, 'eligibility_failures' => $elig['blocking_failures'],
            ]);
        }

        $leadSvc = \RTOFLOW\Bootstrap::container()->make(LeadService::class);
        $result  = $leadSvc->create([
            'service_id' => $serviceId,
            'city_id'    => $cityId,
            'source'     => 'api',
        ], $userId);

        if (!$result['success']) {
            return new \WP_Error('rtoflow_error', $result['message'], ['status' => 422]);
        }

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'rto_leads', ['api_key_id' => $keyRow['id']], ['id' => (int)$result['lead_id']]);

        \RTOFLOW\Services\AuditService::log('api.lead_created', (int)$result['lead_id'], [
            'api_key_id' => $keyRow['id'], 'partner_name' => $keyRow['partner_name'],
        ]);

        return new \WP_REST_Response([
            'lead_id'     => $result['lead_id'],
            'lead_number' => $result['lead_number'],
        ], 201);
    }

    public static function getLeadStatus(\WP_REST_Request $request)
    {
        $keyRow = $request->get_param('_rtoflow_api_key');
        $leadId = (int)$request->get_param('id');

        global $wpdb;
        // Scoped to leads created BY THIS KEY — a partner must never be
        // able to enumerate or read another partner's (or a regular web
        // customer's) lead by guessing an id.
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT id, lead_number, status, created_at, total_amount, payment_status
             FROM {$wpdb->prefix}rto_leads WHERE id = %d AND api_key_id = %d AND deleted_at IS NULL",
            $leadId, (int)$keyRow['id']
        ), ARRAY_A);

        if (!$lead) {
            return new \WP_Error('rtoflow_not_found', 'Lead not found.', ['status' => 404]);
        }

        return new \WP_REST_Response($lead, 200);
    }

    public static function listLeads(\WP_REST_Request $request)
    {
        $keyRow = $request->get_param('_rtoflow_api_key');

        $page    = max(1, Sanitiser::int($request->get_param('page') ?? 1, 1));
        $perPage = min(100, max(1, Sanitiser::int($request->get_param('per_page') ?? 25, 1)));
        $offset  = ($page - 1) * $perPage;

        global $wpdb;
        $total = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rto_leads WHERE api_key_id = %d AND deleted_at IS NULL",
            (int)$keyRow['id']
        ));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, lead_number, status, created_at, total_amount, payment_status
             FROM {$wpdb->prefix}rto_leads WHERE api_key_id = %d AND deleted_at IS NULL
             ORDER BY id DESC LIMIT %d OFFSET %d",
            (int)$keyRow['id'], $perPage, $offset
        ), ARRAY_A) ?: [];

        $response = new \WP_REST_Response($rows, 200);
        $response->header('X-Total-Count', (string)$total);
        $response->header('X-Total-Pages', (string)max(1, (int)ceil($total / $perPage)));
        return $response;
    }

    public static function listServices(\WP_REST_Request $request)
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, name, category, base_price FROM {$wpdb->prefix}rto_services WHERE is_active = 1 ORDER BY name",
            ARRAY_A
        ) ?: [];
        return new \WP_REST_Response($rows, 200);
    }

    public static function listCities(\WP_REST_Request $request)
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT c.id, c.name, c.rto_code, s.name as state_name
             FROM {$wpdb->prefix}rto_cities c JOIN {$wpdb->prefix}rto_states s ON s.id = c.state_id
             WHERE c.is_active = 1 ORDER BY s.name, c.name",
            ARRAY_A
        ) ?: [];
        return new \WP_REST_Response($rows, 200);
    }
}
