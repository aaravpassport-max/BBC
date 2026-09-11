<?php
namespace NAS\Modules\CityPages;

use NAS\Core\Database;
use NAS\Core\Security;
use NAS\Core\Cache;

class CityPagesModule extends \NAS\Core\Module {
    public function key(): string { return 'city_pages'; }

    public function register(): void {
        add_action( 'wp_ajax_nas_get_city_page_meta',    [ CityPagesController::class, 'get_meta' ] );
        add_action( 'wp_ajax_nas_save_city_page_meta',   [ CityPagesController::class, 'save_meta' ] );
        add_action( 'wp_ajax_nas_bulk_generate_city_pages', [ CityPagesController::class, 'bulk_generate' ] );
        add_action( 'template_redirect',                 [ $this, 'handle_city_page' ] );
        add_action( 'wp_head',                           [ $this, 'inject_seo_meta' ] );
    }

    public function boot(): void {}

    // TRACE: handle_city_page() — Trigger: wp_ajax_handle_city_page AJAX action.
    //        Steps: queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function handle_city_page(): void {
        $city_slug = get_query_var( 'nas_city_page' );
        if ( ! $city_slug ) return;
        $db   = Database::instance();
        $city = $db->row( "SELECT * FROM {$db->t('cities')} WHERE slug = %s", sanitize_title( $city_slug ) );
        if ( ! $city ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            return;
        }
        $meta = $db->row( "SELECT * FROM {$db->t('city_page_meta')} WHERE city_id = %d", $city['id'] );
        $newspapers = $db->select(
            "SELECT n.* FROM {$db->t('newspapers')} n WHERE JSON_CONTAINS(n.cities_supported, %s)",
            json_encode( $city['name'] )
        );
        $categories = $db->select( "SELECT * FROM {$db->t('categories')} ORDER BY sort_order ASC" );

        // Use blank template wrapper to suppress theme chrome
        add_filter( 'show_admin_bar', '__return_false', 999 );
        remove_action( 'wp_head', '_admin_bar_bump_cb' );
        $GLOBALS['nas_city_data'] = compact( 'city', 'meta', 'newspapers', 'categories' );
        include NAS_PATH . 'templates/partials/nas-city-wrapper.php';
        exit;
    }

    // TRACE: inject_seo_meta() — Trigger: wp_ajax_inject_seo_meta AJAX action.
    //        Steps: queries DB.
    //        Output: single DB row as associative array or null.
    //        Edge cases: empty input values handled.
    public function inject_seo_meta(): void {
        $city_slug = get_query_var( 'nas_city_page' );
        if ( ! $city_slug ) return;
        $db   = Database::instance();
        $city = $db->row( "SELECT * FROM {$db->t('cities')} WHERE slug = %s", $city_slug );
        if ( ! $city ) return;
        $meta  = $db->row( "SELECT * FROM {$db->t('city_page_meta')} WHERE city_id = %d", $city['id'] );
        $title = $meta['seo_title'] ?? 'Book Newspaper Ads in ' . $city['name'] . ' | AdBooker';
        $desc  = $meta['seo_desc'] ?? 'Book classified and display ads in top newspapers in ' . $city['name'] . '. Lowest rates, instant confirmation.';

        // Use WP filter to set page title — avoids duplicate <title> tag from wp_head()
        add_filter( 'document_title_parts', function() use ($title) {
            return [ 'title' => $title ];
        });

        // Meta tags (description, OG, etc) — these don't duplicate anything WP emits
        echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
        if ( ! empty( $meta['og_image'] ) ) {
            echo '<meta property="og:image" content="' . esc_url( $meta['og_image'] ) . '">' . "\n";
        }
        echo '<meta name="robots" content="index, follow">' . "\n";
        echo '<link rel="canonical" href="' . esc_url( home_url( '/newspaper-ad-' . $city['slug'] . '/' ) ) . '">' . "\n";
        // Schema.org LocalBusiness
        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'LocalBusiness',
            'name'        => 'Newspaper Ad Agency in ' . $city['name'],
            'description' => $desc,
            'areaServed'  => [ '@type' => 'City', 'name' => $city['name'] ],
            'url'         => home_url( '/newspaper-ad-' . $city['slug'] . '/' ),
        ];
        if ( ! empty( $meta['schema_data'] ) ) {
            $extra = json_decode( $meta['schema_data'], true );
            if ( is_array( $extra ) ) $schema = array_merge( $schema, $extra );
        }
        echo '<script type="application/ld+json">' . json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
    }
}

class CityPagesController {
    // TRACE: get_meta() — Trigger: wp_ajax_get_meta AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_meta(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $city_id = (int) Security::post( 'city_id', 'int' );
        $db      = Database::instance();
        $meta    = $db->row( "SELECT * FROM {$db->t('city_page_meta')} WHERE city_id = %d", $city_id );
        wp_send_json_success( [ 'meta' => $meta ] );
    }

    // TRACE: save_meta() — Trigger: wp_ajax_save_meta AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → inserts DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_meta(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $city_id = (int) Security::post( 'city_id', 'int' );
        $db      = Database::instance();
        $data    = [
            'seo_title'    => Security::post( 'seo_title', 'text' ),
            'seo_desc'     => Security::post( 'seo_desc', 'textarea' ),
            'og_image'     => Security::post( 'og_image', 'url' ),
            'content_body' => Security::post( 'content_body', 'html' ),
            'schema_data'  => Security::post( 'schema_data', 'textarea' ),
            'updated_at'   => current_time( 'mysql' ),
        ];
        $exists = $db->scalar( "SELECT id FROM {$db->t('city_page_meta')} WHERE city_id = %d", $city_id );
        if ( $exists ) {
            $db->update( $db->t('city_page_meta'), $data, [ 'city_id' => $city_id ] );
        } else {
            $data['city_id']    = $city_id;
            $data['created_at'] = current_time( 'mysql' );
            $db->insert( $db->t('city_page_meta'), $data );
        }
        wp_send_json_success();
    }

    // TRACE: bulk_generate() — Trigger: wp_ajax_bulk_generate AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → inserts DB row.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function bulk_generate(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $db    = Database::instance();
        $cities = $db->select( "SELECT * FROM {$db->t('cities')} ORDER BY id ASC" );
        $count  = 0;
        foreach ( $cities as $city ) {
            $exists = $db->scalar( "SELECT id FROM {$db->t('city_page_meta')} WHERE city_id = %d", $city['id'] );
            if ( $exists ) continue;
            $db->insert( $db->t('city_page_meta'), [
                'city_id'    => $city['id'],
                'seo_title'  => "Book Newspaper Ads in {$city['name']}, {$city['state']} | Best Rates",
                'seo_desc'   => "Book classified and display newspaper ads in {$city['name']}, {$city['state']}. Get the lowest rates for Obituary, Matrimonial, Property, Job ads and more. Instant booking, fast confirmation.",
                'og_image'   => '',
                'content_body' => self::generate_city_content( $city ),
                'schema_data'  => '',
                'created_at'   => current_time( 'mysql' ),
                'updated_at'   => current_time( 'mysql' ),
            ] );
            $count++;
        }
        wp_send_json_success( [ 'generated' => $count ] );
    }

    // TRACE: generate_city_content() — Trigger: wp_ajax_generate_city_content AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private static function generate_city_content( array $city ): string {
        $name  = $city['name'];
        $state = $city['state'];
        return "<h2>Newspaper Advertising in {$name}, {$state}</h2>
<p>{$name} is one of the key cities in {$state} with a strong readership base across multiple regional and national newspapers. Our platform connects you directly with top newspapers serving {$name} readers, offering the lowest possible advertising rates with no hidden charges.</p>
<h3>Why Advertise in {$name} Newspapers?</h3>
<p>Newspaper advertising in {$name} offers unmatched local reach, credibility, and targeting. Whether you need to publish a matrimonial ad, property listing, job vacancy, or legal notice, newspaper ads remain one of the most trusted mediums in India.</p>
<h3>Popular Ad Categories in {$name}</h3>
<ul>
<li><strong>Obituary/Death Notice</strong> – Reach the entire city with a respectful memorial notice</li>
<li><strong>Matrimonial</strong> – Find the right match through trusted newspaper classifieds</li>
<li><strong>Property</strong> – Sell, rent, or buy property with wide local reach</li>
<li><strong>Job/Recruitment</strong> – Hire local talent with targeted job ads</li>
<li><strong>Name Change</strong> – Publish official name change notices as required by law</li>
<li><strong>Business/Services</strong> – Promote your business to {$name} readers</li>
<li><strong>Education</strong> – Announce admissions, results, and educational offers</li>
</ul>
<h3>How It Works</h3>
<ol>
<li>Select your ad category and newspaper</li>
<li>Write or generate your ad content</li>
<li>Get instant price quote</li>
<li>Submit and make payment</li>
<li>Ad published as per scheduled date</li>
</ol>";
    }
}
