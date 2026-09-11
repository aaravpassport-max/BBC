/**
 * NAS Portal Pages — FAQ, contact, and track-order inits (SPA-safe).
 */
(function () {
  'use strict';

  var STATUS_LABELS = {
    booking_received: 'Booking Received',
    under_review: 'Under Review',
    payment_received: 'Payment Confirmed',
    material_uploaded: 'Material Uploaded',
    material_approved: 'Material Approved',
    material_rejected: 'Material Rejected',
    submitted_to_paper: 'Submitted to Newspaper',
    pub_date_confirmed: 'Publication Date Confirmed',
    published: 'Ad Published',
    proof_delivered: 'Proof Delivered',
    proof_ready: 'Proof Ready',
    completed: 'Order Completed',
    cancelled: 'Cancelled',
    on_hold: 'On Hold'
  };

  var STATUS_ORDER = [
    'booking_received', 'under_review', 'payment_received', 'material_uploaded',
    'material_approved', 'submitted_to_paper', 'pub_date_confirmed', 'published',
    'proof_delivered', 'completed'
  ];

  function escHtml(t) {
    return (t || '').toString()
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function post(action, data) {
    if (typeof window.nasAjax === 'function' && window.NAS) {
      return window.nasAjax(action, data || {});
    }
    return new Promise(function (resolve, reject) {
      if (!window.jQuery || !window.NAS) {
        reject(new Error('NAS scripts not loaded'));
        return;
      }
      var payload = Object.assign({ action: action, nonce: NAS.nonce }, data || {});
      jQuery.post(NAS.ajax_url, payload, function (r) {
        if (r && r.success) resolve(r.data);
        else reject(new Error((r && r.data && r.data.message) || 'Request failed'));
      }).fail(function () {
        reject(new Error('Network error'));
      });
    });
  }

  function initStaticFaq(root) {
    root.querySelectorAll('.nas-faq-q').forEach(function (q) {
      if (q.getAttribute('onclick')) return;
      if (q.dataset.nasFaqBound === '1') return;
      q.dataset.nasFaqBound = '1';
      q.addEventListener('click', function () {
        if (typeof window.nasTogglePortalFaq === 'function') {
          window.nasTogglePortalFaq(q);
        }
      });
    });
  }

  function initFaqPage(root) {
    if (root.dataset.nasPageInit === '1') return;
    root.dataset.nasPageInit = '1';

    var allFaqs = [];
    var currentCat = '';
    var accordion = root.querySelector('#nas-accordion');
    var catEl = root.querySelector('#nas-faq-cats');
    var searchEl = root.querySelector('#nas-faq-search');
    if (!accordion || !catEl) return;

    function renderFaqs(faqs) {
      if (!faqs.length) {
        accordion.innerHTML = '<div style="padding:32px;text-align:center;color:#94a3b8">No questions match your search.</div>';
        return;
      }
      accordion.innerHTML = faqs.map(function (f, i) {
        var num = String(i + 1).padStart(2, '0');
        var ci = i % 6;
        return '<div class="nas-faq-item nas-faq-item--c' + ci + '">' +
          '<button type="button" class="nas-faq-q" aria-expanded="false">' +
          '<span class="nas-faq-q__num">' + num + '</span>' +
          '<span class="nas-faq-q__text">' + escHtml(f.question) + '</span>' +
          '<i class="fa-solid fa-chevron-down nas-faq-q__chevron"></i>' +
          '</button>' +
          '<div class="nas-faq-a">' + f.answer + '</div></div>';
      }).join('');
      initStaticFaq(accordion);
    }

    function filterFaqs() {
      var q = (searchEl && searchEl.value || '').toLowerCase();
      var filtered = allFaqs.filter(function (f) {
        var matchCat = !currentCat || f.category === currentCat;
        var matchQ = !q ||
          f.question.toLowerCase().indexOf(q) !== -1 ||
          f.answer.toLowerCase().indexOf(q) !== -1;
        return matchCat && matchQ;
      });
      renderFaqs(filtered);
    }

    function setCat(btn, cat) {
      catEl.querySelectorAll('.nas-faq-cat').forEach(function (e) {
        e.classList.remove('active');
      });
      btn.classList.add('active');
      currentCat = cat;
      filterFaqs();
    }

    catEl.addEventListener('click', function (e) {
      var btn = e.target.closest('.nas-faq-cat');
      if (!btn) return;
      setCat(btn, btn.dataset.cat || '');
    });

    if (searchEl) {
      searchEl.addEventListener('input', filterFaqs);
    }

    post('nas_get_faqs', {}).then(function (data) {
      allFaqs = data.faqs || [];
      var cats = [];
      allFaqs.forEach(function (f) {
        if (f.category && cats.indexOf(f.category) === -1) cats.push(f.category);
      });
      catEl.innerHTML = '<button type="button" class="nas-faq-cat active" data-cat="">All</button>' +
        cats.map(function (c) {
          return '<button type="button" class="nas-faq-cat" data-cat="' + escHtml(c) + '">' + escHtml(c) + '</button>';
        }).join('');
      renderFaqs(allFaqs);
    }).catch(function () {
      accordion.innerHTML = '<div style="padding:24px;color:#94a3b8;text-align:center">Unable to load questions. Please refresh.</div>';
    });
  }

  function initContactPage(root) {
    if (root.dataset.nasPageInit === '1') return;
    root.dataset.nasPageInit = '1';

    var btn = root.querySelector('#nas-c-submit');
    if (!btn || btn.dataset.nasBound === '1') return;
    btn.dataset.nasBound = '1';

    btn.addEventListener('click', function () {
      var name = (root.querySelector('#nas-c-name') || {}).value || '';
      var email = (root.querySelector('#nas-c-email') || {}).value || '';
      var phone = (root.querySelector('#nas-c-phone') || {}).value || '';
      var city = (root.querySelector('#nas-c-city') || {}).value || '';
      var subject = (root.querySelector('#nas-c-subject') || {}).value || '';
      var message = (root.querySelector('#nas-c-message') || {}).value || '';
      var errEl = root.querySelector('#nas-contact-error');
      var sucEl = root.querySelector('#nas-c-success');

      name = name.trim();
      email = email.trim();
      phone = phone.trim();
      city = city.trim();
      message = message.trim();

      if (errEl) errEl.style.display = 'none';
      if (sucEl) sucEl.style.display = 'none';

      var missing = [];
      if (!name) missing.push('Name');
      if (!email || !/^[^@]+@[^@]+\.[^@]+$/.test(email)) missing.push('a valid Email address');
      if (!message || message.length < 10) missing.push('Message (at least 10 characters)');
      if (missing.length) {
        if (errEl) {
          errEl.textContent = 'Please enter: ' + missing.join(', ') + '.';
          errEl.style.display = 'block';
        }
        return;
      }

      btn.disabled = true;
      btn.innerHTML = 'Sending… <i class="fa-solid fa-spinner fa-spin"></i>';

      post('nas_submit_contact', { name: name, email: email, phone: phone, city: city, subject: subject, message: message })
        .then(function (data) {
          btn.disabled = false;
          btn.innerHTML = 'Send Message <i class="fa-solid fa-paper-plane"></i>';
          if (sucEl) {
            sucEl.textContent = data.message || 'Message sent.';
            sucEl.style.display = 'block';
          }
          ['nas-c-name', 'nas-c-email', 'nas-c-phone', 'nas-c-city', 'nas-c-message'].forEach(function (id) {
            var el = root.querySelector('#' + id);
            if (el) el.value = '';
          });
          var subj = root.querySelector('#nas-c-subject');
          if (subj) subj.value = '';
        })
        .catch(function (err) {
          btn.disabled = false;
          btn.innerHTML = 'Send Message <i class="fa-solid fa-paper-plane"></i>';
          if (errEl) {
            errEl.textContent = err.message || 'Something went wrong. Please try again.';
            errEl.style.display = 'block';
          }
        });
    });
  }

  function initTrackOrderPage(root) {
    if (root.dataset.nasPageInit === '1') return;
    root.dataset.nasPageInit = '1';

    var btn = root.querySelector('.nas-track-submit');
    if (!btn || btn.dataset.nasBound === '1') return;
    btn.dataset.nasBound = '1';

    function renderResult(b, history) {
      var resultEl = root.querySelector('#nas-result');
      var status = b.status;
      if (resultEl) resultEl.style.display = 'block';

      var badge = root.querySelector('#nas-status-badge');
      if (badge) badge.textContent = STATUS_LABELS[status] || status;

      if (b.proof_url) {
        var img = root.querySelector('#nas-proof-img');
        var link = root.querySelector('#nas-proof-link');
        var proofWrap = root.querySelector('#nas-track-proof');
        var proofActions = root.querySelector('#nas-proof-actions');
        if (img) img.src = b.proof_url;
        if (link) link.href = b.proof_url;
        if (proofWrap) proofWrap.style.display = 'block';
        if (proofActions) proofActions.style.display = b.status === 'proof_ready' ? 'block' : 'none';
      }

      var infoEl = root.querySelector('#nas-booking-info');
      if (infoEl) {
        infoEl.innerHTML = [
          ['Order ID', '#' + b.uid],
          ['Newspaper', b.newspaper_name],
          ['City', b.city_name],
          ['Category', b.category_name],
          ['Email', b.client_email]
        ].map(function (r) {
          return '<div class="nas-bi"><strong>' + r[0] + '</strong>' + escHtml(r[1]) + '</div>';
        }).join('');
      }

      var currentIdx = STATUS_ORDER.indexOf(status);
      var timeline = root.querySelector('#nas-timeline');
      if (!timeline) return;
      timeline.innerHTML = '';
      STATUS_ORDER.forEach(function (s, i) {
        if (i > currentIdx && s !== status) return;
        var isDone = i < currentIdx;
        var isCurrent = s === status;
        var dotClass = isDone ? 'done' : (isCurrent ? 'current' : '');
        var histItem = (history || []).find(function (h) { return h.status === s; });
        var time = histItem ? new Date(histItem.time).toLocaleDateString('en-IN', {
          day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
        }) : '';
        var note = histItem && histItem.note ? ' — ' + escHtml(histItem.note) : '';
        timeline.innerHTML += '<div class="nas-timeline-item"><div class="nas-timeline-dot ' + dotClass + '"></div>' +
          '<div class="nas-timeline-content"><h4>' + (STATUS_LABELS[s] || s) + '</h4><p>' + (time || '') + note + '</p></div></div>';
      });
    }

    btn.addEventListener('click', function () {
      var oid = ((root.querySelector('#nas-oid') || {}).value || '').trim();
      var email = ((root.querySelector('#nas-temail') || {}).value || '').trim();
      var errEl = root.querySelector('#nas-track-error');
      if (errEl) errEl.style.display = 'none';

      if (!oid || !email) {
        if (errEl) {
          errEl.textContent = 'Please enter both Order ID and Email';
          errEl.style.display = 'block';
        }
        return;
      }

      post('nas_track_order', { order_id: oid, email: email })
        .then(function (data) {
          renderResult(data.booking, data.history || []);
        })
        .catch(function (err) {
          if (errEl) {
            errEl.textContent = err.message || 'Order not found';
            errEl.style.display = 'block';
          }
          var resultEl = root.querySelector('#nas-result');
          if (resultEl) resultEl.style.display = 'none';
        });
    });
  }

  window.nasInitPortalPage = function (container) {
    container = container || document.getElementById('nas-main-content') || document;
    var page = container.querySelector('[data-portal-page]');
    if (!page) {
      initStaticFaq(container);
      return;
    }

    var type = page.getAttribute('data-portal-page');
    if (type === 'faq') initFaqPage(page);
    else if (type === 'contact') {
      initContactPage(page);
      initStaticFaq(page);
    } else if (type === 'track-order') initTrackOrderPage(page);
    else initStaticFaq(container);
  };

  document.addEventListener('DOMContentLoaded', function () {
    window.nasInitPortalPage(document.getElementById('nas-main-content'));
  });
})();
