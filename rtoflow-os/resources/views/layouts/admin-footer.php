    </div><!-- /.rto-content -->
  </main><!-- /.rto-main -->
</div><!-- /.rto-wrap -->

<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/admin.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/admin.js')) ?>"></script>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/mobile-nav.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/mobile-nav.js')) ?>"></script>
<!-- Part 4.16: window.rtoflowAdmin now lives in admin-header.php's <head>,
     defined before any page-specific script can run — see the comment
     there. Redefining it again here (after every page's own inline scripts
     have already tried and failed to read it) is exactly the bug that was
     fixed, so it is deliberately not duplicated in this file any more. -->
<?php // ENTERPRISE GAP FIX (Phase 6, item — "no global cross-module search") ?>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function() {
  var input = document.getElementById('rtoGlobalSearch');
  var results = document.getElementById('rtoGlobalSearchResults');
  if (!input || !results) return;
  var debounceTimer = null;

  function render(groups) {
    if (!groups || !groups.length) {
      results.innerHTML = '<div style="padding:12px;color:#94a3b8;font-size:13px">No matches.</div>';
      results.style.display = 'block';
      return;
    }
    var html = '';
    groups.forEach(function(g) {
      if (!g.items.length) return;
      html += '<div style="padding:6px 12px;font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;background:#f8fafc">' + g.label + '</div>';
      g.items.forEach(function(it) {
        html += '<a href="' + it.url + '" style="display:block;padding:8px 12px;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit">' +
          '<div style="font-size:13px;font-weight:600">' + it.title + '</div>' +
          (it.subtitle ? '<div style="font-size:12px;color:#94a3b8">' + it.subtitle + '</div>' : '') +
          '</a>';
      });
    });
    results.innerHTML = html || '<div style="padding:12px;color:#94a3b8;font-size:13px">No matches.</div>';
    results.style.display = 'block';
  }

  input.addEventListener('input', function() {
    var q = input.value.trim();
    clearTimeout(debounceTimer);
    if (q.length < 2) { results.style.display = 'none'; return; }
    debounceTimer = setTimeout(function() {
      var fd = new FormData();
      fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin');
      fd.append('rto_action', 'global_search'); fd.append('q', q);
      fd.append('rto_nonce', (window.rtoflowAdmin || {}).nonce || '');
      fetch((window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(r) { render(r.success && r.data ? r.data.groups : []); })
        .catch(function() { results.style.display = 'none'; });
    }, 300);
  });
  document.addEventListener('click', function(e) {
    if (!results.contains(e.target) && e.target !== input) results.style.display = 'none';
  });
})();
</script>
</body>
</html>
