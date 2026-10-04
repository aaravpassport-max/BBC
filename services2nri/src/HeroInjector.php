<?php
/**
 * HeroInjector — Service Page Enhancement
 */

class HeroInjector {

    public static function renderServiceEnhancement( string $slug, array $global_settings ): string {
        $brand = esc_attr( $global_settings['primary_color'] ?? '#4A6FA5' );
        ob_start();
        ?>
<script>
(function() {
    var data = window.S2NRI_SERVICE_DATA;
    if (!data) return;
    var hero    = data.hero_settings    || {};
    var marquee = data.marquee_settings || {};
    var applied = false;

    function applyOverrides() {
        var svcGrid = document.querySelector('.svc-grid');
        if (!svcGrid || applied) return;
        var leftCol  = svcGrid.children[0];
        if (!leftCol) return;
        var heroCard = leftCol.children[0];
        if (!heroCard) return;
        applied = true;

        if (hero.image_url) {
            var img = heroCard.querySelector('img');
            if (img) img.src = hero.image_url;
        }
        if (hero.overlay_opacity !== undefined) {
            var ov = heroCard.querySelector('div[style*="gradient"]');
            if (ov) {
                var op = Math.min(100, Math.max(0, parseInt(hero.overlay_opacity))) / 100;
                ov.style.background = 'linear-gradient(to top,rgba(0,0,0,'+op+') 0%,transparent 50%)';
            }
        }
        if (hero.title) {
            var t = heroCard.querySelector('h1,h2,[style*="fontWeight: 900"]');
            if (t) t.textContent = hero.title;
        }
        if (marquee.enabled && marquee.text) {
            if (!document.getElementById('s2nri-svc-marquee')) {
                var speed   = parseInt(marquee.speed) || 30;
                var bgColor = marquee.bg_color  || '<?php echo $brand; ?>';
                var txColor = marquee.text_color || '#ffffff';
                var mq = document.createElement('div');
                mq.id = 's2nri-svc-marquee';
                mq.style.cssText = 'background:'+bgColor+';overflow:hidden;padding:10px 0;';
                var track = document.createElement('div');
                track.style.cssText = 'display:flex;width:max-content;animation:s2nriScroll '+speed+'s linear infinite;';
                if (marquee.pause_hover !== false) {
                    track.onmouseenter = function(){this.style.animationPlayState='paused';};
                    track.onmouseleave = function(){this.style.animationPlayState='running';};
                }
                for (var r=0;r<4;r++){
                    var sp = document.createElement('span');
                    sp.style.cssText = 'white-space:nowrap;padding:0 60px;font-size:13px;font-weight:700;color:'+txColor+';';
                    sp.textContent = '\u2756 '+marquee.text;
                    track.appendChild(sp);
                }
                mq.appendChild(track);
                if (!document.getElementById('s2nri-svc-marquee-css')){
                    var st = document.createElement('style');
                    st.id  = 's2nri-svc-marquee-css';
                    st.textContent = '@keyframes s2nriScroll{from{transform:translateX(0)}to{transform:translateX(-50%)}}';
                    document.head.appendChild(st);
                }
                if (heroCard.nextSibling) leftCol.insertBefore(mq, heroCard.nextSibling);
                else leftCol.appendChild(mq);
            }
        }
    }

    var ob = new MutationObserver(function(){
        if (document.querySelector('.svc-grid') && !applied) applyOverrides();
    });
    ob.observe(document.body, {childList:true, subtree:true});
    setTimeout(applyOverrides, 500);
    setTimeout(applyOverrides, 1500);
})();
</script>
        <?php
        return ob_get_clean();
    }

    public static function renderSectionNav( string $slug, array $global_settings ): string {
        global $wpdb;
        $p     = $wpdb->prefix;
        $brand = esc_attr( $global_settings['primary_color'] ?? '#4A6FA5' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, type, title, sort_order, is_visible, is_active, content
             FROM `{$p}s2nri_service_sections`
             WHERE service_id = (SELECT id FROM `{$p}s2nri_services` WHERE slug = %s LIMIT 1)
             ORDER BY sort_order ASC, id ASC LIMIT 40",
            $slug
        ), ARRAY_A );

        $null_types = [ 'hero', 'marquee' ];
        $skip_nav   = [ 'trust_badges' ];

        $nav_items  = [];
        $all_items  = [];
        $hidden_ids = [];
        $dev_viz    = [];

        foreach ( (array) $rows as $row ) {
            $type = $row['type'];
            if ( in_array( $type, $null_types, true ) ) continue;

            $id    = 's2nri-sec-' . (int) $row['id'];
            $vis   = (int) $row['is_visible'] || (int) $row['is_active'];
            $in_nav = ! in_array( $type, $skip_nav, true );
            $title = $row['title'] ?: ucfirst( str_replace( '_', ' ', $type ) );

            $all_items[] = [ 'id' => $id, 'type' => $type, 'nav' => $in_nav, 'vis' => $vis ];

            if ( ! $vis ) { $hidden_ids[] = (int) $row['id']; continue; }
            if ( $in_nav ) $nav_items[] = [ 'id' => $id, 'label' => $title ];

            if ( ! empty( $row['content'] ) ) {
                $cr = is_string( $row['content'] ) ? json_decode( $row['content'], true ) : $row['content'];
                if ( isset( $cr['device_visibility'] ) ) $dev_viz[ $id ] = $cr['device_visibility'];
            }
        }

        $nav_j  = wp_json_encode( $nav_items,  JSON_UNESCAPED_UNICODE );
        $all_j  = wp_json_encode( $all_items,  JSON_UNESCAPED_UNICODE );
        $hid_j  = wp_json_encode( $hidden_ids, JSON_UNESCAPED_UNICODE );
        $viz_j  = wp_json_encode( $dev_viz,    JSON_UNESCAPED_UNICODE );
        $has_db = ! empty( $nav_items ) ? 'true' : 'false';

        ob_start();
        ?>
<!-- S2NRI Section Nav -->
<style>
/* The nav is injected INTO the DOM beside .svc-grid by JS.
   It is NOT position:fixed — it lives in the page flow, sticky on scroll. */
#s2nri-nav-wrap {
    width: 180px;
    flex-shrink: 0;
    position: relative;
}
#s2nri-nav {
    position: sticky;
    top: 80px;
    background: #fff;
    border: 1px solid #EBF0F8;
    border-left: 4px solid <?php echo $brand; ?>;
    border-radius: 0 10px 10px 0;
    padding: 6px 0 10px;
    max-height: calc(100vh - 100px);
    overflow-y: auto;
    box-shadow: 2px 0 16px rgba(0,0,0,.08);
    font-family: inherit;
}
#s2nri-nav-hdr {
    padding: 8px 14px 6px;
    font-size: 9px;
    font-weight: 800;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    border-bottom: 1px solid #f0f0f0;
    margin-bottom: 2px;
}
#s2nri-nav a {
    display: block;
    padding: 7px 14px 7px 12px;
    font-size: 12.5px;
    color: #374151;
    text-decoration: none;
    border-left: 3px solid transparent;
    margin-left: -4px;
    transition: background .12s, color .12s;
    line-height: 1.35;
    font-weight: 500;
}
#s2nri-nav a:hover, #s2nri-nav a.on {
    background: <?php echo $brand; ?>12;
    color: <?php echo $brand; ?>;
    border-left-color: <?php echo $brand; ?>;
    font-weight: 600;
}
/* Mobile: hide the wrap, show pill button instead */
#s2nri-nav-btn {
    display: none;
    position: fixed;
    bottom: 20px;
    left: 16px;
    z-index: 9999;
    background: <?php echo $brand; ?>;
    color: #fff;
    border: none;
    cursor: pointer;
    padding: 10px 18px;
    border-radius: 99px;
    font-size: 13px;
    font-weight: 700;
    box-shadow: 0 4px 16px rgba(0,0,0,.25);
    font-family: inherit;
    align-items: center;
    gap: 6px;
}
/* Mobile overlay nav */
#s2nri-nav-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    z-index: 9998;
    background: rgba(0,0,0,.4);
}
#s2nri-nav-overlay.open { display: block; }
#s2nri-nav.mobile-open {
    position: fixed !important;
    top: auto !important;
    bottom: 0;
    left: 0;
    right: 0;
    width: 100%;
    max-width: 100%;
    border-radius: 14px 14px 0 0;
    border-left: none;
    border-top: 4px solid <?php echo $brand; ?>;
    max-height: 60vh;
    z-index: 9999;
    box-shadow: 0 -4px 24px rgba(0,0,0,.2);
}
@media (max-width: 960px) {
    #s2nri-nav-wrap { display: none; }
    #s2nri-nav-btn  { display: flex !important; }
}
</style>
<div id="s2nri-nav-overlay"></div>
<button id="s2nri-nav-btn" aria-label="Page sections">☰ Sections</button>
<script>
(function(){
var navItems = <?php echo $nav_j; ?>;
var allItems = <?php echo $all_j; ?>;
var hiddenIds= <?php echo $hid_j; ?>;
var devViz  = <?php echo $viz_j; ?>;
var hasDb   = <?php echo $has_db; ?>;
var built   = false;
var mobileOpen = false;

// ── Mobile toggle ────────────────────────────────────────────────────────────
var btn     = document.getElementById('s2nri-nav-btn');
var overlay = document.getElementById('s2nri-nav-overlay');

function setMobile(v) {
    mobileOpen = v;
    var nav = document.getElementById('s2nri-nav');
    if (!nav) return;
    if (v) {
        nav.classList.add('mobile-open');
        overlay.classList.add('open');
        btn.textContent = '✕ Close';
        document.body.style.overflow = 'hidden';
    } else {
        nav.classList.remove('mobile-open');
        overlay.classList.remove('open');
        btn.textContent = '☰ Sections';
        document.body.style.overflow = '';
    }
}

if (btn) btn.addEventListener('click', function(e){ e.stopPropagation(); setMobile(!mobileOpen); });
if (overlay) overlay.addEventListener('click', function(){ setMobile(false); });

// ── Active link on scroll ────────────────────────────────────────────────────
function onScroll() {
    var nav = document.getElementById('s2nri-nav');
    if (!nav) return;
    var links = Array.from(nav.querySelectorAll('a[data-t]'));
    var best = null, scrollY = window.scrollY + 140;
    links.forEach(function(a){
        var el = document.getElementById(a.getAttribute('data-t'));
        if (el && el.offsetTop <= scrollY) best = a;
    });
    links.forEach(function(a){ a.classList.remove('on'); });
    if (best) best.classList.add('on');
}
window.addEventListener('scroll', onScroll, {passive:true});

// ── Click nav link ───────────────────────────────────────────────────────────
document.addEventListener('click', function(e) {
    var a = e.target.closest('#s2nri-nav a[data-t]');
    if (!a) return;
    e.preventDefault();
    setMobile(false);
    var el = document.getElementById(a.getAttribute('data-t'));
    if (el) el.scrollIntoView({behavior:'smooth', block:'start'});
});

// ── Build nav and inject into DOM ────────────────────────────────────────────
function buildNav() {
    if (built) return;

    var grid = document.querySelector('.svc-grid');
    if (!grid) return;
    var col = grid.firstElementChild;
    if (!col || col.children.length < 2) return;

    // Create the nav element
    var nav = document.createElement('nav');
    nav.id = 's2nri-nav';
    nav.setAttribute('aria-label', 'On this page');

    var hdr = document.createElement('div');
    hdr.id = 's2nri-nav-hdr';
    hdr.textContent = '📋 On This Page';
    nav.appendChild(hdr);

    var frag = document.createDocumentFragment();
    var found = 0;

    if (hasDb) {
        // DB sections: assign IDs and build links
        var kids = Array.from(col.children).slice(1);
        kids.forEach(function(el, i) {
            if (i >= allItems.length) return;
            var item = allItems[i];
            el.id = el.id || item.id;
            el.style.scrollMarginTop = '80px';
            var v = devViz[item.id];
            if (v) {
                if (v.desktop === false || v.desktop === 'false') el.classList.add('s2nri-hide-desktop');
                if (v.tablet  === false || v.tablet  === 'false') el.classList.add('s2nri-hide-tablet');
                if (v.mobile  === false || v.mobile  === 'false') el.classList.add('s2nri-hide-mobile');
            }
        });
        hiddenIds.forEach(function(dbId) {
            kids.forEach(function(el, i) {
                if (i < allItems.length && parseInt(allItems[i].id.replace('s2nri-sec-',''),10) === dbId)
                    el.style.display = 'none';
            });
        });
        navItems.forEach(function(item) {
            var a = document.createElement('a');
            a.href = '#' + item.id;
            a.setAttribute('data-t', item.id);
            a.textContent = item.label.length > 28 ? item.label.slice(0,26)+'…' : item.label;
            frag.appendChild(a);
            found++;
        });
    } else {
        // No DB sections: discover headings from fallback content
        // col.children[0] = hero card, [1] = badges (no heading), [2+] = content sections
        Array.from(col.children).forEach(function(child, idx) {
            if (idx === 0) return;
            var h = child.querySelector('h2, h3');
            if (!h) return;
            var label = h.textContent.trim();
            // Strip leading emoji/symbols
            label = label.replace(/^[\u{1F300}-\u{1FAFF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}🔐💰📋⭐✅📎⚙️🔑\s]+/u, '').trim();
            if (!label) label = h.textContent.trim();
            if (!label) return;
            var id = 's2nri-sec-' + idx;
            child.id = id;
            child.style.scrollMarginTop = '80px';
            var a = document.createElement('a');
            a.href = '#' + id;
            a.setAttribute('data-t', id);
            a.textContent = label.length > 28 ? label.slice(0,26)+'…' : label;
            frag.appendChild(a);
            found++;
        });
    }

    if (!found) { built = false; return; } // React not ready yet

    nav.appendChild(frag);

    // ── Inject the nav wrap to the LEFT of .svc-grid ─────────────────────────
    // The page has: body > #s2nri-root > div > main > ... > .svc-grid
    // We insert a flex wrapper around .svc-grid so the nav sits left of it.
    var wrap = document.getElementById('s2nri-nav-wrap');
    if (!wrap) {
        // First time: wrap .svc-grid in a flex container and prepend nav
        wrap = document.createElement('div');
        wrap.id = 's2nri-nav-wrap';
        wrap.appendChild(nav);

        // Insert wrap before grid, then move grid inside the flex container
        var parent = grid.parentElement;
        // Create outer flex row
        var row = document.createElement('div');
        row.id = 's2nri-nav-row';
        row.style.cssText = 'display:flex;align-items:flex-start;gap:16px;max-width:1400px;margin:0 auto;padding:0 20px;';
        parent.insertBefore(row, grid);
        row.appendChild(wrap);
        row.appendChild(grid);
        // Remove grid's own horizontal centering since row handles it
        grid.style.margin = '0';
        grid.style.paddingLeft = '0';
        grid.style.paddingRight = '0';
    } else {
        // Already inserted — just add nav to wrap
        wrap.innerHTML = '';
        wrap.appendChild(nav);
    }

    built = true;
    onScroll();
}

var ob = new MutationObserver(function(){
    if (document.querySelector('.svc-grid')) buildNav();
});
ob.observe(document.body, {childList:true, subtree:true});
setTimeout(buildNav, 400);
setTimeout(buildNav, 1200);
setTimeout(buildNav, 3000);
})();
</script>
        <?php
        return ob_get_clean();
    }
}
