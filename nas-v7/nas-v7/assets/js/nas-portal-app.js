/**
 * NAS Portal App Shell — SPA-style navigation + bottom nav state.
 */
(function () {
  'use strict';

  var MAIN = '#nas-main-content';
  var EXCLUDED = (window.NAS_PORTAL_APP && NAS_PORTAL_APP.excludedPaths) || [
    '/book-newspaper-ad',
    '/payment',
    '/client-dashboard',
    '/admin-dashboard',
    '/staff-dashboard',
    '/moderation-dashboard',
    '/vendor-dashboard'
  ];

  function qs(sel, ctx) {
    return (ctx || document).querySelector(sel);
  }

  function normalizePath(path) {
    try {
      var u = new URL(path, window.location.origin);
      var p = u.pathname.replace(/\/+$/, '') || '/';
      return p + u.search;
    } catch (e) {
      return path;
    }
  }

  function isExcluded(path) {
    var p = normalizePath(path).split('?')[0];
    if (p === '/') return false;
    return EXCLUDED.some(function (ex) {
      var e = ex.replace(/\/+$/, '');
      return p === e || p.indexOf(e + '/') === 0;
    });
  }

  function isSpaLink(a) {
    if (!a || !a.href) return false;
    if (a.target === '_blank' || a.hasAttribute('download')) return false;
    if (a.dataset.noSpa === '1' || a.classList.contains('no-spa')) return false;
    if (a.getAttribute('href').charAt(0) === '#') return false;
    var url;
    try {
      url = new URL(a.href, window.location.origin);
    } catch (e) {
      return false;
    }
    if (url.origin !== window.location.origin) return false;
    if (isExcluded(url.pathname)) return false;
    return true;
  }

  function updateBottomNav(path) {
    var p = normalizePath(path).split('?')[0];
    var slug = p === '/' ? 'home' : p.split('/').filter(Boolean).pop() || 'home';
    document.querySelectorAll('.nas-app-bottom-nav__item').forEach(function (el) {
      var key = el.dataset.nasNav;
      var active = false;
      if (key === 'home' && (p === '/' || slug === 'nas-home' || slug === 'nas-homepage')) active = true;
      else if (key === 'browse' && ['newspapers', 'cities', 'newspaper-ads'].indexOf(slug) !== -1) active = true;
      else if (key === 'book' && slug === 'book-newspaper-ad') active = true;
      else if (key === 'track' && ['track-order', 'client-dashboard', 'booking-confirmation'].indexOf(slug) !== -1) active = true;
      else if (key === 'account' && ['newspaper-ad-login', 'client-dashboard', 'vendor-register'].indexOf(slug) !== -1) active = true;
      el.classList.toggle('is-active', active);
      el.setAttribute('aria-current', active ? 'page' : 'false');
    });

    document.querySelectorAll('.nhp-header__link').forEach(function (el) {
      var href = el.getAttribute('href') || '';
      try {
        var ep = new URL(href, window.location.origin).pathname.replace(/\/+$/, '') || '/';
        var cp = p.replace(/\/+$/, '') || '/';
        el.classList.toggle('is-active', ep === cp);
      } catch (e) { /* ignore */ }
    });
  }

  function reinitScripts(container) {
    if (typeof window.nasInitPortalPage === 'function') {
      window.nasInitPortalPage(container);
    }
    document.dispatchEvent(new CustomEvent('nas:page-loaded', { detail: { container: container } }));
  }

  function swapMain(html, url, push) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var newMain = doc.querySelector(MAIN);
    var main = qs(MAIN);
    if (!newMain || !main) {
      window.location.href = url;
      return;
    }

    var doSwap = function () {
      main.innerHTML = newMain.innerHTML;
      if (doc.title) document.title = doc.title;
      updateBottomNav(url);
      reinitScripts(main);
      if (push) history.pushState({ nasSpa: true, url: url }, '', url);
      window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
      document.body.classList.remove('nas-app-navigating');
    };

    if (document.startViewTransition) {
      document.startViewTransition(doSwap);
    } else {
      doSwap();
    }
  }

  function navigate(url, push) {
    if (isExcluded(url)) {
      window.location.href = url;
      return;
    }
    document.body.classList.add('nas-app-navigating');
    fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-NAS-SPA': '1', 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
      })
      .then(function (html) {
        swapMain(html, url, push !== false);
      })
      .catch(function () {
        document.body.classList.remove('nas-app-navigating');
        window.location.href = url;
      });
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (!a || !isSpaLink(a)) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    navigate(a.href, true);
  });

  window.addEventListener('popstate', function (e) {
    if (e.state && e.state.nasSpa) {
      navigate(window.location.href, false);
    }
  });

  if (history.state === null) {
    history.replaceState({ nasSpa: true, url: window.location.href }, '', window.location.href);
  }

  updateBottomNav(window.location.href);

  /* Loader element */
  if (!qs('.nas-app-page-loader')) {
    var loader = document.createElement('div');
    loader.className = 'nas-app-page-loader';
    loader.setAttribute('aria-hidden', 'true');
    document.body.appendChild(loader);
  }
})();
