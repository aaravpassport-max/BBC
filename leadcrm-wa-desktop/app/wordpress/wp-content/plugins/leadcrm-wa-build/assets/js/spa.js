/**
 * LeadCRM SPA Router — v5.0
 *
 * Handles navigation only. Every section is rendered server-side
 * by the same lcrm_page_*() functions used in wp-admin.
 * No reinvented UI. No custom renderers. Same HTML as wp-admin.
 */
(function ($) {
    'use strict';

    var AJAX     = LCRM.ajax_url;
    var NONCE    = LCRM.nonce;
    var SECTIONS = LCRM_SPA.sections;

    var currentSection = '';
    var sectionCache   = {};  // key → html, populated after first load
    var collapsed      = localStorage.getItem('lcrm_sidebar_col') === '1';

    /* ── INIT ──────────────────────────────────────────────── */
    $(function () {
        initSidebar();
        initBottomNav();
        initTopbar();
        routeToHash();
        window.addEventListener('popstate', routeToHash);
    });


    /* ── ROUTING ────────────────────────────────────────────── */

    function routeToHash() {
        var hash    = window.location.hash.replace('#', '') || 'dashboard';
        var parts   = hash.split('/');
        var section = parts[0] || 'dashboard';
        var sub     = parts[1] || '';
        navigate(section, sub, false);
    }

    // TRACE: Core navigation function.
    //        Sets URL hash → marks sidebar active → loads section HTML (cached or AJAX) →
    //        injects into #lcrm-content → re-runs any JS that section needs.
    //        POSTCONDITIONS: Content pane shows the correct section HTML.
    //        EDGE CASES: Same section, no sub → no-op;
    //                    settings always reloads to pick up tab change;
    //                    network error → error message shown.
    function navigate(section, sub, push) {
        if ( typeof push === 'undefined' ) push = true;

        // Same section, no sub → skip (unless settings which needs tab re-render)
        if ( section === currentSection && section !== 'settings' && !sub ) return;

        currentSection = section;

        if ( push ) {
            var hash = sub ? section + '/' + sub : section;
            history.pushState({ section: section, sub: sub }, '', '#' + hash );
        }

        setActive(section);
        setTitle(section);

        // Settings always re-renders (tab changes must be fresh)
        if ( section === 'settings' ) {
            delete sectionCache['settings'];
        }

        if ( sectionCache[section] ) {
            inject(section, sectionCache[section], sub);
            return;
        }

        showLoader();
        post('lcrm_spa_section', { section: section, tab: sub || '' }, function (res) {
            if ( res.success ) {
                sectionCache[section] = res.data.html;
                inject(section, res.data.html, sub);
            } else {
                showError('Could not load this section. Please refresh.');
            }
        });
    }

    // Injects HTML into content pane, scrolls to top, runs post-inject init.
    function inject(section, html, sub) {
        var $c = $('#lcrm-content');
        $c.fadeOut(60, function () {
            $c.html(html);
            $c.fadeIn(80);
            $c.scrollTop(0);
            postInject(section, sub);
        });
    }

    // TRACE: Runs after section HTML is in the DOM.
    //        Triggers the JS that each section already relies on in wp-admin.
    //        For leads: admin.js document.ready already handles #lcrm-leads-table.
    //        For the rest: existing event listeners on $(document) handle everything.
    function postInject(section, sub) {
        // Re-initialise leads table if it's now in DOM
        if ( section === 'leads' ) {
            // Use window.* exports from admin.js IIFE
            try {
                window.lcrmLoadColState();
                window.lcrmLoadFilterPrefs(function () {
                    window.lcrmApplyFilterPrefs();
                    window.lcrmBuildThead();
                    window.lcrmLoadLeads();
                });
            } catch(e) { console.error('[SPA leads init]', e); }
        }

        // Re-init the assignments page if needed
        if ( section === 'assignments' && typeof lasLoad === 'function' ) {
            lasLoad();
        }

        // Re-init WA modal tab injection
        if ( typeof lcrmV4InjectTabs === 'function' ) {
            setTimeout(lcrmV4InjectTabs, 80);
        }

        // Tasks page date picker re-init
        if ( section === 'tasks' ) {
            $('#lf-lead_date').val(new Date().toISOString().slice(0,10));
        }

        // Add Lead page: wire the open modal button
        if ( section === 'add' ) {
            $('#lcrm-open-add-modal').off('click').on('click', function () {
                if ( typeof window.lcrmOpenLeadModal === 'function' )
                    window.lcrmOpenLeadModal(0);
            });
            // Also open modal immediately since user explicitly went to Add Lead
            setTimeout(function () {
                if ( typeof window.lcrmOpenLeadModal === 'function' )
                    window.lcrmOpenLeadModal(0);
            }, 200);
        }

        // Settings: wire inline tab links to SPA navigation
        if ( section === 'settings' ) {
            wireSettingsTabs();
        }

        // Analytics tabs (use existing analytics JS wired in menus.php inline scripts)
        // Pipeline kanban init
        if ( section === 'pipeline' ) {
            if ( typeof lcrmInitKanban === 'function' ) lcrmInitKanban();
        }

        // Trigger lead_loaded event for any listeners
        $(document).trigger('lcrm:section_loaded', [section]);

        // If there was an edit param, open the lead modal
        if ( section === 'leads' && sub ) {
            setTimeout(function () {
                if ( typeof window.lcrmOpenLeadModal === 'function' )
                    window.lcrmOpenLeadModal(parseInt(sub));
            }, 300);
        }
    }

    /* ── SETTINGS TABS ──────────────────────────────────────── */

    // Settings tab links are now javascript:lcrmSpaNavigate('settings','tab',true) in SPA context.
    // They execute directly when clicked — no interception needed.
    // This function is kept as no-op for backward compat.
    function wireSettingsTabs() {
        // lcrm_page_url() already returns javascript:lcrmSpaNavigate(...) hrefs in SPA context.
        // No click interception needed — the href executes directly.
    }

    /* ── SIDEBAR ────────────────────────────────────────────── */

    function initSidebar() {
        if ( collapsed ) $('#lcrm-sidebar').addClass('collapsed');

        $('#lcrm-sidebar-collapse').on('click', function () {
            collapsed = !collapsed;
            $('#lcrm-sidebar').toggleClass('collapsed', collapsed);
            $('#lcrm-menu-toggle').attr('aria-expanded', collapsed ? 'false' : 'true');
            localStorage.setItem('lcrm_sidebar_col', collapsed ? '1' : '0');
        });

        // Mobile drawer
        $('#lcrm-menu-toggle').on('click', function () {
            if ( window.innerWidth <= 782 ) {
                $('#lcrm-sidebar').toggleClass('mobile-open');
                $('#lcrm-sidebar-overlay').toggleClass('active', $('#lcrm-sidebar').hasClass('mobile-open'));
                return;
            }
            collapsed = !collapsed;
            $('#lcrm-sidebar').toggleClass('collapsed', collapsed);
            $(this).attr('aria-expanded', collapsed ? 'false' : 'true');
            localStorage.setItem('lcrm_sidebar_col', collapsed ? '1' : '0');
        });
        $('#lcrm-menu-toggle').attr('aria-expanded', collapsed ? 'false' : 'true');
        $('#lcrm-sidebar-overlay').on('click', closeDrawer);

        // Nav click → SPA navigate
        $(document).on('click', '.lcrm-nav-item', function (e) {
            e.preventDefault();
            var sec = $(this).data('section');
            if ( sec ) { closeDrawer(); navigate(sec, '', true); }
        });
    }

    function closeDrawer() {
        $('#lcrm-sidebar').removeClass('mobile-open');
        $('#lcrm-sidebar-overlay').removeClass('active');
    }

    /* ── TOPBAR ─────────────────────────────────────────────── */

    function initTopbar() {
        $('#lcrm-quick-add-btn').on('click', function () {
            // Navigate to leads section first, then open add modal
            navigate('leads', '', true);
            setTimeout(function () {
                if ( typeof window.lcrmOpenLeadModal === 'function' )
                    window.lcrmOpenLeadModal(0);
            }, 400);
        });

        $('#lcrm-alerts-topbtn').on('click', function () {
            navigate('alerts', '', true);
        });
    }

    /* ── BOTTOM NAV (mobile) ────────────────────────────────── */

    function initBottomNav() {
        var primary = ['dashboard','leads','add','alerts','settings'];
        var html = '';
        primary.forEach(function (id) {
            var sec = SECTIONS.find(function(s){ return s.id===id; });
            if ( !sec ) return;
            var badge = (id==='alerts' && LCRM.unread_alerts > 0)
                ? '<span class="lcrm-bottom-tab-badge">' + LCRM.unread_alerts + '</span>' : '';
            html += '<a class="lcrm-bottom-tab" data-section="' + id + '" href="#' + id + '">'
                + badge
                + '<span class="lcrm-bottom-tab-icon">' + sec.icon + '</span>'
                + '<span>' + sec.label + '</span>'
                + '</a>';
        });
        $('<div class="lcrm-bottom-nav">' + html + '</div>').appendTo('#lcrm-spa');

        $(document).on('click', '.lcrm-bottom-tab', function (e) {
            e.preventDefault();
            var sec = $(this).data('section');
            if ( sec ) navigate(sec, '', true);
        });
    }

    /* ── ACTIVE STATE + TITLE ───────────────────────────────── */

    function setActive(section) {
        $('.lcrm-nav-item').removeClass('active');
        $('.lcrm-nav-item[data-section="' + section + '"]').addClass('active');
        $('.lcrm-bottom-tab').removeClass('active');
        $('.lcrm-bottom-tab[data-section="' + section + '"]').addClass('active');
    }

    function setTitle(section) {
        var sec = SECTIONS.find(function(s){ return s.id===section; });
        var label = sec ? sec.label : 'LeadCRM';
        $('#lcrm-spa-topbar-title').text(label);
        document.title = label + ' — ' + (LCRM.site_name || 'CRM');
    }

    /* ── LOADER / ERROR ─────────────────────────────────────── */

    function showLoader() {
        $('#lcrm-content').html(
            '<div style="display:flex;align-items:center;justify-content:center;min-height:280px;">'
            + '<div style="text-align:center;">'
            + '<div class="lcrm-spinner" style="margin:0 auto 12px;"></div>'
            + '<div style="color:#9ca3af;font-size:13px;">Loading…</div>'
            + '</div></div>'
        );
    }

    function showError(msg) {
        $('#lcrm-content').html(
            '<div style="text-align:center;padding:60px 20px;color:#ef4444;font-size:14px;">⚠ ' + msg + '</div>'
        );
    }

    /* ── AJAX ───────────────────────────────────────────────── */

    function post(action, data, cb) {
        data.action = action;
        data.nonce  = NONCE;
        $.post(AJAX, data)
            .done(function (r) {
                if ( r && r.success ) cb({ success: true, data: r.data });
                else cb({ success: false, data: r ? r.data : {} });
            })
            .fail(function () { cb({ success: false, data: { msg: 'Connection error.' } }); });
    }

    /* ── PUBLIC API ─────────────────────────────────────────── */

    window.lcrmSpaNavigate = navigate;

    // When lead is saved → bust section cache so next visit is fresh
    $(document).on('lcrm:lead_saved', function () {
        delete sectionCache['dashboard'];
        delete sectionCache['leads'];
        delete sectionCache['pipeline'];
        // Reload current section if it's leads
        if ( currentSection === 'leads' && typeof lcrmLoadLeads === 'function' ) {
            lcrmLoadLeads();
        }
    });

    // Trigger lead_saved after modal save (admin.js fires lcrmLoadLeads already,
    // but we also need dashboard to refresh on next visit)
    var _orig = window.lcrmOpenLeadModal;
    $(document).on('click', '#lcrm-form-submit, #lcrm-form-done-btn', function () {
        setTimeout(function () {
            $(document).trigger('lcrm:lead_saved');
        }, 500);
    });

})(jQuery);
