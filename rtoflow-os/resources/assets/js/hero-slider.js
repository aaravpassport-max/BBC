/**
 * Home Hero Section — Slider/Swiper engine (vanilla JS, no CDN dependency).
 *
 * Markup contract (rendered server-side by resources/views/public/home.php):
 *   <section class="hs-hero" id="hsHero" style="--hs-d-height:...px; ...">
 *     <script type="application/json" id="hsHeroSettings">{...}</script>
 *     <div class="hs-track">
 *       <div class="hs-slide hs-active" data-index="0"> ... </div>
 *       <div class="hs-slide" data-index="1"> ... </div>
 *     </div>
 *     <button class="hs-arrow hs-arrow-prev">‹</button>
 *     <button class="hs-arrow hs-arrow-next">›</button>
 *     <div class="hs-dots"><button class="hs-dot hs-active" data-index="0"></button>...</div>
 *   </section>
 *
 * Desktop vs Mobile is decided purely by the admin-configured breakpoint
 * (matchMedia), independent of touch capability, so behaviour always
 * matches the viewport the visitor is actually looking at — exactly the
 * same signal the CSS itself already uses to swap the desktop/mobile
 * <img> elements (see the inline <style> block home.php renders next to
 * this markup), so image and behaviour never disagree about which device
 * is "active".
 */
(function () {
  'use strict';

  function initHero(hero) {
    var settingsEl = hero.querySelector('#hsHeroSettings, .hs-settings-data');
    var settings;
    try { settings = JSON.parse(settingsEl ? settingsEl.textContent : '{}'); } catch (e) { settings = {}; }

    var breakpoint = parseInt(settings.breakpoint, 10) || 760;
    var mq = window.matchMedia('(max-width:' + breakpoint + 'px)');

    var track = hero.querySelector('.hs-track');
    var slides = Array.prototype.slice.call(hero.querySelectorAll('.hs-slide'));
    var dots = Array.prototype.slice.call(hero.querySelectorAll('.hs-dot'));
    var prevBtn = hero.querySelector('.hs-arrow-prev');
    var nextBtn = hero.querySelector('.hs-arrow-next');

    if (slides.length === 0) return;

    if (slides.length <= 1) {
      hero.setAttribute('data-single', '1');
      return; // Nothing to animate/navigate with exactly one slide.
    }

    var current = slides.findIndex(function (s) { return s.classList.contains('hs-active'); });
    if (current < 0) current = 0;

    var timer = null;
    var device = null; // 'desktop' | 'mobile'

    function deviceSettings() {
      return (device === 'mobile' ? settings.mobile : settings.desktop) || {};
    }

    function applyDeviceChrome() {
      var d = deviceSettings();
      hero.setAttribute('data-effect', d.transition_effect || 'fade');
      hero.style.setProperty('--hs-speed', (parseInt(d.transition_speed, 10) || 600) + 'ms');

      var showArrows = !!d.nav_arrows;
      var showDots = !!d.pagination_dots;
      if (prevBtn) prevBtn.style.display = showArrows ? '' : 'none';
      if (nextBtn) nextBtn.style.display = showArrows ? '' : 'none';
      var dotsWrap = hero.querySelector('.hs-dots');
      if (dotsWrap) dotsWrap.style.display = showDots ? '' : 'none';
    }

    function goTo(index, userInitiated) {
      var d = deviceSettings();
      var loop = d.loop !== false;
      var total = slides.length;

      if (index < 0) index = loop ? total - 1 : 0;
      if (index >= total) index = loop ? 0 : total - 1;
      if (index === current) return;

      var prevSlide = slides[current];
      var nextSlide = slides[index];

      prevSlide.classList.remove('hs-active');
      prevSlide.classList.add('hs-leaving');
      nextSlide.classList.add('hs-active');

      if (dots[current]) { dots[current].classList.remove('hs-active'); dots[current].setAttribute('aria-selected', 'false'); }
      if (dots[index]) { dots[index].classList.add('hs-active'); dots[index].setAttribute('aria-selected', 'true'); }

      var speed = parseInt(d.transition_speed, 10) || 600;
      window.setTimeout(function () { prevSlide.classList.remove('hs-leaving'); }, speed + 40);

      current = index;

      if (userInitiated) restartAutoplay();
    }

    function stopAutoplay() {
      if (timer) { window.clearInterval(timer); timer = null; }
    }

    function startAutoplay() {
      stopAutoplay();
      var d = deviceSettings();
      if (!d.autoplay) return;
      var interval = parseInt(d.autoplay_interval, 10) || 5000;
      timer = window.setInterval(function () { goTo(current + 1, false); }, interval);
    }

    function restartAutoplay() { startAutoplay(); }

    // ── Controls ──────────────────────────────────────────────────────
    if (nextBtn) nextBtn.addEventListener('click', function () { goTo(current + 1, true); });
    if (prevBtn) prevBtn.addEventListener('click', function () { goTo(current - 1, true); });
    dots.forEach(function (dot, i) {
      dot.addEventListener('click', function () { goTo(i, true); });
    });

    // ── Pause on hover (Desktop only, per its own setting) ──────────────
    hero.addEventListener('mouseenter', function () {
      if (device === 'desktop' && deviceSettings().pause_on_hover) stopAutoplay();
    });
    hero.addEventListener('mouseleave', function () {
      if (device === 'desktop' && deviceSettings().pause_on_hover) startAutoplay();
    });

    // ── Swipe / touch ────────────────────────────────────────────────
    var touchStartX = null;
    var touchStartY = null;
    hero.addEventListener('touchstart', function (e) {
      if (!deviceSettings().swipe) return;
      var t = e.changedTouches[0];
      touchStartX = t.clientX; touchStartY = t.clientY;
    }, { passive: true });
    hero.addEventListener('touchend', function (e) {
      if (!deviceSettings().swipe || touchStartX === null) return;
      var t = e.changedTouches[0];
      var dx = t.clientX - touchStartX;
      var dy = t.clientY - touchStartY;
      touchStartX = null; touchStartY = null;
      // Ignore mostly-vertical swipes (page scroll intent), require a
      // deliberate horizontal gesture before treating it as slide navigation.
      if (Math.abs(dx) < 40 || Math.abs(dx) < Math.abs(dy)) return;
      goTo(current + (dx < 0 ? 1 : -1), true);
    }, { passive: true });

    // ── Keyboard nav when the hero has focus ────────────────────────
    hero.setAttribute('tabindex', hero.getAttribute('tabindex') || '-1');
    hero.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') goTo(current + 1, true);
      if (e.key === 'ArrowLeft') goTo(current - 1, true);
    });

    // ── Device switch (desktop <-> mobile) ──────────────────────────
    function handleDeviceChange(matches) {
      device = matches ? 'mobile' : 'desktop';
      applyDeviceChrome();
      startAutoplay();
    }
    handleDeviceChange(mq.matches);
    if (typeof mq.addEventListener === 'function') {
      mq.addEventListener('change', function (e) { handleDeviceChange(e.matches); });
    } else if (typeof mq.addListener === 'function') {
      // Older Safari fallback.
      mq.addListener(function (e) { handleDeviceChange(e.matches); });
    }

    // Stop autoplay entirely once the hero scrolls off-screen (perf — no
    // point animating/transitioning slides nobody can see) and resume when
    // it's back in view.
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) startAutoplay(); else stopAutoplay();
        });
      }, { threshold: 0.15 });
      io.observe(hero);
    }
  }

  function init() {
    var heroes = document.querySelectorAll('.hs-hero');
    heroes.forEach ? heroes.forEach(initHero) : Array.prototype.forEach.call(heroes, initHero);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
