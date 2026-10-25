(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('pms-analytics');
    if (!el || !window.PORTMYSTUFF_ADMIN) return;
    fetch(PORTMYSTUFF_ADMIN.restUrl + 'admin/v1/analytics/revenue', {
      headers: { 'X-WP-Nonce': PORTMYSTUFF_ADMIN.nonce },
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        el.innerHTML =
          '<div class="pms-cards">' +
          '<div class="pms-card"><div class="pms-card-label">Trips</div><div class="pms-card-value">' + (data.total_trips || 0) + '</div></div>' +
          '<div class="pms-card"><div class="pms-card-label">Revenue</div><div class="pms-card-value">₹' + (data.gross_revenue || 0) + '</div></div>' +
          '</div>';
      });
  });
})();
