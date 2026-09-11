/**
 * NAS Homepage interactions
 */
(function () {
  'use strict';

  function qs(sel, ctx) {
    return (ctx || document).querySelector(sel);
  }

  function qsa(sel, ctx) {
    return Array.from((ctx || document).querySelectorAll(sel));
  }

  /* Mobile navigation */
  var menuBtn = qs('#nhp-menu-btn');
  var mobileNav = qs('#nhp-mobile-nav');
  var closeBtn = qs('#nhp-mobile-close');

  if (menuBtn && mobileNav) {
    menuBtn.addEventListener('click', function () {
      mobileNav.classList.add('is-open');
      document.body.style.overflow = 'hidden';
    });
  }

  if (closeBtn && mobileNav) {
    closeBtn.addEventListener('click', closeMobileNav);
    mobileNav.addEventListener('click', function (e) {
      if (e.target === mobileNav) closeMobileNav();
    });
  }

  function closeMobileNav() {
    if (mobileNav) {
      mobileNav.classList.remove('is-open');
      document.body.style.overflow = '';
    }
  }

  /* Quick book form */
  window.nhpQuickBook = function () {
    var city = qs('#nhp-qb-city');
    var cat = qs('#nhp-qb-category');
    var np = qs('#nhp-qb-newspaper');
    var base = (window.NAS && NAS.booking_url) || '/book-newspaper-ad/';
    var params = [];
    if (city && city.value) params.push('city=' + encodeURIComponent(city.value));
    if (cat && cat.value) params.push('category=' + encodeURIComponent(cat.value));
    if (np && np.value) params.push('newspaper=' + encodeURIComponent(np.value));
    window.location.href = base + (params.length ? '?' + params.join('&') : '');
  };

  var quickForm = qs('#nhp-quick-book');
  if (quickForm) {
    quickForm.addEventListener('submit', function (e) {
      e.preventDefault();
      window.nhpQuickBook();
    });
  }

  /* Newspaper marketplace search & filter */
  var paperSearch = qs('#nhp-paper-search');
  var paperCards = qsa('.nhp-paper-card');
  var filterChips = qsa('.nhp-filter-chip');
  var papersEmpty = qs('#nhp-papers-empty');
  var activeLang = 'all';

  function filterPapers() {
    var q = paperSearch ? paperSearch.value.toLowerCase().trim() : '';
    var visible = 0;

    paperCards.forEach(function (card) {
      var name = (card.dataset.name || '').toLowerCase();
      var lang = (card.dataset.lang || '').toLowerCase();
      var matchSearch = !q || name.indexOf(q) !== -1 || lang.indexOf(q) !== -1;
      var matchLang = activeLang === 'all' || lang === activeLang;
      var show = matchSearch && matchLang;
      card.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    if (papersEmpty) {
      papersEmpty.style.display = visible ? 'none' : '';
    }
  }

  if (paperSearch) {
    paperSearch.addEventListener('input', filterPapers);
  }

  filterChips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      filterChips.forEach(function (c) { c.classList.remove('is-active'); });
      chip.classList.add('is-active');
      activeLang = chip.dataset.lang || 'all';
      filterPapers();
    });
  });

  /* City search */
  var citySearch = qs('#nhp-city-search');
  var cityTags = qsa('.nhp-city-tag');

  if (citySearch) {
    citySearch.addEventListener('input', function () {
      var q = citySearch.value.toLowerCase().trim();
      cityTags.forEach(function (tag) {
        var name = (tag.dataset.name || '').toLowerCase();
        tag.style.display = !q || name.indexOf(q) !== -1 ? '' : 'none';
      });
    });
  }

  /* FAQ accordion — single open at a time */
  qsa('.nhp-faq-question').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var item = btn.closest('.nhp-faq-item');
      if (!item) return;
      var wasOpen = item.classList.contains('is-open');
      qsa('.nhp-faq-item').forEach(function (i) { i.classList.remove('is-open'); });
      if (!wasOpen) item.classList.add('is-open');
    });
  });

  /* FAQ search */
  var faqSearch = qs('#nhp-faq-search');
  var faqItems = qsa('.nhp-faq-item');

  if (faqSearch) {
    faqSearch.addEventListener('input', function () {
      var q = faqSearch.value.toLowerCase().trim();
      faqItems.forEach(function (item) {
        var text = (item.textContent || '').toLowerCase();
        item.style.display = !q || text.indexOf(q) !== -1 ? '' : 'none';
      });
    });
  }

  /* Booking panel step highlight on focus */
  var bookingFields = [
    { el: '#nhp-qb-city', step: 0 },
    { el: '#nhp-qb-category', step: 1 },
    { el: '#nhp-qb-newspaper', step: 2 }
  ];

  bookingFields.forEach(function (field) {
    var el = qs(field.el);
    if (!el) return;
    el.addEventListener('focus', function () {
      qsa('.nhp-booking-panel__step').forEach(function (s, i) {
        s.classList.toggle('is-active', i === field.step);
      });
    });
  });
})();
