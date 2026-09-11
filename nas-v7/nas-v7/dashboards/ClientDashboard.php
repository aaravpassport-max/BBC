<?php
namespace NAS\Dashboards;

use NAS\Core\Database;

/**
 * Client Dashboard — React SPA
 *
 * PHP renders a minimal container div and injects all configuration
 * data as a window.NAS_DASH JS object. The React bundle
 * (assets/js/nas-client-dashboard.js) mounts into #nas-react-dashboard.
 *
 * Data injection: stats and first page of bookings are server-rendered
 * so the initial paint shows real data instantly — no spinner, no AJAX
 * needed for the initial view.
 */
class ClientDashboard {

    // TRACE: register() — Registers shortcode and script enqueue.
    public static function register(): void {
        add_shortcode( 'nas_client_dashboard', [ self::class, 'render' ] );
        add_action( 'wp_enqueue_scripts',      [ self::class, 'enqueue' ] );
    }

    // TRACE: enqueue() — Enqueues the compiled React bundle only on client dashboard page.
    public static function enqueue(): void {
        $page_id = (int) get_option( 'nas_page_client_dashboard' );
        if ( ! $page_id || ! is_page( $page_id ) ) return;

        // Enqueue compiled React bundle (self-contained — includes React + ReactDOM)
        wp_enqueue_script(
            'nas-client-dashboard',
            NAS_URL . 'assets/js/nas-client-dashboard.js',
            [],         // no WP dependencies — React is bundled in
            NAS_VERSION,
            true        // footer
        );
    }

    // TRACE: render() — Shortcode callback. Outputs the React mount point + config.
    //        PHP queries initial data so the first paint is instant.
    //        Edge cases: not logged in → redirect to login page.
    public static function render( $atts ): string {
        if ( ! is_user_logged_in() ) {
            $redirect = urlencode( home_url( '/client-dashboard/' ) );
            wp_redirect( nas_get_page_url( 'nas_page_login', '/newspaper-ad-login/' ) . '?redirect_to=' . $redirect );
            exit;
        }

        $db  = Database::instance();
        $uid = (int) get_current_user_id();
        $cfg = \NAS\Core\Config::instance();

        // ── Client row ────────────────────────────────────────────────────
        $client = $db->row(
            "SELECT * FROM `{$db->t('clients')}` WHERE wp_user_id = %d",
            $uid
        );
        $cid = $client ? (int) $client['id'] : 0;

        // ── Server-side initial data (cached 90s per user) ─────────────────
        $cache_key = 'nas_dash_' . $uid . '_' . $cid;
        $cached    = $cid ? get_transient( $cache_key ) : false;

        if ( $cached !== false ) {
            $stats         = $cached['stats'];
            $bookings_data = $cached['bookings_data'];
        } else {
            $stats = [ 'total_bookings'=>0, 'active_bookings'=>0, 'completed_bookings'=>0, 'total_spent'=>0 ];
            $bookings_data = [ 'bookings'=>[], 'total'=>0 ];

            if ( $cid ) {
                $t   = $db->t('bookings');
                $agg = $db->row(
                    "SELECT
                        COUNT(*) AS total,
                        SUM(CASE WHEN status NOT IN ('published','completed','rejected','cancelled') THEN 1 ELSE 0 END) AS active,
                        SUM(CASE WHEN status IN ('published','completed') THEN 1 ELSE 0 END) AS completed,
                        COALESCE(SUM(CASE WHEN payment_status='paid' THEN total_amount ELSE 0 END),0) AS spent
                     FROM `$t` WHERE client_id=%d",
                    $cid
                );
                if ( $agg ) {
                    $stats = [
                        'total_bookings'     => (int)   $agg['total'],
                        'active_bookings'    => (int)   $agg['active'],
                        'completed_bookings' => (int)   $agg['completed'],
                        'total_spent'        => (float) $agg['spent'],
                    ];
                }
                $bookings = $db->select(
                    "SELECT b.id, b.uid, b.uid AS booking_uid, b.status, b.payment_status,
                            b.total_amount, b.submitted_at, b.publish_date,
                            n.name AS newspaper_name, cat.name AS category_name
                     FROM `$t` b
                     LEFT JOIN `{$db->t('newspapers')}` n   ON n.id = b.newspaper_id
                     LEFT JOIN `{$db->t('categories')}` cat ON cat.id = b.category_id
                     WHERE b.client_id = %d
                     ORDER BY b.submitted_at DESC LIMIT 20",
                    $cid
                );
                $bookings_data = [ 'bookings' => $bookings ?: [], 'total' => $stats['total_bookings'] ];
                set_transient( $cache_key, [ 'stats'=>$stats, 'bookings_data'=>$bookings_data ], 90 );
            }
        }

        // ── Config for React ──────────────────────────────────────────────
        $user = wp_get_current_user();
        $nas_config = [
            // AJAX — POST to current page URL (CDN always passes POST to origin)
            'ajax'           => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('nas_action'),
            // URLs
            'dashUrl'        => get_permalink() ?: home_url('/client-dashboard/'),
            'bookingUrl'     => nas_get_page_url('nas_page_booking', '/book-newspaper-ad/'),
            'homeUrl'        => home_url('/'),
            'logoutUrl'      => wp_logout_url( home_url('/') ),
            // User
            'userId'         => $uid,
            'userName'       => $user->display_name ?: $user->user_login,
            'userEmail'      => $user->user_email,
            // Branding
            'brand'          => $cfg->get('brand_name', get_bloginfo('name')),
            'accentColor'    => $cfg->get('pwa_accent_color') ?: $cfg->get('brand_color', '#6366f1'),
            'currency'       => $cfg->get('currency_symbol', '₹'),
            // Pre-loaded data (avoids AJAX on initial render)
            'initialStats'   => $stats,
            'initialBookings'=> $bookings_data,
        ];

        // Inline the config before the bundle runs
        $config_json = wp_json_encode( $nas_config );

        ob_start();
        ?>
        <div id="nas-react-dashboard">
          <script>window.NAS_DASH = <?php echo $config_json; ?>;</script>
          <!-- React mounts here. If JS is disabled, show a fallback. -->
          <noscript>
            <div style="padding:40px;text-align:center;font-family:sans-serif">
              <p>Please enable JavaScript to use this dashboard.</p>
              <a href="<?php echo esc_url($nas_config['bookingUrl']); ?>">Book a Newspaper Ad</a>
            </div>
          </noscript>
        </div>

        <?php
        // ─────────────────────────────────────────────────────────────────
        // GAP FILL (audit): the compiled React bundle (nas-client-dashboard.js)
        // has no cancel-booking UI, and no source exists in this plugin to add
        // one to it safely (no .jsx/source map/build config found anywhere —
        // confirmed by exhaustive search of the plugin tree). The backend
        // (EnterpriseModule::client_cancel_booking, action
        // nas_client_cancel_booking) is fully implemented and schema-verified,
        // it just has no caller.
        //
        // This widget is intentionally a SEPARATE container from
        // #nas-react-dashboard — it does not touch the React bundle's DOM at
        // all, so it cannot break the existing dashboard. It sources the real
        // booking `id` from the JSON response of nas_get_client_bookings
        // (already verified correct in this audit — see get_client_bookings()),
        // never from rendered/scraped text, so the ID passed to the cancel
        // endpoint is always the server's own value.
        // ─────────────────────────────────────────────────────────────────
        ?>
        <div id="nas-cancel-widget" style="max-width:760px;margin:24px auto;font-family:'Inter',sans-serif">
          <details>
            <summary style="cursor:pointer;font-size:14px;font-weight:700;color:#374151;padding:10px 0">
              Need to cancel a recent booking?
            </summary>
            <div id="nas-cw-body" style="padding:12px 0;font-size:13px;color:#64748b">
              Loading your bookings…
            </div>
          </details>
        </div>
        <script>
        (function(){
          'use strict';
          var CFG = window.NAS_DASH || {};
          var AJAX = CFG.ajax, NONCE = CFG.nonce, CUR = CFG.currency || '₹';
          // Must match EnterpriseModule::client_cancel_booking()'s own $cancellable list exactly
          // (modules/Enterprise/EnterpriseModule.php) — kept in sync deliberately, not guessed.
          var CANCELLABLE = ['booking_received', 'under_review', 'quotation_sent'];

          function esc(t){ return (t||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

          function post(action, data){
            var fd = new FormData();
            fd.append('action', action);
            fd.append('nas_action', '1');
            fd.append('nonce', NONCE);
            Object.keys(data||{}).forEach(function(k){ fd.append(k, data[k]); });
            return fetch(AJAX, { method:'POST', credentials:'same-origin', body:fd })
              .then(function(r){ return r.json(); })
              .then(function(r){
                if (!r.success) throw new Error((r.data && r.data.message) || 'Request failed');
                return r.data;
              });
          }

          function render(bookings){
            var body = document.getElementById('nas-cw-body');
            var eligible = (bookings||[]).filter(function(b){ return CANCELLABLE.indexOf(b.status) !== -1; });
            if (!eligible.length) {
              body.innerHTML = '<p>No bookings are currently eligible for cancellation. Bookings can only be cancelled before they enter processing.</p>';
              return;
            }
            body.innerHTML = eligible.map(function(b){
              var uid = b.booking_uid || b.uid || ('#' + b.id);
              return '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #e2e8f0" data-row-id="' + b.id + '">' +
                '<div><strong>' + esc(uid) + '</strong> — ' + esc(b.newspaper_name||'—') + ' (' + CUR + parseFloat(b.total_amount||0).toFixed(2) + ')</div>' +
                '<button type="button" class="nas-cw-cancel-btn" data-id="' + b.id + '" data-uid="' + esc(uid) + '" ' +
                'style="background:#fee2e2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer">Cancel</button>' +
                '</div>';
            }).join('');

            body.querySelectorAll('.nas-cw-cancel-btn').forEach(function(btn){
              btn.addEventListener('click', function(){
                // Real numeric booking id, taken directly from the JSON row this button
                // was built from — never parsed from displayed text.
                var id  = btn.getAttribute('data-id');
                var uid = btn.getAttribute('data-uid');
                if (!window.confirm('Cancel booking ' + uid + '? This cannot be undone.')) return;
                btn.disabled = true;
                btn.textContent = 'Cancelling…';
                post('nas_client_cancel_booking', { booking_id: id, reason: 'Cancelled by client from dashboard' })
                  .then(function(res){
                    var row = document.querySelector('[data-row-id="' + id + '"]');
                    if (row) row.remove();
                    var body2 = document.getElementById('nas-cw-body');
                    var note = document.createElement('p');
                    note.style.cssText = 'color:#059669;font-weight:600';
                    note.textContent = (res && res.message) || 'Booking cancelled.';
                    body2.appendChild(note);
                  })
                  .catch(function(e){
                    btn.disabled = false;
                    btn.textContent = 'Cancel';
                    alert(e.message);
                  });
              });
            });
          }

          document.addEventListener('DOMContentLoaded', function(){
            var details = document.querySelector('#nas-cancel-widget details');
            var loaded = false;
            details.addEventListener('toggle', function(){
              if (details.open && !loaded) {
                loaded = true;
                post('nas_get_client_bookings', { page:1, per_page:50 })
                  .then(function(d){ render(d.bookings || []); })
                  .catch(function(e){
                    document.getElementById('nas-cw-body').innerHTML =
                      '<p style="color:#dc2626">Could not load bookings: ' + esc(e.message) + '</p>';
                  });
              }
            });
          });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // Invalidate cache when a booking status changes
    public static function invalidate_cache( int $booking_id ): void {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.client_id, cl.wp_user_id FROM {$wpdb->prefix}nas_bookings b
             LEFT JOIN {$wpdb->prefix}nas_clients cl ON cl.id = b.client_id
             WHERE b.id = %d", $booking_id
        ) );
        if ( $row ) {
            delete_transient( 'nas_dash_' . (int)$row->wp_user_id . '_' . (int)$row->client_id );
        }
    }
}
