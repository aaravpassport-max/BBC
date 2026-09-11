/**
 * NAS Animations — Lenis smooth scroll + GSAP ScrollTrigger
 */
(function () {
  'use strict';

  function initLenis() {
    if (typeof Lenis === 'undefined') return;
    const lenis = new Lenis({ lerp: 0.08, smoothWheel: true });
    function raf(time) { lenis.raf(time); requestAnimationFrame(raf); }
    requestAnimationFrame(raf);

    // GSAP ticker sync
    if (typeof gsap !== 'undefined') {
      gsap.ticker.add((time) => { lenis.raf(time * 1000); });
      gsap.ticker.lagSmoothing(0);
    }
    window.nasLenis = lenis;
  }

  function initGSAP() {
    if (typeof gsap === 'undefined' || typeof ScrollTrigger === 'undefined') return;
    gsap.registerPlugin(ScrollTrigger);

    // ── City Landing Page ─────────────────────────────────────────────────
    if (document.querySelector('.nas-city-hero')) {
      // Hero entrance
      gsap.from('.nas-city-hero__eyebrow', { y: 20, opacity: 0, duration: 0.6, delay: 0.1 });
      gsap.from('.nas-city-hero__title', { y: 30, opacity: 0, duration: 0.7, delay: 0.25 });
      gsap.from('.nas-city-hero__badges', { y: 20, opacity: 0, duration: 0.6, delay: 0.4 });
      gsap.from('.nas-city-hero__cta', { y: 20, opacity: 0, duration: 0.6, delay: 0.55 });

      // Feature strip items
      gsap.from('.nas-features-strip .nas-feature-item', {
        scrollTrigger: { trigger: '.nas-features-strip', start: 'top 85%' },
        y: 24, opacity: 0, duration: 0.5, stagger: 0.1,
      });

      // Content section
      gsap.from('.nas-city-content', {
        scrollTrigger: { trigger: '.nas-city-content', start: 'top 80%' },
        y: 30, opacity: 0, duration: 0.6,
      });

      // Newspaper grid
      gsap.from('.nas-newspaper-card', {
        scrollTrigger: { trigger: '.nas-newspaper-grid', start: 'top 80%' },
        y: 20, opacity: 0, duration: 0.45, stagger: 0.07,
      });

      // Category items
      gsap.from('.nas-category-item', {
        scrollTrigger: { trigger: '.nas-categories-strip', start: 'top 80%' },
        scale: 0.9, opacity: 0, duration: 0.4, stagger: 0.06,
      });

      // FAQ items
      gsap.from('.nas-faq-item', {
        scrollTrigger: { trigger: '.nas-faq-section', start: 'top 80%' },
        y: 16, opacity: 0, duration: 0.4, stagger: 0.08,
      });

      // CTA band
      gsap.from('.nas-cta-band', {
        scrollTrigger: { trigger: '.nas-cta-band', start: 'top 85%' },
        y: 20, opacity: 0, duration: 0.6,
      });
    }

    // ── Booking Wizard ────────────────────────────────────────────────────
    if (document.querySelector('.nas-wizard-outer')) {
      gsap.from('.nas-wizard-outer', { y: 40, opacity: 0, duration: 0.7, ease: 'power2.out' });

      // Category cards entrance (triggered by wizard showing step)
      window.nasAnimateGrid = function (selector) {
        gsap.from(selector, { y: 18, opacity: 0, duration: 0.35, stagger: 0.05, ease: 'power1.out' });
      };

      // Step progress bar
      window.nasAnimateStep = function (stepEl) {
        if (!stepEl) return;
        gsap.from(stepEl, { opacity: 0, x: 20, duration: 0.4, ease: 'power2.out' });
      };
    }

    // ── Generic scroll-reveal for any page ───────────────────────────────
    document.querySelectorAll('.nas-reveal').forEach(el => {
      gsap.from(el, {
        scrollTrigger: { trigger: el, start: 'top 85%' },
        y: 24, opacity: 0, duration: 0.6,
      });
    });

    // Stat counter animation (client dashboard)
    document.querySelectorAll('.nas-stat-card[data-count]').forEach(el => {
      const target = parseFloat(el.dataset.count) || 0;
      const isCurrency = el.dataset.currency === '1';
      ScrollTrigger.create({
        trigger: el,
        start: 'top 90%',
        once: true,
        onEnter() {
          const obj = { val: 0 };
          gsap.to(obj, {
            val: target,
            duration: 1.2,
            ease: 'power1.out',
            onUpdate() {
              const v = isCurrency ? nasFmt.currency(obj.val) : Math.round(obj.val);
              el.querySelector('.nas-stat-value').textContent = v;
            },
          });
        },
      });
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    initLenis();
    initGSAP();
  });
})();
