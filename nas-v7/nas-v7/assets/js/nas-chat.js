/**
 * NAS Chat — Enhanced v3
 * Features: Internal Notes · 3-Way Send (Chat / +Email / +WhatsApp)
 *           Quick Reply Picker · Email & WA badges · Reply threading
 */
(function () {
  'use strict';

  const $ = (sel, ctx) => (ctx || document).querySelector(sel);
  const $$ = (sel, ctx) => [...(ctx || document).querySelectorAll(sel)];
  // Use the globally localized NAS object (set by nas-core.js via wp_localize_script)
  // Fallback chain: NAS object → WordPress admin-ajax path (works on all WP installs)
  const AJAX  = (typeof NAS !== 'undefined' && NAS.ajax_url)  ? NAS.ajax_url  : (window.nasChat?.ajaxUrl  || '/wp-admin/admin-ajax.php');
  const NONCE = (typeof NAS !== 'undefined' && NAS.nonce)     ? NAS.nonce     : (window.nasChat?.nonce    || '');

  function ajax(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', NONCE);
    Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
    // TRACE: chatAjax — adds 15s timeout via AbortController; mirrors nasAjax pattern
    const ctrl = new AbortController();
    const tid  = setTimeout(() => ctrl.abort(), 15000);
    return fetch(AJAX, { method: 'POST', body: fd, signal: ctrl.signal })
      .then(r => { clearTimeout(tid); return r.json(); })
      .catch(e => { clearTimeout(tid); if (e.name === 'AbortError') throw new Error((typeof NAS !== 'undefined' && NAS.i18n && NAS.i18n.chat_timeout) || 'Chat request timed out. Please try again.'); throw e; });
  }

  // ── State ─────────────────────────────────────────────────────────────
  let state = {
    bookingId: null,
    lastId: 0,
    pollTimer: null,
    isInternal: false,
    replyToId: null,
    replyToText: '',
    qrPickerOpen: false,
    qrCategories: [],
    qrAll: [],
    isAdmin: (typeof NAS !== 'undefined') ? ['admin','manager','staff'].includes(NAS.user_role) : (window.nasChat?.isAdmin || false),
  };

  // ── Init ──────────────────────────────────────────────────────────────
  function init() {
    const wrap = document.getElementById('nas-chat-wrap');
    if (!wrap) return;
    state.bookingId = parseInt(wrap.dataset.bookingId || 0);
    if (!state.bookingId) return;

    buildUI(wrap);
    loadMessages();
    startPolling();
    bindEvents();
  }

  // ── Build UI ──────────────────────────────────────────────────────────
  function buildUI(wrap) {
    wrap.innerHTML = `
      <div class="nas-chat" id="nas-chat">
        <div class="nas-chat-header">
          <div class="nas-chat-header-title">
            <i class="fa-solid fa-comments"></i>
            <span>Booking Chat</span>
            <span class="nas-chat-unread-badge" id="nas-chat-unread" style="display:none"></span>
          </div>
          <div class="nas-chat-header-actions">
            ${state.isAdmin ? `<button class="nas-chat-action-btn" id="nas-qr-picker-btn" title="Quick Replies"><i class="fa-solid fa-bolt"></i></button>` : ''}
            <button class="nas-chat-action-btn" id="nas-chat-refresh-btn" title="Refresh"><i class="fa-solid fa-rotate-right"></i></button>
          </div>
        </div>

        <!-- Messages Area -->
        <div class="nas-chat-messages" id="nas-chat-messages">
          <div class="nas-chat-loading" id="nas-chat-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading messages…</div>
        </div>

        <!-- Reply preview -->
        <div class="nas-chat-reply-preview" id="nas-chat-reply-preview" style="display:none">
          <div class="nas-chat-reply-bar">
            <i class="fa-solid fa-reply"></i>
            <span id="nas-reply-preview-text"></span>
          </div>
          <button class="nas-chat-reply-cancel" id="nas-reply-cancel-btn"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <!-- Internal note toggle (admin only) -->
        ${state.isAdmin ? `
        <div class="nas-chat-mode-bar" id="nas-chat-mode-bar">
          <button class="nas-chat-mode-btn ${!state.isInternal ? 'active' : ''}" data-mode="public">
            <i class="fa-solid fa-comment"></i> Public Message
          </button>
          <button class="nas-chat-mode-btn ${state.isInternal ? 'active' : ''}" data-mode="internal">
            <i class="fa-solid fa-lock"></i> Internal Note
          </button>
        </div>` : ''}

        <!-- Composer -->
        <div class="nas-chat-composer" id="nas-chat-composer">
          <div class="nas-composer-textarea-wrap">
            <textarea id="nas-chat-input" class="nas-chat-input"
              placeholder="${state.isInternal ? 'Add internal note (visible to team only)…' : 'Type your message…'}"
              rows="3" aria-label="Message input"></textarea>
          </div>
          <!-- Attachments bar -->
          <div class="nas-composer-toolbar">
            <label class="nas-attach-btn" title="Attach file">
              <i class="fa-solid fa-paperclip"></i>
              <input type="file" id="nas-attach-input" style="display:none" multiple accept="image/*,.pdf,.doc,.docx">
            </label>
            <div class="nas-attach-preview" id="nas-attach-preview"></div>
          </div>
          <!-- 3-way send -->
          <div class="nas-chat-send-group">
            <button class="nas-chat-send-btn" id="nas-send-chat-btn">
              <i class="fa-solid fa-paper-plane"></i> Send
            </button>
            ${state.isAdmin && !state.isInternal ? `
            <button class="nas-chat-send-btn nas-send-email-btn" id="nas-send-email-btn" title="Send + Email">
              <i class="fa-solid fa-envelope"></i> +Email
            </button>
            <button class="nas-chat-send-btn nas-send-wa-btn" id="nas-send-wa-btn" title="Send + WhatsApp">
              <i class="fa-brands fa-whatsapp"></i> +WA
            </button>` : ''}
          </div>
        </div>

        <!-- Quick Reply Picker (admin) -->
        ${state.isAdmin ? `
        <div class="nas-qr-picker" id="nas-qr-picker" style="display:none">
          <div class="nas-qr-picker-header">
            <span><i class="fa-solid fa-bolt"></i> Quick Replies</span>
            <button id="nas-qr-close-btn"><i class="fa-solid fa-xmark"></i></button>
          </div>
          <div class="nas-qr-search">
            <input type="text" id="nas-qr-search" class="nas-input nas-input-sm" placeholder="Search quick replies…">
          </div>
          <div class="nas-qr-cats" id="nas-qr-cats"></div>
          <div class="nas-qr-list" id="nas-qr-list">
            <div class="nas-chat-loading"><i class="fa-solid fa-spinner fa-spin"></i></div>
          </div>
        </div>` : ''}
      </div>
    `;
  }

  // ── Load Messages ─────────────────────────────────────────────────────
  function loadMessages() {
    ajax('nas_get_messages', { booking_id: state.bookingId }).then(res => {
      const msgs = res.data || [];
      const container = document.getElementById('nas-chat-messages');
      if (!container) return;
      container.innerHTML = '';
      if (!msgs.length) {
        container.innerHTML = `<div class="nas-chat-empty"><i class="fa-solid fa-comments"></i><p>No messages yet. Start the conversation.</p></div>`;
        return;
      }
      msgs.forEach(m => appendMessage(m, false));
      state.lastId = msgs.length ? Math.max(...msgs.map(m => parseInt(m.id))) : 0;
      scrollBottom();
      ajax('nas_mark_messages_read', { booking_id: state.bookingId });
    });
  }

  // ── Append Message ────────────────────────────────────────────────────
  function appendMessage(msg, scroll = true) {
    const container = document.getElementById('nas-chat-messages');
    if (!container) return;
    const isMine = state.isAdmin ? msg.sender_role !== 'client' : msg.sender_role === 'client';
    const isInternal = parseInt(msg.is_internal) === 1;
    const sentEmail = parseInt(msg.sent_via_email) === 1;
    const sentWA    = parseInt(msg.sent_via_whatsapp) === 1;

    // Date separator
    const msgDate = msg.created_at ? msg.created_at.split(' ')[0] : '';
    const lastSep = container.querySelector('.nas-chat-date-sep:last-of-type');
    const lastSepDate = lastSep ? lastSep.dataset.date : null;
    if (msgDate && msgDate !== lastSepDate) {
      const sep = document.createElement('div');
      sep.className = 'nas-chat-date-sep'; sep.dataset.date = msgDate;
      sep.innerHTML = `<span>${formatDate(msgDate)}</span>`;
      container.appendChild(sep);
    }

    const div = document.createElement('div');
    div.className = [
      'nas-chat-bubble',
      isMine ? 'nas-bubble-mine' : 'nas-bubble-theirs',
      isInternal ? 'nas-bubble-internal' : '',
    ].filter(Boolean).join(' ');
    div.dataset.id = msg.id;

    // Reply context
    let replyHTML = '';
    if (msg.reply_to_id && msg.reply_to_content) {
      replyHTML = `<div class="nas-bubble-reply-ctx">${esc(truncate(msg.reply_to_content, 80))}</div>`;
    }

    // Delivery badges
    let badges = '';
    if (sentEmail) badges += `<span class="nas-msg-badge nas-badge-email"><i class="fa-solid fa-envelope"></i> Email</span>`;
    if (sentWA)    badges += `<span class="nas-msg-badge nas-badge-wa"><i class="fa-brands fa-whatsapp"></i> WA</span>`;

    // Internal label
    const internalLabel = isInternal ? `<span class="nas-internal-label"><i class="fa-solid fa-lock"></i> Internal Note</span>` : '';

    div.innerHTML = `
      ${replyHTML}
      ${internalLabel}
      <div class="nas-bubble-content">${esc(msg.message).replace(/\n/g, '<br>')}</div>
      <div class="nas-bubble-footer">
        <span class="nas-bubble-sender">${esc(msg.sender_name || (isMine ? 'You' : 'Team'))}</span>
        <span class="nas-bubble-time">${formatTime(msg.created_at)}</span>
        ${badges}
        ${msg.is_read ? '<i class="fa-solid fa-check-double nas-read-tick"></i>' : ''}
      </div>
      <div class="nas-bubble-actions" style="display:none">
        <button class="nas-bubble-action-btn nas-reply-action-btn" data-id="${msg.id}" data-text="${esc(msg.message)}" title="Reply">
          <i class="fa-solid fa-reply"></i>
        </button>
      </div>
    `;
    div.addEventListener('mouseenter', () => { const a = div.querySelector('.nas-bubble-actions'); if (a) a.style.display = 'flex'; });
    div.addEventListener('mouseleave', () => { const a = div.querySelector('.nas-bubble-actions'); if (a) a.style.display = 'none'; });
    container.appendChild(div);
    if (scroll) scrollBottom();
  }

  // ── Poll for new messages ─────────────────────────────────────────────
  function startPolling() {
    window.addEventListener('beforeunload',function(){if(state.pollTimer){clearInterval(state.pollTimer);state.pollTimer=null;}});
    document.addEventListener('visibilitychange',function(){if(document.hidden&&state.pollTimer){clearInterval(state.pollTimer);state.pollTimer=null;}});
    state.pollTimer = setInterval(() => {
      ajax('nas_get_messages_since', { booking_id: state.bookingId, since_id: state.lastId }).then(res => {
        const msgs = res.data || [];
        if (msgs.length) {
          msgs.forEach(m => appendMessage(m));
          state.lastId = Math.max(...msgs.map(m => parseInt(m.id)));
          ajax('nas_mark_messages_read', { booking_id: state.bookingId });
        }
      });
    }, 5000);
  }

  // ── Send Message ──────────────────────────────────────────────────────
  async function send(sendVia = 'chat') {
    const input = document.getElementById('nas-chat-input');
    if (!input) return;
    const msg = input.value.trim();
    if (!msg) return;

    input.disabled = true;
    const data = {
      booking_id: state.bookingId,
      message:    msg,
      is_internal: state.isInternal ? 1 : 0,
      reply_to_id: state.replyToId || 0,
      send_email:  (sendVia === 'email' || sendVia === 'both') ? 1 : 0,
      send_wa:     (sendVia === 'wa' || sendVia === 'both') ? 1 : 0,
    };
    const res = await ajax('nas_send_message', data);
    if (res.success) {
      input.value = '';
      clearReply();
      appendMessage(res.data);
      state.lastId = Math.max(state.lastId, parseInt(res.data.id));
    } else {
      showToast(res.data?.message || 'Failed to send. Try again.', 'error');
    }
    input.disabled = false;
    input.focus();
  }

  // ── Bind Events ───────────────────────────────────────────────────────
  function bindEvents() {
    const wrap = document.getElementById('nas-chat');
    if (!wrap) return;

    // Send buttons
    wrap.addEventListener('click', e => {
      if (e.target.closest('#nas-send-chat-btn'))  send('chat');
      if (e.target.closest('#nas-send-email-btn')) send('email');
      if (e.target.closest('#nas-send-wa-btn'))    send('wa');
      // Refresh
      if (e.target.closest('#nas-chat-refresh-btn')) loadMessages();
      // Reply action
      const replyBtn = e.target.closest('.nas-reply-action-btn');
      if (replyBtn) setReply(parseInt(replyBtn.dataset.id), replyBtn.dataset.text);
      // Cancel reply
      if (e.target.closest('#nas-reply-cancel-btn')) clearReply();
      // QR picker
      if (e.target.closest('#nas-qr-picker-btn')) toggleQRPicker();
      if (e.target.closest('#nas-qr-close-btn'))  closeQRPicker();
      // Mode toggles
      const modeBtn = e.target.closest('.nas-chat-mode-btn');
      if (modeBtn) {
        const mode = modeBtn.dataset.mode;
        state.isInternal = mode === 'internal';
        $$('.nas-chat-mode-btn', wrap).forEach(b => b.classList.toggle('active', b.dataset.mode === mode));
        const input = document.getElementById('nas-chat-input');
        if (input) input.placeholder = state.isInternal ? 'Add internal note (team only)…' : 'Type your message…';
        const composer = document.getElementById('nas-chat-composer');
        if (composer) composer.classList.toggle('nas-composer-internal', state.isInternal);
        // Hide/show email/WA buttons for internal notes
        $$('.nas-send-email-btn, .nas-send-wa-btn', wrap).forEach(b => { b.style.display = state.isInternal ? 'none' : ''; });
      }
    });

    // Enter to send (Shift+Enter for newline)
    document.addEventListener('keydown', e => {
      if (e.target.id !== 'nas-chat-input') return;
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send('chat'); }
    });

    // QR search
    const qrSearch = document.getElementById('nas-qr-search');
    if (qrSearch) qrSearch.addEventListener('input', () => renderQRList(qrSearch.value));

    // QR category filter
    const qrCats = document.getElementById('nas-qr-cats');
    if (qrCats) qrCats.addEventListener('click', e => {
      const btn = e.target.closest('.nas-qr-cat-btn');
      if (!btn) return;
      $$('.nas-qr-cat-btn', qrCats).forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      renderQRList('', btn.dataset.cat);
    });
  }

  // ── Reply ─────────────────────────────────────────────────────────────
  function setReply(id, text) {
    state.replyToId = id;
    state.replyToText = text;
    const preview = document.getElementById('nas-chat-reply-preview');
    const previewText = document.getElementById('nas-reply-preview-text');
    if (preview) preview.style.display = 'flex';
    if (previewText) previewText.textContent = truncate(text, 80);
    document.getElementById('nas-chat-input')?.focus();
  }
  function clearReply() {
    state.replyToId = null; state.replyToText = '';
    const preview = document.getElementById('nas-chat-reply-preview');
    if (preview) preview.style.display = 'none';
  }

  // ── Quick Reply Picker ────────────────────────────────────────────────
  function toggleQRPicker() { state.qrPickerOpen ? closeQRPicker() : openQRPicker(); }
  function openQRPicker() {
    const picker = document.getElementById('nas-qr-picker');
    if (!picker) return;
    picker.style.display = 'flex';
    state.qrPickerOpen = true;
    if (!state.qrAll.length) loadQReplies();
  }
  function closeQRPicker() {
    const picker = document.getElementById('nas-qr-picker');
    if (picker) picker.style.display = 'none';
    state.qrPickerOpen = false;
  }
  function loadQReplies() {
    ajax('nas_get_quick_replies').then(res => {
      state.qrAll = res.data || [];
      state.qrCategories = [...new Set(state.qrAll.map(r => r.category).filter(Boolean))];
      renderQRCats();
      renderQRList();
    });
  }
  function renderQRCats() {
    const cats = document.getElementById('nas-qr-cats');
    if (!cats) return;
    cats.innerHTML = `<button class="nas-qr-cat-btn active" data-cat="">All</button>` +
      state.qrCategories.map(c => `<button class="nas-qr-cat-btn" data-cat="${esc(c)}">${esc(c)}</button>`).join('');
  }
  function renderQRList(query = '', cat = '') {
    const list = document.getElementById('nas-qr-list');
    if (!list) return;
    let items = state.qrAll;
    if (cat) items = items.filter(r => r.category === cat);
    if (query) {
      const q = query.toLowerCase();
      items = items.filter(r => r.title.toLowerCase().includes(q) || r.content.toLowerCase().includes(q));
    }
    if (!items.length) { list.innerHTML = `<div class="nas-chat-empty" style="padding:20px">No results</div>`; return; }
    list.innerHTML = items.map(r => `
      <div class="nas-qr-item" data-content="${esc(r.content)}" tabindex="0" role="button">
        <div class="nas-qr-item-title">${esc(r.title)}</div>
        <div class="nas-qr-item-preview">${esc(truncate(r.content, 90))}</div>
        <span class="nas-qr-item-cat">${esc(r.category)}</span>
      </div>
    `).join('');
    list.addEventListener('click', e => {
      const item = e.target.closest('.nas-qr-item');
      if (!item) return;
      const input = document.getElementById('nas-chat-input');
      if (input) {
        input.value = item.dataset.content;
        input.focus();
        input.dispatchEvent(new Event('input'));
      }
      closeQRPicker();
    });
  }

  // ── Helpers ───────────────────────────────────────────────────────────
  function scrollBottom() {
    const c = document.getElementById('nas-chat-messages');
    if (c) c.scrollTop = c.scrollHeight;
  }
  function esc(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function truncate(str, n) { return (str || '').length > n ? str.slice(0, n) + '…' : str; }
  function formatTime(dt) {
    if (!dt) return '';
    try { return new Date(dt.replace(' ', 'T')).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }); }
    catch (e) { return ''; }
  }
  function formatDate(d) {
    if (!d) return '';
    try {
      const date = new Date(d); const today = new Date(); today.setHours(0,0,0,0);
      const yesterday = new Date(today); yesterday.setDate(yesterday.getDate() - 1);
      if (date >= today) return 'Today';
      if (date >= yesterday) return 'Yesterday';
      return date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
    } catch (e) { return d; }
  }
  function showToast(msg, type) {
    const t = document.createElement('div');
    t.className = `nas-toast nas-toast-${type || 'success'}`;
    t.textContent = msg; document.body.appendChild(t);
    setTimeout(() => t.classList.add('show'), 10);
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 3500);
  }

  // ── Boot ──────────────────────────────────────────────────────────────
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

})();
