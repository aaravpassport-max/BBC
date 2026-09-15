(function () {
  'use strict';

  var root = document.documentElement;
  root.classList.add('rfh-ready');

  /* Sticky header */
  var header = document.getElementById('rfhHeader');
  if (header) {
    var onScroll = function () {
      header.classList.toggle('is-scrolled', window.scrollY > 8);
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  /* Mobile nav */
  var nav = document.getElementById('rfhNav');
  var toggle = document.getElementById('rfhMenuToggle');
  if (nav && toggle) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function (e) {
      if (!nav.contains(e.target) && !toggle.contains(e.target)) {
        nav.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  /* Quick apply dock */
  var dockGo = document.getElementById('rfhDockGo');
  var dockSvc = document.getElementById('rfhDockService');
  var dockCity = document.getElementById('rfhDockCity');
  if (dockGo && dockSvc) {
    var base = dockGo.getAttribute('data-base') || '/rto-apply/';
    dockGo.addEventListener('click', function (e) {
      var slug = dockSvc.value;
      var city = dockCity ? dockCity.value : '';
      if (!slug && !city) return;
      e.preventDefault();
      var url = base;
      var qs = [];
      if (slug) qs.push('service=' + encodeURIComponent(slug));
      if (city) qs.push('city=' + encodeURIComponent(city));
      if (qs.length) url += (url.indexOf('?') > -1 ? '&' : '?') + qs.join('&');
      window.location.href = url;
    });
    dockSvc.addEventListener('change', function () {
      var step = document.querySelector('.rfh-dock-step[data-step="1"]');
      if (step) step.classList.toggle('is-on', !!dockSvc.value);
    });
    if (dockCity) {
      dockCity.addEventListener('change', function () {
        var step = document.querySelector('.rfh-dock-step[data-step="2"]');
        if (step) step.classList.toggle('is-on', !!dockCity.value);
      });
    }
  }

  /* Service search + category chips */
  var svcSearch = document.getElementById('rfhServiceSearch');
  var svcGrid = document.getElementById('rfhServiceGrid');
  var chips = document.querySelectorAll('.rfh-chip[data-cat]');
  var activeCat = '';

  function filterServices() {
    if (!svcGrid) return;
    var q = (svcSearch && svcSearch.value || '').toLowerCase().trim();
    var cards = svcGrid.querySelectorAll('.rfh-service-card');
    var shown = 0;
    cards.forEach(function (card) {
      var name = (card.dataset.name || '').toLowerCase();
      var cat = (card.dataset.cat || '').toLowerCase();
      var match = (!q || name.indexOf(q) !== -1) && (!activeCat || cat === activeCat.toLowerCase());
      card.style.display = match ? '' : 'none';
      if (match) shown++;
    });
    var empty = document.getElementById('rfhServiceEmpty');
    if (empty) empty.style.display = shown ? 'none' : 'block';
  }

  if (svcSearch) svcSearch.addEventListener('input', filterServices);
  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      var cat = chip.dataset.cat || '';
      if (chip.classList.contains('is-active')) {
        chip.classList.remove('is-active');
        activeCat = '';
      } else {
        chips.forEach(function (c) { c.classList.remove('is-active'); });
        chip.classList.add('is-active');
        activeCat = cat;
      }
      filterServices();
    });
  });

  /* City search */
  var citySearch = document.getElementById('rfhCitySearch');
  if (citySearch) {
    citySearch.addEventListener('input', function () {
      var q = (citySearch.value || '').toLowerCase().trim();
      document.querySelectorAll('#rfhCityTags .rfh-city-tag').forEach(function (el) {
        el.style.display = (!q || (el.dataset.name || '').indexOf(q) !== -1) ? '' : 'none';
      });
    });
  }

  /* FAQ accordion + search */
  var faqList = document.getElementById('rfhFaqList');
  var faqSearch = document.getElementById('rfhFaqSearch');
  if (faqList) {
    faqList.addEventListener('click', function (e) {
      var btn = e.target.closest('.rfh-faq-q');
      if (!btn) return;
      var item = btn.closest('.rfh-faq-item');
      if (!item) return;
      var wasOpen = item.classList.contains('is-open');
      faqList.querySelectorAll('.rfh-faq-item').forEach(function (el) {
        el.classList.remove('is-open');
        var b = el.querySelector('.rfh-faq-q');
        if (b) b.setAttribute('aria-expanded', 'false');
      });
      if (!wasOpen) {
        item.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
      }
    });
  }
  if (faqSearch && faqList) {
    faqSearch.addEventListener('input', function () {
      var q = (faqSearch.value || '').toLowerCase().trim();
      faqList.querySelectorAll('.rfh-faq-item').forEach(function (item) {
        var text = (item.textContent || '').toLowerCase();
        item.style.display = (!q || text.indexOf(q) !== -1) ? '' : 'none';
      });
    });
  }

  /* Scroll reveal */
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var reveals = document.querySelectorAll('.rfh-reveal');
  if (reduce || !('IntersectionObserver' in window)) {
    reveals.forEach(function (el) { el.classList.add('is-in'); });
  } else {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-in');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    reveals.forEach(function (el) { io.observe(el); });
  }
})();
