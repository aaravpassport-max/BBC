<?php
/**
 * Central navigation registry — single source of truth for header, footer, bottom nav, SPA.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * @return array<string,array{label:string,url:string,icon:string,primary?:bool,bottom?:bool,match:array<int,string>}>
 */
function nas_portal_nav_registry(): array {
    $booking_url = nas_get_page_url( 'nas_page_booking', '/book-newspaper-ad/' );
    $contact_url = nas_get_page_url( 'nas_page_contact', '/contact-us/' );
    $faq_url     = nas_get_page_url( 'nas_page_faq', '/faq/' );
    $track_url   = nas_get_page_url( 'nas_page_track_order', '/track-order/' );
    $login_url   = nas_get_page_url( 'nas_page_login', '/newspaper-ad-login/' );
    $dash_url    = nas_get_page_url( 'nas_page_client_dashboard', '/client-dashboard/' );
    $blog_url    = nas_get_page_url( 'nas_page_blog', '/blog/' );
    $papers_url  = home_url( '/newspapers/' );
    $cities_url  = home_url( '/cities/' );

    $account_url = is_user_logged_in() ? $dash_url : $login_url;
    $account_label = is_user_logged_in() ? 'Account' : 'Login';

    return [
        'home' => [
            'label'   => 'Home',
            'url'     => home_url( '/' ),
            'icon'    => 'fa-house',
            'primary' => true,
            'bottom'  => true,
            'match'   => [ '', 'index', 'nas-home', 'nas-homepage', 'home', 'homepage' ],
        ],
        'browse' => [
            'label'   => 'Browse',
            'url'     => $papers_url,
            'icon'    => 'fa-compass',
            'bottom'  => true,
            'match'   => [ 'newspapers', 'cities', 'newspaper-ads', 'category', 'categories', 'states', 'state' ],
        ],
        'book' => [
            'label'   => 'Book',
            'url'     => $booking_url,
            'icon'    => 'fa-pen-nib',
            'primary' => false,
            'bottom'  => true,
            'match'   => [ 'book-newspaper-ad', 'book-ad' ],
        ],
        'track' => [
            'label'   => 'Orders',
            'url'     => is_user_logged_in() ? $dash_url : $track_url,
            'icon'    => 'fa-receipt',
            'bottom'  => true,
            'match'   => [ 'track-order', 'client-dashboard', 'booking-confirmation' ],
        ],
        'account' => [
            'label'   => $account_label,
            'url'     => $account_url,
            'icon'    => is_user_logged_in() ? 'fa-circle-user' : 'fa-right-to-bracket',
            'bottom'  => true,
            'match'   => [ 'newspaper-ad-login', 'client-dashboard', 'vendor-register' ],
        ],
        'newspapers' => [
            'label'   => 'Newspapers',
            'url'     => $papers_url,
            'icon'    => 'fa-newspaper',
            'primary' => true,
            'match'   => [ 'newspapers' ],
        ],
        'cities' => [
            'label'   => 'Cities',
            'url'     => $cities_url,
            'icon'    => 'fa-city',
            'primary' => true,
            'match'   => [ 'cities', 'newspaper-ads' ],
        ],
        'faq' => [
            'label'   => 'FAQ',
            'url'     => $faq_url,
            'icon'    => 'fa-circle-question',
            'primary' => true,
            'match'   => [ 'faq' ],
        ],
        'contact-us' => [
            'label'   => 'Contact',
            'url'     => $contact_url,
            'icon'    => 'fa-envelope',
            'primary' => true,
            'match'   => [ 'contact', 'contact-us' ],
        ],
        'pricing' => [
            'label' => 'Pricing',
            'url'   => home_url( '/pricing/' ),
            'icon'  => 'fa-tags',
            'match' => [ 'pricing' ],
        ],
        'support' => [
            'label' => 'Support',
            'url'   => home_url( '/support/' ),
            'icon'  => 'fa-headset',
            'match' => [ 'support' ],
        ],
        'about' => [
            'label' => 'About',
            'url'   => home_url( '/about/' ),
            'icon'  => 'fa-building',
            'match' => [ 'about', 'about-us' ],
        ],
        'blog' => [
            'label' => 'Blog',
            'url'   => $blog_url,
            'icon'  => 'fa-blog',
            'match' => [ 'blog' ],
        ],
    ];
}

/** @return string[] */
function nas_portal_primary_nav_keys(): array {
    return [ 'home', 'newspapers', 'cities', 'faq', 'contact-us' ];
}

/** @return string[] */
function nas_portal_bottom_nav_keys(): array {
    return [ 'home', 'browse', 'book', 'track', 'account' ];
}

function nas_portal_nav_active_slug(): string {
    if ( isset( $GLOBALS['portal_active_nav'] ) && $GLOBALS['portal_active_nav'] ) {
        return (string) $GLOBALS['portal_active_nav'];
    }
    return function_exists( 'nas_portal_current_slug' ) ? nas_portal_current_slug() : '';
}

function nas_portal_nav_match_slug( string $nav_key, ?string $active = null ): bool {
    $active = $active ?? nas_portal_nav_active_slug();
    $registry = nas_portal_nav_registry();
    if ( ! isset( $registry[ $nav_key ] ) ) {
        return false;
    }
    $item = $registry[ $nav_key ];
    if ( $active === $nav_key ) {
        return true;
    }
    if ( ! empty( $item['match'] ) && in_array( $active, $item['match'], true ) ) {
        return true;
    }
    if ( $nav_key === 'home' && in_array( $active, [ '', 'index' ], true ) ) {
        return true;
    }
    if ( $nav_key === 'contact-us' && in_array( $active, [ 'contact', 'contact-us' ], true ) ) {
        return true;
    }
    if ( $nav_key === 'browse' && in_array( $active, $item['match'], true ) ) {
        return true;
    }
    return false;
}

function nas_portal_should_show_bottom_nav(): bool {
    if ( function_exists( 'nas_portal_dashboard_slugs' ) ) {
        $slug = nas_portal_nav_active_slug();
        if ( in_array( $slug, nas_portal_dashboard_slugs(), true ) ) {
            return false;
        }
    }
    return true;
}

/** Paths where SPA navigation is disabled (forms, dashboards, payment). */
function nas_portal_spa_excluded_paths(): array {
    return [
        '/book-newspaper-ad',
        '/payment',
        '/faq',
        '/contact-us',
        '/track-order',
        '/newspaper-ad-login',
        '/client-dashboard',
        '/admin-dashboard',
        '/staff-dashboard',
        '/moderation-dashboard',
        '/vendor-dashboard',
        '/vendor-register',
        '/wp-admin',
        '/wp-login.php',
    ];
}

function nas_portal_is_spa_excluded_path( string $path ): bool {
    $path = untrailingslashit( parse_url( $path, PHP_URL_PATH ) ?: $path );
    foreach ( nas_portal_spa_excluded_paths() as $excluded ) {
        if ( $path === untrailingslashit( $excluded ) || str_starts_with( $path, untrailingslashit( $excluded ) . '/' ) ) {
            return true;
        }
    }
    return false;
}
