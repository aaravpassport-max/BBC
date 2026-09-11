/**
 * NAS Super Combo — Booking Wizard JS v3
 * 11-Step: Category→Samples→City→Newspaper→Edition→AdType→BuildAd→Pricing→Date→Details→Review
 */
(function () {
  'use strict';

  /* ── CONFIG ──────────────────────────────────────────────────────────── */
  const CFG = (function () {
    try {
      return JSON.parse(document.getElementById('nas-wizard-config').textContent);
    } catch (e) {
      return { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: '', gstRate: 18, minAdvanceDays: 2, currency: '₹' };
    }
  })();

  const $ = (sel, ctx) => (ctx || document).querySelector(sel);
  const $$ = (sel, ctx) => [...(ctx || document).querySelectorAll(sel)];
  const fmt = (n) => CFG.currency + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const ajax = (action, data) => {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', CFG.nonce);
    Object.entries(data || {}).forEach(([k, v]) => {
      if (v !== null && v !== undefined) fd.append(k, v);
    });
    const ctrl = new AbortController();
    const tid = setTimeout(() => ctrl.abort(), 30000);
    return fetch(CFG.ajaxUrl, { method: 'POST', body: fd, signal: ctrl.signal })
      .then(r => { clearTimeout(tid); return r.text(); })
      .then(text => {
        const t = text.trim();
        // WordPress returns "0" when action is not registered, "-1" on failed nonce
        if (t === '0') throw new Error('Action not registered. Please deactivate and reactivate the plugin.');
        if (t === '-1') throw new Error('Security check failed. Please refresh the page and try again.');
        try {
          return JSON.parse(t);
        } catch (_) {
          const snippet = t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
          throw new Error(snippet || 'Unexpected server response. Please try again.');
        }
      })
      .catch(e => { clearTimeout(tid); if (e.name === 'AbortError') throw new Error((NAS.i18n && NAS.i18n.request_timeout) || 'Request timed out. Please try again.'); throw e; });
  };

  /* ── Branded load-error state (network/API failure inside a grid) ────── */
  function renderLoadError(containerEl, message, retryFn) {
    if (!containerEl) return;
    containerEl.innerHTML =
      '<div class="nas-load-error">' +
        '<i class="fa-solid fa-plug-circle-exclamation"></i>' +
        '<p>' + escH(message || 'Something went wrong while loading. Please check your connection.') + '</p>' +
        '<button type="button" class="nas-btn nas-btn-outline nas-btn-sm nas-load-error-retry">' +
          '<i class="fa-solid fa-rotate-right"></i> Retry' +
        '</button>' +
      '</div>';
    const btn = containerEl.querySelector('.nas-load-error-retry');
    if (btn && typeof retryFn === 'function') btn.addEventListener('click', retryFn, { once: true });
  }

  /* ══════════════════════════════════════════════════════════════════════
     STATE
  ══════════════════════════════════════════════════════════════════════ */
  const S = {
    step: 1,
    totalSteps: 11,
    // Selections
    category_id: null, category_name: '', category_icon: '',
    city_id: null, city_name: '', city_state: '',
    newspaper_id: null, newspaper_name: '', newspaper_lang: '', newspaper_circ: 0,
    newspaper_rate_cl: 0, newspaper_rate_di: 0, newspaper_rate_dc: 0, newspaper_min: 0,
    _selectedNewspapers: [],   // array of {id,name,lang,circ,rate_cl,rate_di,rate_dc,min}
    _editionsByPaper: {},      // paperId -> selected edition string
    edition: '',
    ad_type: '',
    ad_title: '', ad_content: '',
    word_count: 0, width_cm: 7.5, height_cm: 5,
    publish_date: '', publish_date_label: '',
    client_name: '', client_phone: '', client_email: '',
    client_city: '', client_company: '', client_gst: '', client_notes: '',
    whatsapp_optin: true,
    // Pricing
    base_amount: 0, discount_amount: 0, gst_amount: 0, total_amount: 0,
    combo_id: null, combo_discount_pct: 0, coupon_code: '', coupon_discount: 0,
    // Cache
    _categories: [], _newspapers: [], _templates: [], _combos: [],
    _sampleAdSelected: false,
  };

  /* ══════════════════════════════════════════════════════════════════════
     INIT
  ══════════════════════════════════════════════════════════════════════ */
  function init() {
    if (!document.getElementById('nas-booking-wizard')) return;
    prefillUser();
    loadCategories();
    loadCities();
    bindAll();
    initCalendar();
    updateStepTrack();
    setMockDate();
    updateSidebar(1);
  }

  function prefillUser() {
    if (CFG.userName) { const el = $('#nas-client-name'); if (el) el.value = CFG.userName; S.client_name = CFG.userName; }
    if (CFG.userEmail) { const el = $('#nas-client-email'); if (el) el.value = CFG.userEmail; S.client_email = CFG.userEmail; }
  }

  /* ══════════════════════════════════════════════════════════════════════
     NAVIGATION
  ══════════════════════════════════════════════════════════════════════ */
  function goTo(n) {
    if (n < 1 || n > S.totalSteps) return;
    $$('.nas-wizard-step').forEach((el, i) => el.classList.toggle('active', i + 1 === n));
    updateStepTrack(n);
    S.step = n;
    // On-enter hooks
    const hooks = {
      2: loadSampleAds,
      4: loadNewspapers,
      5: renderEditionGrid,
      6: renderAdTypes,
      7: onEnterBuildAd,
      8: onEnterPricing,
      9: null,
      11: renderReview,
    };
    if (hooks[n]) hooks[n]();
    updateSidebar(n);
    scrollToWizard();
  }

  /* ── Sidebar summary (live, non-blocking — safe no-op if markup absent) ── */
  function updateSidebar(n) {
    const stepNum = document.getElementById('nas-progress-current-step');
    const stripFill = document.getElementById('nas-progress-strip-fill');
    if (stepNum) stepNum.textContent = n;
    if (stripFill) stripFill.style.width = Math.round((n / S.totalSteps) * 100) + '%';

    const fill = document.getElementById('nas-side-progress-fill');
    const label = document.getElementById('nas-side-progress-label');
    if (!fill || !label) return; // sidebar not present on this page render
    const pct = Math.round((n / S.totalSteps) * 100);
    fill.style.width = pct + '%';
    const stepEl = document.querySelector('.nas-step-item[data-step="' + n + '"] .nas-step-label');
    label.textContent = 'Step ' + n + ' of ' + S.totalSteps + (stepEl ? ' · ' + stepEl.textContent : '');

    const setField = (id, value) => {
      const el = document.getElementById(id);
      if (!el) return;
      if (value) { el.textContent = value; el.classList.add('nas-side-filled'); }
      else { el.textContent = '—'; el.classList.remove('nas-side-filled'); }
    };
    setField('side-category', S.category_name);
    setField('side-city', S.city_name);
    const paperNames = (S._selectedNewspapers && S._selectedNewspapers.length)
      ? S._selectedNewspapers.map(p => p.name).join(', ')
      : S.newspaper_name;
    setField('side-newspaper', paperNames);
    setField('side-adtype', S.ad_type ? (S.ad_type.charAt(0).toUpperCase() + S.ad_type.slice(1)) : '');
    setField('side-date', S.publish_date_label);

    const totalWrap = document.getElementById('nas-side-total-wrap');
    const totalEl = document.getElementById('side-total');
    if (totalWrap && totalEl) {
      if (S.total_amount > 0) { totalWrap.style.display = 'flex'; totalEl.textContent = fmt(S.total_amount); }
      else { totalWrap.style.display = 'none'; }
    }
  }

  function updateStepTrack(n) {
    n = n || S.step;
    $$('.nas-step-item').forEach((el, i) => {
      const sn = i + 1;
      el.classList.remove('active', 'done');
      if (sn < n) el.classList.add('done');
      if (sn === n) el.classList.add('active');
    });
    // On mobile: scroll step track to active
    const active = $('.nas-step-item.active', document.getElementById('nas-step-track'));
    if (active) active.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
  }

  function scrollToWizard() {
    const wiz = document.getElementById('nas-booking-wizard');
    if (wiz) window.scrollTo({ top: wiz.offsetTop - 80, behavior: 'smooth' });
  }

  function bindAll() {
    // Nav buttons
    document.addEventListener('click', (e) => {
      const t = e.target.closest('.nas-wizard-prev');
      if (t) { const s = parseInt(t.dataset.step); if (s > 1) goTo(s - 1); }
    });
    document.addEventListener('click', (e) => {
      const t = e.target.closest('.nas-wizard-next');
      if (t) {
        const s = parseInt(t.dataset.step);
        if (validateStep(s)) goTo(s + 1);
      }
    });
    // Review edit buttons
    document.addEventListener('click', (e) => {
      const t = e.target.closest('.nas-review-edit-btn');
      if (t) goTo(parseInt(t.dataset.goto));
    });
    // Submit
    const submitBtn = $('#nas-submit-btn');
    if (submitBtn) submitBtn.addEventListener('click', submitBooking);
    // Terms checkbox
    const terms = $('#nas-terms-check');
    if (terms) terms.addEventListener('change', () => { if (submitBtn) submitBtn.disabled = !terms.checked; });
    // City search
    bindCitySearch();
    // Combo banner
    const comboApply = $('#nas-combo-apply-btn');
    if (comboApply) comboApply.addEventListener('click', applyComboFromBanner);
    // Width/height inputs
    const w = $('#nas-width-cm'), h = $('#nas-height-cm');
    if (w) w.addEventListener('input', calcDisplayPrice);
    if (h) h.addEventListener('input', calcDisplayPrice);
    // Coupon
    const couponBtn = $('#nas-coupon-apply-btn');
    if (couponBtn) couponBtn.addEventListener('click', applyCoupon);
    // AI buttons
    const aiImprove = $('#nas-ai-improve-btn');
    const aiShorten = $('#nas-ai-shorten-btn');
    const aiFormal  = $('#nas-ai-formal-btn');
    if (aiImprove) aiImprove.addEventListener('click', () => aiTransform('improve'));
    if (aiShorten) aiShorten.addEventListener('click', () => aiTransform('shorten'));
    if (aiFormal)  aiFormal.addEventListener('click',  () => aiTransform('formalise'));
    // Date clear button (Step 9) — MUST be in bindAll, not at module scope
    const dateClearBtn = $('#nas-date-clear-btn');
    if (dateClearBtn) dateClearBtn.addEventListener('click', () => {
      S.publish_date = ''; S.publish_date_label = '';
      const card = $('#nas-date-selected-card'); if (card) card.style.display = 'none';
      const inp  = $('#nas-publish-date-input'); if (inp) inp.value = '';
      const cal  = $('#nas-publish-date-calendar'); if (cal) renderCalendar(cal, new Date());
      const btn  = $('#nas-btn-next-9'); if (btn) btn.disabled = true;
    });
  }


  /* ══════════════════════════════════════════════════════════════════════
     STEP VALIDATION
  ══════════════════════════════════════════════════════════════════════ */
  function validateStep(s) {
    const errMap = {
      1: () => S.category_id ? '' : 'Please select an ad category',
      3: () => S.city_id ? '' : 'Please select a city',
      4: () => S.newspaper_id ? '' : 'Please select a newspaper',
      5: () => S.edition ? '' : 'Please select an edition',
      6: () => S.ad_type ? '' : 'Please select an ad format',
      7: () => S.ad_content.trim().length > 10 ? '' : 'Please enter your ad content (minimum 10 characters)',
      9: () => S.publish_date ? '' : 'Please select a publication date',
      10: () => {
        if (!S.client_name.trim()) return 'Please enter your full name';
        if (!/^[6-9][0-9]{9}$/.test(S.client_phone.replace(/\s/g, ''))) return 'Please enter a valid 10-digit Indian mobile number';
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(S.client_email)) return 'Please enter a valid email address';
        return '';
      },
      2: () => '', 8: () => '', 11: () => '',
    };
    const fn = errMap[s];
    const err = fn ? fn() : '';
    if (err) { showStepError(s, err); return false; }
    clearStepError(s);
    return true;
  }

  function showStepError(s, msg) {
    const toast = document.createElement('div');
    toast.className = 'nas-toast nas-toast-error';
    toast.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> ${msg}`;
    document.body.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3500);
  }

  function clearStepError(s) { }

  /* ══════════════════════════════════════════════════════════════════════
     STEP 1: CATEGORIES
  ══════════════════════════════════════════════════════════════════════ */
  function loadCategories() {
    const loadLbl = document.getElementById('nas-category-loading-label');
    if (loadLbl) loadLbl.style.display = '';
    ajax('nas_get_categories').then(res => {
      if (!res.success) return;
      S._categories = res.data || [];
      renderCategoryGrid(S._categories);
    }).catch(() => {
      if (loadLbl) loadLbl.style.display = 'none';
      renderLoadError($('#nas-category-grid'), "Couldn't load ad categories.", loadCategories);
    });
  }

  function renderCategoryGrid(cats) {
    const grid = $('#nas-category-grid');
    if (!grid) return;
    const loadLbl = document.getElementById('nas-category-loading-label');
    if (loadLbl) loadLbl.style.display = 'none';
    grid.innerHTML = cats.map(cat => `
      <div class="nas-category-card" data-id="${cat.id}" data-name="${escH(cat.name)}" data-icon="${escH(cat.icon || '')}"
           tabindex="0" role="button" aria-pressed="false">
        <div class="nas-category-card-icon">${cat.icon || '📰'}</div>
        <div class="nas-category-card-name">${escH(cat.name)}</div>
        <div class="nas-category-card-arrow"><i class="fa-solid fa-chevron-right"></i></div>
      </div>
    `).join('');
    grid.addEventListener('click', e => {
      const card = e.target.closest('.nas-category-card');
      if (!card) return;
      $$('.nas-category-card', grid).forEach(c => { c.classList.remove('selected'); c.setAttribute('aria-pressed', 'false'); });
      card.classList.add('selected');
      card.setAttribute('aria-pressed', 'true');
      S.category_id = parseInt(card.dataset.id);
      S.category_name = card.dataset.name;
      S.category_icon = card.dataset.icon;
      const btn = $('#nas-btn-next-1');
      if (btn) btn.disabled = false;
      const info = $('#nas-step1-info');
      if (info) info.textContent = `Selected: ${S.category_name}`;
    });
    // Keyboard
    grid.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.target.click(); }
    });
  }

  /* ══════════════════════════════════════════════════════════════════════
     STEP 2: SAMPLE ADS
  ══════════════════════════════════════════════════════════════════════ */
  function loadSampleAds() {
    if (!S.category_id) return;
    const label = $('#nas-samples-category-label');
    if (label) label.textContent = S.category_icon + ' ' + S.category_name;
    ajax('nas_get_sample_ads', { category_id: S.category_id }).then(res => {
      const grid = $('#nas-samples-grid');
      if (!grid) return;
      const loadLbl = document.getElementById('nas-samples-loading-label');
      if (loadLbl) loadLbl.style.display = 'none';
      const samples = res.data || [];
      if (!samples.length) {
        grid.innerHTML = '<div class="nas-empty-state"><i class="fa-solid fa-images"></i><p>No curated samples for this category yet.<br><small>No problem — continue to Step 7 and write your ad from scratch, or use a template there.</small></p></div>';
        return;
      }
      grid.innerHTML = samples.map(s => `
        <div class="nas-sample-card" data-content="${escH(s.content)}" data-title="${escH(s.title)}" tabindex="0" role="button">
          <div class="nas-sample-card-title">${escH(s.title)}</div>
          <div class="nas-sample-card-content">${escH(truncate(s.content, 180))}</div>
          <button class="nas-btn nas-btn-sm nas-btn-accent nas-sample-use-btn">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Use This
          </button>
        </div>
      `).join('');
      grid.addEventListener('click', e => {
        const btn = e.target.closest('.nas-sample-use-btn');
        if (!btn) return;
        const card = btn.closest('.nas-sample-card');
        S.ad_content = card.dataset.content;
        S._sampleAdSelected = true;
        $$('.nas-sample-card', grid).forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
        showToast('Sample loaded! Edit it in Step 7.', 'success');
      });
    }).catch(() => {
      const grid = $('#nas-samples-grid');
      const loadLbl = document.getElementById('nas-samples-loading-label');
      if (loadLbl) loadLbl.style.display = 'none';
      if (grid) grid.innerHTML = '<div class="nas-empty-state"><i class="fa-solid fa-triangle-exclamation" style="color:var(--nas-danger)"></i><p>Couldn\'t load sample ads.<br><small>You can skip this step and write your ad directly in Step 7.</small></p></div>';
    });
  }

  /* ══════════════════════════════════════════════════════════════════════
     STEP 3: CITY
  ══════════════════════════════════════════════════════════════════════ */
  let _allCities = [];
  function loadCities() {
    ajax('nas_get_cities').then(res => {
      _allCities = res.data || [];
      renderStateChips(_allCities);
    }).catch(() => {
      showToast("Couldn't load the city list. Please refresh and try again.", 'error');
    });
  }

  function bindCitySearch() {
    const input = $('#nas-city-search');
    const dropdown = $('#nas-city-dropdown');
    if (!input || !dropdown) return;

    let debounce;
    input.addEventListener('input', () => {
      clearTimeout(debounce);
      debounce = setTimeout(() => {
        const q = input.value.trim().toLowerCase();
        if (q.length < 2) { dropdown.style.display = 'none'; return; }
        const filtered = _allCities.filter(c =>
          c.name.toLowerCase().includes(q) || (c.state || '').toLowerCase().includes(q)
        ).slice(0, 12);
        renderCityDropdown(filtered, dropdown);
        input.setAttribute('aria-expanded', 'true');
      }, 180);
    });

    input.addEventListener('focus', () => { if (_allCities.length && !S.city_id) renderCityDropdown(_allCities.slice(0, 10), dropdown); });
    document.addEventListener('click', e => { if (!input.closest('.nas-city-selector').contains(e.target)) { dropdown.style.display = 'none'; } });

    // Popular city buttons
    $$('.nas-popular-city-btn').forEach(btn => {
      btn.addEventListener('click', () => selectCityByName(btn.dataset.city));
    });

    // Clear button
    const clearBtn = $('#nas-city-clear-btn');
    if (clearBtn) clearBtn.addEventListener('click', clearCity);
  }

  function renderCityDropdown(cities, dropdown) {
    if (!cities.length) { dropdown.style.display = 'none'; return; }
    dropdown.innerHTML = cities.map(c => `
      <div class="nas-city-option" data-id="${c.id}" data-name="${escH(c.name)}" data-state="${escH(c.state || '')}"
           data-tier="${c.tier || 3}" role="option" tabindex="0">
        <i class="fa-solid fa-location-dot"></i>
        <span class="nas-city-opt-name">${escH(c.name)}</span>
        <span class="nas-city-opt-state">${escH(c.state || '')}</span>
        <span class="nas-tier-badge nas-tier-${c.tier || 3}">T${c.tier || 3}</span>
      </div>
    `).join('');
    dropdown.style.display = 'block';
    dropdown.querySelectorAll('.nas-city-option').forEach(opt => {
      opt.addEventListener('click', () => selectCity(opt));
      opt.addEventListener('keydown', e => { if (e.key === 'Enter') selectCity(opt); });
    });
  }

  function selectCity(opt) {
    S.city_id = parseInt(opt.dataset.id);
    S.city_name = opt.dataset.name;
    S.city_state = opt.dataset.state;
    showCitySelectedCard();
    const input = $('#nas-city-search');
    const dropdown = $('#nas-city-dropdown');
    if (input) { input.value = ''; input.setAttribute('aria-expanded', 'false'); }
    if (dropdown) dropdown.style.display = 'none';
    const btn = $('#nas-btn-next-3');
    if (btn) btn.disabled = false;
    // Update client city prefill
    const cc = $('#nas-client-city');
    if (cc && !cc.value) cc.value = S.city_name;
    S.client_city = S.city_name;
  }

  function selectCityByName(name) {
    const city = _allCities.find(c => c.name.toLowerCase() === name.toLowerCase());
    if (city) {
      const fakeOpt = { dataset: { id: city.id, name: city.name, state: city.state || '' } };
      selectCity(fakeOpt);
    }
  }

  function clearCity() {
    S.city_id = null; S.city_name = ''; S.city_state = '';
    // Also reset all newspaper selections — they are city-specific
    S._selectedNewspapers = [];
    S.newspaper_id = null; S.newspaper_name = '';
    const bar = document.getElementById('nas-selected-papers-bar');
    if (bar) bar.style.display = 'none';
    const card = $('#nas-city-selected-card');
    if (card) card.style.display = 'none';
    const input = $('#nas-city-search');
    if (input) { input.style.display = ''; input.placeholder = 'Search 300+ cities — type city name...'; }
    const sg = $('#nas-city-state-groups');
    if (sg) sg.style.display = '';
    const btn = $('#nas-btn-next-3');
    if (btn) btn.disabled = true;
  }

  function showCitySelectedCard() {
    const card = $('#nas-city-selected-card');
    const nameEl = $('#nas-city-selected-name');
    const stateEl = $('#nas-city-selected-state');
    const searchWrap = $('.nas-city-search-wrap');
    const sg = $('#nas-city-state-groups');
    if (card) card.style.display = 'flex';
    if (nameEl) nameEl.textContent = S.city_name;
    if (stateEl) stateEl.textContent = S.city_state;
    if (searchWrap) searchWrap.style.display = 'none';
    if (sg) sg.style.display = 'none';
  }

  function renderStateChips(cities) {
    const wrap = $('#nas-state-chips');
    if (!wrap) return;
    const states = [...new Set(cities.map(c => c.state).filter(Boolean))].sort();
    wrap.innerHTML = states.map(s =>
      `<button class="nas-state-chip" type="button" data-state="${escH(s)}">${escH(s)}</button>`
    ).join('');

    // Remove any previously attached listener (re-render safety)
    const newWrap = wrap.cloneNode(true);
    wrap.parentNode.replaceChild(newWrap, wrap);

    newWrap.addEventListener('click', e => {
      const chip = e.target.closest('.nas-state-chip');
      if (!chip) return;
      const state = chip.dataset.state;

      // Mark active chip
      $$('.nas-state-chip', newWrap).forEach(c => c.classList.remove('active'));
      chip.classList.add('active');

      // Filter cities for this state
      const filtered = _allCities.filter(c => c.state === state);
      const dropdown = $('#nas-city-dropdown');
      const input    = $('#nas-city-search');

      // Clear input so it shows placeholder, not state name
      if (input) {
        input.value = '';
        input.placeholder = `Cities in ${state}…`;
        input.focus();
      }

      // Show city dropdown for this state
      renderCityDropdown(filtered, dropdown);

      // Scroll the dropdown into view on mobile
      if (dropdown && dropdown.children.length) {
        setTimeout(() => dropdown.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 50);
      }
    });
  }

  /* ══════════════════════════════════════════════════════════════════════
     STEP 4: NEWSPAPERS
  ══════════════════════════════════════════════════════════════════════ */
  function loadNewspapers() {
    if (!S.city_id && !S.city_name) return;
    // Reset multi-newspaper selections whenever newspapers reload (city/category changed)
    S._selectedNewspapers = [];
    S.newspaper_id = null; S.newspaper_name = ''; S.newspaper_lang = '';
    S.newspaper_circ = 0; S.newspaper_rate_cl = 0; S.newspaper_rate_di = 0;
    S.newspaper_rate_dc = 0; S.newspaper_min = 0;
    const nextBtn = document.getElementById('nas-btn-next-4');
    if (nextBtn) nextBtn.disabled = true;
    const bar = document.getElementById('nas-selected-papers-bar');
    if (bar) bar.style.display = 'none';

    const grid = $('#nas-newspaper-grid');
    if (grid) grid.innerHTML = Array(6).fill('<div class="nas-skeleton" style="height:80px;border-radius:12px"></div>').join('');
    const loadLbl = document.getElementById('nas-newspaper-loading-label');
    if (loadLbl) loadLbl.style.display = '';
    const cityLabel = $('#nas-paper-city-label');
    if (cityLabel) cityLabel.textContent = S.city_name;
    ajax('nas_get_newspapers', { city_name: S.city_name, category_id: S.category_id }).then(res => {
      S._newspapers = res.data || [];
      renderNewspaperGrid(S._newspapers);
      populateLangFilter(S._newspapers);
      checkCombos();
    }).catch(() => {
      if (loadLbl) loadLbl.style.display = 'none';
      renderLoadError($('#nas-newspaper-grid'), "Couldn't load newspapers for this city.", loadNewspapers);
    });
  }

  function renderNewspaperGrid(papers) {
    const grid = $('#nas-newspaper-grid');
    const empty = $('#nas-paper-empty');
    if (!grid) return;
    const loadLbl = document.getElementById('nas-newspaper-loading-label');
    if (loadLbl) loadLbl.style.display = 'none';
    if (!papers.length) {
      grid.innerHTML = '';
      if (empty) empty.style.display = 'block';
      return;
    }
    if (empty) empty.style.display = 'none';
    grid.innerHTML = papers.map(p => `
      <div class="nas-paper-card" data-id="${p.id}" data-name="${escH(p.name)}"
           data-lang="${escH(p.language || '')}" data-circ="${p.circulation || 0}"
           data-cl="${p.base_rate_classified || 0}" data-di="${p.base_rate_display || 0}"
           data-dc="${p.base_rate_dc || 0}" data-min="${p.min_charge || 0}"
           tabindex="0" role="button" aria-pressed="false">
        <div class="nas-paper-card-inner">
          <div class="nas-paper-card-left">
            <div class="nas-paper-initial">${escH(p.name.charAt(0))}</div>
          </div>
          <div class="nas-paper-card-body">
            <div class="nas-paper-name">${escH(p.name)}</div>
            <div class="nas-paper-meta">
              <span class="nas-lang-badge">${escH(p.language || 'English')}</span>
              ${p.circulation ? `<span class="nas-circ-badge"><i class="fa-solid fa-users"></i> ${fmtCirc(p.circulation)}</span>` : ''}
              <span class="nas-rate-badge">₹${p.base_rate_classified || 0}/word</span>
            </div>
          </div>
          <div class="nas-paper-card-check"><i class="fa-solid fa-check-circle"></i></div>
        </div>
      </div>
    `).join('');
    grid.addEventListener('click', e => {
      const card = e.target.closest('.nas-paper-card');
      if (!card) return;

      const id   = parseInt(card.dataset.id);
      const already = S._selectedNewspapers.findIndex(p => p.id === id);

      if (already > -1) {
        // Deselect
        S._selectedNewspapers.splice(already, 1);
        card.classList.remove('selected');
        card.setAttribute('aria-pressed', 'false');
      } else {
        // Select
        S._selectedNewspapers.push({
          id,
          name:    card.dataset.name,
          lang:    card.dataset.lang,
          circ:    parseInt(card.dataset.circ),
          rate_cl: parseFloat(card.dataset.cl),
          rate_di: parseFloat(card.dataset.di),
          rate_dc: parseFloat(card.dataset.dc),
          min:     parseFloat(card.dataset.min),
        });
        card.classList.add('selected');
        card.setAttribute('aria-pressed', 'true');
      }

      // Primary = first selected; drives pricing & edition steps
      const primary = S._selectedNewspapers[0] || null;
      S.newspaper_id   = primary ? primary.id   : null;
      S.newspaper_name = primary ? primary.name : '';
      S.newspaper_lang = primary ? primary.lang : '';
      S.newspaper_circ = primary ? primary.circ : 0;
      S.newspaper_rate_cl = primary ? primary.rate_cl : 0;
      S.newspaper_rate_di = primary ? primary.rate_di : 0;
      S.newspaper_rate_dc = primary ? primary.rate_dc : 0;
      S.newspaper_min     = primary ? primary.min     : 0;

      // Update multi-select summary chip below grid
      updateNewspaperSummary();

      const btn = $('#nas-btn-next-4');
      if (btn) btn.disabled = S._selectedNewspapers.length === 0;
      if (S._selectedNewspapers.length > 0) S.edition = ''; // reset edition on change
    });
    // Keyboard
    grid.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') e.target.click(); });
    // Bind search/filter
    bindPaperFilters();
  }

  function updateNewspaperSummary() {
    let bar = document.getElementById('nas-selected-papers-bar');
    if (!bar) {
      // Create the summary bar once and insert it above the filter row
      bar = document.createElement('div');
      bar.id = 'nas-selected-papers-bar';
      bar.className = 'nas-selected-papers-bar';
      const grid = document.getElementById('nas-newspaper-grid');
      if (grid) grid.parentNode.insertBefore(bar, grid);
    }
    if (!S._selectedNewspapers.length) {
      bar.style.display = 'none';
      return;
    }
    bar.style.display = 'flex';
    bar.innerHTML =
      `<span class="nas-spb-label"><i class="fa-solid fa-layer-group"></i> ${S._selectedNewspapers.length} newspaper${S._selectedNewspapers.length > 1 ? 's' : ''} selected:</span>` +
      S._selectedNewspapers.map((p, i) =>
        `<span class="nas-spb-chip${i === 0 ? ' nas-spb-primary' : ''}" data-id="${p.id}">
           ${escH(p.name)}${i === 0 ? ' <em>(primary)</em>' : ''}
           <button class="nas-spb-remove" data-id="${p.id}" title="Remove" type="button">×</button>
         </span>`
      ).join('') +
      `<span class="nas-spb-hint">Tip: first selected is used for pricing & editions</span>`;

    // Remove button handler
    bar.querySelectorAll('.nas-spb-remove').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const removeId = parseInt(btn.dataset.id);
        S._selectedNewspapers = S._selectedNewspapers.filter(p => p.id !== removeId);
        // Deselect card in grid
        const card = document.querySelector(`.nas-paper-card[data-id="${removeId}"]`);
        if (card) { card.classList.remove('selected'); card.setAttribute('aria-pressed', 'false'); }
        // Rebuild primary
        const primary = S._selectedNewspapers[0] || null;
        S.newspaper_id   = primary ? primary.id   : null;
        S.newspaper_name = primary ? primary.name : '';
        S.newspaper_lang = primary ? primary.lang : '';
        S.newspaper_circ = primary ? primary.circ : 0;
        S.newspaper_rate_cl = primary ? primary.rate_cl : 0;
        S.newspaper_rate_di = primary ? primary.rate_di : 0;
        S.newspaper_rate_dc = primary ? primary.rate_dc : 0;
        S.newspaper_min     = primary ? primary.min : 0;
        const nextBtn = document.getElementById('nas-btn-next-4');
        if (nextBtn) nextBtn.disabled = S._selectedNewspapers.length === 0;
        updateNewspaperSummary();
      });
    });
  }

  function bindPaperFilters() {
    const search = $('#nas-paper-search');
    const langFilter = $('#nas-lang-filter');
    const sort = $('#nas-paper-sort');
    const filter = () => {
      const q = (search?.value || '').toLowerCase();
      const lang = langFilter?.value || '';
      const sortBy = sort?.value || 'circ';
      let papers = [...S._newspapers];
      if (q) papers = papers.filter(p => p.name.toLowerCase().includes(q) || (p.language || '').toLowerCase().includes(q));
      if (lang) papers = papers.filter(p => p.language === lang);
      if (sortBy === 'circ') papers.sort((a, b) => (b.circulation || 0) - (a.circulation || 0));
      if (sortBy === 'rate_asc') papers.sort((a, b) => (a.base_rate_classified || 0) - (b.base_rate_classified || 0));
      if (sortBy === 'rate_desc') papers.sort((a, b) => (b.base_rate_classified || 0) - (a.base_rate_classified || 0));
      if (sortBy === 'name') papers.sort((a, b) => a.name.localeCompare(b.name));
      renderNewspaperGrid(papers);
    };
    if (search) search.addEventListener('input', filter);
    if (langFilter) langFilter.addEventListener('change', filter);
    if (sort) sort.addEventListener('change', filter);
    const clearBtn = document.getElementById('nas-paper-clear-filters-btn');
    if (clearBtn) clearBtn.addEventListener('click', () => {
      if (search) search.value = '';
      if (langFilter) langFilter.value = '';
      if (sort) sort.value = 'circ';
      filter();
    });
  }

  function populateLangFilter(papers) {
    const sel = $('#nas-lang-filter');
    if (!sel) return;
    const langs = [...new Set(papers.map(p => p.language).filter(Boolean))].sort();
    sel.innerHTML = '<option value="">All Languages</option>' +
      langs.map(l => `<option value="${escH(l)}">${escH(l)}</option>`).join('');
  }

  /* ══════════════════════════════════════════════════════════════════════
     STEP 5: EDITION — supports multiple newspapers
  ══════════════════════════════════════════════════════════════════════ */
  function renderEditionGrid() {
    S._editionsByPaper = {};
    S.edition = '';

    const container = document.getElementById('nas-multi-edition-container');
    if (!container) return;
    container.innerHTML = '';
    const editionLoadLbl = document.getElementById('nas-edition-loading-label');
    if (editionLoadLbl) editionLoadLbl.style.display = '';

    const hint = document.getElementById('nas-edition-all-hint');
    const nextBtn = document.getElementById('nas-btn-next-5');
    if (nextBtn) nextBtn.disabled = true;
    if (hint) hint.style.display = 'none';

    // Update header label
    const paperLabel = document.getElementById('nas-edition-paper-label');
    const papers = S._selectedNewspapers.length > 0 ? S._selectedNewspapers : (S.newspaper_id ? [{
      id: S.newspaper_id, name: S.newspaper_name, lang: S.newspaper_lang,
      circ: S.newspaper_circ, rate_cl: S.newspaper_rate_cl,
      rate_di: S.newspaper_rate_di, rate_dc: S.newspaper_rate_dc, min: S.newspaper_min
    }] : []);

    if (paperLabel) paperLabel.textContent = papers.length > 1
      ? papers.length + ' selected newspapers'
      : (papers[0]?.name || 'the newspaper');

    if (papers.length === 0 && editionLoadLbl) editionLoadLbl.style.display = 'none';

    const checkAll = () => {
      const done = papers.every(p => S._editionsByPaper[p.id]);
      if (nextBtn) nextBtn.disabled = !done;
      if (hint) hint.style.display = (done && papers.length > 1) ? 'flex' : 'none';
      if (done && papers[0]) S.edition = S._editionsByPaper[papers[0].id] || '';
    };

    // Render each paper section
    if (editionLoadLbl) editionLoadLbl.style.display = 'none';
    papers.forEach(paper => {
      const section = document.createElement('div');
      section.style.cssText = 'border:1.5px solid #E3E6F5;border-radius:14px;overflow:hidden;';
      section.dataset.paperId = paper.id;

      const header = document.createElement('div');
      header.style.cssText = 'display:flex;align-items:center;gap:12px;padding:14px 16px;background:#F7F8FC;border-bottom:1px solid #E3E6F5;';
      header.innerHTML =
        '<div style="width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#2A8AFA,#202C39);color:#fff;font-size:16px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;">'+escH(paper.name.charAt(0))+'</div>'+
        '<div style="flex:1;min-width:0;">'+
          '<div style="font-size:14px;font-weight:700;color:#202C39;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+escH(paper.name)+'</div>'+
          '<div style="display:flex;gap:6px;align-items:center;">'+
            '<span style="background:#D7DBFF;color:#202C39;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;">'+escH(paper.lang||'English')+'</span>'+
            (paper.circ ? '<span style="font-size:11px;color:#9ca3af;"><i class="fa-solid fa-users"></i> '+fmtCirc(paper.circ)+'</span>' : '')+
          '</div>'+
        '</div>'+
        '<div id="nas-eps-status-'+paper.id+'" style="font-size:12px;font-weight:600;color:#9ca3af;white-space:nowrap;">Choose ↓</div>';
      section.appendChild(header);

      const gridDiv = document.createElement('div');
      gridDiv.id = 'nas-egrid-'+paper.id;
      gridDiv.className = 'nas-edition-grid';
      gridDiv.style.cssText = 'padding:12px;gap:8px;';
      gridDiv.innerHTML = '<div class="nas-skeleton" style="height:50px;border-radius:10px;"></div>';
      section.appendChild(gridDiv);
      container.appendChild(section);

      // Load editions async
      ajax('nas_get_editions', {newspaper_id: paper.id, city_name: S.city_name}).then(res => {
        const editions = res.data?.length ? res.data : ['Main Edition'];
        gridDiv.innerHTML = editions.map(e =>
          '<div class="nas-edition-card" data-edition="'+escH(e)+'" data-paper-id="'+paper.id+'" tabindex="0" role="button" aria-pressed="false">'+
          '<i class="fa-solid fa-map-pin"></i> '+escH(e)+
          '<div class="nas-edition-check"><i class="fa-solid fa-check"></i></div></div>'
        ).join('');

        // Auto-select city match or Main Edition if only one
        const autoEd = editions.find(e => e.toLowerCase() === S.city_name.toLowerCase())
          || (editions.length === 1 ? editions[0] : null);
        if (autoEd) {
          const ac = gridDiv.querySelector('[data-edition="'+escH(autoEd)+'"]');
          if (ac) pickEdition(ac, paper.id, section, checkAll);
        }

        gridDiv.addEventListener('click', ev => {
          const card = ev.target.closest('.nas-edition-card');
          if (card) pickEdition(card, paper.id, section, checkAll);
        });
        gridDiv.addEventListener('keydown', ev => { if (ev.key==='Enter'||ev.key===' ') ev.target.click(); });
      }).catch(() => {
        gridDiv.innerHTML = '<div class="nas-edition-card" data-edition="Main Edition" data-paper-id="'+paper.id+'" tabindex="0" role="button" aria-pressed="false"><i class="fa-solid fa-map-pin"></i> Main Edition<div class="nas-edition-check"><i class="fa-solid fa-check"></i></div></div>';
        const mc = gridDiv.querySelector('.nas-edition-card');
        if (mc) pickEdition(mc, paper.id, section, checkAll);
      });
    });
  }

  function pickEdition(card, paperId, section, afterPick) {
    // Deselect all in this paper
    const grid = document.getElementById('nas-egrid-'+paperId);
    if (grid) grid.querySelectorAll('.nas-edition-card').forEach(c => {
      c.classList.remove('selected'); c.setAttribute('aria-pressed','false');
    });
    card.classList.add('selected');
    card.setAttribute('aria-pressed','true');
    S._editionsByPaper[paperId] = card.dataset.edition;
    if (section) section.style.borderColor = '#c4b5fd';
    const statusEl = document.getElementById('nas-eps-status-'+paperId);
    if (statusEl) statusEl.innerHTML = '<span style="color:#10b981;"><i class="fa-solid fa-circle-check"></i> '+escH(card.dataset.edition)+'</span>';
    if (afterPick) afterPick();
  }

  // backward-compat alias
  function selectEdition(card) {
    const pid = card.dataset.paperId || S.newspaper_id;
    pickEdition(card, pid, card.closest('[data-paper-id]'), () => {
      S.edition = S._editionsByPaper[S.newspaper_id] || card.dataset.edition;
      const btn = document.getElementById('nas-btn-next-5');
      if (btn) btn.disabled = false;
    });
  }

  /* ══════════════════════════════════════════════════════════════════════
     STEP 6: AD TYPE
  ══════════════════════════════════════════════════════════════════════ */
  function renderAdTypes() {
    // Populate rates
    const rcl = $('#nas-adtype-rate-cl'); if (rcl) rcl.textContent = S.newspaper_rate_cl;
    const rdi = $('#nas-adtype-rate-di'); if (rdi) rdi.textContent = S.newspaper_rate_di;
    const rdc = $('#nas-adtype-rate-dc'); if (rdc) rdc.textContent = S.newspaper_rate_dc;
    // Bind click
    $$('.nas-adtype-card').forEach(card => {
      card.addEventListener('click', () => {
        $$('.nas-adtype-card').forEach(c => { c.classList.remove('selected'); c.setAttribute('aria-pressed', 'false'); });
        card.classList.add('selected');
        card.setAttribute('aria-pressed', 'true');
        S.ad_type = card.dataset.type;
        const btn = $('#nas-btn-next-6');
        if (btn) btn.disabled = false;
      });
    });
    // Restore if already selected
    if (S.ad_type) {
      const card = $(`.nas-adtype-card[data-type="${S.ad_type}"]`);
      if (card) { card.classList.add('selected'); card.setAttribute('aria-pressed', 'true'); }
    }
  }

  /* ══════════════════════════════════════════════════════════════════════
     STEP 7: BUILD AD
  ══════════════════════════════════════════════════════════════════════ */
  function onEnterBuildAd() {
    const titleGroup = $('#nas-ad-title-group');
    const hintEl = $('#nas-content-type-hint');
    if (S.ad_type === 'classified_text') {
      if (titleGroup) titleGroup.style.display = 'none';
      if (hintEl) hintEl.textContent = '(Plain text, charged per word)';
    } else {
      if (titleGroup) titleGroup.style.display = '';
      if (hintEl) hintEl.textContent = '(Design will be prepared by our team)';
    }
    // Update mock header
    const mockPaper = $('#nas-mock-paper-name');
    if (mockPaper) mockPaper.textContent = S.newspaper_name || 'Newspaper Name';
    // Update mock category
    const mockCat = $('#nas-mock-cat');
    if (mockCat) mockCat.textContent = S.category_name;
    // Load templates
    loadTemplates();
    // Restore content
    const ta = $('#nas-ad-content');
    if (ta) { ta.value = S.ad_content || ''; bindContentEditor(ta); }
    const ti = $('#nas-ad-title');
    if (ti) { ti.value = S.ad_title || ''; ti.addEventListener('input', () => { S.ad_title = ti.value; updateMockTitle(); }); }
    updateWordCount();
    setMockDate();
  }

  function loadTemplates() {
    ajax('nas_get_templates', { category_id: S.category_id }).then(res => {
      const templates = res.data || [];
      S._templates = templates;
      const grid = $('#nas-template-grid');
      if (!grid) return;
      const extras = templates.map(t => `
        <button class="nas-template-chip" data-id="${t.id}" data-content="${escH(t.content)}" title="${escH(t.name)}">
          ${escH(t.name.length > 22 ? t.name.slice(0, 22) + '…' : t.name)}
        </button>
      `).join('');
      // Keep blank + add templates
      const blank = grid.querySelector('.nas-template-chip-blank');
      grid.innerHTML = '';
      if (blank) grid.appendChild(blank);
      grid.insertAdjacentHTML('beforeend', extras);
      grid.addEventListener('click', e => {
        const chip = e.target.closest('.nas-template-chip');
        if (!chip) return;
        $$('.nas-template-chip', grid).forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        if (chip.classList.contains('nas-template-chip-blank')) {
          const ta = $('#nas-ad-content');
          if (ta) { ta.value = ''; ta.focus(); }
          S.ad_content = '';
        } else {
          const ta = $('#nas-ad-content');
          if (ta) { ta.value = chip.dataset.content; ta.focus(); }
          S.ad_content = chip.dataset.content;
        }
        updateWordCount();
        updateMockContent();
      });
    });
  }

  function bindContentEditor(ta) {
    ta.addEventListener('input', () => {
      S.ad_content = ta.value;
      updateWordCount();
      updateMockContent();
      calcPriceEstimate();
    });
  }

  function updateWordCount() {
    const ta = $('#nas-ad-content');
    if (!ta) return;
    const words = ta.value.trim().split(/\s+/).filter(Boolean);
    S.word_count = words.length;
    const label = $('#nas-word-count-label');
    if (label) label.textContent = S.word_count + ' words';
    const charCount = $('#nas-char-count');
    if (charCount) charCount.textContent = ta.value.length + ' characters';
    const maxWords = 150;
    const pct = Math.min((S.word_count / maxWords) * 100, 100);
    const bar = $('#nas-word-bar-fill');
    if (bar) {
      bar.style.width = pct + '%';
      bar.style.background = S.word_count > maxWords ? 'var(--nas-danger)' : S.word_count > maxWords * 0.85 ? 'var(--nas-warning)' : 'var(--nas-success)';
    }
    const warning = $('#nas-word-warning');
    if (warning) {
      if (S.word_count > maxWords) {
        warning.textContent = `⚠️ Ad is too long (max ${maxWords} words recommended)`;
        warning.style.display = 'inline';
      } else if (S.word_count === 0) {
        warning.style.display = 'none';
      } else {
        warning.style.display = 'none';
      }
    }
    // Enable next
    const btn = $('#nas-btn-next-7');
    if (btn) btn.disabled = S.ad_content.trim().length < 10;
  }

  function updateMockContent() {
    const el = $('#nas-mock-ad-content');
    if (el) el.innerHTML = S.ad_content ? escH(S.ad_content).replace(/\n/g, '<br>') : '<em>Your ad will appear here…</em>';
  }

  function updateMockTitle() {
    const el = $('#nas-mock-ad-title');
    if (el) {
      el.style.display = S.ad_title ? '' : 'none';
      el.textContent = S.ad_title;
    }
  }

  function setMockDate() {
    const el = $('#nas-mock-date');
    if (el) el.textContent = S.publish_date_label || new Date().toLocaleDateString('en-IN', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  }

  function calcPriceEstimate() {
    const rate = S.ad_type === 'display' ? S.newspaper_rate_di : (S.ad_type === 'display_classified' ? S.newspaper_rate_dc : S.newspaper_rate_cl);
    let base = 0;
    if (S.ad_type === 'classified_text') {
      base = Math.max(S.word_count * rate, S.newspaper_min);
    } else {
      base = Math.max(S.width_cm * S.height_cm * rate, S.newspaper_min);
    }
    S.base_amount = base;
    const est = $('#nas-preview-est-cost');
    if (est) est.textContent = fmt(base) + ' (est.)';
    const note = $('#nas-preview-cost-note');
    if (note) note.textContent = S.ad_type === 'classified_text' ? `${S.word_count} words × ₹${rate}/word` : `${S.width_cm}×${S.height_cm} cm × ₹${rate}/sq.cm`;
  }

  /* ══════════════════════════════════════════════════════════════════════
     AI ASSIST
  ══════════════════════════════════════════════════════════════════════ */
  async function aiTransform(mode) {
    const ta = $('#nas-ad-content');
    if (!ta || !ta.value.trim()) return;
    const spinner = $('#nas-ai-spinner');
    if (spinner) spinner.style.display = 'inline-flex';
    try {
      const res = await ajax('nas_ai_transform', { content: ta.value, mode, category: S.category_name, ad_type: S.ad_type });
      if (res.success && res.data?.content) {
        ta.value = res.data.content;
        S.ad_content = ta.value;
        updateWordCount(); updateMockContent();
        showToast('AI improved your ad!', 'success');
      }
    } catch (e) {}
    if (spinner) spinner.style.display = 'none';
  }

  /* ══════════════════════════════════════════════════════════════════════
     STEP 8: PRICING
  ══════════════════════════════════════════════════════════════════════ */
  function onEnterPricing() {
    const classifiedPanel = $('#nas-pricing-classified-panel');
    const displayPanel    = $('#nas-pricing-display-panel');
    if (S.ad_type === 'classified_text') {
      if (classifiedPanel) classifiedPanel.style.display = '';
      if (displayPanel) displayPanel.style.display = 'none';
      renderClassifiedPricing();
    } else {
      if (classifiedPanel) classifiedPanel.style.display = 'none';
      if (displayPanel) displayPanel.style.display = '';
      loadSizePresets();
      calcDisplayPrice();
    }
    renderAvailableCombos();
  }

  function renderClassifiedPricing() {
    const rate = S.ad_type === 'classified_text' ? S.newspaper_rate_cl : S.newspaper_rate_dc;
    const base = S.word_count * rate;
    const min  = S.newspaper_min;
    const actual = Math.max(base, min);
    S.base_amount = actual;
    const wc = $('#nas-price-word-count'); if (wc) wc.textContent = S.word_count + ' words';
    const pw = $('#nas-price-per-word'); if (pw) pw.textContent = `₹${rate}`;
    const pb = $('#nas-price-base'); if (pb) pb.textContent = fmt(base);
    const mr = $('#nas-pricing-min-row');
    if (actual > base && mr) {
      mr.style.display = '';
      const pm = $('#nas-price-min-applied'); if (pm) pm.textContent = fmt(min);
    } else if (mr) mr.style.display = 'none';
    calcTotals();
  }

  function loadSizePresets() {
    ajax('nas_get_ad_sizes').then(res => {
      const sizes = res.data || [];
      const grid = $('#nas-size-preset-grid');
      if (!grid) return;
      grid.innerHTML = sizes.map(s => `
        <button class="nas-size-preset-btn" data-w="${s.width_cm}" data-h="${s.height_cm}" title="${escH(s.description || '')}">
          ${escH(s.name)}<br><small>${s.width_cm}×${s.height_cm} cm</small>
        </button>
      `).join('');
      grid.addEventListener('click', e => {
        const btn = e.target.closest('.nas-size-preset-btn');
        if (!btn) return;
        $$('.nas-size-preset-btn', grid).forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const w = $('#nas-width-cm'), h = $('#nas-height-cm');
        if (w) w.value = btn.dataset.w;
        if (h) h.value = btn.dataset.h;
        S.width_cm = parseFloat(btn.dataset.w);
        S.height_cm = parseFloat(btn.dataset.h);
        calcDisplayPrice();
      });
    });
  }

  function calcDisplayPrice() {
    const w = parseFloat($('#nas-width-cm')?.value || S.width_cm);
    const h = parseFloat($('#nas-height-cm')?.value || S.height_cm);
    S.width_cm = w; S.height_cm = h;
    const area = (w * h).toFixed(2);
    const areaEl = $('#nas-area-sqcm'); if (areaEl) areaEl.value = area + ' sq.cm';
    const rate = S.ad_type === 'display' ? S.newspaper_rate_di : S.newspaper_rate_dc;
    const base = Math.max(w * h * rate, S.newspaper_min);
    S.base_amount = base;
    const pr = $('#nas-price-per-sqcm'); if (pr) pr.textContent = `₹${rate}`;
    const pb = $('#nas-price-display-base'); if (pb) pb.textContent = fmt(base);
    // Size visual
    const box = $('#nas-size-box');
    const label = $('#nas-size-box-label');
    if (box) {
      const scale = 6;
      box.style.width  = Math.min(w * scale, 200) + 'px';
      box.style.height = Math.min(h * scale, 200) + 'px';
    }
    if (label) label.textContent = `${w} × ${h} cm`;
    calcTotals();
  }

  function calcTotals() {
    const comboDiscount = (S.base_amount * S.combo_discount_pct) / 100;
    const afterCombo    = S.base_amount - comboDiscount - S.coupon_discount;
    const gst           = (afterCombo * CFG.gstRate) / 100;
    const total         = afterCombo + gst;
    S.discount_amount = comboDiscount + S.coupon_discount;
    S.gst_amount      = gst;
    S.total_amount    = total;
    const gstEl = $('#nas-price-gst'); if (gstEl) gstEl.textContent = fmt(gst);
    const totEl = $('#nas-price-total'); if (totEl) totEl.textContent = fmt(total);
    const discRow = $('#nas-combo-discount-row');
    const discAmt = $('#nas-combo-discount-amount');
    if (S.discount_amount > 0 && discRow) {
      discRow.style.display = '';
      if (discAmt) discAmt.textContent = '-' + fmt(S.discount_amount);
    } else if (discRow) discRow.style.display = 'none';
    updateSidebar(S.step);
  }

  function checkCombos() {
    ajax('nas_get_combo_offers', { city_name: S.city_name, newspaper_id: S.newspaper_id }).then(res => {
      S._combos = res.data || [];
      if (S._combos.length) {
        const top = S._combos[0];
        const banner = $('#nas-combo-banner');
        if (banner) {
          banner.style.display = 'flex';
          const nameEl = $('#nas-combo-name'); if (nameEl) nameEl.textContent = top.name;
          const descEl = $('#nas-combo-desc'); if (descEl) descEl.textContent = top.conditions;
          banner.dataset.comboId = top.id;
          banner.dataset.discount = top.discount_value;
        }
      }
    });
  }

  function applyComboFromBanner() {
    const banner = $('#nas-combo-banner');
    if (!banner) return;
    S.combo_id = banner.dataset.comboId;
    S.combo_discount_pct = parseFloat(banner.dataset.discount);
    banner.classList.add('applied');
    banner.querySelector('#nas-combo-apply-btn').innerHTML = '<i class="fa-solid fa-check"></i> Applied';
    showToast(`Combo applied! ${S.combo_discount_pct}% discount activated.`, 'success');
    calcTotals();
  }

  function renderAvailableCombos() {
    const wrap = $('#nas-available-combos');
    const list = $('#nas-combo-list');
    if (!wrap || !list || !S._combos.length) return;
    wrap.style.display = '';
    list.innerHTML = S._combos.map(c => `
      <div class="nas-combo-item">
        <div class="nas-combo-item-info">
          <strong>${escH(c.name)}</strong>
          <span>${c.discount_value}% off</span>
          <small>${escH(c.conditions || '')}</small>
        </div>
        <button class="nas-btn nas-btn-sm nas-btn-outline" data-combo-id="${c.id}" data-discount="${c.discount_value}">Apply</button>
      </div>
    `).join('');
    list.addEventListener('click', e => {
      const btn = e.target.closest('button[data-combo-id]');
      if (!btn) return;
      S.combo_id = btn.dataset.comboId;
      S.combo_discount_pct = parseFloat(btn.dataset.discount);
      $$('.nas-combo-item button', list).forEach(b => { b.textContent = 'Apply'; b.classList.remove('applied'); });
      btn.innerHTML = '<i class="fa-solid fa-check"></i> Applied';
      btn.classList.add('applied');
      showToast(`${S.combo_discount_pct}% combo discount applied!`, 'success');
      calcTotals();
    });
  }

  async function applyCoupon() {
    const input = $('#nas-coupon-code');
    const msg   = $('#nas-coupon-msg');
    if (!input || !input.value.trim()) return;
    const res = await ajax('nas_validate_coupon', { code: input.value.trim(), amount: S.base_amount });
    if (msg) { msg.style.display = ''; }
    if (res.success) {
      S.coupon_code     = input.value.trim();
      S.coupon_discount = parseFloat(res.data?.discount || 0);
      if (msg) { msg.className = 'nas-coupon-msg nas-coupon-msg-success'; msg.innerHTML = `<i class="fa-solid fa-circle-check"></i> Coupon applied! ₹${S.coupon_discount} off`; }
      calcTotals();
    } else {
      if (msg) { msg.className = 'nas-coupon-msg nas-coupon-msg-error'; msg.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + escH(res.data?.message || 'Invalid coupon code'); }
    }
  }

  /* ══════════════════════════════════════════════════════════════════════
     STEP 9: PUBLISH DATE (inline calendar)
  ══════════════════════════════════════════════════════════════════════ */
  function initCalendar() {
    const cal = $('#nas-publish-date-calendar');
    if (!cal) return;
    renderCalendar(cal, new Date());
  }

  function renderCalendar(container, baseDate) {
    const today = new Date();
    const minDate = new Date(today);
    minDate.setDate(minDate.getDate() + CFG.minAdvanceDays);
    let viewDate = new Date(baseDate.getFullYear(), baseDate.getMonth(), 1);

    function draw() {
      const y = viewDate.getFullYear();
      const m = viewDate.getMonth();
      const firstDay = new Date(y, m, 1).getDay();
      const daysInMonth = new Date(y, m + 1, 0).getDate();
      const monthLabel = viewDate.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
      let html = `<div class="nas-cal-header">
        <button class="nas-cal-nav" id="nas-cal-prev"><i class="fa-solid fa-chevron-left"></i></button>
        <span class="nas-cal-month">${monthLabel}</span>
        <button class="nas-cal-nav" id="nas-cal-next"><i class="fa-solid fa-chevron-right"></i></button>
      </div>
      <div class="nas-cal-grid">
        ${['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(d => `<div class="nas-cal-day-label">${d}</div>`).join('')}`;
      for (let i = 0; i < firstDay; i++) html += '<div class="nas-cal-cell nas-cal-empty"></div>';
      for (let d = 1; d <= daysInMonth; d++) {
        const date = new Date(y, m, d);
        const dateStr = date.toISOString().split('T')[0];
        const isDisabled = date < minDate;
        const isSelected = S.publish_date === dateStr;
        const isSunday = date.getDay() === 0;
        html += `<div class="nas-cal-cell${isDisabled ? ' nas-cal-disabled' : ''}${isSelected ? ' nas-cal-selected' : ''}${isSunday ? ' nas-cal-sunday' : ''}"
          data-date="${dateStr}" ${isDisabled ? '' : 'tabindex="0" role="button"'}>${d}</div>`;
      }
      html += '</div>';
      container.innerHTML = html;
      container.addEventListener('click', e => {
        const cell = e.target.closest('.nas-cal-cell:not(.nas-cal-disabled):not(.nas-cal-empty)');
        if (cell) selectDate(cell.dataset.date);
      });
      container.addEventListener('keydown', e => {
        const cell = e.target.closest('.nas-cal-cell:not(.nas-cal-disabled)');
        if (cell && (e.key === 'Enter' || e.key === ' ')) selectDate(cell.dataset.date);
      });
      $('#nas-cal-prev', container)?.addEventListener('click', () => { viewDate.setMonth(viewDate.getMonth() - 1); draw(); });
      $('#nas-cal-next', container)?.addEventListener('click', () => { viewDate.setMonth(viewDate.getMonth() + 1); draw(); });
    }
    draw();
  }

  function selectDate(dateStr) {
    S.publish_date = dateStr;
    const d = new Date(dateStr + 'T00:00:00');
    S.publish_date_label = d.toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    const input = $('#nas-publish-date-input');
    if (input) input.value = S.publish_date_label;
    // Show card
    const card = $('#nas-date-selected-card');
    const dateLabel = $('#nas-date-display-label');
    const daysLabel = $('#nas-date-days-label');
    if (card) card.style.display = 'flex';
    if (dateLabel) dateLabel.textContent = S.publish_date_label;
    if (daysLabel) {
      const today = new Date(); today.setHours(0,0,0,0);
      const diff = Math.round((d - today) / 86400000);
      daysLabel.textContent = `${diff} days from today`;
    }
    const btn = $('#nas-btn-next-9');
    if (btn) btn.disabled = false;
    // Redraw calendar to show selection
    const cal = $('#nas-publish-date-calendar');
    if (cal) renderCalendar(cal, d);
    setMockDate();
  }

  /* ══════════════════════════════════════════════════════════════════════
     STEP 10: CLIENT DETAILS — live bind
  ══════════════════════════════════════════════════════════════════════ */
  document.addEventListener('input', e => {
    const id = e.target?.id;
    if (id === 'nas-client-name')    { S.client_name    = e.target.value; }
    if (id === 'nas-client-phone')   { S.client_phone   = e.target.value.replace(/\D/g, '').slice(0, 10); e.target.value = S.client_phone; }
    if (id === 'nas-client-email')   { S.client_email   = e.target.value; }
    if (id === 'nas-client-city')    { S.client_city    = e.target.value; }
    if (id === 'nas-client-company') { S.client_company = e.target.value; }
    if (id === 'nas-client-gst')     { S.client_gst     = e.target.value.toUpperCase(); e.target.value = S.client_gst; }
    if (id === 'nas-client-notes')   { S.client_notes   = e.target.value; }
  });
  document.addEventListener('change', e => {
    if (e.target?.id === 'nas-whatsapp-optin') S.whatsapp_optin = e.target.checked;
  });

  /* ══════════════════════════════════════════════════════════════════════
     STEP 11: REVIEW
  ══════════════════════════════════════════════════════════════════════ */
  function renderReview() {
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val || '—'; };
    set('rv-category',  S.category_icon + ' ' + S.category_name);
    set('rv-city',      S.city_name + (S.city_state ? ', ' + S.city_state : ''));
    set('rv-newspaper', S._selectedNewspapers.length > 1
      ? S._selectedNewspapers.map(p => p.name).join(', ')
      : S.newspaper_name);
    set('rv-edition',   S.edition);
    set('rv-adtype',    { classified_text: 'Classified Text', display: 'Display Ad', display_classified: 'Display Classified' }[S.ad_type] || S.ad_type);
    set('rv-date',      S.publish_date_label);
    set('rv-name',      S.client_name);
    set('rv-phone',     '+91 ' + S.client_phone);
    set('rv-email',     S.client_email);
    // Ad content
    const titleEl = document.getElementById('rv-ad-title');
    const textEl  = document.getElementById('rv-ad-text');
    if (titleEl) { titleEl.textContent = S.ad_title; titleEl.style.display = S.ad_title ? '' : 'none'; }
    if (textEl)  textEl.innerHTML = escH(S.ad_content).replace(/\n/g, '<br>');
    // Pricing
    set('rv-base',  fmt(S.base_amount));
    set('rv-gst',   fmt(S.gst_amount));
    set('rv-total', fmt(S.total_amount));
    const discRow = document.getElementById('rv-discount-row');
    const discEl  = document.getElementById('rv-discount');
    if (S.discount_amount > 0 && discRow) {
      discRow.style.display = '';
      if (discEl) discEl.textContent = '-' + fmt(S.discount_amount);
    } else if (discRow) discRow.style.display = 'none';
    // Reset submit
    const submitBtn = document.getElementById('nas-submit-btn');
    const terms = document.getElementById('nas-terms-check');
    if (submitBtn) submitBtn.disabled = true;
    if (terms) { terms.checked = false; }
  }

  /* ══════════════════════════════════════════════════════════════════════
     SUBMIT
  ══════════════════════════════════════════════════════════════════════ */
  async function submitBooking() {
    const submitBtn = document.getElementById('nas-submit-btn');
    const spinner   = document.getElementById('nas-submit-spinner');
    const errEl     = document.getElementById('nas-submit-error');

    // Pre-flight client-side validation
    if (!S.client_name || !S.client_name.trim()) {
      if (errEl) { errEl.textContent = 'Please go back to Step 10 and enter your name.'; errEl.style.display = ''; }
      return;
    }
    if (!S.client_email || !/^[^@]+@[^@]+\.[^@]+$/.test(S.client_email)) {
      if (errEl) { errEl.textContent = 'Please go back to Step 10 and enter a valid email address.'; errEl.style.display = ''; }
      return;
    }
    if (!S.client_phone || !/^[6-9][0-9]{9}$/.test(S.client_phone.replace(/\D/g, ''))) {
      if (errEl) { errEl.textContent = 'Please go back to Step 10 and enter a valid 10-digit Indian mobile number.'; errEl.style.display = ''; }
      return;
    }
    if (!S.newspaper_id) {
      if (errEl) { errEl.textContent = 'Please go back to Step 4 and select a newspaper.'; errEl.style.display = ''; }
      return;
    }
    if (!S.ad_content || !S.ad_content.trim()) {
      if (errEl) { errEl.textContent = 'Please go back to Step 7 and enter your ad content.'; errEl.style.display = ''; }
      return;
    }

    if (submitBtn) submitBtn.disabled = true;
    if (spinner)   spinner.style.display = 'inline-flex';
    if (errEl)     errEl.style.display = 'none';

    const payload = {
      category_id:      S.category_id,
      category_name:    S.category_name,
      city_id:          S.city_id,
      city_name:        S.city_name,
      newspaper_id:     S.newspaper_id,
      newspaper_name:   S.newspaper_name,
      multi_newspapers: S._selectedNewspapers.length > 1
                          ? JSON.stringify(S._selectedNewspapers.map(p => ({id:p.id, name:p.name, edition:S._editionsByPaper[p.id]||S.edition})))
                          : '',
      edition:          S.edition,
      ad_type:          S.ad_type || 'classified',
      ad_title:         S.ad_title,
      ad_content:       S.ad_content,
      word_count:       S.word_count,
      width_cm:         S.width_cm,
      height_cm:        S.height_cm,
      publish_date:     S.publish_date,
      base_amount:      S.base_amount   || 0,
      discount_amount:  S.discount_amount || 0,
      gst_amount:       S.gst_amount    || 0,
      total_amount:     S.total_amount  || 0,
      combo_id:         S.combo_id,
      coupon_code:      S.coupon_code,
      client_name:      S.client_name,
      client_phone:     S.client_phone,
      client_email:     S.client_email,
      client_city:      S.client_city,
      client_company:   S.client_company,
      client_gst:       S.client_gst,
      client_notes:     S.client_notes,
      whatsapp_optin:   S.whatsapp_optin ? 1 : 0,
    };

    try {
      const res = await ajax('nas_create_booking', payload);

      if (!res || typeof res !== 'object') {
        throw new Error('Invalid server response. Please try again.');
      }

      if (res.success) {
        const data = res.data || {};
        // No popup — go straight to the dedicated confirmation page with the
        // real booking reference so the client lands on a full, shareable page.
        const params = new URLSearchParams();
        if (data.id)  params.set('booking_id', data.id);
        if (data.uid) params.set('ref', data.uid);
        const base = CFG.confirmationUrl || '/booking-confirmation/';
        const sep  = base.includes('?') ? '&' : '?';
        window.location.href = params.toString() ? (base + sep + params.toString()) : base;
        return;

      } else {
        const msg = res.data?.message || 'Something went wrong. Please try again.';
        if (errEl) { errEl.textContent = msg; errEl.style.display = ''; }
        if (submitBtn) submitBtn.disabled = false;
      }

    } catch (e) {
      const msg = (e && e.message && e.message.length > 3)
        ? e.message
        : 'Network error. Please check your connection and retry.';
      if (errEl) { errEl.textContent = msg; errEl.style.display = ''; }
      if (submitBtn) submitBtn.disabled = false;
    }

    if (spinner) spinner.style.display = 'none';
  }

  /* ══════════════════════════════════════════════════════════════════════
     HELPERS
  ══════════════════════════════════════════════════════════════════════ */
  function escH(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  }
  function truncate(str, n) { return str.length > n ? str.slice(0, n) + '…' : str; }
  function fmtCirc(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 100000)  return (n / 100000).toFixed(1) + 'L';
    if (n >= 1000)    return (n / 1000).toFixed(0) + 'K';
    return n;
  }
  function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = `nas-toast nas-toast-${type}`;
    t.innerHTML = `<i class="fa-solid fa-${type === 'success' ? 'circle-check' : 'circle-exclamation'}"></i> ${msg}`;
    document.body.appendChild(t);
    setTimeout(() => t.classList.add('show'), 10);
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 3500);
  }

  /* ── Boot ── */
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

})();
