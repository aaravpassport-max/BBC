/**
 * NAS Core JS
 * Utilities: Toast, Modal, Ajax, Form helpers, Nav
 */
(function($) {
  'use strict';
  // 16-A-5: Guard — if NAS not injected by wp_localize_script, exit gracefully without crash
  if (typeof NAS === 'undefined') {
    console.error('[NAS] NAS global not defined — check wp_localize_script registration in Enqueue.php');
    return;
  }

  /* =========================================================
     Toast
  ========================================================= */
  window.nasToast = {
    container: null,
    init() {
      if (!this.container) {
        this.container = document.createElement('div');
        this.container.id = 'nas-toast-container';
        document.body.appendChild(this.container);
      }
    },
    show(msg, type = 'info', duration = 4000) {
      this.init();
      const icons = { success: '✓', error: '✕', warning: '⚠', info: 'ℹ' };
      const el = document.createElement('div');
      el.className = `nas-toast nas-toast-${type}`;
      el.setAttribute('role', 'alert');
      el.setAttribute('aria-live', 'polite');
      el.style.cssText = 'display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border-radius:12px;background:#fff;border-left:4px solid ' +
        ({success:'#16a34a',error:'#dc2626',warning:'#d97706',info:'#2563eb'}[type]||'#6c47ff') + ';box-shadow:0 4px 6px -1px rgba(0,0,0,.07),0 10px 40px -5px rgba(0,0,0,.12);min-width:280px;max-width:360px';
      el.innerHTML = `<span style="font-size:18px;flex-shrink:0;line-height:1.3" aria-hidden="true">${icons[type]||'ℹ️'}</span>
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;font-size:13px;color:#0f172a;line-height:1.4">${msg}</div>
        </div>
        <button class="nas-toast-close" aria-label="Close notification" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:16px;padding:0;flex-shrink:0">✕</button>`;
      el.querySelector('.nas-toast-close').addEventListener('click', () => this.dismiss(el));
      this.container.appendChild(el);
      if (duration > 0) setTimeout(() => this.dismiss(el), duration);
    },
    dismiss(el) {
      el.classList.add('nas-fade-out');
      setTimeout(() => el.remove(), 350);
    },
    success(m, d) { this.show(m, 'success', d); },
    error(m, d)   { this.show(m, 'error', d); },
    warning(m, d) { this.show(m, 'warning', d); },
    info(m, d)    { this.show(m, 'info', d); },
  };

  /* =========================================================
     Modal
  ========================================================= */
  window.nasModal = {
    _lastFocus: null,
    open(id) {
      const el = typeof id === 'string' ? (document.getElementById(id.replace('#','')) || document.querySelector(id)) : id;
      if (!el) return;
      this._lastFocus = document.activeElement;
      el.style.display = 'flex';
      el.classList.add('open');
      el.setAttribute('aria-modal', 'true');
      el.setAttribute('role', 'dialog');
      document.body.style.overflow = 'hidden';
      // Move focus to first focusable element inside modal
      const focusable = el.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (focusable.length) focusable[0].focus();
      // Trap focus inside modal
      el._trapHandler = (e) => {
        if (e.key !== 'Tab') return;
        const all = [...el.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')].filter(n => !n.disabled);
        if (!all.length) return;
        const first = all[0], last = all[all.length - 1];
        if (e.shiftKey) { if (document.activeElement === first) { e.preventDefault(); last.focus(); } }
        else            { if (document.activeElement === last)  { e.preventDefault(); first.focus(); } }
      };
      el._escHandler = (e) => { if (e.key === 'Escape') this.close(el); };
      el.addEventListener('keydown', el._trapHandler);
      document.addEventListener('keydown', el._escHandler);
    },
    close(id) {
      const el = typeof id === 'string' ? (document.getElementById(id.replace('#','')) || document.querySelector(id)) : id;
      if (!el) return;
      el.style.display = 'none';
      el.classList.remove('open');
      el.removeAttribute('aria-modal');
      document.body.style.overflow = '';
      if (el._trapHandler) el.removeEventListener('keydown', el._trapHandler);
      if (el._escHandler)  document.removeEventListener('keydown', el._escHandler);
      // Return focus to the element that opened this modal
      if (this._lastFocus && this._lastFocus.focus) this._lastFocus.focus();
    },
    closeAll() {
      document.querySelectorAll('.nas-modal-overlay').forEach(el => { el.style.display = 'none'; el.classList.remove('open'); el.removeAttribute('aria-modal'); });
      document.body.style.overflow = '';
      if (this._lastFocus && this._lastFocus.focus) this._lastFocus.focus();
    },
  };

  // Modal close event delegation
  document.addEventListener('click', function(e) {
    if (e.target.classList.contains('nas-modal-overlay')) nasModal.close(e.target);
    if (e.target.matches('.nas-modal-close') || e.target.closest('.nas-modal-close')) {
      const overlay = e.target.closest('.nas-modal-overlay');
      if (overlay) nasModal.close(overlay);
    }
  });

  /* =========================================================
     Ajax helper
  ========================================================= */
  // TRACE: nasAjax(action, data, opts) triggered by any dashboard component → builds FormData,
  //        appends nonce, fires fetch with 30-second AbortController timeout →
  //        parses JSON response, throws on !success → returns r.data to caller.
  //        Precondition: NAS.ajax_url and NAS.nonce are set by wp_localize_script.
  //        Postcondition: resolved Promise contains r.data on success; rejects with Error on failure/timeout.
  //        Edge cases: AbortError → "Request timed out"; !r.success → uses r.data.message; network error → rethrows.
  window.nasAjax = function(action, data = {}, opts = {}) {
    const timeout = opts.timeout || 30000;
    const ctrl = new AbortController();
    const tid  = setTimeout(() => ctrl.abort(), timeout);

    const formData = new FormData();
    formData.append('action', action);
    formData.append('nonce', NAS.nonce);
    for (const [k, v] of Object.entries(data)) {
      if (Array.isArray(v)) v.forEach(i => formData.append(k + '[]', i));
      else formData.append(k, v ?? '');
    }
    return fetch(NAS.ajax_url, { method: 'POST', credentials: 'same-origin', body: formData, signal: ctrl.signal })
      .then(r => { clearTimeout(tid); return r.json(); })
      .then(r => {
        if (!r.success) throw new Error(r.data?.message || r.data || 'Error');
        return r.data;
      })
      .catch(e => {
        clearTimeout(tid);
        if (e.name === 'AbortError') throw new Error((NAS.i18n && NAS.i18n.request_timeout) || 'Request timed out. Please try again.');
        throw e;
      });
  };

  /* =========================================================
     Form serializer
  ========================================================= */
  window.nasFormData = function(form) {
    const out = {};
    const fd = new FormData(form instanceof HTMLFormElement ? form : document.querySelector(form));
    fd.forEach((v, k) => {
      if (out[k] !== undefined) {
        if (!Array.isArray(out[k])) out[k] = [out[k]];
        out[k].push(v);
      } else out[k] = v;
    });
    return out;
  };

  /* =========================================================
     Tabs
  ========================================================= */
  function initTabs() {
    document.querySelectorAll('.nas-tabs').forEach(tabBar => {
      tabBar.querySelectorAll('.nas-tab').forEach(tab => {
        tab.addEventListener('click', function() {
          const target = this.dataset.tab;
          const root = this.closest('[data-tabs-root]') || document;
          root.querySelectorAll('.nas-tab').forEach(t => t.classList.remove('active'));
          root.querySelectorAll('.nas-tab-panel').forEach(p => p.classList.remove('active'));
          this.classList.add('active');
          const panel = root.querySelector(`[data-panel="${target}"]`);
          if (panel) panel.classList.add('active');
          // URL hash
          if (history.replaceState) history.replaceState(null, '', '#' + target);
        });
      });
    });
    // Auto-activate from hash
    const hash = location.hash.slice(1);
    if (hash) {
      const tab = document.querySelector(`.nas-tab[data-tab="${hash}"]`);
      if (tab) tab.click();
    }
  }

  /* =========================================================
     Dashboard Top Nav
  ========================================================= */
  function initTopNav() {
    // Mobile hamburger — handles both .nas-mobile-nav and .nas-mobile-nav-drawer
    const ham = document.querySelector('.nas-hamburger');
    // Support both class names used across templates
    const mobileNav = document.querySelector('.nas-mobile-nav-drawer') 
                   || document.querySelector('.nas-mobile-nav');
    const mobileOverlay = document.querySelector('.nas-mobile-nav-overlay');
    const mobileClose = document.querySelector('.nas-mobile-nav-drawer__close')
                     || document.querySelector('.nas-mobile-nav-close');

    function nasOpenMobileNav() {
      if (!mobileNav) return;
      // Support both 'open' and 'is-open' class names
      mobileNav.classList.add('is-open', 'open');
      if (mobileOverlay) mobileOverlay.classList.add('is-open', 'open');
      document.body.style.overflow = 'hidden';
    }
    function nasCloseMobileNav() {
      if (!mobileNav) return;
      mobileNav.classList.remove('is-open', 'open');
      if (mobileOverlay) mobileOverlay.classList.remove('is-open', 'open');
      document.body.style.overflow = '';
    }

    if (ham) ham.addEventListener('click', nasOpenMobileNav);
    if (mobileClose) mobileClose.addEventListener('click', nasCloseMobileNav);
    if (mobileOverlay) mobileOverlay.addEventListener('click', nasCloseMobileNav);
    // Close on nav link click
    if (mobileNav) {
      mobileNav.querySelectorAll('a').forEach(a => a.addEventListener('click', nasCloseMobileNav));
    }

    // User dropdown (top-nav avatar button)
    const userMenuBtn  = document.getElementById('nas-user-menu-btn');
    const userDropdown = document.getElementById('nas-user-dropdown');
    if (userMenuBtn && userDropdown) {
      userMenuBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        userDropdown.classList.toggle('is-open');
        // Close notif panel if open
        document.getElementById('nas-notif-panel')?.classList.remove('is-open');
      });
    }

    // Notification panel
    const notifBtn   = document.getElementById('nas-notif-btn')
                    || document.querySelector('.nas-top-nav__icon-btn');
    const notifPanel = document.getElementById('nas-notif-panel');
    const notifClose = document.getElementById('nas-notif-close');
    if (notifBtn && notifPanel) {
      notifBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        notifPanel.classList.toggle('is-open');
        userDropdown?.classList.remove('is-open');
        // Load notifications on open
        if (notifPanel.classList.contains('is-open') && typeof nasLoadNotifications === 'function') {
          nasLoadNotifications();
        }
      });
    }
    if (notifClose) notifClose.addEventListener('click', () => notifPanel?.classList.remove('is-open'));

    // Header scroll shadow — public portal (nhp-header) + legacy nas-top-nav
    const portalHeader = document.getElementById('nhp-header') || document.querySelector('.nhp-header');
    const legacyNav = document.getElementById('nas-top-nav') || document.querySelector('.nas-top-nav');
    const onScroll = () => {
      const scrolled = window.scrollY > 12;
      if (portalHeader) portalHeader.classList.toggle('nhp-header--scrolled', scrolled);
      if (legacyNav) legacyNav.classList.toggle('is-scrolled', scrolled);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    // Close all dropdowns on outside click
    document.addEventListener('click', (e) => {
      if (!e.target.closest('#nas-user-menu-btn') && !e.target.closest('#nas-user-dropdown')) {
        userDropdown?.classList.remove('is-open');
      }
      if (!e.target.closest('#nas-notif-btn') && !e.target.closest('#nas-notif-panel')
          && !e.target.closest('.nas-top-nav__icon-btn')) {
        notifPanel?.classList.remove('is-open');
      }
    });
  }

  /* =========================================================
     Drawer
  ========================================================= */
  window.nasDrawer = {
    open(id) {
      const el = document.getElementById(id) || document.querySelector(id);
      const overlay = document.querySelector('.nas-drawer-overlay');
      if (el) { el.classList.add('open'); document.body.style.overflow = 'hidden'; }
      if (overlay) overlay.classList.add('open');
    },
    close() {
      document.querySelectorAll('.nas-drawer.open').forEach(d => d.classList.remove('open'));
      const overlay = document.querySelector('.nas-drawer-overlay');
      if (overlay) overlay.classList.remove('open');
      document.body.style.overflow = '';
    },
  };
  document.addEventListener('click', function(e) {
    if (e.target.matches('.nas-drawer-overlay')) nasDrawer.close();
    if (e.target.matches('.nas-drawer-close') || e.target.closest('.nas-drawer-close')) nasDrawer.close();
  });

  /* =========================================================
     FAQ accordion (city pages)
  ========================================================= */
  window.nasTogglePortalFaq = function(btn) {
    const item = btn.closest('.nas-faq-item');
    if (!item) return;
    const wasOpen = item.classList.contains('is-open') || item.classList.contains('open');
    document.querySelectorAll('.nas-faq-item.is-open, .nas-faq-item.open').forEach(e => {
      e.classList.remove('is-open', 'open');
      const b = e.querySelector('.nas-faq-q');
      if (b) b.setAttribute('aria-expanded', 'false');
    });
    if (!wasOpen) {
      item.classList.add('is-open');
      btn.setAttribute('aria-expanded', 'true');
    }
  };

  function initFaq() {
    document.querySelectorAll('.nas-faq-q').forEach(q => {
      if (q.getAttribute('onclick')) return;
      q.addEventListener('click', () => nasTogglePortalFaq(q));
    });
  }

  /* =========================================================
     Format currency
  ========================================================= */
  window.nasFmt = {
    currency(val) {
      return '₹' + parseFloat(val || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    date(str) {
      if (!str) return '—';
      return new Date(str).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
    },
    timeAgo(str) {
      if (!str) return '';
      const diff = (Date.now() - new Date(str).getTime()) / 1000;
      if (diff < 60) return 'just now';
      if (diff < 3600) return Math.floor(diff/60) + 'm ago';
      if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
      return Math.floor(diff/86400) + 'd ago';
    },
  };

  /* =========================================================
     Status badge renderer
  ========================================================= */
  window.nasStatusBadge = function(status) {
    const labels = {
      booking_received:   'Received',
      ad_under_review:    'Under Review',   // was missing — displayed as raw slug
      under_review:       'Under Review',
      ready_to_process:   'Ready to Process',
      documents_received: 'Docs Received',
      payment_received:   'Payment Received',
      payment_done:       'Payment Done',
      ad_processing:      'Processing',
      submitted_to_pub:   'Submitted to Pub',
      proof_ready:        'Proof Ready',
      proof_approved:     'Proof Approved',
      sent_to_vendor:     'Sent to Vendor',
      published:          'Published',
      completed:          'Completed',
      not_able_to_process:'Not Processable',
      not_eligible:       'Not Eligible',
      no_service:         'No Service',
      rejected:           'Rejected',
      cancelled:          'Cancelled',
      refunded:           'Refunded',
    };
    const label = labels[status] || status.replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase());
    return `<span class="nas-status-badge" data-status="${status}">${label}</span>`;
  };

  /* =========================================================
     Pagination helper
  ========================================================= */
  window.nasPagination = function(container, total, current, perPage, onPageChange) {
    const pages = Math.ceil(total / perPage);
    if (pages <= 1) { container.innerHTML = ''; return; }
    let html = '';
    html += `<button class="nas-page-btn" ${current===1?'disabled':''} data-page="${current-1}">‹ Prev</button>`;
    let lastPrinted = 0;
    for (let i = 1; i <= pages; i++) {
      const show = i === 1 || i === pages || (i >= current - 2 && i <= current + 2);
      if (show) {
        // Print ellipsis only when there's a gap
        if (lastPrinted && i - lastPrinted > 1) {
          html += `<span style="padding:0 6px;color:#94a3b8">…</span>`;
        }
        html += `<button class="nas-page-btn ${i===current?'active':''}" data-page="${i}">${i}</button>`;
        lastPrinted = i;
      }
    }
    html += `<button class="nas-page-btn" ${current===pages?'disabled':''} data-page="${current+1}">Next ›</button>`;
    container.innerHTML = html;
    container.querySelectorAll('.nas-page-btn:not([disabled])').forEach(btn => {
      btn.addEventListener('click', () => onPageChange(+btn.dataset.page));
    });
  };

  /* =========================================================
     Init on DOM ready
  ========================================================= */
  document.addEventListener('DOMContentLoaded', function() {
    initTabs();
    initTopNav();
    initFaq();
  });


  // 16-E-6: Global error/rejection handlers — catch unhandled JS errors without crash
  window.addEventListener('unhandledrejection', function(e) {
    var msg = (e.reason && e.reason.message) ? e.reason.message : String(e.reason || '');
    if (msg.indexOf('AbortError') < 0 && msg.indexOf('timed out') < 0) {
      console.error('[NAS] Unhandled Promise rejection:', msg);
    }
  });
  window.addEventListener('error', function(e) {
    console.error('[NAS] Uncaught error:', e.message);
  });

})(jQuery);
