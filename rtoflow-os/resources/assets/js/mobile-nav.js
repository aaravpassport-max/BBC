/**
 * Mobile app-shell navigation: fixed bottom nav tab switching via fetch(),
 * shared by the Admin, Vendor, and Client dashboards.
 *
 * TRACE: user taps a .rto-bn-item[href] on a touch/narrow viewport →
 *        click intercepted, preventDefault() → fetch(href) with header
 *        X-Rto-Partial:1 → Router::extractPartialResponse() (PHP) returns
 *        {ok, html, title} instead of a full page → on ok:true, the
 *        content region is swapped in-place, <title> and the URL bar
 *        (history.pushState) are updated, inline <script> tags in the new
 *        fragment are re-executed (innerHTML swaps never run embedded
 *        scripts), and the tapped tab gets the active state →
 *        preconditions: viewport is under the CSS mobile breakpoint (the
 *        bottom nav is display:none above it, so this code simply never
 *        gets a click to intercept on desktop — no separate JS gate
 *        needed) →
 *        postconditions: dashboard content changes without a full page
 *        reload, exactly like tapping a tab in a native app →
 *        edge cases: fetch() fails (network drop) or the server reports
 *        ok:false (couldn't find the expected content region — e.g. the
 *        route redirected to a login page) → falls back to a normal
 *        full-page navigation via location.href, so the user is never
 *        stuck on a broken partial view; browser back/forward (popstate)
 *        re-fetches the target URL as a partial too, so history navigation
 *        stays app-like instead of dropping back to full reloads.
 */
(function () {
  'use strict';

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  // Re-run any <script> tag inside a freshly-inserted HTML fragment.
  // Setting .innerHTML never executes <script> tags the browser parses
  // from it — each one must be re-created and appended to actually run.
  function reExecuteScripts(container) {
    qsa('script', container).forEach(function (oldScript) {
      var newScript = document.createElement('script');
      for (var i = 0; i < oldScript.attributes.length; i++) {
        var attr = oldScript.attributes[i];
        newScript.setAttribute(attr.name, attr.value);
      }
      newScript.textContent = oldScript.textContent;
      oldScript.parentNode.replaceChild(newScript, oldScript);
    });
  }

  function getContentRegion() {
    return qs('#rtoContentRegion') /* admin */ || qs('.rto-client-main') /* vendor/client */;
  }

  // TRACE: page load / AJAX partial swap / viewport resize crossing the
  //        mobile breakpoint → scans for elements whose INLINE style sets
  //        a multi-track grid-template-columns (e.g. "1fr 1fr",
  //        "repeat(6,1fr)", "1fr 110px 110px 150px 150px") → on a mobile
  //        viewport, collapses each to a single column and remembers the
  //        original value; on a return to desktop width, restores it →
  //        preconditions: none — safe to call repeatedly/idempotently →
  //        postconditions: every ad hoc inline multi-column form/detail
  //        grid across the codebase (there is no single shared CSS class
  //        for these — each screen writes its own style="grid-template-
  //        columns:..." — so a handful of targeted CSS overrides would
  //        have missed most of them) becomes single-column on phones
  //        without touching desktop rendering at all →
  //        edge cases handled: an element with only ONE track (e.g.
  //        "1fr", "minmax(0,1fr)") is left alone since collapsing it is a
  //        no-op anyway; auto-fill/auto-fit grids (already self-
  //        responsive by design) naturally compute to fewer tracks as the
  //        viewport narrows so they are correctly skipped once genuinely
  //        down to one column; repeated calls on an already-collapsed
  //        element do nothing (guarded by data-rto-grid-collapsed).
  var MOBILE_MQ = '(max-width: 782px)';
  function isMobileViewport() {
    return window.matchMedia(MOBILE_MQ).matches;
  }
  function collapseGridsForMobile(root) {
    if (!isMobileViewport()) return;
    qsa('[style*="grid-template-columns"]', root || document).forEach(function (el) {
      if (el.hasAttribute('data-rto-grid-collapsed')) return;
      var tracks = getComputedStyle(el).gridTemplateColumns.trim().split(/\s+/).filter(Boolean);
      if (tracks.length > 1) {
        el.setAttribute('data-rto-grid-orig', el.style.gridTemplateColumns || '');
        el.setAttribute('data-rto-grid-collapsed', '1');
        el.style.gridTemplateColumns = '1fr';
      }
    });
  }
  function restoreGridsForDesktop(root) {
    qsa('[data-rto-grid-collapsed]', root || document).forEach(function (el) {
      el.style.gridTemplateColumns = el.getAttribute('data-rto-grid-orig') || '';
      el.removeAttribute('data-rto-grid-collapsed');
      el.removeAttribute('data-rto-grid-orig');
    });
  }
  function applyGridResponsiveness(root) {
    if (isMobileViewport()) collapseGridsForMobile(root);
    else restoreGridsForDesktop(root);
  }

  // TRACE: same triggers as the grid collapse above → finds elements whose
  //        INLINE style sets a min-width larger than the current viewport
  //        (this codebase has ~20 such inline min-widths — filter inputs,
  //        KPI cards, the automation-rule timeline boxes, one-off modal
  //        boxes — none sharing a class, so no single CSS selector can
  //        reach them all) → zeroes the min-width so the element can
  //        actually shrink to fit instead of forcing the whole page to
  //        scroll horizontally → restores the original value once the
  //        viewport is wide enough again →
  //        preconditions: none, idempotent →
  //        postconditions: no inline min-width anywhere on the page can
  //        exceed the viewport width, eliminating a real class of
  //        "page scrolls sideways" bugs (e.g. the Add RTO modal's
  //        min-width:420px on a 375px phone, which mobile-nav.css also
  //        patches directly for that specific element — this is the
  //        general-purpose net for every other one) →
  //        edge cases handled: a min-width comfortably under the viewport
  //        (e.g. a 20px checkbox) is left completely alone since it can
  //        never cause overflow; repeated calls on an already-adjusted
  //        element are a no-op (guarded by data-rto-minw-adjusted).
  function collapseMinWidthsForMobile(root) {
    if (!isMobileViewport()) return;
    var maxAllowed = window.innerWidth - 32; // leave room for page padding
    qsa('[style*="min-width"]', root || document).forEach(function (el) {
      if (el.hasAttribute('data-rto-minw-adjusted')) return;
      var mw = parseFloat(getComputedStyle(el).minWidth);
      if (mw && mw > maxAllowed) {
        el.setAttribute('data-rto-minw-orig', el.style.minWidth || '');
        el.setAttribute('data-rto-minw-adjusted', '1');
        el.style.minWidth = '0';
      }
    });
  }
  function restoreMinWidthsForDesktop(root) {
    qsa('[data-rto-minw-adjusted]', root || document).forEach(function (el) {
      el.style.minWidth = el.getAttribute('data-rto-minw-orig') || '';
      el.removeAttribute('data-rto-minw-adjusted');
      el.removeAttribute('data-rto-minw-orig');
    });
  }
  function applyMinWidthResponsiveness(root) {
    if (isMobileViewport()) collapseMinWidthsForMobile(root);
    else restoreMinWidthsForDesktop(root);
  }

  function setActiveTab(page) {
    qsa('.rto-bn-item[data-rto-page]').forEach(function (el) {
      var isActive = el.getAttribute('data-rto-page') === page;
      el.classList.toggle('active', isActive);
      if (isActive) el.setAttribute('aria-current', 'page');
      else el.removeAttribute('aria-current');
    });
  }

  function loadPartial(url, page, pushHistory) {
    var region = getContentRegion();
    var navEl = qs('.rto-bottom-nav');
    if (!region) { window.location.href = url; return; }

    if (navEl) navEl.classList.add('rto-bn-busy');
    region.classList.add('rto-content-transitioning');

    fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-Rto-Partial': '1' }
    })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        if (!data || data.ok !== true || typeof data.html !== 'string') {
          throw new Error('partial response not ok');
        }
        region.innerHTML = data.html;
        reExecuteScripts(region);
        collapseGridsForMobile(region);
        collapseMinWidthsForMobile(region);
        if (data.title) document.title = data.title;
        if (pushHistory) history.pushState({ rtoPartial: true, page: page }, data.title || '', url);
        setActiveTab(page);
        region.scrollTop = 0;
        window.scrollTo(0, 0);
        document.dispatchEvent(new CustomEvent('rto:partial-loaded', { detail: { page: page, url: url } }));
      })
      .catch(function () {
        // Fallback: never leave the user on a half-broken view — do the
        // normal full navigation this click would have done anyway.
        window.location.href = url;
      })
      .finally(function () {
        if (navEl) navEl.classList.remove('rto-bn-busy');
        region.classList.remove('rto-content-transitioning');
      });
  }

  function onBottomNavClick(e) {
    var link = e.target.closest('.rto-bn-item[href][data-rto-page]');
    if (!link) return;
    // Only intercept when the bottom nav is actually the visible nav
    // (i.e. we're under the mobile breakpoint) — on desktop the bottom
    // nav is display:none and never receives a click in the first place,
    // but this guards against a resize between render and click too.
    var navEl = qs('.rto-bottom-nav');
    if (!navEl || getComputedStyle(navEl).display === 'none') return;

    e.preventDefault();
    var page = link.getAttribute('data-rto-page');
    if (link.classList.contains('active')) return; // already on this tab
    loadPartial(link.getAttribute('href'), page, true);
  }

  function onMoreClick() {
    // Reuse the EXISTING desktop sidebar-toggle handler (admin.js) rather
    // than implementing a second, competing open/close mechanism.
    var toggle = document.getElementById('sidebarToggle');
    if (toggle) { toggle.click(); return; }
    // Vendor/Client layouts have no sidebar at all — "More" isn't shown
    // there (see their header templates), so this is effectively
    // admin-only, but guarded here in case a future layout adds one.
  }

  function onPopState(e) {
    var page = e.state && e.state.rtoPartial ? e.state.page : null;
    if (!page) return; // not one of our states — let the browser reload normally
    loadPartial(location.href, page, false);
  }

  // Initial pass on first paint, plus a debounced re-check on resize/
  // orientation-change so a tablet rotated from portrait to landscape (or
  // a desktop window narrowed past the breakpoint) gets grids collapsed
  // or restored without needing a full page reload.
  function runResponsivenessPasses() {
    collapseGridsForMobile();
    collapseMinWidthsForMobile();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', runResponsivenessPasses);
  } else {
    runResponsivenessPasses();
  }
  var gridResizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(gridResizeTimer);
    gridResizeTimer = setTimeout(function () {
      applyGridResponsiveness();
      applyMinWidthResponsiveness();
    }, 150);
  });

  document.addEventListener('click', onBottomNavClick);
  document.addEventListener('click', function (e) {
    if (e.target.closest('#rtoBottomNavMore')) onMoreClick();
  });
  window.addEventListener('popstate', onPopState);

  // Close the off-canvas sidebar sheet when a link inside it is tapped, and
  // when the scrim behind it is tapped — pure UX polish, does not affect
  // desktop where .rto-sidebar-scrim is never added (see mobile-nav.css,
  // scoped to the mobile breakpoint only).
  document.addEventListener('click', function (e) {
    var sidebar = document.getElementById('rtoSidebar');
    var wrap = document.querySelector('.rto-wrap');
    if (!sidebar || !wrap) return;
    if (sidebar.classList.contains('open')) {
      wrap.classList.add('rto-sidebar-scrim');
      if (!e.target.closest('#rtoSidebar') && !e.target.closest('#sidebarToggle') && !e.target.closest('#rtoBottomNavMore')) {
        sidebar.classList.remove('open');
        wrap.classList.remove('rto-sidebar-scrim');
      } else if (e.target.closest('#rtoSidebar a')) {
        sidebar.classList.remove('open');
        wrap.classList.remove('rto-sidebar-scrim');
      }
    } else {
      wrap.classList.remove('rto-sidebar-scrim');
    }
  });
})();
