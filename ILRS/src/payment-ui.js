// ILRS — Payment tracking UI (optional per work item)
(function () {
  function esc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  }

  function formatRupee(amount) {
    const n = Math.round((Number(amount) || 0) * 100) / 100;
    return `₹${n.toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;
  }

  function formatPayDate(dateStr) {
    if (!dateStr) return '—';
    if (typeof formatDate === 'function') return formatDate(dateStr);
    const d = new Date(`${String(dateStr).slice(0, 10)}T00:00:00`);
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  function appData() {
    if (typeof App !== 'undefined') return App;
    if (typeof window !== 'undefined' && window.App) return window.App;
    return { workPayments: {} };
  }

  function getPaymentsFor(entityType, entityId) {
    const key = `${entityType}:${entityId}`;
    return (appData().workPayments || {})[key] || [];
  }

  function computeSummary(item, entityType) {
    const payments = getPaymentsFor(entityType, item.id);
    const enabled = Number(item.payment_tracking_enabled) === 1;
    const total = Number(item.payment_total) > 0
      ? Number(item.payment_total)
      : (Number(item.quotation_amount) > 0 ? Number(item.quotation_amount) : Number(item.expected_value) || 0);
    const received = payments.reduce((s, p) => s + (Number(p.amount) || 0), 0);
    const balance = Math.max(0, total - received);
    const today = typeof todayStr === 'function' ? todayStr() : new Date().toISOString().slice(0, 10);
    const nextDue = String(item.next_payment_due_date || '').slice(0, 10);
    const nextAmount = Number(item.next_payment_amount) || 0;

    let status = 'none';
    let statusLabel = 'No Payment';
    if (!enabled) return { enabled, total, received, balance, nextDue, nextAmount, status, statusLabel, payments };

    if (total > 0 && received >= total) {
      status = 'paid';
      statusLabel = 'Fully Paid';
    } else if (received > 0) {
      status = 'partial';
      statusLabel = 'Partially Paid';
    }

    if (balance > 0 && nextDue) {
      if (nextDue < today) {
        status = 'overdue';
        statusLabel = 'Payment Overdue';
      } else if (nextDue === today) {
        status = 'due';
        statusLabel = 'Payment Due';
      } else if (received === 0) {
        status = 'due';
        statusLabel = 'Payment Due';
      }
    }

    return { enabled, total, received, balance, nextDue, nextAmount, status, statusLabel, payments };
  }

  function statusClass(status) {
    return `payment-status-${status || 'none'}`;
  }

  function paymentCardHtml(item, entityType, idPrefix = 'pay') {
    const summary = computeSummary(item, entityType);
    if (!summary.enabled) return '';

    const uid = `${idPrefix}-payment-${item.id}`;
    const history = summary.payments.slice().reverse();

    const historyLines = history.map((p) =>
      `<li><strong>${formatRupee(p.amount)}</strong> — Received on ${formatPayDate(p.received_date)}${p.note ? `<span class="payment-entry-note">${esc(p.note)}</span>` : ''}</li>`
    ).join('');

    const nextLine = summary.balance > 0 && summary.nextDue
      ? `<div class="payment-next-line ${summary.status === 'overdue' ? 'overdue' : ''}">
          <strong>Next Payment:</strong> ${summary.nextAmount > 0 ? formatRupee(summary.nextAmount) : 'Outstanding balance'}
          — Due on ${formatPayDate(summary.nextDue)}
        </div>`
      : '';

    return `
      <div class="card-payment-block ${statusClass(summary.status)}" id="${uid}" onclick="event.stopPropagation()">
        <div class="payment-block-header" onclick="event.stopPropagation();togglePaymentBlock('${uid}')">
          <span class="payment-status-pill ${statusClass(summary.status)}">${summary.statusLabel}</span>
          <span class="payment-block-summary">
            ${formatRupee(summary.received)} / ${formatRupee(summary.total)}
            ${summary.balance > 0 ? ` · Bal ${formatRupee(summary.balance)}` : ''}
          </span>
          <button type="button" class="note-expand-btn" onclick="event.stopPropagation();togglePaymentBlock('${uid}')">${history.length ? 'history' : 'details'}</button>
        </div>
        <div class="payment-block-body" style="display:none">
          <div class="payment-history-title">Payment History</div>
          ${history.length
            ? `<ul class="payment-history-list">${historyLines}</ul>`
            : '<p class="payment-empty">No payments recorded yet.</p>'}
          <div class="payment-totals-grid">
            <div><span>Total Payment</span><strong>${formatRupee(summary.total)}</strong></div>
            <div><span>Total Received</span><strong>${formatRupee(summary.received)}</strong></div>
            <div><span>Balance</span><strong class="${summary.balance > 0 ? 'overdue' : ''}">${formatRupee(summary.balance)}</strong></div>
          </div>
          ${nextLine}
          <button type="button" class="btn btn-ghost btn-sm payment-add-btn" onclick="event.stopPropagation();showRecordPaymentModal('${entityType}','${item.id}')">+ Record Payment</button>
        </div>
      </div>`;
  }

  function paymentFormSection(entityType, item = {}) {
    const enabled = Number(item.payment_tracking_enabled) === 1;
    const total = item.payment_total || item.quotation_amount || '';
    const payments = getPaymentsFor(entityType, item.id);
    const paymentRows = payments.map((p) => `
      <div class="payment-form-row">
        <input type="number" class="form-input payment-row-amount" value="${p.amount}" min="0" step="0.01" placeholder="Amount" data-payment-id="${p.id}" readonly />
        <input type="date" class="form-input payment-row-date" value="${String(p.received_date).slice(0, 10)}" data-payment-id="${p.id}" readonly />
        <input type="text" class="form-input payment-row-note" value="${esc(p.note)}" placeholder="Note" data-payment-id="${p.id}" readonly />
      </div>`).join('');

    return `
      <div class="payment-form-section">
        <div class="setting-row" style="padding:8px 0">
          <span class="form-label">Track payments for this item</span>
          <div class="toggle ${enabled ? 'on' : ''}" id="payment-tracking-toggle"></div>
        </div>
        <input type="hidden" id="payment-tracking-enabled" value="${enabled ? '1' : '0'}" />
        <div id="payment-fields" style="display:${enabled ? 'block' : 'none'}">
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Total Payment / Amount (₹)</label>
              <input type="number" class="form-input" id="payment-total" value="${total}" min="0" step="0.01" />
            </div>
            <div class="form-group">
              <label class="form-label">Next Payment Amount (₹)</label>
              <input type="number" class="form-input" id="payment-next-amount" value="${item.next_payment_amount || ''}" min="0" step="0.01" />
            </div>
            <div class="form-group">
              <label class="form-label">Next Payment Due Date</label>
              <input type="date" class="form-input" id="payment-next-due" value="${String(item.next_payment_due_date || '').slice(0, 10)}" />
            </div>
          </div>
          <label class="form-label">Record a payment</label>
          <div class="payment-form-row">
            <input type="number" class="form-input" id="payment-new-amount" min="0" step="0.01" placeholder="Amount ₹" />
            <input type="date" class="form-input" id="payment-new-date" value="${typeof todayStr === 'function' ? todayStr() : ''}" />
            <input type="text" class="form-input" id="payment-new-note" placeholder="Payment note" />
          </div>
          ${paymentRows ? `<div class="payment-existing-label">Previous payments</div>${paymentRows}` : ''}
        </div>
      </div>`;
  }

  function wirePaymentFormToggle(overlay) {
    overlay.querySelector('#payment-tracking-toggle')?.addEventListener('click', function () {
      this.classList.toggle('on');
      const on = this.classList.contains('on');
      document.getElementById('payment-tracking-enabled').value = on ? '1' : '0';
      const fields = document.getElementById('payment-fields');
      if (fields) fields.style.display = on ? 'block' : 'none';
    });
  }

  async function savePaymentSettingsFromForm(entityType, entityId) {
    const enabled = document.getElementById('payment-tracking-enabled')?.value === '1';
    const total = parseFloat(document.getElementById('payment-total')?.value) || 0;
    const nextDue = document.getElementById('payment-next-due')?.value || '';
    const nextAmount = parseFloat(document.getElementById('payment-next-amount')?.value) || 0;

    await window.ilrs?.updatePaymentSettings?.({
      entityType,
      entityId,
      enabled,
      total,
      nextPaymentDueDate: nextDue,
      nextPaymentAmount: nextAmount,
    });

    const newAmount = parseFloat(document.getElementById('payment-new-amount')?.value) || 0;
    if (newAmount > 0) {
      await window.ilrs?.recordWorkPayment?.({
        entityType,
        entityId,
        amount: newAmount,
        receivedDate: document.getElementById('payment-new-date')?.value || todayStr(),
        note: document.getElementById('payment-new-note')?.value || '',
      });
    }
  }

  function showRecordPaymentModal(entityType, entityId) {
    const item = entityType === 'inquiry'
      ? (App.inquiries || []).find((i) => i.id === entityId)
      : (App.reminders || []).find((r) => r.id === entityId);
    if (!item) return;

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'payment-modal';
    overlay.innerHTML = `
      <div class="capture-sheet" style="max-width:420px">
        <div class="capture-header">
          <h2>Record Payment</h2>
          <button class="modal-close" onclick="document.getElementById('payment-modal').remove()">✕</button>
        </div>
        <div class="form-group"><label class="form-label">Amount (₹)</label>
          <input type="number" class="form-input" id="pm-amount" min="0" step="0.01" /></div>
        <div class="form-group"><label class="form-label">Received date</label>
          <input type="date" class="form-input" id="pm-date" value="${typeof todayStr === 'function' ? todayStr() : ''}" /></div>
        <div class="form-group"><label class="form-label">Note</label>
          <input type="text" class="form-input" id="pm-note" placeholder="Optional remark" /></div>
        <div class="capture-actions">
          <button class="btn btn-ghost" onclick="document.getElementById('payment-modal').remove()">Cancel</button>
          <button class="btn btn-primary" id="pm-save">Save Payment</button>
        </div>
      </div>`;
    if (typeof attachModalDismiss === 'function') attachModalDismiss(overlay);
    document.body.appendChild(overlay);

    overlay.querySelector('#pm-save')?.addEventListener('click', async () => {
      const amount = parseFloat(document.getElementById('pm-amount')?.value) || 0;
      if (amount <= 0) {
        if (typeof toast === 'function') toast('Enter a valid amount', 'warning');
        return;
      }
      const result = await window.ilrs?.recordWorkPayment?.({
        entityType,
        entityId,
        amount,
        receivedDate: document.getElementById('pm-date')?.value,
        note: document.getElementById('pm-note')?.value || '',
      });
      if (!result?.success) {
        if (typeof toast === 'function') toast(result?.error || 'Could not save payment', 'warning');
        return;
      }
      overlay.remove();
      if (typeof toast === 'function') toast('Payment recorded');
      if (typeof loadAllData === 'function') await loadAllData();
      if (typeof refreshCurrentView === 'function') refreshCurrentView();
    });
  }

  window.togglePaymentBlock = function (uid) {
    const block = document.getElementById(uid);
    const body = block?.querySelector('.payment-block-body');
    if (!body) return;
    const open = body.style.display !== 'block';
    body.style.display = open ? 'block' : 'none';
    block.classList.toggle('expanded', open);
  };

  window.ILRSPayment = {
    formatRupee,
    formatPayDate,
    getPaymentsFor,
    computeSummary,
    paymentCardHtml,
    paymentFormSection,
    wirePaymentFormToggle,
    savePaymentSettingsFromForm,
  };
  window.showRecordPaymentModal = showRecordPaymentModal;
})();
