/**
 * RTOFLOW Form Engine v3
 * Exact conditional rendering from your Fluent Forms JSON export.
 * - Shows ONLY master dropdown first
 * - Reveals service-specific fields based on selections
 * - Contact fields always shown at bottom
 * - File uploads with drag-drop
 * - City loads via AJAX fallback
 */
(function () {
'use strict';

if (typeof RTO_FORM_JSON === 'undefined') return;

const FORM    = RTO_FORM_JSON;
const FIELDS  = FORM.fields || [];
const NONCE   = window.RTO_NONCE || (window.RtoApp || {}).nonce || '';
const CITIES  = window.RTO_CITIES || [];
// Fields that are contact/personal - always in contact section
const CONTACT_NAMES = new Set(['names','phone','email','address_1','address_2','contact_name','contact_mobile','contact_email']);

let FORM_EL = null;

// ─── Init ────────────────────────────────────────────────────────────────────
function init() {
    const container = document.getElementById('rto-quote-form');
    if (!container) return;
    document.getElementById('rto-form-loading')?.remove();

    FORM_EL = document.createElement('form');
    FORM_EL.id = 'rto-ff-form';
    FORM_EL.noValidate = true;

    // ── STEP 1: Master Service Selector ───────────────────────────────────
    const masterField = FIELDS.find(f => f.n === 'dropdown');
    const step1 = mkSectionHeader('Step 1 — What service do you need?');
    FORM_EL.appendChild(step1);
    if (masterField) FORM_EL.appendChild(mkField(masterField));

    // ── STEP 2: Service Details (conditional fields) ───────────────────────
    const step2Header = mkSectionHeader('Step 2 — Service Details');
    step2Header.id = 'rto-step2-header';
    step2Header.style.display = 'none';
    FORM_EL.appendChild(step2Header);

    const detailDiv = document.createElement('div');
    detailDiv.id = 'rto-detail-fields';
    detailDiv.style.display = 'none';

    FIELDS.forEach(f => {
        // Skip: master, contact, and address fields (handled separately)
        if (f.n === 'dropdown') return;
        if (CONTACT_NAMES.has(f.n)) return;
        if (f.el === 'input_name' || f.el === 'phone' || f.el === 'input_email' || f.el === 'address') return;
        const wrap = mkField(f);
        if (wrap) detailDiv.appendChild(wrap);
    });
    FORM_EL.appendChild(detailDiv);

    // ── STEP 3: Your Contact Details (always shown) ────────────────────────
    FORM_EL.appendChild(mkSectionHeader('Step 3 — Your Contact Details'));
    FORM_EL.appendChild(mkContactSection());
    FORM_EL.appendChild(mkCitySection());

    // ── Submit ─────────────────────────────────────────────────────────────
    const submitDiv = document.createElement('div');
    submitDiv.style.cssText = 'margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0';
    submitDiv.innerHTML =
        '<button type="submit" id="rto-submit-btn" class="rto-btn rto-btn--primary rto-btn--lg" style="width:100%;padding:14px;font-size:15px;justify-content:center">Submit Request →</button>' +
        '<p style="text-align:center;font-size:12px;color:#94a3b8;margin-top:8px">Our expert will call you within 30 minutes with a free quote.</p>';
    FORM_EL.appendChild(submitDiv);

    container.appendChild(FORM_EL);

    // Initial state: hide step 2
    evalAll();

    FORM_EL.addEventListener('change', evalAll);
    FORM_EL.addEventListener('input', evalAll);
    FORM_EL.addEventListener('submit', handleSubmit);
}

// ─── Section Headers ─────────────────────────────────────────────────────────
function mkSectionHeader(title) {
    const h = document.createElement('div');
    h.style.cssText = 'font-size:12px;font-weight:800;color:#1B2A6B;background:#EFF6FF;border-left:4px solid #2563EB;padding:8px 12px;border-radius:0 6px 6px 0;margin:16px 0 10px;letter-spacing:.02em;text-transform:uppercase';
    h.textContent = title;
    return h;
}

// ─── Field Builder ───────────────────────────────────────────────────────────
function mkField(f) {
    const el = f.el;
    if (['raw_html','section_break','container','input_name','phone','input_email','address'].includes(el)) return null;

    const wrap = document.createElement('div');
    wrap.className = 'rto-field rto-ff-field';
    wrap.dataset.fname = f.n;
    wrap.style.marginBottom = '14px';

    // Conditional logic: hide if has conditions (shown by evalAll)
    if (f.c && f.c.length) {
        wrap.style.display = 'none';
        wrap.dataset.conditions = JSON.stringify(f.c);
        wrap.dataset.condType   = f.ct || 'any';
    }

    // Label
    if (f.l) {
        const lbl = document.createElement('label');
        lbl.className = 'rto-label';
        lbl.innerHTML = escHtml(f.l) + (f.r ? ' <span style="color:#dc2626">*</span>' : '');
        wrap.appendChild(lbl);
    }

    let input = null;

    if (el === 'select') {
        input = document.createElement('select');
        input.name = f.n; input.className = 'rto-input';
        if (f.r) input.required = true;
        const blank = document.createElement('option');
        blank.value = ''; blank.textContent = '— Select —';
        input.appendChild(blank);
        (f.o || []).forEach(opt => {
            const o = document.createElement('option');
            o.value = opt.v || ''; o.textContent = opt.l || opt.v || '';
            input.appendChild(o);
        });
    } else if (el === 'input_text') {
        input = document.createElement('input');
        input.type = 'text'; input.name = f.n; input.className = 'rto-input';
        input.placeholder = f.ph || '';
        if (f.r) input.required = true;
    } else if (el === 'input_date') {
        input = document.createElement('input');
        input.type = 'date'; input.name = f.n; input.className = 'rto-input';
        if (f.r) input.required = true;
    } else if (el === 'input_file') {
        const uid = 'fi_' + f.n;
        const fw = document.createElement('div');
        fw.style.cssText = 'border:2px dashed #CBD5E1;border-radius:8px;padding:16px;text-align:center;background:#F8FAFC;transition:border-color .2s';
        fw.innerHTML =
            '<div style="font-size:28px;margin-bottom:6px">📎</div>' +
            '<input type="file" name="' + esc(f.n) + '" id="' + uid + '" accept=".pdf,.jpg,.jpeg,.png"' + (f.r ? ' required' : '') + ' style="display:none">' +
            '<label for="' + uid + '" style="cursor:pointer;color:#2563EB;font-weight:600;font-size:13px">Click to upload or drag file here</label>' +
            '<div id="fn_' + esc(f.n) + '" style="font-size:11px;color:#94A3B8;margin-top:4px">PDF, JPG or PNG · Max 5MB</div>';
        fw.addEventListener('dragover', e => { e.preventDefault(); fw.style.borderColor='#2563EB'; });
        fw.addEventListener('dragleave', () => fw.style.borderColor='#CBD5E1');
        fw.addEventListener('drop', e => {
            e.preventDefault(); fw.style.borderColor='#CBD5E1';
            const fi = document.getElementById(uid);
            if (fi && e.dataTransfer.files[0]) {
                const dt = new DataTransfer();
                dt.items.add(e.dataTransfer.files[0]);
                fi.files = dt.files;
                document.getElementById('fn_' + f.n).textContent = '✅ ' + e.dataTransfer.files[0].name;
            }
        });
        fw.addEventListener('change', e => {
            if (e.target.files && e.target.files[0]) {
                document.getElementById('fn_' + f.n).textContent = '✅ ' + e.target.files[0].name;
            }
        });
        wrap.appendChild(fw);
        return wrap;
    } else if (el === 'input_checkbox') {
        const cbWrap = document.createElement('div');
        cbWrap.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin-top:4px';
        (f.o || []).forEach(opt => {
            const lbl = document.createElement('label');
            lbl.style.cssText = 'display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:6px 12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;transition:all .15s';
            const cb = document.createElement('input');
            cb.type = 'checkbox'; cb.name = f.n + '[]'; cb.value = opt.v || opt.l || '';
            lbl.appendChild(cb);
            lbl.appendChild(document.createTextNode(opt.l || opt.v || ''));
            cbWrap.appendChild(lbl);
        });
        wrap.appendChild(cbWrap);
        return wrap;
    } else if (el === 'input_radio') {
        const rdWrap = document.createElement('div');
        rdWrap.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin-top:4px';
        (f.o || []).forEach(opt => {
            const lbl = document.createElement('label');
            lbl.style.cssText = 'display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:6px 12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;transition:all .15s';
            const rd = document.createElement('input');
            rd.type = 'radio'; rd.name = f.n; rd.value = opt.v || opt.l || '';
            lbl.appendChild(rd);
            lbl.appendChild(document.createTextNode(opt.l || opt.v || ''));
            rdWrap.appendChild(lbl);
        });
        wrap.appendChild(rdWrap);
        return wrap;
    } else {
        return null;
    }

    if (input) wrap.appendChild(input);
    return wrap;
}

// ─── Contact Section ─────────────────────────────────────────────────────────
function mkContactSection() {
    const grid = document.createElement('div');
    grid.className = 'rto-form-grid';
    grid.style.marginBottom = '12px';

    [
        ['text',  'contact_name',   'Full Name',     true],
        ['tel',   'contact_mobile', 'Mobile Number', true],
        ['email', 'contact_email',  'Email Address', false],
    ].forEach(([type, name, label, req]) => {
        const f = document.createElement('div');
        f.className = 'rto-field';
        const lbl = document.createElement('label');
        lbl.className = 'rto-label';
        lbl.innerHTML = label + (req ? ' <span style="color:#dc2626">*</span>' : '');
        const inp = document.createElement('input');
        inp.type = type; inp.name = name; inp.className = 'rto-input';
        inp.placeholder = label;
        if (req) inp.required = true;
        if (type === 'tel') { inp.maxLength = 10; inp.pattern = '[0-9]{10}'; }
        f.appendChild(lbl); f.appendChild(inp);
        grid.appendChild(f);
    });
    return grid;
}

// ─── City Section ─────────────────────────────────────────────────────────────
function mkCitySection() {
    const wrap = document.createElement('div');
    wrap.className = 'rto-field';
    wrap.style.marginBottom = '14px';

    const lbl = document.createElement('label');
    lbl.className = 'rto-label';
    lbl.innerHTML = 'Your City (Service Location) <span style="color:#dc2626">*</span>';
    wrap.appendChild(lbl);

    const sel = document.createElement('select');
    sel.name = 'city_id'; sel.className = 'rto-input'; sel.id = 'rto-city-select'; sel.required = true;

    const loading = document.createElement('option');
    loading.value = ''; loading.textContent = '⏳ Loading cities...';
    sel.appendChild(loading);
    wrap.appendChild(sel);

    // Populate cities
    function populate(cities) {
        sel.innerHTML = '';
        if (!cities || !cities.length) {
            // Fall back to text input
            const inp = document.createElement('input');
            inp.type = 'text'; inp.name = 'city_name'; inp.className = 'rto-input';
            inp.placeholder = 'Enter your city name'; inp.required = true;
            wrap.replaceChild(inp, sel);
            return;
        }
        const blank = document.createElement('option');
        blank.value = ''; blank.textContent = '— Select your city —';
        sel.appendChild(blank);
        cities.forEach(c => {
            const o = document.createElement('option');
            o.value = c.id; o.textContent = c.name;
            sel.appendChild(o);
        });
        // Apply prefill
        if (window.RTO_PREFILL && window.RTO_PREFILL.city) {
            sel.value = window.RTO_PREFILL.city;
        }
    }

    if (CITIES && CITIES.length > 0) {
        populate(CITIES);
    } else {
        // AJAX load
        const ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';
        fetch(ajaxUrl + '?action=rtoflow_get_cities')
            .then(r => r.json())
            .then(d => populate(d.success && d.cities ? d.cities : []))
            .catch(() => populate([]));
    }
    return wrap;
}

// ─── Conditional Logic Engine ────────────────────────────────────────────────
function getVal(name) {
    if (!FORM_EL) return '';
    const cbs = FORM_EL.querySelectorAll('[name="' + name + '[]"]:checked');
    if (cbs.length) return Array.from(cbs).map(c => c.value);
    const rd = FORM_EL.querySelector('[name="' + name + '"]:checked');
    if (rd) return rd.value;
    const el = FORM_EL.querySelector('[name="' + name + '"]');
    return el ? (el.value || '') : '';
}

function evalCond([field, op, val]) {
    const cur = getVal(field);
    const curArr = Array.isArray(cur) ? cur : [String(cur)];
    switch (op) {
        case '=':        return curArr.some(v => v === String(val));
        case '!=':       return curArr.every(v => v !== String(val));
        case '>':        return parseFloat(cur) > parseFloat(val);
        case '<':        return parseFloat(cur) < parseFloat(val);
        case 'contains': return curArr.some(v => v.toLowerCase().includes(String(val).toLowerCase()));
        default:         return curArr.some(v => v === String(val));
    }
}

function evalAll() {
    // Show/hide step 2 based on master selection
    const masterVal = getVal('dropdown');
    const detailDiv   = document.getElementById('rto-detail-fields');
    const step2Header = document.getElementById('rto-step2-header');
    if (detailDiv && step2Header) {
        const show = !!masterVal;
        detailDiv.style.display   = show ? '' : 'none';
        step2Header.style.display = show ? '' : 'none';
    }

    // Evaluate individual field conditions
    FORM_EL.querySelectorAll('.rto-ff-field[data-conditions]').forEach(wrap => {
        const conds = JSON.parse(wrap.dataset.conditions || '[]');
        const type  = wrap.dataset.condType || 'any';
        if (!conds.length) return;

        const results = conds.map(evalCond);
        const show    = type === 'all' ? results.every(Boolean) : results.some(Boolean);

        wrap.style.display = show ? '' : 'none';

        // Manage required state
        wrap.querySelectorAll('input,select,textarea').forEach(inp => {
            if (show) {
                if (inp.dataset.wasRequired) { inp.required = true; delete inp.dataset.wasRequired; }
            } else {
                if (inp.required) { inp.dataset.wasRequired = '1'; inp.required = false; inp.value = ''; }
                if (inp.type === 'checkbox' || inp.type === 'radio') inp.checked = false;
            }
        });
    });
}

// ─── Form Submission ──────────────────────────────────────────────────────────
function handleSubmit(e) {
    e.preventDefault();

    const name   = FORM_EL.querySelector('[name="contact_name"]')?.value?.trim();
    const mobile = FORM_EL.querySelector('[name="contact_mobile"]')?.value?.trim();
    const svc    = getVal('dropdown');

    if (!svc)    { return showErr('Please select a service'); }
    if (!name)   { return showErr('Please enter your full name'); }
    if (!mobile || mobile.replace(/\D/g,'').length < 10) { return showErr('Please enter a valid 10-digit mobile number'); }

    const btn = document.getElementById('rto-submit-btn');
    btn.disabled = true; btn.textContent = '⏳ Submitting...';
    hideErr();

    const fd = new FormData(FORM_EL);
    fd.append('_wpnonce', NONCE);
    fd.append('source', 'quote_form');
    fd.append('service_name_detected', svc);

    const url = window.RTO_SUBMIT_URL || window.location.href;

    fetch(url, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false; btn.textContent = 'Submit Request →';
            if (d.success) {
                FORM_EL.style.display = 'none';
                const ok = document.getElementById('rto-form-success');
                if (ok) {
                    ok.classList.remove('rto--hidden');
                    const ref = document.getElementById('rto-ref-number');
                    if (ref) ref.textContent = d.lead_number || '';
                    const msg = document.getElementById('rto-redirect-msg');
                    if (d.redirect) {
                        if (msg) msg.textContent = 'Redirecting to your dashboard in 3 seconds...';
                        setTimeout(() => window.location.href = d.redirect, 3000);
                    }
                } else if (d.redirect) {
                    window.location.href = d.redirect;
                }
            } else {
                showErr(d.message || 'Submission failed. Please try again.');
            }
        })
        .catch(() => {
            btn.disabled = false; btn.textContent = 'Submit Request →';
            showErr('Connection error. Please try again.');
        });
}

function showErr(msg) {
    let e = document.getElementById('rto-form-error');
    if (!e) {
        e = document.createElement('div');
        e.id = 'rto-form-error';
        e.className = 'rto-alert rto-alert--error';
        e.style.marginBottom = '12px';
        FORM_EL.prepend(e);
    }
    e.textContent = msg;
    e.style.display = 'block';
    e.scrollIntoView({behavior:'smooth', block:'nearest'});
}
function hideErr() {
    const e = document.getElementById('rto-form-error');
    if (e) e.style.display = 'none';
}
function esc(s) { return String(s).replace(/["'<>&]/g, ''); }
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ─── Boot ─────────────────────────────────────────────────────────────────────
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

})();
