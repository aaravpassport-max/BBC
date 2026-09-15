/* ===================================================================
   RTOFLOW Booking Form — 2-Step JS Engine
   Handles: service search/select, city search/select,
            step navigation, form submission, auto-login redirect
   =================================================================== */

/* ── State store (keyed by form UID) ────────────────────────────── */
window._rtobf = window._rtobf || {};

function _rtobfState(uid) {
  if (!window._rtobf[uid]) {
    window._rtobf[uid] = { service_id: null, service_name: null, service_price: null, city_id: null, city_name: null };
  }
  return window._rtobf[uid];
}

/* ── Step 1: Service search ─────────────────────────────────────── */
function rtobfSearchService(uid, q) {
  var list  = document.getElementById(uid + '_svc_list');
  if (!list) return;
  q = q.trim().toLowerCase();
  var items = list.querySelectorAll('.rtobf__svc-item');
  var cats  = list.querySelectorAll('.rtobf__cat-label');

  items.forEach(function(el) {
    var match = !q || el.dataset.name.toLowerCase().includes(q);
    el.classList.toggle('rtobf__svc-item--hidden', !match);
  });

  // Hide category headers if all children hidden
  cats.forEach(function(cat) {
    var catName = cat.dataset.cat;
    var anyVisible = false;
    items.forEach(function(el) {
      if (el.dataset.cat === catName && !el.classList.contains('rtobf__svc-item--hidden')) anyVisible = true;
    });
    cat.style.display = anyVisible ? '' : 'none';
  });
}

function rtobfSelectService(uid, el) {
  var state = _rtobfState(uid);
  state.service_id    = el.dataset.id;
  state.service_name  = el.dataset.name;
  state.service_price = el.dataset.price;

  // Mark selected
  var list = document.getElementById(uid + '_svc_list');
  list.querySelectorAll('.rtobf__svc-item').forEach(function(i){ i.classList.remove('rtobf__svc-item--selected'); });
  el.classList.add('rtobf__svc-item--selected');

  // Show pill, hide search
  document.getElementById(uid + '_svc_label').textContent = el.dataset.name + ' — ₹' + Number(el.dataset.price).toLocaleString('en-IN');
  document.getElementById(uid + '_svc_selected').style.display = 'flex';
  document.querySelector('#' + uid + ' .rtobf__svc-list').style.display = 'none';
  var searchWrap = document.querySelector('#' + uid + '_s1 .rtobf__search-wrap');
  if (searchWrap) searchWrap.style.display = 'none';

  // Clear error
  _rtobfHideErr(uid + '_s1_err');
}

function rtobfClearService(uid) {
  var state = _rtobfState(uid);
  state.service_id = null; state.service_name = null; state.service_price = null;
  document.getElementById(uid + '_svc_selected').style.display = 'none';
  document.querySelector('#' + uid + ' .rtobf__svc-list').style.display = '';
  var searchWrap = document.querySelector('#' + uid + '_s1 .rtobf__search-wrap');
  if (searchWrap) searchWrap.style.display = '';
  document.getElementById(uid + '_svc_search').value = '';
  rtobfSearchService(uid, '');
  document.querySelector('#' + uid + ' .rtobf__svc-item--selected') && document.querySelector('#' + uid + ' .rtobf__svc-item--selected').classList.remove('rtobf__svc-item--selected');
}

/* ── Step 1: City search ────────────────────────────────────────── */
function rtobfSearchCity(uid, q) {
  var drop = document.getElementById(uid + '_city_drop');
  var safeKey = '_rtobf_cities_' + uid.replace(/\W/g, '_');
  var cities  = window[safeKey] || [];

  if (!q || q.trim().length < 2) {
    drop.style.display = 'none';
    drop.innerHTML = '';
    return;
  }
  q = q.trim().toLowerCase();
  var matches = cities.filter(function(c){ return c.name.toLowerCase().includes(q) || (c.state||'').toLowerCase().includes(q); }).slice(0, 12);

  if (!matches.length) {
    drop.innerHTML = '<div class="rtobf__city-empty">No cities found. Try another name.</div>';
    drop.style.display = 'block';
    return;
  }
  drop.innerHTML = matches.map(function(c){
    return '<div class="rtobf__city-item" data-id="' + c.id + '" data-name="' + _esc(c.name) + '" data-state="' + _esc(c.state||'') + '" onclick="rtobfSelectCity(\'' + uid + '\', this)">'
      + '<span>' + _esc(c.name) + '</span>'
      + (c.state ? '<span class="rtobf__city-state">' + _esc(c.state) + '</span>' : '')
      + '</div>';
  }).join('');
  drop.style.display = 'block';
}

function rtobfSelectCity(uid, el) {
  var state = _rtobfState(uid);
  state.city_id   = el.dataset.id;
  state.city_name = el.dataset.name;

  document.getElementById(uid + '_city_label').textContent = el.dataset.name + (el.dataset.state ? ', ' + el.dataset.state : '');
  document.getElementById(uid + '_city_selected').style.display = 'flex';
  document.getElementById(uid + '_city_drop').style.display = 'none';

  var citySearchWrap = document.querySelector('#' + uid + '_s1 .rtobf__field:nth-child(2) .rtobf__search-wrap');
  if (citySearchWrap) citySearchWrap.style.display = 'none';

  _rtobfHideErr(uid + '_s1_err');
}

function rtobfClearCity(uid) {
  var state = _rtobfState(uid);
  state.city_id = null; state.city_name = null;
  document.getElementById(uid + '_city_selected').style.display = 'none';
  document.getElementById(uid + '_city_drop').style.display = 'none';

  var citySearchWrap = document.querySelector('#' + uid + '_s1 .rtobf__field:nth-child(2) .rtobf__search-wrap');
  if (citySearchWrap) {
    citySearchWrap.style.display = '';
    citySearchWrap.querySelector('input').value = '';
  }
}

/* ── Navigation ─────────────────────────────────────────────────── */
function rtobfNext(uid) {
  var state = _rtobfState(uid);
  if (!state.service_id) { _rtobfShowErr(uid + '_s1_err', 'Please select a service to continue.'); return; }
  if (!state.city_id)    { _rtobfShowErr(uid + '_s1_err', 'Please select your city to continue.');  return; }
  _rtobfHideErr(uid + '_s1_err');

  // Build summary
  var price = state.service_price ? '₹' + Number(state.service_price).toLocaleString('en-IN') : '';
  document.getElementById(uid + '_summary').innerHTML =
    '<div class="rtobf__summary-item">🔧 <strong>' + _esc(state.service_name) + '</strong></div>' +
    '<div class="rtobf__summary-item">📍 <strong>' + _esc(state.city_name) + '</strong></div>' +
    (price ? '<div class="rtobf__summary-item">💰 <strong>' + price + '</strong></div>' : '');

  // Slide transition
  var s1 = document.getElementById(uid + '_s1');
  var s2 = document.getElementById(uid + '_s2');
  s1.style.animation = 'rtobf-out .2s forwards';
  setTimeout(function(){
    s1.style.display = 'none';
    s2.style.display = '';
    s2.style.animation = 'rtobf-in .25s cubic-bezier(.34,1.56,.64,1) both';
    // Focus first input
    var nameInput = document.getElementById(uid + '_name');
    if (nameInput) setTimeout(function(){ nameInput.focus(); }, 100);
  }, 180);
}

function rtobfBack(uid) {
  var s1 = document.getElementById(uid + '_s1');
  var s2 = document.getElementById(uid + '_s2');
  s2.style.display = 'none';
  s1.style.display = '';
  s1.style.animation = 'rtobf-in .25s cubic-bezier(.34,1.56,.64,1) both';
}

/* ── Submit ─────────────────────────────────────────────────────── */
function rtobfSubmit(uid, nonce) {
  var state  = _rtobfState(uid);
  var name   = (document.getElementById(uid + '_name').value   || '').trim();
  var mobile = (document.getElementById(uid + '_mobile').value || '').replace(/\D/g,'');
  var email  = (document.getElementById(uid + '_email').value  || '').trim();
  var vehicle= (document.getElementById(uid + '_vehicle').value|| '').trim();

  // Validate
  if (!name)                         { _rtobfShowErr(uid + '_s2_err', 'Please enter your full name.'); return; }
  if (!mobile || mobile.length !== 10){ _rtobfShowErr(uid + '_s2_err', 'Please enter a valid 10-digit mobile number.'); return; }
  if (!/^[6-9]/.test(mobile))        { _rtobfShowErr(uid + '_s2_err', 'Mobile must start with 6, 7, 8 or 9.'); return; }
  _rtobfHideErr(uid + '_s2_err');

  // Disable button
  var btn     = document.getElementById(uid + '_submitbtn');
  var btnText = document.getElementById(uid + '_btn_text');
  btn.disabled = true;
  btnText.innerHTML = '<span style="display:inline-flex;align-items:center;gap:8px"><span style="width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:rtobf-spin .7s linear infinite;display:inline-block"></span> Submitting…</span>';

  var fd = new URLSearchParams();
  fd.append('contact_name',   name);
  fd.append('contact_mobile', mobile);
  fd.append('contact_email',  email);
  fd.append('service_id',     state.service_id);
  fd.append('city_id',        state.city_id);
  fd.append('vehicle_number', vehicle);
  fd.append('source',         'booking_shortcode');
  fd.append('_wpnonce',       nonce);

  fetch(window._rtobf_submit_url || '/get-quote', {
    method:  'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body:    fd.toString()
  })
  .then(function(r){ return r.json(); })
  .then(function(d) {
    if (d.success) {
      // Show success panel
      document.getElementById(uid + '_s2').style.display = 'none';
      var successEl = document.getElementById(uid + '_success');
      successEl.style.display = 'block';
      document.getElementById(uid + '_ref').textContent = d.lead_number || '—';

      // Redirect to client dashboard after 2.5s
      var redirect = d.redirect || '/my-account/orders';
      setTimeout(function(){ window.location.href = redirect; }, 2500);
    } else {
      btn.disabled = false;
      btnText.textContent = 'Submit Request 🚀';
      _rtobfShowErr(uid + '_s2_err', d.message || 'Submission failed. Please try again.');
    }
  })
  .catch(function() {
    btn.disabled = false;
    btnText.textContent = 'Submit Request 🚀';
    _rtobfShowErr(uid + '_s2_err', 'Network error. Please check your connection and try again.');
  });
}

/* ── Helpers ────────────────────────────────────────────────────── */
function _rtobfShowErr(id, msg) {
  var el = document.getElementById(id);
  if (!el) return;
  el.textContent = '⚠ ' + msg;
  el.style.display = 'block';
}
function _rtobfHideErr(id) {
  var el = document.getElementById(id);
  if (el) el.style.display = 'none';
}
function _esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Slide-out keyframe (injected once) ─────────────────────────── */
(function(){
  if (document.getElementById('rtobf-keyframes')) return;
  var style = document.createElement('style');
  style.id = 'rtobf-keyframes';
  style.textContent = '@keyframes rtobf-out { to { opacity:0; transform:translateY(-8px) scale(.98); } } @keyframes rtobf-in { from { opacity:0; transform:translateY(8px) scale(.98); } to { opacity:1; transform:translateY(0) scale(1); } } @keyframes rtobf-spin { to { transform:rotate(360deg); } }';
  document.head.appendChild(style);
})();
