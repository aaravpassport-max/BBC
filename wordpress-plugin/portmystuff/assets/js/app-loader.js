(function () {
  'use strict';

  function api(path, opts) {
    var cfg = window.PORTMYSTUFF_CONFIG || {};
    var url = (cfg.apiBase || '').replace(/\/$/, '') + path;
    var headers = Object.assign({ 'Content-Type': 'application/json' }, (opts && opts.headers) || {});
    if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
    var token = localStorage.getItem('pms_access_token');
    if (token) headers['Authorization'] = 'Bearer ' + token;
    return fetch(url, Object.assign({ headers: headers }, opts || {})).then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok) throw data;
        return data;
      });
    });
  }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'className') node.className = attrs[k];
      else if (k === 'text') node.textContent = attrs[k];
      else if (k === 'onclick') node.onclick = attrs[k];
      else node.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) {
      if (typeof c === 'string') node.appendChild(document.createTextNode(c));
      else if (c) node.appendChild(c);
    });
    return node;
  }

  function tryLoadBundledApp(app, root) {
    var cfg = window.PORTMYSTUFF_CONFIG || {};
    var indexUrl = cfg.assetsUrl + 'index.html';
    return fetch(indexUrl, { method: 'HEAD' }).then(function (r) {
      if (!r.ok) return false;
      var iframe = el('iframe', {
        src: indexUrl,
        className: 'pms-iframe',
        title: app + ' app',
      });
      root.innerHTML = '';
      root.appendChild(iframe);
      return true;
    }).catch(function () { return false; });
  }

  function renderCustomerApp(root) {
    var state = { step: 'login', otpId: '', quotes: [], booking: null };

    function render() {
      root.innerHTML = '';
      var wrap = el('div', { className: 'pms-mini-app' });
      var title = el('h2', { text: 'PORTMYSTUFF' });
      wrap.appendChild(title);

      if (state.step === 'login') {
        var phone = el('input', { id: 'pms-phone', placeholder: 'Phone (10 digits)', maxlength: '10', value: '9000000001' });
        var btn = el('button', { className: 'pms-btn', text: 'Send OTP', onclick: function () {
          api('/auth/otp/request', { method: 'POST', body: JSON.stringify({ phone: phone.value, country_code: '+91', device_id: 'wp-web' }) })
            .then(function (r) { state.otpId = r.otp_id; state.step = 'verify'; render(); });
        }});
        wrap.appendChild(phone);
        wrap.appendChild(btn);
      }

      if (state.step === 'verify') {
        var otp = el('input', { id: 'pms-otp', placeholder: 'OTP (demo: 111111)', maxlength: '6' });
        var verify = el('button', { className: 'pms-btn', text: 'Verify', onclick: function () {
          api('/auth/otp/verify', { method: 'POST', body: JSON.stringify({ otp_id: state.otpId, code: otp.value, device_id: 'wp-web' }) })
            .then(function (res) {
              localStorage.setItem('pms_access_token', res.access_token);
              state.step = 'home';
              render();
            });
        }});
        wrap.appendChild(otp);
        wrap.appendChild(verify);
      }

      if (state.step === 'home') {
        var tabs = el('div', { className: 'pms-tabs' });
        ['ride', 'parcel'].forEach(function (t) {
          tabs.appendChild(el('button', { className: 'pms-tab', text: t.toUpperCase(), onclick: function () {
            state.bookingType = t;
            state.step = 'quote';
            render();
          }}));
        });
        var listBtn = el('button', { className: 'pms-btn pms-btn-secondary', text: 'My bookings', onclick: function () {
          api('/bookings').then(function (r) { state.bookings = r.items; state.step = 'bookings'; render(); });
        }});
        wrap.appendChild(tabs);
        wrap.appendChild(listBtn);
      }

      if (state.step === 'quote') {
        var type = state.bookingType || 'ride';
        wrap.appendChild(el('p', { text: 'Getting quotes for ' + type + '…' }));
        var quoteBtn = el('button', { className: 'pms-btn', text: 'Get quotes', onclick: function () {
          api('/pricing/quote', {
            method: 'POST',
            body: JSON.stringify({
              booking_type: type,
              pickup: { lat: 12.9716, lng: 77.5946 },
              drops: [{ lat: 12.9352, lng: 77.6245 }],
            }),
          }).then(function (data) {
            state.quotes = data.quotes || [];
            state.step = 'select';
            render();
          });
        }});
        wrap.appendChild(quoteBtn);
      }

      if (state.step === 'select') {
        wrap.appendChild(el('p', { text: 'Select a vehicle:' }));
        state.quotes.forEach(function (q) {
          var fare = q.fare_breakdown && q.fare_breakdown.final_fare;
          wrap.appendChild(el('button', {
            className: 'pms-btn pms-quote-card',
            text: q.vehicle_category + ' — ₹' + fare,
            onclick: function () {
              api('/bookings', {
                method: 'POST',
                headers: { 'Idempotency-Key': 'wp-' + Date.now() },
                body: JSON.stringify({ quote_id: q.quote_id, payment_method: 'upi', passenger_count: 1 }),
              }).then(function (b) {
                state.booking = b;
                state.step = 'track';
                render();
              });
            },
          }));
        });
      }

      if (state.step === 'track' && state.booking) {
        wrap.appendChild(el('p', { text: 'Booking ' + state.booking.id.slice(0, 8) + ' — ' + state.booking.status }));
        wrap.appendChild(el('button', { className: 'pms-btn', text: 'Refresh status', onclick: function () {
          api('/bookings/' + state.booking.id).then(function (b) { state.booking = b; render(); });
        }}));
        if (['searching', 'driver_assigned', 'driver_arriving', 'driver_arrived', 'in_progress'].indexOf(state.booking.status) >= 0) {
          wrap.appendChild(el('button', { className: 'pms-btn pms-btn-danger', text: 'SOS', onclick: function () {
            api('/bookings/' + state.booking.id + '/sos', { method: 'POST', body: JSON.stringify({ lat: 12.97, lng: 77.59 }) })
              .then(function () { alert('SOS triggered — ops team notified.'); });
          }}));
        }
        wrap.appendChild(el('button', { className: 'pms-btn pms-btn-secondary', text: 'Back home', onclick: function () { state.step = 'home'; render(); } }));
      }

      if (state.step === 'bookings' && state.bookings) {
        state.bookings.forEach(function (b) {
          wrap.appendChild(el('div', { className: 'pms-list-item', text: b.id.slice(0, 8) + ' — ' + b.booking_type + ' — ' + b.status }));
        });
        wrap.appendChild(el('button', { className: 'pms-btn', text: 'Back', onclick: function () { state.step = 'home'; render(); } }));
      }

      root.appendChild(wrap);
    }

    render();
  }

  function renderDriverApp(root) {
    var state = { step: 'login', offer: null, job: null };

    function render() {
      root.innerHTML = '';
      var wrap = el('div', { className: 'pms-mini-app' });
      wrap.appendChild(el('h2', { text: 'PORTMYSTUFF Partner' }));

      if (state.step === 'login') {
        wrap.appendChild(el('p', { text: 'Demo: 9000000002 / OTP 222222' }));
        wrap.appendChild(el('button', { className: 'pms-btn', text: 'Quick login', onclick: function () {
          api('/auth/otp/request', { method: 'POST', body: JSON.stringify({ phone: '9000000002', country_code: '+91', device_id: 'wp-web' }) })
            .then(function (r) {
              return api('/auth/otp/verify', { method: 'POST', body: JSON.stringify({ otp_id: r.otp_id, code: '222222', device_id: 'wp-web' }) });
            })
            .then(function (res) {
              localStorage.setItem('pms_access_token', res.access_token);
              state.step = 'dashboard';
              render();
            });
        }}));
      }

      if (state.step === 'dashboard') {
        api('/driver/dashboard').then(function (d) {
          wrap.appendChild(el('div', { className: 'pms-stat', text: 'Trips today: ' + (d.trips_today || 0) }));
          wrap.appendChild(el('div', { className: 'pms-stat', text: 'Earnings: ₹' + (d.gross_earnings_today || 0) }));
        });
        wrap.appendChild(el('button', { className: 'pms-btn', text: 'Go online', onclick: function () {
          api('/driver/status', { method: 'POST', body: JSON.stringify({ online: true }) })
            .then(function () {
              api('/driver/location', { method: 'POST', body: JSON.stringify({ lat: 12.9716, lng: 77.5946 }) });
              state.step = 'online';
              render();
            });
        }}));
      }

      if (state.step === 'online') {
        wrap.appendChild(el('p', { text: 'You are online. Waiting for offers…' }));
        var poll = function () {
          api('/driver/jobs/pending-offer').then(function (offer) {
            if (offer && offer.offer_id) { state.offer = offer; state.step = 'offer'; render(); }
          });
          api('/driver/jobs/active').then(function (job) {
            if (job && job.id) { state.job = job; state.step = 'trip'; render(); }
          });
        };
        poll();
        var interval = setInterval(poll, 5000);
        wrap.appendChild(el('button', { className: 'pms-btn pms-btn-secondary', text: 'Go offline', onclick: function () {
          clearInterval(interval);
          api('/driver/status', { method: 'POST', body: JSON.stringify({ online: false }) });
          state.step = 'dashboard';
          render();
        }}));
      }

      if (state.step === 'offer' && state.offer) {
        wrap.appendChild(el('p', { text: 'New ' + state.offer.booking_type + ' offer — ₹' + (state.offer.fare_breakdown && state.offer.fare_breakdown.final_fare) }));
        wrap.appendChild(el('button', { className: 'pms-btn', text: 'Accept', onclick: function () {
          api('/driver/jobs/offers/' + state.offer.offer_id + '/accept', { method: 'POST' })
            .then(function (job) { state.job = job; state.step = 'trip'; render(); });
        }}));
        wrap.appendChild(el('button', { className: 'pms-btn pms-btn-secondary', text: 'Reject', onclick: function () {
          api('/driver/jobs/offers/' + state.offer.offer_id + '/reject', { method: 'POST' })
            .then(function () { state.offer = null; state.step = 'online'; render(); });
        }}));
      }

      if (state.step === 'trip' && state.job) {
        var next = {
          driver_assigned: 'driver_arriving',
          driver_arriving: 'driver_arrived',
          driver_arrived: 'in_progress',
          in_progress: 'completed',
        };
        wrap.appendChild(el('p', { text: 'Trip ' + state.job.id.slice(0, 8) + ' — ' + state.job.status }));
        var n = next[state.job.status];
        if (n) {
          wrap.appendChild(el('button', { className: 'pms-btn', text: 'Mark ' + n.replace('_', ' '), onclick: function () {
            api('/driver/jobs/' + state.job.id + '/status', { method: 'POST', body: JSON.stringify({ status: n }) })
              .then(function (job) {
                state.job = job;
                if (job.status === 'completed') { state.step = 'dashboard'; state.job = null; }
                render();
              });
          }}));
        }
      }

      root.appendChild(wrap);
    }

    render();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.portmystuff-app-shell').forEach(function (shell) {
      var app = shell.getAttribute('data-app');
      var root = shell.querySelector('.portmystuff-app-root');
      if (!root) return;

      tryLoadBundledApp(app, root).then(function (loaded) {
        if (loaded) return;
        if (app === 'driver') renderDriverApp(root);
        else renderCustomerApp(root);
      });
    });
  });
})();
