<?php
namespace S2NRI\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use S2NRI\Api\Request;
use S2NRI\Api\Response;
use S2NRI\Design\DesignPresets;
use S2NRI\Design\DesignSystem;
use S2NRI\Design\FontLibrary;
use S2NRI\Services\ServiceRegistry;

class DesignSystemController extends BaseController {

    public function getPublic( Request $req ): void {
        $design = DesignSystem::getPublicPayload();
        $fonts  = is_array( $design['fonts'] ?? null ) ? $design['fonts'] : [];
        $assets = DesignSystem::publicFontAssets( $fonts );
        Response::json( [
            'design'        => $design,
            'revision'      => DesignSystem::publicRevision(),
            'fonts_css_url' => $assets['css_url'],
            'font_stacks'   => $assets['stacks'],
            'presets'       => array_keys( DesignPresets::list() ),
        ] );
    }

    public function getAdmin( Request $req ): void {
        $this->requireManager();
        Response::json( [
            'config'  => DesignSystem::resolve( [] ),
            'presets' => DesignPresets::list(),
            'fonts'   => [
                'categories' => FontLibrary::categories(),
                'library'    => FontLibrary::all(),
            ],
        ] );
    }

    public function update( Request $req ): void {
        $this->requireManager();
        $body = $req->body();
        if ( ! is_array( $body ) ) {
            Response::json( [ 'error' => 'Invalid payload.' ], 422 );
            return;
        }
        DesignSystem::save( $body );
        Response::json( [ 'ok' => true, 'config' => DesignSystem::resolve( [] ) ] );
    }

    public function applyPreset( Request $req ): void {
        $this->requireManager();
        $id = sanitize_key( (string) $req->input( 'preset', '' ) );
        if ( ! $id || ! isset( DesignPresets::list()[ $id ] ) ) {
            Response::json( [ 'error' => 'Unknown preset.' ], 422 );
            return;
        }
        $mode = sanitize_key( (string) $req->input( 'mode', 'theme' ) );
        if ( ! in_array( $mode, [ 'theme', 'factory' ], true ) ) {
            $mode = 'theme';
        }
        $config = DesignSystem::applyPreset( $id, $mode );
        Response::json( [ 'ok' => true, 'config' => $config ] );
    }

    public function fonts( Request $req ): void {
        $this->requireStaff();
        $q    = sanitize_text_field( $req->query( 'q', '' ) );
        $cat  = sanitize_text_field( $req->query( 'category', '' ) );
        Response::json( [ 'fonts' => FontLibrary::search( $q, $cat ?: null ) ] );
    }
}

class NavigationController extends BaseController {

    public function getPublic( Request $req ): void {
        ServiceRegistry::seedNavMenuStructureIfMissing();
        Response::json( [
            'menu'     => ServiceRegistry::buildNavigationMenu(),
            'services' => ServiceRegistry::forSurface( 'nav_dropdown' ),
        ] );
    }

    public function getAdmin( Request $req ): void {
        $this->requireManager();
        ServiceRegistry::seedNavMenuStructureIfMissing();
        Response::json( [
            'structure' => ServiceRegistry::getNavMenuStructure(),
            'menu'        => ServiceRegistry::buildNavigationMenu(),
            'default'     => ServiceRegistry::defaultNavMenuStructure(),
        ] );
    }

    public function updateAdmin( Request $req ): void {
        $this->requireManager();
        $body = $req->body();
        if ( ! is_array( $body ) || ! isset( $body['structure'] ) || ! is_array( $body['structure'] ) ) {
            Response::json( [ 'error' => 'Invalid navigation structure.' ], 422 );
            return;
        }
        ServiceRegistry::saveNavMenuStructure( $body['structure'] );
        Response::json( [
            'ok'   => true,
            'menu' => ServiceRegistry::buildNavigationMenu(),
        ] );
    }
}

class ServiceRegistryAdminController extends BaseController {

    public function registry( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $cats = $wpdb->get_results(
            "SELECT id, slug, name, public_status, visibility_rules, hide_when_empty_children, is_active, sort_order
             FROM {$wpdb->prefix}s2nri_categories ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        ) ?: [];
        foreach ( $cats as &$cat ) {
            $rules = $cat['visibility_rules'] ?? '';
            if ( is_string( $rules ) && $rules !== '' ) {
                $decoded = json_decode( $rules, true );
                $cat['visibility_rules'] = is_array( $decoded ) ? $decoded : ServiceRegistry::defaultVisibilityRules();
            } elseif ( ! is_array( $rules ) ) {
                $cat['visibility_rules'] = ServiceRegistry::defaultVisibilityRules();
            }
        }
        unset( $cat );
        $cities = $wpdb->get_results(
            "SELECT id, slug, name, public_status, visibility_rules, is_active, sort_order
             FROM {$wpdb->prefix}s2nri_cities ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        ) ?: [];
        foreach ( $cities as &$city ) {
            $city = \S2NRI\Services\PublicEntityRegistry::normalizeCityRow( $city );
        }
        unset( $city );

        Response::json( [
            'services'   => ServiceRegistry::allServices( true ),
            'categories' => $cats,
            'cities'     => $cities,
            'surfaces'   => ServiceRegistry::SURFACES,
            'city_surfaces' => \S2NRI\Services\PublicEntityRegistry::CITY_SURFACES,
            'statuses'   => ServiceRegistry::STATUSES,
            'entities'   => \S2NRI\Services\PublicEntityRegistry::types(),
        ] );
    }

    public function impact( Request $req ): void {
        $this->requireStaff();
        $id = (int) $req->param( 'id' );
        Response::json( ServiceRegistry::impactPreview( $id ) );
    }

    public function updateVisibility( Request $req ): void {
        $this->requireManager();
        $id = (int) $req->param( 'id' );
        $body = $req->body();
        if ( ! is_array( $body ) ) {
            Response::json( [ 'error' => 'Invalid payload.' ], 422 );
            return;
        }
        $ok = ServiceRegistry::updateServiceVisibility( $id, $body );
        if ( ! $ok ) {
            Response::json( [ 'error' => 'Nothing to update.' ], 422 );
            return;
        }
        Response::json( [
            'ok'     => true,
            'impact' => ServiceRegistry::impactPreview( $id ),
        ] );
    }

    public function updateCategoryVisibility( Request $req ): void {
        $this->requireManager();
        $id = (int) $req->param( 'id' );
        $body = $req->body();
        if ( ! is_array( $body ) ) {
            Response::json( [ 'error' => 'Invalid payload.' ], 422 );
            return;
        }
        $ok = ServiceRegistry::updateCategoryVisibility( $id, $body );
        if ( ! $ok ) {
            Response::json( [ 'error' => 'Nothing to update.' ], 422 );
            return;
        }
        Response::json( [ 'ok' => true ] );
    }

    public function updateCityVisibility( Request $req ): void {
        $this->requireManager();
        $id = (int) $req->param( 'id' );
        $body = $req->body();
        if ( ! is_array( $body ) ) {
            Response::json( [ 'error' => 'Invalid payload.' ], 422 );
            return;
        }
        $ok = \S2NRI\Services\PublicEntityRegistry::updateCityVisibility( $id, $body );
        if ( ! $ok ) {
            Response::json( [ 'error' => 'Nothing to update.' ], 422 );
            return;
        }
        Response::json( [ 'ok' => true ] );
    }
}
