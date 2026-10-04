<?php
namespace S2NRI\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use S2NRI\Api\{Request, Response};
use S2NRI\Services\{CacheService, NotificationService};

// ══════════════════════════════════════════════════════════════════════════════
// CategoryController
// ══════════════════════════════════════════════════════════════════════════════

class CategoryController extends BaseController {

    public function index( Request $req ): void {
        $surface = sanitize_key( $req->query( 'surface', 'directory' ) );
        $rows    = CacheService::remember( 'cats_active_' . $surface, 900, function () use ( $surface ) {
            return \S2NRI\Services\ServiceRegistry::getPublicCategories( $surface );
        } );
        Response::json( [ 'categories' => $rows ] );
    }

    public function show( Request $req ): void {
        $slug = sanitize_key( $req->param( 'slug' ) );
        $cat = null;
        foreach ( \S2NRI\Services\ServiceRegistry::getPublicCategories( 'directory' ) as $c ) {
            if ( $c['slug'] === $slug ) {
                $cat = $c;
                break;
            }
        }

        if ( ! $cat ) { Response::json( [ 'error' => 'Category not found.' ], 404 ); return; }

        $services = \S2NRI\Services\ServiceRegistry::forSurface( 'directory', [
            'category_slug' => $slug,
        ] );
        $cat['services'] = array_map( static function ( $s ) {
            return [
                'id'              => (int) $s['id'],
                'slug'            => $s['slug'],
                'name'            => $s['name'],
                'name_hi'         => $s['name_hi'] ?? '',
                'short_desc'      => $s['short_desc'] ?? '',
                'pricing_model'   => $s['pricing_model'] ?? 'quote',
                'base_price'      => $s['base_price'] ?? null,
                'price_min'       => $s['price_min'] ?? null,
                'price_max'       => $s['price_max'] ?? null,
                'turnaround_days' => (int) ( $s['turnaround_days'] ?? 7 ),
                'public_status'   => $s['public_status'] ?? 'published',
            ];
        }, $services );
        Response::json( [ 'category' => $cat ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// ServiceController
// ══════════════════════════════════════════════════════════════════════════════

class ServiceController extends BaseController {

    public function index( Request $req ): void {
        $cat_slug = sanitize_key( $req->query( 'category', '' ) );
        $search   = sanitize_text_field( $req->query( 'search', '' ) );
        $surface  = sanitize_key( $req->query( 'surface', $search ? 'search' : 'directory' ) );
        $limit    = max( 0, (int) $req->query( 'per_page', 0 ) );

        $rows = \S2NRI\Services\ServiceRegistry::forSurface( $surface, [
            'category_slug'  => $cat_slug,
            'featured_only'  => $req->query( 'featured', '' ) === '1',
            'popular_only'   => $req->query( 'popular', '' ) === '1',
            'limit'          => $limit > 0 ? $limit : 0,
        ] );

        if ( $search ) {
            $q = strtolower( $search );
            $rows = array_values( array_filter( $rows, function ( $s ) use ( $q ) {
                return str_contains( strtolower( (string) ( $s['name'] ?? '' ) ), $q )
                    || str_contains( strtolower( (string) ( $s['short_desc'] ?? '' ) ), $q );
            } ) );
        }

        $out = [];
        foreach ( $rows as $s ) {
            $out[] = [
                'id'                  => (int) $s['id'],
                'slug'                => $s['slug'],
                'name'                => $s['name'],
                'name_hi'             => $s['name_hi'] ?? '',
                'short_desc'          => $s['short_desc'] ?? '',
                'pricing_model'       => $s['pricing_model'] ?? 'quote',
                'base_price'          => $s['base_price'] ?? null,
                'price_min'           => $s['price_min'] ?? null,
                'price_max'           => $s['price_max'] ?? null,
                'turnaround_days'     => (int) ( $s['turnaround_days'] ?? 7 ),
                'image_url'           => $s['image_url'] ?? '',
                'sort_order'          => (int) ( $s['sort_order'] ?? 0 ),
                'is_featured'         => (int) ( $s['is_featured'] ?? 0 ),
                'is_popular'          => (int) ( $s['is_popular'] ?? 0 ),
                'is_active'           => (int) ( $s['is_active'] ?? 1 ),
                'public_status'       => $s['public_status'] ?? 'published',
                'category_id'         => (int) ( $s['category_id'] ?? 0 ),
                'category_name'       => $s['category_name'] ?? '',
                'category_slug'       => $s['category_slug'] ?? '',
                'icon'                => $s['icon'] ?? '📋',
                'color'               => $s['color'] ?? '#4A6FA5',
                'category_image_url'  => $s['category_image_url'] ?? '',
            ];
        }

        Response::json( [ 'services' => $out ] );
    }

    public function show( Request $req ): void {
        $slug = sanitize_key( $req->param( 'slug' ) );
        global $wpdb;
        $p = $wpdb->prefix;

        list( $http, $action, $redirect ) = \S2NRI\Services\ServiceRegistry::resolveDirectUrl( $slug );
        if ( $http === 404 ) {
            Response::json( [ 'error' => 'Service not found.' ], 404 );
            return;
        }
        if ( $action === 'redirect' && $redirect ) {
            Response::json( [ 'redirect' => $redirect ], 200 );
            return;
        }

        $service = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, c.name AS category_name, c.slug AS category_slug, c.icon, c.color,
                    c.public_status AS category_public_status, c.visibility_rules AS category_visibility_rules
             FROM {$p}s2nri_services s
             LEFT JOIN {$p}s2nri_categories c ON c.id = s.category_id
             WHERE s.slug = %s LIMIT 1",
            $slug
        ), ARRAY_A );

        if ( ! $service ) { Response::json( [ 'error' => 'Service not found.' ], 404 ); return; }

        $service = \S2NRI\Services\ServiceRegistry::normalizeServiceRow( $service );
        if ( $action === 'unavailable' ) {
            Response::json( [
                'service'     => [ 'slug' => $slug, 'name' => $service['name'], 'unavailable' => true ],
                'unavailable' => true,
                'message'     => 'This service is currently unavailable.',
            ] );
            return;
        }
        if ( ! \S2NRI\Services\ServiceRegistry::isVisibleOnSurface( $service, 'direct_url' )
            && in_array( $service['public_status'] ?? '', [ 'hidden', 'disabled', 'draft' ], true ) ) {
            Response::json( [ 'error' => 'Service not found.' ], 404 );
            return;
        }

        // TRACE: Load form fields from s2nri_form_fields (admin-managed, authoritative source).
        //        If admin has defined fields for this service, they override form_schema JSON.
        //        Each field row includes: field_key, label, field_type, step, required,
        //        placeholder, help_text, options (JSON array), conditions (JSON), is_active, sort_order.
        //        Options for dropdown/searchable also pull from s2nri_field_options table.
        //        POSTCONDITION: service.form_schema is always an array of field objects.
        $db_fields = $wpdb->get_results( $wpdb->prepare(
            "SELECT f.id, f.field_key AS `key`, f.label, f.field_type AS `type`,
                    f.step, f.required, f.placeholder, f.help_text AS description,
                    f.options, f.conditions, f.is_active, f.sort_order
             FROM {$p}s2nri_form_fields f
             WHERE f.service_id = %d AND f.is_active = 1
             ORDER BY f.sort_order ASC, f.id ASC",
            $service['id']
        ), ARRAY_A );

        if ( ! empty( $db_fields ) ) {
            // Use admin-defined fields — build form_schema matching NewRequest.jsx field format
            $schema = [];
            foreach ( $db_fields as $f ) {
                // Inline options from field definition
                $opts = $f['options'] ? ( json_decode( $f['options'], true ) ?: [] ) : [];

                // For dropdown/searchable/radio: merge with s2nri_field_options table entries
                if ( in_array( $f['type'], ['select','searchable','radio','checkbox'], true ) ) {
                    $db_opts = $wpdb->get_col( $wpdb->prepare(
                        "SELECT option_value FROM {$p}s2nri_field_options
                         WHERE field_id = %d ORDER BY sort_order ASC, id ASC",
                        $f['id']
                    ) );
                    if ( ! empty( $db_opts ) ) {
                        $opts = array_values( array_unique( array_merge( $opts, $db_opts ) ) );
                    }
                }

                // Normalize field_type: keep 'select' as 'select' so FieldRenderer's
                // `if (type === 'select')` branch renders the <select> element correctly.
                // Both 'select' and 'dropdown' are treated as a dropdown — map 'dropdown' → 'select'
                // so there is only one canonical type name on the frontend.
                $type = $f['type'];
                if ( $type === 'dropdown' ) $type = 'select'; // normalize stored alias to canonical

                $conditions = $f['conditions'] ? json_decode( $f['conditions'], true ) : null;

                $schema[] = [
                    'key'         => $f['key'],
                    'label'       => $f['label'],
                    'type'        => $type,
                    'required'    => (bool) $f['required'],
                    'placeholder' => $f['placeholder'] ?: '',
                    'description' => $f['description'] ?: '',
                    'options'     => $opts,
                    'conditions'  => $conditions,
                    'step'        => (int) $f['step'],
                ];
            }
            $service['form_schema'] = $schema;
        } else {
            // Fallback: decode existing form_schema JSON blob (legacy/seeded services)
            $raw_schema = $service['form_schema']
                ? json_decode( $service['form_schema'], true )
                : [];

            if ( ! empty( $raw_schema ) ) {
                // Auto-assign step numbers. Covers all 45 seeded services exactly.
                // Step 1: What service / what type / classification
                // Step 2: Record identifiers, institutional info, document-specific data
                // Step 3: Who the applicant is — personal details, contact, location, context
                // Step 4: Delivery address, file uploads, schedule, final remarks
                $step1_keys = [
                    'service_type','document_type','application_type','attestation_required',
                    'urgency','affidavit_type','change_type','certificate_type','reason_duplicate',
                    'exam_type','institution_type','indian_origin','aos_or_consular','relationship',
                    'ead_category','visa_type','need_notarised',
                    'property_type','services_needed','inspection_areas','furnishing',
                    'account_type','withdrawal_type','service_frequency',
                    'poa_type','registration',
                    'bill_types','transaction_type','income_sources','tds_source',
                    'basis','reason_renewal','renewal_reason',
                    'risk_appetite','preferred_tenant',
                    'consultation_mode',
                ];
                $step2_keys = [
                    'university_name','college_name','university','enrollment_no','enrollment_number',
                    'year_admission','year_passing','year_passed','exam_year','roll_number',
                    'course_name','exam_board','institution_name','issuing_state','birth_state',
                    'oci_number','foreign_passport','passport_number','current_passport',
                    'uscis_receipt','petitioner_name','alien_number','new_passport',
                    'source_language','target_language',
                    'doc_count','copies_required','property_size',
                    'pan_number','uan_number','pf_number','survey_no','bhk',
                    'assessment_year','account_numbers','last_exam',
                    'taluk_district','period_required','years_to_check',
                    'investment_amount','remittance_amount','approx_income','approx_tds',
                    'annual_turnover','expected_rent','monthly_rent',
                    'property_details','assets_india','beneficiaries',
                    'birth_city','gc_since','gc_expiry',
                ];
                $step3_keys = [
                    'applicant_name','first_name','last_name','deponent_name','testator_name',
                    'sender_name','principal_name','agent_name','business_name',
                    'father_name','mother_name','hospital_name','executor_name',
                    'dob','deponent_dob','priority_date','travel_date','leaving_date',
                    'phone','current_location','current_country','country_residing','country',
                    'country_citizen','nationality','state','city','indian_address',
                    'current_address','current_status',
                    'consulate','pan_available','business_type',
                    'tenant_name','owner_name','owner_contact','preferred_bank',
                    'agent_address','recipient_name','recipient_address',
                    'rent_due_date','bank_details','lease_period','start_date','security_deposit',
                    'property_address',
                    'purpose','destination','destination_country','destination_univ',
                    'purpose_remittance','source_of_funds',
                    'employer_name','employer_details','entry_port','previous_visas',
                    'country_tax','indian_bank','bank_account','ifsc',
                    'brief_facts','details','relief_sought','matter_type',
                    'access_details',
                    'address_to_send','special_content',
                    'expiry_date',
                ];
                $step4_keys = [
                    'delivery_address','delivery_location',
                    'documents',
                    'frequency','timeline','deadline',
                    'remarks','special_clauses','additional_details','description',
                    'service_frequency','services_needed',
                ];

                foreach ( $raw_schema as &$field ) {
                    if ( ! empty( $field['step'] ) ) continue; // already has step
                    $k = $field['key'] ?? '';
                    if      ( in_array( $k, $step1_keys, true ) ) { $field['step'] = 1; }
                    elseif  ( in_array( $k, $step2_keys, true ) ) { $field['step'] = 2; }
                    elseif  ( in_array( $k, $step3_keys, true ) ) { $field['step'] = 3; }
                    elseif  ( in_array( $k, $step4_keys, true ) ) { $field['step'] = 4; }
                    else    { $field['step'] = 1; } // default to step 1 for unrecognised keys
                }
                unset( $field );
            }

            $service['form_schema'] = $raw_schema;
        }

        // PRIORITY ORDER for the booking form (highest to lowest):
        // 1. s2nri_form_fields (admin-edited via portal) — already set above as $service['form_schema']
        // 2. qualification_schema (seeded multi-step JSON) — only used when no db_fields exist
        // 3. form_schema JSON blob — legacy fallback
        //
        // qualification_schema is only the INITIAL source. Once admin edits fields
        // in the portal (which auto-imports qualification_schema on first open),
        // the db_fields become authoritative and qualification_schema is ignored.
        if ( empty( $db_fields ) && ! empty( $service['qualification_schema'] ) ) {
            $qs = json_decode( $service['qualification_schema'], true );
            if ( is_array( $qs ) && count( $qs ) > 0 ) {
                $service['form_schema']          = $qs;
                $service['qualification_schema'] = $qs;
            }
        } elseif ( ! empty( $db_fields ) ) {
            // db_fields were already set as form_schema above — also expose qualification_schema
            // for backward-compat consumers, but populated from db_fields not the raw JSON
            $service['qualification_schema'] = $service['form_schema'];
        }
        if ( $service['required_docs'] ) {
            $service['required_docs'] = json_decode( $service['required_docs'], true );
        }

        Response::json( [ 'service' => $service ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// SettingsController
// ══════════════════════════════════════════════════════════════════════════════

class SettingsController extends BaseController {

    public function getPublic( Request $req ): void {
        Response::json( [ 'settings' => \S2NRI\Models\Setting::getPublic() ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// ProfileController
// ══════════════════════════════════════════════════════════════════════════════

class ProfileController extends BaseController {

    public function show( Request $req ): void {
        global $wpdb;
        $profile = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}s2nri_customers WHERE wp_user_id = %d LIMIT 1",
            $this->user['wp_id']
        ), ARRAY_A );

        $wp_user = get_user_by( 'id', $this->user['wp_id'] );
        Response::json( [
            'user'    => $this->user,
            'profile' => $profile,
            'name'    => $wp_user ? $wp_user->display_name : '',
            'email'   => $wp_user ? $wp_user->user_email : '',
            // ADDED: s2nri_2fa_enabled is real WordPress user meta, not a
            // column on s2nri_customers — it would never have appeared
            // in the 'profile' field above. Exposed as its own top-level
            // field so the frontend's TwoFactorCard can read the real
            // current state.
            'two_factor_enabled' => get_user_meta( $this->user['wp_id'], 's2nri_2fa_enabled', true ) === '1',
        ] );
    }

    public function update( Request $req ): void {
        global $wpdb;
        $name        = sanitize_text_field( $req->input( 'name', '' ) );
        $phone       = sanitize_text_field( $req->input( 'phone', '' ) );
        $whatsapp    = sanitize_text_field( $req->input( 'whatsapp', '' ) );
        $country     = sanitize_text_field( $req->input( 'country', '' ) );
        $city_abroad = sanitize_text_field( $req->input( 'city_abroad', '' ) );
        $city_india  = sanitize_text_field( $req->input( 'city_india', '' ) );
        $address_india  = sanitize_textarea_field( $req->input( 'address_india', '' ) );
        $address_abroad = sanitize_textarea_field( $req->input( 'address_abroad', '' ) );
        $first_name     = sanitize_text_field( $req->input( 'first_name', '' ) );
        $last_name      = sanitize_text_field( $req->input( 'last_name', '' ) );

        if ( $name ) {
            wp_update_user( [ 'ID' => $this->user['wp_id'], 'display_name' => $name ] );
        }

        if ( ! \S2NRI\Models\User::ensureCustomerProfile( $this->user['wp_id'] ) ) {
            // CHECKED (was previously fire-and-forget): if this fails, the
            // customer row genuinely doesn't exist, and the update() call
            // below would match zero rows and return 0 (not false) — the
            // "=== false" check on that update alone would NOT have
            // caught this, since 0 !== false under strict comparison.
            // Stop here instead of silently reporting success on a save
            // that touched nothing.
            Response::json( [ 'error' => 'Failed to prepare your profile. Please try again.' ], 500 ); return;
        }

        // FIXED: array_filter(compact(...)) previously silently dropped
        // any of these fields if the customer submitted an empty string —
        // e.g. clearing the Phone field and clicking Save appeared to
        // work in the UI, but the old phone number remained in the
        // database because the empty string never reached the UPDATE.
        // These are plain text inputs (confirmed against
        // src/pages/customer/index.tsx) a customer can legitimately
        // clear, so an explicitly-submitted empty value must overwrite,
        // not be silently skipped.
        $update = compact( 'phone', 'whatsapp', 'country', 'city_abroad', 'city_india' );
        if ( $address_india )  $update['address_india']  = $address_india;
        if ( $address_abroad ) $update['address_abroad'] = $address_abroad;
        if ( $first_name )     $update['first_name']     = $first_name;
        if ( $last_name )      $update['last_name']      = $last_name;
        $update['updated_at'] = current_time( 'mysql' );

        if ( $wpdb->update(
            $wpdb->prefix . 's2nri_customers',
            $update,
            [ 'wp_user_id' => $this->user['wp_id'] ]
        ) === false ) {
            Response::json( [ 'error' => 'Failed to update profile. Please try again.' ], 500 ); return;
        }

        Response::json( [ 'success' => true, 'message' => 'Profile updated successfully.' ] );
    }

    public function changePassword( Request $req ): void {
        $current = $req->input( 'current_password', '' );
        $new     = $req->input( 'new_password', '' );

        if ( strlen( $new ) < 8 ) {
            Response::json( [ 'error' => 'New password must be at least 8 characters.' ], 422 ); return;
        }

        $wp_user = get_user_by( 'id', $this->user['wp_id'] );
        if ( ! wp_check_password( $current, $wp_user->user_pass, $wp_user->ID ) ) {
            Response::json( [ 'error' => 'Current password is incorrect.' ], 422 ); return;
        }

        wp_set_password( $new, $wp_user->ID );
        Response::json( [ 'success' => true, 'message' => 'Password changed. Please log in again.' ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// NotificationController
// ══════════════════════════════════════════════════════════════════════════════

class NotificationController extends BaseController {

    public function index( Request $req ): void {
        global $wpdb;
        [ 'page' => $page, 'per_page' => $per_page ] = $this->paginate( $req );
        $offset = $this->offset( $page, $per_page );

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}s2nri_notifications WHERE user_id = %d",
            $this->user['wp_id']
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}s2nri_notifications
             WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $this->user['wp_id'], $per_page, $offset
        ), ARRAY_A );

        $unread = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}s2nri_notifications WHERE user_id = %d AND is_read = 0",
            $this->user['wp_id']
        ) );

        Response::json( compact( 'rows', 'total', 'page', 'per_page', 'unread' ) );
    }

    public function markRead( Request $req ): void {
        global $wpdb;
        $id = (int) $req->param( 'id' );
        if ( $wpdb->update(
            $wpdb->prefix . 's2nri_notifications',
            [ 'is_read' => 1, 'read_at' => current_time( 'mysql' ) ],
            [ 'id' => $id, 'user_id' => $this->user['wp_id'] ]
        ) === false ) {
            Response::json( [ 'error' => 'Failed to update notification.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    public function markAllRead( Request $req ): void {
        global $wpdb;
        if ( $wpdb->update(
            $wpdb->prefix . 's2nri_notifications',
            [ 'is_read' => 1, 'read_at' => current_time( 'mysql' ) ],
            [ 'user_id' => $this->user['wp_id'], 'is_read' => 0 ]
        ) === false ) {
            Response::json( [ 'error' => 'Failed to update notifications.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// MessageController
// ══════════════════════════════════════════════════════════════════════════════

class MessageController extends BaseController {

    public function list( Request $req ): void {
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = (int) $req->param( 'id' );

        // Verify access
        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, cu.wp_user_id FROM {$p}s2nri_bookings b
             JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             WHERE b.id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $booking ) { Response::json( [ 'error' => 'Booking not found.' ], 404 ); return; }
        if ( (int) $booking['wp_user_id'] !== $this->user['wp_id'] ) {
            Response::json( [ 'error' => 'Access denied.' ], 403 ); return;
        }

        $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.*, COALESCE(u.display_name, u.user_login, '') AS sender_name
             FROM {$p}s2nri_messages m
             LEFT JOIN {$p}users u ON u.ID = m.sender_id
             WHERE m.booking_id = %d AND m.is_internal = 0
             ORDER BY m.created_at ASC",
            $id
        ), ARRAY_A );

        // Mark staff messages as read
        if ( $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}s2nri_messages SET is_read = 1, read_at = %s
             WHERE booking_id = %d AND sender_type = 'staff' AND is_read = 0",
            current_time( 'mysql' ), $id
        ) ) === false ) {
            error_log( '[S2NRI] Failed to mark messages read for booking ' . $id . ': ' . $wpdb->last_error );
            // Non-blocking — the message list itself still loads correctly either way
        }

        Response::json( [ 'messages' => $messages ] );
    }

    public function send( Request $req ): void {
        global $wpdb;
        $p          = $wpdb->prefix;
        $id         = (int) $req->param( 'id' );
        $message    = sanitize_textarea_field( $req->input( 'message', '' ) );
        // send_email=true = customer explicitly clicked "Send via Email" (copy to their inbox)
        // send_email=false (default) = chat-only, no email to customer
        $send_email = filter_var( $req->input( 'send_email', false ), FILTER_VALIDATE_BOOLEAN );

        if ( strlen( $message ) < 2 ) {
            Response::json( [ 'error' => 'Message cannot be empty.' ], 422 ); return;
        }

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, cu.wp_user_id, u.user_email AS cust_email, COALESCE(u.display_name, u.user_login, '') AS cust_name
             FROM {$p}s2nri_bookings b
             JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             JOIN {$p}users u ON u.ID = cu.wp_user_id
             WHERE b.id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $booking ) { Response::json( [ 'error' => 'Booking not found.' ], 404 ); return; }
        if ( (int) $booking['wp_user_id'] !== $this->user['wp_id'] ) {
            Response::json( [ 'error' => 'Access denied.' ], 403 ); return;
        }
        if ( in_array( $booking['status'], [ 'cancelled', 'completed' ], true ) ) {
            Response::json( [ 'error' => 'Cannot send messages on closed bookings.' ], 422 ); return;
        }

        $msg_insert = $wpdb->insert( $p . 's2nri_messages', [
            'booking_id'  => $id,
            'sender_id'   => $this->user['wp_id'],
            'sender_type' => 'customer',
            'message'     => $message,
            'is_internal' => 0,
            'created_at'  => current_time( 'mysql' ),
        ] );
        if ( $msg_insert === false ) {
            error_log( '[S2NRI] Customer message insert failed for booking ' . $id . ': ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to send message. Please try again.' ], 500 ); return;
        }

        // Always notify admins in-app
        NotificationService::notifyAdmins( 'new_customer_message', [
            'booking_id' => $id,
            'title'      => "New message on {$booking['booking_ref']}",
            'body'       => substr( $message, 0, 100 ),
        ] );

        // Only send email to customer when they explicitly clicked "Send via Email"
        if ( $send_email && ! empty( $booking['cust_email'] ) ) {
            try {
                // FIXED: EmailService has no send() method at all (checked
                // its full method list — only sendQuoteEmail,
                // sendStatusUpdate, sendBookingConfirmation,
                // sendPaymentConfirmation, sendWelcomeEmail exist, none
                // named plain send()). This threw "Call to undefined
                // method" every single time, 100% of the time this
                // feature was used — caught by the \Throwable below (so
                // it never crashed the request, the message itself was
                // already saved), but the confirmation email silently
                // never sent, ever. Replaced with the actual working
                // wp_mail() pattern used consistently everywhere else in
                // this file (see sendStatusUpdate, sendPaymentConfirmation
                // — both use wp_mail($to,$subject,$body,['Content-Type:
                // text/html; charset=UTF-8'])).
                $email_body =
                    "<p>Hi {$booking['cust_name']},</p>"
                    . "<p>Your message has been sent to the support team and saved on your dashboard:</p>"
                    . "<blockquote style='border-left:4px solid #0d7ab5;padding:8px 16px;margin:16px 0;color:#374151;'>"
                    . nl2br( esc_html( $message ) )
                    . "</blockquote>"
                    . "<p>You can view the full conversation on your <a href='" . home_url( '/portal/bookings/' . $id ) . "'>booking dashboard</a>.</p>";
                wp_mail(
                    $booking['cust_email'],
                    "Your message on booking {$booking['booking_ref']} has been sent",
                    $email_body,
                    [ 'Content-Type: text/html; charset=UTF-8' ]
                );
            } catch ( \Throwable $e ) {
                error_log( '[S2NRI] Customer message email failed: ' . $e->getMessage() );
                // Non-fatal — message is already saved, email failure shouldn't block the response
            }
        }

        Response::json( [ 'success' => true, 'message' => 'Message sent.' ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// DocumentController
// ══════════════════════════════════════════════════════════════════════════════

class DocumentController extends BaseController {

    public function list( Request $req ): void {
        global $wpdb;
        $id = (int) $req->param( 'id' );

        $docs = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.*, COALESCE(u.display_name, u.user_login, '') AS uploaded_by_name
             FROM {$wpdb->prefix}s2nri_documents d
             JOIN {$wpdb->prefix}users u ON u.ID = d.uploaded_by
             WHERE d.booking_id = %d AND d.is_visible_to_customer = 1
             ORDER BY d.created_at ASC",
            $id
        ), ARRAY_A );

        Response::json( [ 'documents' => $docs ] );
    }

    public function upload( Request $req ): void {
        $id       = (int) $req->param( 'id' );
        $doc_type = sanitize_text_field( $req->input( 'doc_type', 'Document' ) );
        $notes    = sanitize_textarea_field( $req->input( 'notes', '' ) );

        if ( empty( $_FILES['file'] ) ) {
            Response::json( [ 'error' => 'No file uploaded.' ], 422 ); return;
        }

        $file = $_FILES['file'];
        $allowed_types = [ 'application/pdf', 'image/jpeg', 'image/png', 'image/jpg',
                           'application/msword',
                           'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ];

        // Check real MIME
        $finfo = new \finfo( FILEINFO_MIME_TYPE );
        $real_mime = $finfo->file( $file['tmp_name'] );

        if ( ! in_array( $real_mime, $allowed_types, true ) ) {
            Response::json( [ 'error' => 'Only PDF, JPG, PNG, and DOC files are allowed.' ], 422 ); return;
        }

        if ( $file['size'] > 10 * 1024 * 1024 ) {
            Response::json( [ 'error' => 'File size must not exceed 10MB.' ], 422 ); return;
        }

        // Upload via WP media library
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $att_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $att_id ) ) {
            Response::json( [ 'error' => 'Upload failed: ' . $att_id->get_error_message() ], 500 ); return;
        }

        $url = wp_get_attachment_url( $att_id );
        global $wpdb;

        $doc_insert = $wpdb->insert( $wpdb->prefix . 's2nri_documents', [
            'booking_id'            => $id,
            'uploaded_by'           => $this->user['wp_id'],
            'doc_type'              => $doc_type,
            'file_name'             => $file['name'],
            'file_url'              => $url,
            'file_size'             => $file['size'],
            'mime_type'             => $real_mime,
            'is_from_staff'         => 0,
            'is_visible_to_customer'=> 1,
            'notes'                 => $notes,
            'created_at'            => current_time( 'mysql' ),
        ] );
        // CHECKED (was previously unchecked): worse than the usual pattern
        // here — by this point media_handle_upload() already succeeded, so
        // the file physically exists in the WP media library. A failed
        // insert here previously still returned success:true with a
        // (possibly stale) doc_id — the frontend's upload try/catch
        // (ServiceDetailPage.tsx) could never catch this as a failure
        // since HTTP 200 was returned, so the file would silently never
        // appear in the booking's document list with no error anywhere.
        if ( $doc_insert === false ) {
            error_log( '[S2NRI] Document row insert failed for booking ' . $id . ' (file already uploaded to media library, attachment ' . $att_id . '): ' . $wpdb->last_error );
            Response::json( [ 'error' => 'File uploaded but failed to save. Please try again.' ], 500 ); return;
        }

        Response::json( [ 'success' => true, 'url' => $url, 'doc_id' => $wpdb->insert_id ] );
    }

    public function delete( Request $req ): void {
        global $wpdb;
        $doc_id = (int) $req->param( 'doc_id' );

        $doc = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}s2nri_documents WHERE id = %d LIMIT 1", $doc_id
        ), ARRAY_A );

        if ( ! $doc ) { Response::json( [ 'error' => 'Document not found.' ], 404 ); return; }
        if ( (int) $doc['uploaded_by'] !== $this->user['wp_id'] ) {
            Response::json( [ 'error' => 'Access denied.' ], 403 ); return;
        }

        if ( $wpdb->delete( $wpdb->prefix . 's2nri_documents', [ 'id' => $doc_id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to delete document.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// PaymentController (customer side — bank transfer submission)
// ══════════════════════════════════════════════════════════════════════════════

class PaymentController extends BaseController {

    public function submit( Request $req ): void {
        global $wpdb;
        $p           = $wpdb->prefix;
        $id          = (int) $req->param( 'id' );
        $method      = sanitize_key( $req->input( 'method', 'bank_transfer' ) );
        $amount      = (float) $req->input( 'amount', 0 );
        $payment_ref = sanitize_text_field( $req->input( 'payment_ref', '' ) );
        $notes       = sanitize_textarea_field( $req->input( 'notes', '' ) );

        $allowed_methods = [ 'bank_transfer', 'razorpay', 'stripe' ];
        if ( ! in_array( $method, $allowed_methods, true ) ) {
            Response::json( [ 'error' => 'Invalid payment method.' ], 422 ); return;
        }
        if ( $amount <= 0 ) {
            Response::json( [ 'error' => 'Payment amount must be greater than zero.' ], 422 ); return;
        }

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, cu.wp_user_id FROM {$p}s2nri_bookings b
             JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             WHERE b.id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $booking ) { Response::json( [ 'error' => 'Booking not found.' ], 404 ); return; }
        if ( (int) $booking['wp_user_id'] !== $this->user['wp_id'] ) {
            Response::json( [ 'error' => 'Access denied.' ], 403 ); return;
        }

        // Insert payment record (status = pending — staff must verify)
        $payment_result = $wpdb->insert( $p . 's2nri_payments', [
            'booking_id'  => $id,
            'method'      => $method,
            'amount'      => $amount,
            'currency'    => 'INR',
            'status'      => 'pending',
            'payment_ref' => $payment_ref,
            'notes'       => $notes,
            'created_at'  => current_time( 'mysql' ),
        ] );
        if ( $payment_result === false ) {
            Response::json( [ 'error' => 'Failed to record payment. Please try again.' ], 500 ); return;
        }

        if ( $wpdb->update( $p . 's2nri_bookings', [
            'payment_status' => 'bank_transfer_pending',
            'payment_method' => $method,
            'payment_ref'    => $payment_ref,
            'updated_at'     => current_time( 'mysql' ),
        ], [ 'id' => $id ] ) === false ) {
            error_log( '[S2NRI] Booking payment_status update failed after payment submission for booking ' . $id . ': ' . $wpdb->last_error );
            // Non-fatal: the payment row itself is already safely saved
            // and checked above — staff can still see and verify it from
            // the Payments list even if this specific booking-row mirror
            // update failed. Logged for visibility rather than blocking
            // the customer's submission on a secondary, recoverable field.
        }

        NotificationService::notifyAdmins( 'payment_submitted', [
            'booking_id' => $id,
            'title'      => "Payment submitted for {$booking['booking_ref']}",
            'body'       => "Amount: ₹{$amount}. Method: {$method}. Ref: {$payment_ref}. Please verify.",
        ] );

        Response::json( [ 'success' => true, 'message' => 'Payment details submitted. Our team will verify within 24 hours.' ] );
    }

    // ── Razorpay order creation (called before Razorpay checkout opens) ──────

    /**
     * TRACE: POST bookings/{id}/razorpay-order → verify booking is quote_approved
     *        → call Razorpay Orders API → return order_id to frontend.
     *        Preconditions: razorpay_enabled='1', booking.status='quote_approved'.
     *        Postconditions: Razorpay order_id returned. Frontend opens checkout.
     *        Edge cases: API key missing → 503; booking wrong status → 400.
     */
    public function createRazorpayOrder( Request $req ): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $id      = (int) $req->param( 'id' );
        $customer = $this->getCustomerProfile();

        if ( ! $customer ) {
            throw new \S2NRI\Exceptions\AuthException( 'Customer profile not found.' );
        }

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}s2nri_bookings WHERE id = %d AND customer_id = %d LIMIT 1",
            $id, $customer['id']
        ), ARRAY_A );

        if ( ! $booking ) throw new \S2NRI\Exceptions\NotFoundException( 'Booking not found.' );
        if ( $booking['status'] !== 'quote_approved' ) {
            throw new \S2NRI\Exceptions\ValidationException( 'Booking must be in quote_approved status to initiate payment.' );
        }
        if ( ! $booking['quoted_amount'] || $booking['quoted_amount'] <= 0 ) {
            throw new \S2NRI\Exceptions\ValidationException( 'No quoted amount set for this booking.' );
        }

        $key_id     = \S2NRI\Models\Setting::get( 'razorpay_key_id' );
        $key_secret = \S2NRI\Models\Setting::get( 'razorpay_key_secret' );

        // FIXED: this function's own docblock documented
        // "razorpay_enabled='1'" as a precondition, but nothing in the
        // actual code ever checked it — only the frontend read this flag,
        // to decide whether to show the "Pay Online via Razorpay" button.
        // An admin unchecking "Enable Razorpay" in Settings (intending to
        // temporarily disable the payment method, e.g. during
        // maintenance or before keys are fully tested) did NOT actually
        // stop the backend from creating a real order and accepting a
        // real payment if this endpoint was called directly while the
        // keys remained configured — the admin's toggle only hid a
        // button, it enforced nothing. Now genuinely enforced server-side,
        // matching what the docblock always claimed.
        if ( \S2NRI\Models\Setting::get( 'razorpay_enabled' ) !== '1' ) {
            throw new \S2NRI\Exceptions\ValidationException( 'Online payment is not currently available. Please use bank transfer or contact support.' );
        }

        if ( ! $key_id || ! $key_secret ) {
            throw new \S2NRI\Exceptions\ValidationException( 'Razorpay is not configured. Please contact support.' );
        }

        // Amount in paise (Razorpay requires smallest currency unit)
        $amount_paise = (int) round( (float) $booking['quoted_amount'] * 100 );

        $order_data = [
            'amount'          => $amount_paise,
            'currency'        => 'INR',
            'receipt'         => 'S2NRI-' . $id . '-' . time(),
            'notes'           => [
                'booking_id'  => $id,
                'booking_ref' => $booking['booking_ref'],
                'platform'    => 'services2nri',
            ],
        ];

        $response = wp_remote_post( 'https://api.razorpay.com/v1/orders', [
            'timeout' => 15,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . base64_encode( "{$key_id}:{$key_secret}" ),
            ],
            'body' => wp_json_encode( $order_data ),
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[S2NRI Razorpay] Order creation failed: ' . $response->get_error_message() );
            throw new \S2NRI\Exceptions\ValidationException( 'Payment service unavailable. Please try bank transfer or contact support.' );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $code = wp_remote_retrieve_response_code( $response );

        if ( $code !== 200 || empty( $body['id'] ) ) {
            error_log( '[S2NRI Razorpay] API error: ' . wp_json_encode( $body ) );
            throw new \S2NRI\Exceptions\ValidationException( 'Failed to create payment order. Please try again.' );
        }

        // Store Razorpay order_id for verification later. CHECKED (was
        // previously unchecked): if this insert fails, the frontend would
        // otherwise still get a valid order_id/key_id and the customer
        // could complete payment through Razorpay's checkout with no row
        // ever recorded — the webhook handler later looks up this exact
        // row by payment_ref and silently does nothing if it's missing,
        // so the booking would never be marked paid. Same check-and-fail
        // pattern already used for the bank-transfer path above.
        $payment_insert = $wpdb->insert( $p . 's2nri_payments', [
            'booking_id'        => $id,
            'customer_id'       => $customer['id'],
            'amount'            => $booking['quoted_amount'],
            'method'            => 'razorpay',
            'payment_ref'       => $body['id'], // Razorpay order_id
            'status'            => 'pending',
            'notes'             => 'Razorpay order created',
            'created_at'        => current_time( 'mysql' ),
            'updated_at'        => current_time( 'mysql' ),
        ] );
        if ( $payment_insert === false ) {
            error_log( '[S2NRI Razorpay] Failed to record pending payment row for order ' . $body['id'] . ': ' . $wpdb->last_error );
            throw new \S2NRI\Exceptions\ValidationException( 'Failed to prepare payment. Please try again.' );
        }

        Response::json( [
            'order_id'   => $body['id'],
            'amount'     => $amount_paise,
            'currency'   => 'INR',
            'key_id'     => $key_id,
            'booking_ref'=> $booking['booking_ref'],
            'customer_name'  => get_user_by( 'id', $this->user['wp_id'] )->display_name,
            'customer_email' => $this->user['email'],
        ] );
    }

    // ── Razorpay payment verification (called after frontend checkout succeeds) ─

    /**
     * TRACE: POST bookings/{id}/razorpay-verify → receive razorpay_payment_id + signature
     *        → verify HMAC signature → mark payment verified → update booking.
     *        Preconditions: razorpay_payment_id, razorpay_order_id, razorpay_signature in body.
     *        Postconditions: payment.status='verified', booking.payment_status='paid'.
     *        Edge cases: invalid signature → 400 (fraud prevention).
     */
    public function verifyRazorpayPayment( Request $req ): void {
        global $wpdb;
        $p          = $wpdb->prefix;
        $id         = (int) $req->param( 'id' );
        $customer   = $this->getCustomerProfile();

        if ( ! $customer ) throw new \S2NRI\Exceptions\AuthException( 'Customer profile not found.' );

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}s2nri_bookings WHERE id = %d AND customer_id = %d LIMIT 1",
            $id, $customer['id']
        ), ARRAY_A );
        if ( ! $booking ) throw new \S2NRI\Exceptions\NotFoundException( 'Booking not found.' );

        $payment_id  = sanitize_text_field( $req->input('razorpay_payment_id', '' ) );
        $order_id    = sanitize_text_field( $req->input('razorpay_order_id',   '' ) );
        $signature   = sanitize_text_field( $req->input('razorpay_signature',  '' ) );

        if ( ! $payment_id || ! $order_id || ! $signature ) {
            throw new \S2NRI\Exceptions\ValidationException( 'Missing payment verification fields.' );
        }

        $key_secret = \S2NRI\Models\Setting::get( 'razorpay_key_secret' );

        // HMAC-SHA256 signature verification — prevents fraud
        $expected = hash_hmac( 'sha256', "{$order_id}|{$payment_id}", $key_secret );
        if ( ! hash_equals( $expected, $signature ) ) {
            error_log( "[S2NRI Razorpay] Signature mismatch for booking {$id}. order={$order_id} payment={$payment_id}" );
            throw new \S2NRI\Exceptions\ValidationException( 'Payment verification failed. Please contact support.' );
        }

        // Update payment row with verified payment_id
        // CHECKED (was previously unchecked): this is the single most
        // money-critical unchecked write found in this entire audit — if
        // either update below silently failed, the function still fell
        // through to Response::json(['success'=>true, 'message'=>
        // 'Payment verified successfully...']) even though the booking's
        // payment_status may never have actually changed to 'paid' in the
        // database. The customer's money is genuinely captured by
        // Razorpay and the HMAC signature genuinely verified by this
        // point — only the DB write recording that fact was unverified.
        $payment_update = $wpdb->update(
            $p . 's2nri_payments',
            [
                'status'        => 'verified',
                'payment_ref'   => $payment_id,
                'notes'         => "Razorpay payment verified. Order: {$order_id}",
                'verified_at'   => current_time( 'mysql' ),
                'updated_at'    => current_time( 'mysql' ),
            ],
            [
                'booking_id' => $id,
                'method'     => 'razorpay',
                'status'     => 'pending',
            ]
        );

        // Update booking payment status
        $booking_update = $wpdb->update( $p . 's2nri_bookings', [
            'payment_status' => 'paid',
            'paid_amount'    => $booking['quoted_amount'],
            'updated_at'     => current_time( 'mysql' ),
        ], [ 'id' => $id ] );

        if ( $payment_update === false || $booking_update === false ) {
            // The signature is genuinely valid and the customer's money is
            // genuinely captured — this is NOT a "payment failed" state,
            // it's "payment succeeded but our own record of it failed to
            // save". Do not tell the customer their payment failed (it
            // didn't); do not silently claim success either. Log loudly
            // with everything needed to manually reconcile, and tell the
            // customer support will confirm — this is the one path in the
            // whole payment flow where a human MUST be alerted rather than
            // the system self-healing, since retrying the signature
            // verification isn't possible (Razorpay only sends it once).
            error_log( "[S2NRI Razorpay] CRITICAL: payment verified (signature valid, payment_id={$payment_id}) but DB update failed for booking {$id}. payment_update=" . var_export( $payment_update, true ) . " booking_update=" . var_export( $booking_update, true ) . " wpdb_error=" . $wpdb->last_error );
            \S2NRI\Services\NotificationService::notifyAdmins( 'payment_verify_db_failure', [
                'booking_id' => $id,
                'title'      => "URGENT: Payment DB write failed for {$booking['booking_ref']}",
                'body'       => "Razorpay payment {$payment_id} was verified (signature valid, customer was charged) but saving it to the booking failed. Manual reconciliation required.",
            ] );
            Response::json( [
                'success' => true,
                'message' => 'Your payment was received. We are confirming it on our end — you will receive an update shortly. If you do not hear from us within a few hours, please contact support with your booking reference.',
            ] );
            return;
        }

        $this->logAudit( $id, 'payment_verified', 'pending', 'verified', [
            'method'     => 'razorpay',
            'payment_id' => $payment_id,
            'amount'     => (float) $booking['quoted_amount'],
        ] );

        \S2NRI\Services\NotificationService::notifyAdmins( 'payment_verified', [
            'booking_id' => $id,
            'title'      => "Payment Verified: {$booking['booking_ref']}",
            'body'       => "Razorpay payment of ₹{$booking['quoted_amount']} verified for booking {$booking['booking_ref']}.",
        ] );

        // M-14: Fire WhatsApp payment hook
        do_action( 's2nri_payment_verified', $id, [
            'booking_ref'   => $booking['booking_ref'],
            'customer_name' => '',
            'service_name'  => '',
            'amount'        => $booking['quoted_amount'],
        ] );

        Response::json( [
            'success' => true,
            'message' => 'Payment verified successfully. Your booking is now being processed.',
        ] );
    }

    // ── Razorpay webhook (called by Razorpay server — signature must be verified) ─

    /**
     * TRACE: POST /api/v1/razorpay-webhook → verify X-Razorpay-Signature header
     *        → handle payment.captured event → idempotency check → update booking.
     *        Preconditions: razorpay_webhook_secret configured.
     *        Postconditions: Booking payment_status='paid' on payment.captured event.
     *        Edge cases: invalid signature → 401 logged, duplicate event → idempotent (no double-pay).
     */
    public function razorpayWebhook( Request $req ): void {
        $webhook_secret = \S2NRI\Models\Setting::get( 'razorpay_webhook_secret' );
        $raw_body       = file_get_contents( 'php://input' );
        $signature      = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

        // SECURITY FIX (pre-launch): previously "if ($webhook_secret) {
        // verify... }" — meaning if razorpay_webhook_secret was left
        // unconfigured (an easy thing to skip during setup, especially
        // since this field did not even exist in the admin UI until
        // earlier in this same audit session), signature verification was
        // SKIPPED ENTIRELY and this endpoint processed payment.captured
        // events from ANYONE, unauthenticated. An attacker who obtained
        // or guessed a valid order_id could mark any booking "paid" for
        // free — a real payment-fraud bypass, not a theoretical one. Fail
        // CLOSED instead: no configured secret means this feature isn't
        // set up, not "trust every caller".
        if ( ! $webhook_secret ) {
            error_log( '[S2NRI Razorpay Webhook] Rejected: razorpay_webhook_secret is not configured. Configure it in Settings before Razorpay webhooks can be processed.' );
            http_response_code( 401 );
            exit;
        }
        $expected = hash_hmac( 'sha256', $raw_body, $webhook_secret );
        if ( ! hash_equals( $expected, $signature ) ) {
            error_log( '[S2NRI Razorpay Webhook] Invalid signature.' );
            http_response_code( 401 );
            exit;
        }

        $event = json_decode( $raw_body, true );
        if ( empty( $event['event'] ) ) { Response::json( [ 'received' => true ] ); return; }

        // Handle payment captured event
        if ( $event['event'] === 'payment.captured' ) {
            global $wpdb;
            $p          = $wpdb->prefix;
            $payment    = $event['payload']['payment']['entity'] ?? [];
            $order_id   = $payment['order_id'] ?? '';
            $payment_id = $payment['id'] ?? '';
            $amount     = isset( $payment['amount'] ) ? $payment['amount'] / 100 : 0;

            if ( $order_id ) {
                // Find our payment row by Razorpay order_id (stored in payment_ref)
                $pay_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$p}s2nri_payments WHERE payment_ref = %s AND method = 'razorpay' LIMIT 1",
                    $order_id
                ), ARRAY_A );

                if ( $pay_row && $pay_row['status'] !== 'verified' ) {
                    // Idempotency: only process if not already verified
                    $wh_pay_update = $wpdb->update( $p . 's2nri_payments', [
                        'status'      => 'verified',
                        'payment_ref' => $payment_id,
                        'notes'       => "Webhook: payment.captured. Amount: ₹{$amount}",
                        'verified_at' => current_time( 'mysql' ),
                        'updated_at'  => current_time( 'mysql' ),
                    ], [ 'id' => $pay_row['id'] ] );

                    $wh_booking_update = $wpdb->update( $p . 's2nri_bookings', [
                        'payment_status' => 'paid',
                        'paid_amount'    => $amount,
                        'updated_at'     => current_time( 'mysql' ),
                    ], [ 'id' => $pay_row['booking_id'] ] );

                    // CHECKED (was previously unchecked) — same
                    // money-critical class as verifyRazorpayPayment()
                    // above. Razorpay retries webhooks that don't return
                    // 200, so returning a non-200 here on DB failure lets
                    // Razorpay's own retry mechanism recover it — unlike
                    // verifyRazorpayPayment() (a one-shot client call),
                    // this is safe to signal failure on.
                    if ( $wh_pay_update === false || $wh_booking_update === false ) {
                        error_log( "[S2NRI Razorpay Webhook] DB update failed for booking {$pay_row['booking_id']}: " . $wpdb->last_error );
                        http_response_code( 500 );
                        exit;
                    }

                    error_log( "[S2NRI Razorpay Webhook] Payment captured for booking {$pay_row['booking_id']}: ₹{$amount}" );
                }
            }
        }

        Response::json( [ 'received' => true ] );
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function getCustomerProfile(): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}s2nri_customers WHERE wp_user_id = %d LIMIT 1",
            $this->user['wp_id']
        ), ARRAY_A ) ?: null;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// TicketController (customer)
// ══════════════════════════════════════════════════════════════════════════════

class TicketController extends BaseController {

    public function index( Request $req ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $customer = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$p}s2nri_customers WHERE wp_user_id = %d LIMIT 1",
            $this->user['wp_id']
        ), ARRAY_A );

        if ( ! $customer ) { Response::json( [ 'tickets' => [] ] ); return; }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.*, b.booking_ref
             FROM {$p}s2nri_tickets t
             LEFT JOIN {$p}s2nri_bookings b ON b.id = t.booking_id
             WHERE t.customer_id = %d
             ORDER BY t.updated_at DESC LIMIT 50",
            $customer['id']
        ), ARRAY_A );

        Response::json( [ 'tickets' => $rows ] );
    }

    public function store( Request $req ): void {
        global $wpdb;
        $p          = $wpdb->prefix;
        $subject    = sanitize_text_field( $req->input( 'subject', '' ) );
        $message    = sanitize_textarea_field( $req->input( 'message', '' ) );
        $booking_id = (int) $req->input( 'booking_id', 0 );

        if ( strlen( $subject ) < 5 ) {
            Response::json( [ 'error' => 'Subject must be at least 5 characters.' ], 422 ); return;
        }

        // CHECKED (was previously ignored): ensureCustomerProfile() now
        // returns bool (fixed earlier this session) — if it fails,
        // $customer would be null below, and $customer['id'] on null
        // throws a real PHP warning while inserting a null customer_id,
        // orphaning the ticket from any real customer record.
        if ( ! \S2NRI\Models\User::ensureCustomerProfile( $this->user['wp_id'] ) ) {
            Response::json( [ 'error' => 'Failed to prepare your account. Please try again.' ], 500 ); return;
        }
        $customer = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$p}s2nri_customers WHERE wp_user_id = %d LIMIT 1",
            $this->user['wp_id']
        ), ARRAY_A );

        $ticket_insert = $wpdb->insert( $p . 's2nri_tickets', [
            'booking_id'  => $booking_id ?: null,
            'customer_id' => $customer['id'],
            'subject'     => $subject,
            'status'      => 'open',
            'created_at'  => current_time( 'mysql' ),
            'updated_at'  => current_time( 'mysql' ),
        ] );
        // CHECKED (was previously unchecked): insert_id does not reset to
        // 0 on failure — a silently failed insert here would previously
        // still cascade a stale id into the message insert below AND the
        // response's ticket_id, telling the customer their support
        // ticket (potentially about an urgent issue) was created when it
        // was not, and silently losing their message too.
        if ( $ticket_insert === false ) {
            error_log( '[S2NRI] Support ticket insert failed for customer ' . $customer['id'] . ': ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to create support ticket. Please try again.' ], 500 ); return;
        }
        $ticket_id = (int) $wpdb->insert_id;

        if ( $message ) {
            if ( $wpdb->insert( $p . 's2nri_ticket_messages', [
                'ticket_id'   => $ticket_id,
                'sender_id'   => $this->user['wp_id'],
                'sender_type' => 'customer',
                'message'     => $message,
                'created_at'  => current_time( 'mysql' ),
            ] ) === false ) {
                error_log( '[S2NRI] Ticket message insert failed for ticket ' . $ticket_id . ': ' . $wpdb->last_error );
                // Non-fatal — the ticket itself is already safely created
                // and checked above; staff can still see it exists even
                // if the initial message failed to attach.
            }
        }

        Response::json( [ 'success' => true, 'ticket_id' => $ticket_id, 'message' => 'Support ticket created.' ], 201 );
    }

    public function show( Request $req ): void {
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = (int) $req->param( 'id' );

        $customer = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$p}s2nri_customers WHERE wp_user_id = %d LIMIT 1",
            $this->user['wp_id']
        ), ARRAY_A );

        $ticket = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}s2nri_tickets WHERE id = %d AND customer_id = %d LIMIT 1",
            $id, $customer['id'] ?? 0
        ), ARRAY_A );

        if ( ! $ticket ) { Response::json( [ 'error' => 'Ticket not found.' ], 404 ); return; }

        $ticket['messages'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.*, COALESCE(u.display_name, u.user_login, '') AS sender_name
             FROM {$p}s2nri_ticket_messages m
             LEFT JOIN {$p}users u ON u.ID = m.sender_id
             WHERE m.ticket_id = %d ORDER BY m.created_at ASC",
            $id
        ), ARRAY_A );

        Response::json( [ 'ticket' => $ticket ] );
    }

    public function sendMessage( Request $req ): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $id      = (int) $req->param( 'id' );
        $message = sanitize_textarea_field( $req->input( 'message', '' ) );

        if ( ! $message ) { Response::json( [ 'error' => 'Message required.' ], 422 ); return; }

        $customer = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$p}s2nri_customers WHERE wp_user_id = %d LIMIT 1",
            $this->user['wp_id']
        ), ARRAY_A );

        $ticket = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$p}s2nri_tickets WHERE id = %d AND customer_id = %d LIMIT 1",
            $id, $customer['id'] ?? 0
        ), ARRAY_A );

        if ( ! $ticket ) { Response::json( [ 'error' => 'Ticket not found.' ], 404 ); return; }

        if ( $wpdb->insert( $p . 's2nri_ticket_messages', [
            'ticket_id'   => $id,
            'sender_id'   => $this->user['wp_id'],
            'sender_type' => 'customer',
            'message'     => $message,
            'created_at'  => current_time( 'mysql' ),
        ] ) === false ) {
            error_log( '[S2NRI] Customer ticket reply insert failed for ticket ' . $id . ': ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to send your reply. Please try again.' ], 500 ); return;
        }

        if ( $wpdb->update( $p . 's2nri_tickets', [ 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] ) === false ) {
            error_log( '[S2NRI] Failed to touch ticket updated_at for id ' . $id . ': ' . $wpdb->last_error );
            // Non-fatal — the reply itself is already safely saved and checked above.
        }
        Response::json( [ 'success' => true ] );
    }

    /**
     * reply() — TRACE: POST tickets/{id}/replies {message} → inserts into s2nri_ticket_messages.
     *   Alias for sendMessage() — Dispatcher routes both tickets/{id}/replies and tickets/{id}/messages here.
     *   Preconditions: auth required, ticket belongs to customer.
     *   Postconditions: reply inserted, ticket updated_at bumped.
     *   Edge cases: empty message → 422. Ticket not found → 404.
     */
    public function reply( Request $req ): void {
        $this->sendMessage( $req );
    }

    /**
     * byBooking() — GET bookings/{id}/tickets
     * TRACE: Customer portal → lists tickets linked to a specific booking.
     *        Reads booking_id from route param, verifies booking belongs to customer,
     *        returns tickets array. Used by booking detail page ticket tab.
     * Preconditions: auth required, booking_id valid.
     * Postconditions: JSON { tickets: [] } — empty array if none found, never null.
     * Edge cases: booking not owned by customer → empty array (no 403 leak).
     */
    public function byBooking( Request $req ): void {
        global $wpdb;
        $p          = $wpdb->prefix;
        $booking_id = (int) $req->param( 'id' );

        \S2NRI\Models\User::ensureCustomerProfile( $this->user['wp_id'] );
        $customer = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$p}s2nri_customers WHERE wp_user_id = %d LIMIT 1",
            $this->user['wp_id']
        ), ARRAY_A );

        if ( ! $customer ) { Response::json( [ 'tickets' => [] ] ); return; }

        // Verify this booking belongs to the customer
        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$p}s2nri_bookings WHERE id = %d AND customer_id = %d LIMIT 1",
            $booking_id, $customer['id']
        ), ARRAY_A );

        if ( ! $booking ) { Response::json( [ 'tickets' => [] ] ); return; }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.*, b.booking_ref
             FROM {$p}s2nri_tickets t
             LEFT JOIN {$p}s2nri_bookings b ON b.id = t.booking_id
             WHERE t.booking_id = %d AND t.customer_id = %d
             ORDER BY t.updated_at DESC LIMIT 50",
            $booking_id, $customer['id']
        ), ARRAY_A );

        Response::json( [ 'tickets' => $rows ?: [] ] );
    }

    /**
     * storeForBooking() — POST bookings/{id}/tickets
     * TRACE: Customer portal → creates support ticket linked to a booking.
     *        Reads booking_id from route param, category + notes from body,
     *        creates ticket row and optional first message.
     * Preconditions: auth required, booking must belong to customer.
     * Postconditions: ticket row inserted, JSON { success, ticket_id }.
     * Edge cases: booking not owned → 403. Missing subject → uses booking ref.
     */
    public function storeForBooking( Request $req ): void {
        global $wpdb;
        $p          = $wpdb->prefix;
        $booking_id = (int) $req->param( 'id' );
        $category   = sanitize_text_field( $req->input( 'category', 'General' ) );
        $notes      = sanitize_textarea_field( $req->input( 'notes', '' ) );
        $message    = sanitize_textarea_field( $req->input( 'message', $notes ) );

        \S2NRI\Models\User::ensureCustomerProfile( $this->user['wp_id'] );
        $customer = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$p}s2nri_customers WHERE wp_user_id = %d LIMIT 1",
            $this->user['wp_id']
        ), ARRAY_A );
        if ( ! $customer ) { Response::json( [ 'error' => 'Customer profile not found.' ], 404 ); return; }

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, booking_ref FROM {$p}s2nri_bookings WHERE id = %d AND customer_id = %d LIMIT 1",
            $booking_id, $customer['id']
        ), ARRAY_A );
        if ( ! $booking ) { Response::json( [ 'error' => 'Booking not found.' ], 403 ); return; }

        $subject = "Support for {$booking['booking_ref']} — {$category}";

        $tk_insert = $wpdb->insert( $p . 's2nri_tickets', [
            'customer_id' => $customer['id'],
            'booking_id'  => $booking_id,
            'subject'     => $subject,
            'category'    => $category,
            'status'      => 'open',
            'created_at'  => current_time( 'mysql' ),
            'updated_at'  => current_time( 'mysql' ),
        ] );
        // CHECKED (was previously unchecked): same insert_id staleness
        // pattern fixed throughout this codebase — a failed insert here
        // would previously return success:true with a stale ticket_id to
        // the CUSTOMER, and could attach their message to a wrong ticket.
        if ( $tk_insert === false ) {
            error_log( '[S2NRI] Customer ticket insert failed for booking ' . $booking_id . ': ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to create support ticket. Please try again.' ], 500 ); return;
        }
        $ticket_id = (int) $wpdb->insert_id;

        if ( $message && $ticket_id ) {
            if ( $wpdb->insert( $p . 's2nri_ticket_messages', [
                'ticket_id'   => $ticket_id,
                'sender_id'   => $this->user['wp_id'],
                'sender_type' => 'customer',
                'message'     => $message,
                'created_at'  => current_time( 'mysql' ),
            ] ) === false ) {
                error_log( '[S2NRI] Ticket message insert failed for ticket ' . $ticket_id . ': ' . $wpdb->last_error );
                // Non-fatal — the ticket itself is already safely created and checked above.
            }
        }

        Response::json( [ 'success' => true, 'ticket_id' => $ticket_id, 'message' => 'Support ticket created.' ], 201 );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// SystemController
// ══════════════════════════════════════════════════════════════════════════════

class SystemController extends BaseController {

    // ── Temporary public debug — remove after diagnosis ──────────────────────
    // SECURITY FIX (pre-launch): this function's route registration has been
    // REMOVED from Dispatcher.php (it was requiresAuth=false — genuinely
    // public, dumping real customer/user PII, session cookie names, and
    // full page content to anyone). This explicit guard is defense-in-depth
    // in case the route is ever accidentally re-added later — it will
    // immediately 403 for anyone who isn't a manager, rather than silently
    // relying on the route table alone.
    public function debugState( Request $req ): void {
        if ( ! method_exists( $this, 'requireManager' ) || ! current_user_can( 'manage_options' ) ) {
            Response::json( [ 'error' => 'Not found.' ], 404 ); return;
        }
        global $wpdb;
        $p = $wpdb->prefix;

        // Table existence check
        $tables = [ 's2nri_bookings', 's2nri_services', 's2nri_categories', 's2nri_customers', 's2nri_staff' ];
        $table_status = [];
        foreach ( $tables as $t ) {
            $full = $p . $t;
            $table_status[ $t ] = $wpdb->get_var( "SHOW TABLES LIKE '{$full}'" ) === $full ? 'exists' : 'MISSING';
        }

        // Row counts
        $counts = [];
        foreach ( $tables as $t ) {
            if ( $table_status[$t] === 'exists' ) {
                $counts[$t] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}{$t}" );
            } else {
                $counts[$t] = 'table missing';
            }
        }

        // Sample bookings (last 5)
        $bookings = $wpdb->get_results(
            "SELECT id, booking_ref, customer_id, status, created_at FROM {$p}s2nri_bookings ORDER BY id DESC LIMIT 5",
            ARRAY_A
        );

        // Sample customers (last 5)
        $customers = $wpdb->get_results(
            "SELECT id, wp_user_id, created_at FROM {$p}s2nri_customers ORDER BY id DESC LIMIT 5",
            ARRAY_A
        );

        // WP users (last 5)
        $wp_users = $wpdb->get_results(
            "SELECT ID, user_email, user_registered FROM {$p}users ORDER BY ID DESC LIMIT 5",
            ARRAY_A
        );

        // Run the EXACT query that getBookingDetail uses for booking id=1
        $booking_detail = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.id, b.booking_ref, b.customer_id, b.status,
                    s.name AS service_name,
                    c.name AS category_name,
                    cu.id AS cu_id, cu.wp_user_id AS cu_wp_user_id,
                    u.ID AS u_id, u.user_email
             FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_services s ON s.id = b.service_id
             LEFT JOIN {$p}s2nri_categories c ON c.id = b.category_id
             LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             LEFT JOIN {$p}users u ON u.ID = cu.wp_user_id
             LEFT JOIN {$p}users au ON au.ID = b.assigned_to
             LEFT JOIN {$p}s2nri_staff st ON st.wp_user_id = b.assigned_to
             WHERE b.id = %d LIMIT 1",
            1
        ), ARRAY_A );

        // WordPress pages on this site
        $wp_pages = $wpdb->get_results(
            "SELECT ID, post_title, post_name, post_status, post_content FROM {$p}posts 
             WHERE post_type = 'page' AND post_status = 'publish' 
             ORDER BY ID ASC",
            ARRAY_A
        );

        // Auth diagnosis — call authenticate() exactly as Dispatcher does
        $auth        = new \S2NRI\Api\Middleware\Auth();
        $auth_result = $auth->authenticate();

        // Admin bookings list test — run exact same query as BookingAdminController::index()
        $admin_list_test = $wpdb->get_results(
            "SELECT b.id, b.booking_ref, b.status, b.created_at,
                    s.name AS service_name,
                    COALESCE(u.display_name, u.user_login, '') AS customer_name
             FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_services s ON s.id = b.service_id
             LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             LEFT JOIN {$p}users u ON u.ID = cu.wp_user_id
             LEFT JOIN {$p}users au ON au.ID = b.assigned_to
             ORDER BY b.updated_at DESC LIMIT 5",
            ARRAY_A
        );
        $admin_list_error = $wpdb->last_error ?: 'none';

        // Cookie diagnosis
        $cookies_present = array_keys( $_COOKIE );
        $wp_logged_in_cookies = array_filter( $cookies_present, fn($k) => strpos($k, 'wordpress_logged_in_') === 0 );
        $cookie_auth_result = null;
        foreach ( $_COOKIE as $key => $value ) {
            if ( strpos( $key, 'wordpress_logged_in_' ) === 0 ) {
                $cookie_auth_result = wp_validate_auth_cookie( $value, 'logged_in' );
                break;
            }
        }

        // Check current WP auth state
        $current_user_id = get_current_user_id();
        $is_logged_in    = is_user_logged_in();

        // Check nonce from request header
        $nonce       = $_SERVER['HTTP_X_WP_NONCE'] ?? 'not sent';
        $nonce_valid = $nonce !== 'not sent' ? (bool) wp_verify_nonce( $nonce, 'wp_rest' ) : false;

        // Generate a fresh nonce to show what SHOULD be sent
        $fresh_nonce       = wp_create_nonce( 'wp_rest' );
        $fresh_nonce_valid = (bool) wp_verify_nonce( $fresh_nonce, 'wp_rest' );

        // DB version stored vs plugin version
        $stored_version = get_option( 's2nri_db_version', 'not set' );

        Response::json( [
            'plugin_version'        => S2NRI_VERSION,
            'db_version'            => $stored_version,
            'prefix'                => $p,
            'table_status'          => $table_status,
            'row_counts'            => $counts,
            'last_bookings'         => $bookings ?: 'none',
            'last_customers'        => $customers ?: 'none',
            'wp_users'              => $wp_users ?: 'none',
            'booking_1_detail'      => $booking_detail ?: 'NULL — query returned nothing',
            'booking_1_query_error' => $wpdb->last_error ?: 'none',
            'current_wp_user_id'    => $current_user_id,
            'is_logged_in'          => $is_logged_in,
            'nonce_header_sent'     => $nonce !== 'not sent',
            'nonce_valid'           => $nonce_valid,
            'fresh_nonce'           => $fresh_nonce,
            'fresh_nonce_valid'     => $fresh_nonce_valid,
            'cookies_present'       => array_values( $wp_logged_in_cookies ),
            'cookie_auth_user_id'   => $cookie_auth_result,
            'wp_pages'              => $wp_pages,
            'authenticate_result'   => $auth_result ? [ 'wp_id' => $auth_result['wp_id'], 's2nri_role' => $auth_result['s2nri_role'] ] : null,
            'admin_list_test'       => $admin_list_test ?: 'empty',
            'admin_list_error'      => $admin_list_error,
            'wpdb_last_error'       => $wpdb->last_error ?: 'none',
        ] );
    }

    public function health( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $tables = [
            's2nri_bookings', 's2nri_services', 's2nri_categories',
            's2nri_customers', 's2nri_quotes', 's2nri_payments',
        ];
        $status = [];
        foreach ( $tables as $t ) {
            $full = $wpdb->prefix . $t;
            $status[ $t ] = $wpdb->get_var( "SHOW TABLES LIKE '{$full}'" ) === $full ? 'ok' : 'missing';
        }
        Response::json( [
            'status'  => 'ok',
            'version' => S2NRI_VERSION,
            'tables'  => $status,
            'php'     => PHP_VERSION,
            'wp'      => get_bloginfo( 'version' ),
        ] );
    }

    public function jobs( Request $req ): void {
        $this->requireStaff();
        $jobs = [
            's2nri_job_notifications'   => wp_next_scheduled( 's2nri_job_notifications' ),
            's2nri_job_quote_reminders' => wp_next_scheduled( 's2nri_job_quote_reminders' ),
            's2nri_job_cleanup'         => wp_next_scheduled( 's2nri_job_cleanup' ),
        ];
        Response::json( [ 'jobs' => $jobs ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// CityController — public city endpoints
// TRACE: GET cities → returns active cities ordered by sort_order.
//        GET cities/{slug} → returns single city by slug.
// ══════════════════════════════════════════════════════════════════════════════

class CityController extends BaseController {

    public function index( Request $req ): void {
        $rows = \S2NRI\Services\CacheService::remember( 'cities_active', 3600, function () {
            global $wpdb;
            return $wpdb->get_results(
                "SELECT id, name, slug, state, tagline, image_url, sort_order
                 FROM {$wpdb->prefix}s2nri_cities
                 WHERE is_active = 1
                 ORDER BY sort_order ASC, name ASC",
                ARRAY_A
            );
        } );
        Response::json( [ 'cities' => $rows ] );
    }

    public function show( Request $req ): void {
        $slug = sanitize_key( $req->param( 'slug' ) );
        global $wpdb;
        $city = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}s2nri_cities WHERE slug = %s AND is_active = 1 LIMIT 1",
            $slug
        ), ARRAY_A );
        if ( ! $city ) { Response::json( [ 'error' => 'City not found.' ], 404 ); return; }
        Response::json( [ 'city' => $city ] );
    }
}


// ══════════════════════════════════════════════════════════════════════════════
// ServiceSectionController — public sections endpoint
// ══════════════════════════════════════════════════════════════════════════════

class ServiceSectionController extends BaseController {

    public function index( Request $req ): void {
        global $wpdb;
        $slug = sanitize_key( $req->param( 'slug' ) );

        $svc = \S2NRI\Services\ServiceRegistry::findBySlug( $slug );
        if ( ! $svc || ! \S2NRI\Services\ServiceRegistry::isVisibleOnSurface( $svc, 'direct_url' ) ) {
            Response::json( [ 'sections' => [] ] );
            return;
        }
        $service_id = (int) $svc['id'];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, type, title, content, sort_order
             FROM `{$wpdb->prefix}s2nri_service_sections`
             WHERE service_id = %d AND is_visible = 1
             ORDER BY sort_order ASC, id ASC",
            $service_id
        ), ARRAY_A );

        foreach ( $rows as &$row ) {
            if ( $row['content'] ) {
                $decoded = json_decode( $row['content'], true );
                if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                    $row['content'] = \S2NRI\Services\ServiceRegistry::sanitizeSectionContent(
                        $decoded,
                        (string) ( $row['type'] ?? '' )
                    );
                } else {
                    $row['content'] = $decoded;
                }
            }
        }
        unset( $row );
        Response::json( [ 'sections' => $rows ] );
    }
}
