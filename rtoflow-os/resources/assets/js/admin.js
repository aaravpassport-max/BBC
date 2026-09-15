/* RTOFLOW OS — Admin JS */
(function() {
  'use strict';

  // ── Sidebar toggle (P8-A11Y-003 FIX: manage aria-expanded) ─────────────
  var sidebarToggle = document.getElementById('sidebarToggle');
  var sidebar       = document.querySelector('.rto-sidebar');
  if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', function() {
      var isOpen = sidebar.classList.toggle('open');
      sidebarToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      // P8-A11Y-007 FIX: remove hidden sidebar from tab order on mobile
      if (window.innerWidth <= 768) {
        sidebar.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
        if ('inert' in HTMLElement.prototype) sidebar.inert = !isOpen;
      }
    });
    // Initialise closed on mobile
    if (window.innerWidth <= 768) {
      sidebar.setAttribute('aria-hidden', 'true');
      if ('inert' in HTMLElement.prototype) sidebar.inert = true;
    }
  }

  // ── Auto-close alerts ───────────────────────────────────
  document.querySelectorAll('.rto-msg').forEach(function(msg) {
    if (msg.classList.contains('rto-msg-success')) {
      setTimeout(function() { msg.style.opacity = '0'; setTimeout(function(){ msg.style.display='none'; }, 400); }, 4000);
    }
  });

  // ── Confirm before dangerous actions ───────────────────
  document.querySelectorAll('[data-confirm]').forEach(function(el) {
    el.addEventListener('click', function(e) {
      if (!confirm(el.dataset.confirm)) e.preventDefault();
    });
  });

  // ── Toast notification helper (P3-JS-006 FIX: replace alert()) ─────────
  function rtoToast(msg, type) {
    var t = document.createElement('div');
    t.className = 'rto-msg rto-msg-' + (type || 'info');
    t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    t.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
    t.setAttribute('aria-atomic', 'true');
    t.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;max-width:360px;padding:12px 16px;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.15);';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function(){ if(t.parentNode) t.parentNode.removeChild(t); }, 4000);
  }
  window.rtoToast = rtoToast;

  // ── Document verify/reject buttons (P3-JS-001 + .catch() fix) ──────────
  document.querySelectorAll('[data-action="verify"],[data-action="reject"]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var docId  = btn.dataset.doc;
      var action = btn.dataset.action;
      var reason = '';
      if (action === 'reject') {
        reason = prompt('Enter rejection reason (required):');
        if (!reason || !reason.trim()) return;
      }
      btn.disabled = true;
      var fd = new FormData();
      fd.append('action', 'rto_admin');
      fd.append('rto_area', 'admin');
      fd.append('rto_action', action + '_document');
      fd.append('doc_id', docId);
      fd.append('reason', reason);
      fd.append('rto_nonce', (window.rtoflowAdmin || {}).nonce || '');
      fetch((window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php', {
        method: 'POST', credentials: 'same-origin', body: fd
      })
        .then(function(r){ if(!r.ok) throw new Error('Server error '+r.status); return r.json(); })
        .then(function(r){
          if(r.success) { rtoToast(r.data ? r.data.message : 'Done.', 'success'); setTimeout(function(){ location.reload(); }, 1200); }
          else { rtoToast(r.data ? r.data.message : 'Action failed.', 'error'); btn.disabled = false; }
        })
        .catch(function(){ rtoToast('Request failed. Please check your connection.', 'error'); btn.disabled = false; });
    });
  });

  // ── Scroll message boxes to bottom ─────────────────────
  document.querySelectorAll('.rto-messages').forEach(function(box){
    box.scrollTop = box.scrollHeight;
  });

})();

