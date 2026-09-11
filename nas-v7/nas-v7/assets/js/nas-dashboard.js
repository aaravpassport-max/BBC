/**
 * NAS Client Dashboard
 */
(function () {
  'use strict';

  const Dashboard = {
    page: 1,
    perPage: 10,
    filters: { status: '', search: '' },
    notifPage: 1,
    notifPollTimer: null,

    async init() {
      await this.loadStats();
      await this.loadBookings();
      this.bindFilters();
      this.bindDrawer();
      this.startNotifPolling();
      this.bindProfileForm();
    },

    // ── Stats ─────────────────────────────────────────────────────────────
    async loadStats() {
      try {
        const data = await nasAjax('nas_get_dashboard_stats', {});
        this.renderStats(data);
      } catch (_) {}
    },

    renderStats(data) {
      const map = {
        'stat-total': data.total_bookings,
        'stat-active': data.active_bookings,
        'stat-completed': data.completed_bookings,
        'stat-spent': nasFmt ? nasFmt.currency(data.total_spent) : data.total_spent,
      };
      Object.entries(map).forEach(([id, val]) => {
        const el = document.getElementById(id);
        if (el) el.textContent = val ?? '—';
      });
    },

    // ── Bookings List ─────────────────────────────────────────────────────
    async loadBookings(page = 1) {
      this.page = page;
      const container = document.getElementById('nas-bookings-list');
      if (!container) return;
      container.innerHTML = '<div class="nas-spinner-wrap"><div class="nas-spinner"></div></div>';

      try {
        const data = await nasAjax('nas_get_client_bookings', {
          page: this.page,
          per_page: this.perPage,
          status: this.filters.status,
          search: this.filters.search,
        });
        this.renderBookings(data.bookings || [], container);
        this.renderPagination(data.total || 0);
      } catch (_) {
        container.innerHTML = '<div class="nas-empty-state"><p>Failed to load bookings.</p></div>';
      }
    },

    renderBookings(bookings, container) {
      if (!bookings.length) {
        container.innerHTML = `<div class="nas-empty-state">
          <i class="fa-regular fa-folder-open"></i>
          <p>No bookings found.</p>
          <a href="${NAS.booking_url || '#'}" class="nas-btn nas-btn-primary">Book an Ad</a>
        </div>`;
        return;
      }

      container.innerHTML = bookings.map(b => `
        <div class="nas-booking-row" data-booking-id="${b.id}">
          <div class="nas-booking-row__left">
            <div class="nas-booking-row__id">#${b.booking_uid}</div>
            <div class="nas-booking-row__meta">
              <span>${b.newspaper_name || '—'}</span>
              <span class="nas-dot"></span>
              <span>${b.city_name || '—'}</span>
              <span class="nas-dot"></span>
              <span>${b.category_name || '—'}</span>
            </div>
            <div class="nas-booking-row__date">${nasFmt ? nasFmt.date(b.submitted_at || b.created_at) : (b.submitted_at || b.created_at)}</div>
          </div>
          <div class="nas-booking-row__right">
            <span class="nas-status-pill" data-status="${b.status}">${this.statusLabel(b.status)}</span>
            <span class="nas-booking-row__amount">${nasFmt ? nasFmt.currency(b.total_amount) : b.total_amount}</span>
            <button class="nas-btn nas-btn-ghost nas-btn-sm nas-open-drawer" data-id="${b.id}">
              View <i class="fa-solid fa-chevron-right"></i>
            </button>
          </div>
        </div>`).join('');
    },

    renderPagination(total) {
      const wrap = document.getElementById('nas-bookings-pagination');
      if (!wrap || !nasPagination) return;
      nasPagination(wrap, total, this.page, this.perPage, (p) => this.loadBookings(p));
    },

    // ── Filters ───────────────────────────────────────────────────────────
    bindFilters() {
      const statusSel = document.getElementById('nas-filter-status');
      const searchIn = document.getElementById('nas-filter-search');

      statusSel?.addEventListener('change', () => {
        this.filters.status = statusSel.value;
        this.loadBookings(1);
      });

      let debounce;
      searchIn?.addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => {
          this.filters.search = searchIn.value.trim();
          this.loadBookings(1);
        }, 400);
      });
    },

    // ── Detail Drawer ─────────────────────────────────────────────────────
    bindDrawer() {
      document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.nas-open-drawer');
        if (!btn) return;
        const bookingId = btn.dataset.id;
        await this.openDrawer(bookingId);
      });

      document.addEventListener('click', (e) => {
        if (e.target.closest('[data-drawer-close]') || e.target.classList.contains('nas-drawer-backdrop')) {
          nasDrawer.close();
        }
      });
    },

    async openDrawer(bookingId) {
      const drawer = document.getElementById('nas-booking-drawer');
      if (!drawer) return;

      // Show loading
      const body = drawer.querySelector('.nas-drawer-body');
      if (body) body.innerHTML = '<div class="nas-spinner-wrap"><div class="nas-spinner"></div></div>';
      nasDrawer.open('nas-booking-drawer');

      try {
        const data = await nasAjax('nas_get_booking_detail', { booking_id: bookingId });
        this.renderDrawer(data, body);

        // Wire up chat for this booking
        if (window.NASChat) {
          window.NASChat.bookingId = bookingId;
          window.NASChat.loadHistory(data.messages || []);
        }
      } catch (_) {
        if (body) body.innerHTML = '<p class="nas-error">Failed to load booking details.</p>';
      }
    },

    renderDrawer(data, body) {
      const b = data.booking;
      if (!body || !b) return;

      const stages = NAS.workflow_stages || [];
      const currentIdx = stages.indexOf(b.status);

      const stageHtml = stages.map((s, i) => {
        let cls = 'nas-wf-step';
        if (i < currentIdx) cls += ' done';
        else if (i === currentIdx) cls += ' active';
        return `<div class="${cls}" title="${this.statusLabel(s)}">
          <div class="nas-wf-dot"><i class="fa-solid ${i < currentIdx ? 'fa-check' : 'fa-circle'}"></i></div>
          <div class="nas-wf-label">${this.statusLabel(s)}</div>
        </div>`;
      }).join('<div class="nas-wf-connector"></div>');

      body.innerHTML = `
        <div class="nas-drawer-section">
          <div class="nas-drawer-header-row">
            <h3>#${b.booking_uid}</h3>
            <span class="nas-status-pill" data-status="${b.status}">${this.statusLabel(b.status)}</span>
          </div>
          <div class="nas-data-rows">
            <div class="nas-data-row"><span>Newspaper</span><strong>${b.newspaper_name || '—'}</strong></div>
            <div class="nas-data-row"><span>Edition/City</span><strong>${b.city_name || '—'}</strong></div>
            <div class="nas-data-row"><span>Category</span><strong>${b.category_name || '—'}</strong></div>
            <div class="nas-data-row"><span>Publish Date</span><strong>${nasFmt ? nasFmt.date(b.publish_date) : b.publish_date}</strong></div>
            <div class="nas-data-row"><span>Amount</span><strong>${nasFmt ? nasFmt.currency(b.total_amount) : b.total_amount}</strong></div>
          </div>
        </div>

        <div class="nas-drawer-section">
          <h4>Ad Progress</h4>
          <div class="nas-workflow-track">${stageHtml}</div>
        </div>

        <div class="nas-drawer-section">
          <h4>Ad Content</h4>
          <div class="nas-ad-content-box">${b.ad_content ? b.ad_content.replace(/</g,'&lt;') : '—'}</div>
        </div>

        ${data.payments?.length ? `
        <div class="nas-drawer-section">
          <h4>Payments</h4>
          ${data.payments.map(p => `
            <div class="nas-data-row">
              <span>${nasFmt ? nasFmt.date(p.created_at) : p.created_at}</span>
              <strong class="nas-text-success">${nasFmt ? nasFmt.currency(p.amount) : p.amount}</strong>
            </div>`).join('')}
        </div>` : ''}

        <div class="nas-drawer-section nas-drawer-chat-section" id="nas-drawer-chat">
          <h4>Messages</h4>
          <div class="nas-chat-messages" id="nas-chat-messages" style="max-height:260px;overflow-y:auto;"></div>
          <div class="nas-chat-footer">
            <textarea id="nas-chat-input" placeholder="Type a message…" rows="1"></textarea>
            <button class="nas-btn nas-btn-primary" id="nas-chat-send"><i class="fa-solid fa-paper-plane"></i></button>
          </div>
        </div>`;

      // Re-init chat binding for new DOM
      if (window.NASChat) {
        window.NASChat.bookingId = data.booking.id;
        window.NASChat.bindInputArea();
        window.NASChat.loadHistory(data.messages || []);
        window.NASChat.startPolling();
      }
    },

    // ── Notifications ─────────────────────────────────────────────────────
    startNotifPolling() {
      this.loadNotifications();
      this.notifPollTimer = setInterval(() => this.loadNotifications(), 30000);
    },

    async loadNotifications() {
      try {
        const data = await nasAjax('nas_get_notifications', { page: 1, per_page: 20 });
        this.renderNotifications(data.notifications || []);
        const unread = (data.notifications || []).filter(n => !n.is_read).length;
        const badge = document.getElementById('nas-notif-badge');
        if (badge) {
          badge.textContent = unread || '';
          badge.style.display = unread ? 'flex' : 'none';
        }
      } catch (_) {}
    },

    renderNotifications(notifs) {
      const list = document.getElementById('nas-notif-list');
      if (!list) return;
      if (!notifs.length) {
        list.innerHTML = '<div class="nas-empty-state-sm">No notifications yet.</div>';
        return;
      }
      list.innerHTML = notifs.map(n => `
        <div class="nas-notif-item ${n.is_read ? '' : 'unread'}" data-notif-id="${n.id}">
          <div class="nas-notif-icon"><i class="fa-solid fa-bell"></i></div>
          <div class="nas-notif-body">
            <div class="nas-notif-title">${n.title || ''}</div>
            <div class="nas-notif-msg">${n.message || ''}</div>
            <div class="nas-notif-time">${nasFmt ? nasFmt.timeAgo(n.created_at) : ''}</div>
          </div>
        </div>`).join('');

      // Mark read on click
      list.querySelectorAll('.nas-notif-item.unread').forEach(el => {
        el.addEventListener('click', () => {
          nasAjax('nas_mark_notification_read', { notif_id: el.dataset.notifId }).catch(() => {});
          el.classList.remove('unread');
        });
      });
    },

    // ── Profile Form ──────────────────────────────────────────────────────
    bindProfileForm() {
      const form = document.getElementById('nas-profile-form');
      if (!form) return;
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = form.querySelector('[type=submit]');
        if (btn) btn.disabled = true;
        try {
          await nasAjax('nas_update_profile', Object.fromEntries(new FormData(form)));
          nasToast.success('Profile updated!');
        } catch (err) {
          nasToast.error(err.message || 'Update failed.');
        } finally {
          if (btn) btn.disabled = false;
        }
      });
    },

    statusLabel(status) {
      const labels = {
        booking_received: 'Booking Received',
        under_review: 'Under Review',
        ready_to_process: 'Ready to Process',
        documents_received: 'Documents Received',
        payment_received: 'Payment Received',
        ad_processing: 'Ad Processing',
        submitted_to_pub: 'Submitted to Publisher',
        published: 'Published',
        completed: 'Completed',
        not_able_to_process: 'Not Able to Process',
        not_eligible: 'Not Eligible',
        no_service: 'No Service',
        rejected: 'Rejected',
      };
      return labels[status] || status;
    },
  };

  document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('nas-client-dashboard')) Dashboard.init();
  });
})();
