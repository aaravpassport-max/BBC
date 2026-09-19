// ILRS — Top action queues (what to do with each item). Sidebar stays for navigation.
(function () {
  const LC = () => window.ILRSWorkLifecycle;

  const ENTITY_KEYS = ['global', 'focus', 'reminder', 'task', 'inquiry', 'work'];
  const WORK_SYNC_KEYS = ['global', 'reminder', 'task', 'inquiry', 'work'];
  const FOCUS_PAGES = new Set(['today', 'dashboard', 'tomorrow', 'upcoming', 'overdue', 'postponed', 'completed']);

  function syncWorkQueues(tabId) {
    ensureFilters();
    for (const k of WORK_SYNC_KEYS) {
      App.lifecycleQueueFilters[k] = tabId;
    }
  }

  const TAB_DEFS = [
    {
      id: 'all',
      label: 'All',
      actionHint: 'Show every item regardless of work status.',
    },
    {
      id: 'active',
      label: '▶ Act now',
      actionHint: 'New enquiries and tasks you still need to start — pick up and begin work.',
    },
    {
      id: 'in_process',
      label: '⚙ In process',
      actionHint: 'Work is underway — enquiry converted or moved into the pipeline beyond initial qualification.',
    },
    {
      id: 'pending',
      label: '⏸ Waiting',
      actionHint: 'On hold — waiting on a client, document, payment, or reply. Follow-up is optional.',
    },
    {
      id: 'completed',
      label: '✓ Finished',
      actionHint: 'Work is done. No routine reminders unless you schedule another action.',
    },
    {
      id: 'closed',
      label: '⊘ Closed',
      actionHint: 'Fully closed — no further work, reminders, or queue placement.',
    },
  ];

  function ensureFilters() {
    if (!App.lifecycleQueueFilters) App.lifecycleQueueFilters = {};
  }

  function getFilter(entityKey, defaultTab = 'active') {
    ensureFilters();
    if (entityKey === 'focus') {
      if (App.lifecycleQueueFilters.focus) return App.lifecycleQueueFilters.focus;
      return App.currentPage === 'completed' ? 'completed' : defaultTab;
    }
    if (WORK_SYNC_KEYS.includes(entityKey)) {
      return App.lifecycleQueueFilters.global || App.lifecycleQueueFilters[entityKey] || defaultTab;
    }
    return App.lifecycleQueueFilters[entityKey] || defaultTab;
  }

  function setFilter(entityKey, tabId) {
    ensureFilters();
    if (entityKey === 'focus') {
      App.lifecycleQueueFilters.focus = tabId;
    } else if (WORK_SYNC_KEYS.includes(entityKey)) {
      syncWorkQueues(tabId);
    } else {
      App.lifecycleQueueFilters[entityKey] = tabId;
    }
    if (typeof navigate === 'function' && App.currentPage) {
      navigate(App.currentPage);
    }
  }

  function applyGlobalFilter(tabId) {
    syncWorkQueues(tabId);
  }

  function setGlobalFilter(tabId, { refresh = true } = {}) {
    applyGlobalFilter(tabId);
    if (refresh && typeof navigate === 'function' && App.currentPage) {
      navigate(App.currentPage);
    }
  }

  function isInquiryItem(item) {
    return Boolean(
      item?.inquiry_number
      || (item?.outcome_status !== undefined && !item?.task_type && item?.client_name),
    );
  }

  function inferItem(item, entityKey) {
    const api = LC();
    if (!api) return 'active';
    if (entityKey === 'inquiry' || (entityKey === 'work' && isInquiryItem(item)) || isInquiryItem(item)) {
      return api.inferInquiryQueueTab ? api.inferInquiryQueueTab(item) : api.inferLifecycleFromInquiry(item);
    }
    return api.inferReminderQueueTab ? api.inferReminderQueueTab(item) : api.inferLifecycleFromReminder(item);
  }

  function inferInquiryQueueTab(inquiry) {
    const api = LC();
    return api?.inferInquiryQueueTab ? api.inferInquiryQueueTab(inquiry) : 'active';
  }

  function filterItems(items, entityKey, tabId) {
    const tab = tabId || getFilter(entityKey);
    if (tab === 'all') return items.slice();
    return items.filter((item) => inferItem(item, entityKey) === tab);
  }

  function countByTab(items, entityKey) {
    const counts = { all: items.length };
    for (const t of TAB_DEFS) {
      if (t.id !== 'all') counts[t.id] = 0;
    }
    for (const item of items) {
      const s = inferItem(item, entityKey);
      if (counts[s] !== undefined) counts[s] += 1;
    }
    return counts;
  }

  function selectedHint(entityKey) {
    const selected = getFilter(entityKey);
    return TAB_DEFS.find((t) => t.id === selected)?.actionHint || '';
  }

  function tabsHtml(entityKey, items, { defaultTab = 'active', extraClass = '', showHint = true } = {}) {
    const selected = getFilter(entityKey, defaultTab);
    const counts = countByTab(items, entityKey);
    const cls = `lifecycle-queue-tabs smart-tabs action-queue-tabs ${extraClass}`.trim();
    const hint = showHint ? `<p class="action-queue-hint">${selectedHint(entityKey)}</p>` : '';
    return `<div class="action-queue-bar">
      <div class="action-queue-bar-title">What to do with these items</div>
      <div class="${cls}" role="tablist" aria-label="Work action queues">
        ${TAB_DEFS.map((t) => {
          const n = counts[t.id] ?? 0;
          const label = t.id === 'all' ? t.label : `${t.label} · ${n}`;
          return `<button type="button" class="smart-tab ${selected === t.id ? 'active' : ''}" role="tab"
            title="${t.actionHint}"
            onclick="ILRSLifecycleQueue.setFilter('${entityKey}','${t.id}')">${label}</button>`;
        }).join('')}
      </div>
      ${hint}
    </div>`;
  }

  function lifecycleToQueueTab(newLifecycle, item) {
    const api = LC();
    let tab = api?.normalizeLifecycle ? api.normalizeLifecycle(newLifecycle) : String(newLifecycle || '').trim().toLowerCase();
    const valid = new Set(['all', 'active', 'in_process', 'pending', 'completed', 'closed']);
    if (!valid.has(tab)) tab = 'active';
    if (tab === 'active' && item && api?.inferInquiryQueueTab && isInquiryItem(item)) {
      return api.inferInquiryQueueTab(item);
    }
    if (tab === 'active' && item && api?.inferReminderQueueTab && !isInquiryItem(item)) {
      return api.inferReminderQueueTab(item);
    }
    return tab;
  }

  async function afterStatusChange(entityKey, newLifecycle, item) {
    ensureFilters();
    const tab = lifecycleToQueueTab(newLifecycle, item);
    syncWorkQueues(tab);
    if (FOCUS_PAGES.has(App.currentPage)) {
      App.lifecycleQueueFilters.focus = tab;
    }
    if (typeof loadAllData === 'function') await loadAllData();
    if (typeof updateBadges === 'function') updateBadges();
    if (typeof window.softRefreshCurrentPage === 'function') {
      await window.softRefreshCurrentPage();
    } else if (typeof refreshCurrentView === 'function') {
      refreshCurrentView();
    }
  }

  window.ILRSLifecycleQueue = {
    TAB_DEFS,
    ENTITY_KEYS,
    getFilter,
    setFilter,
    setGlobalFilter,
    applyGlobalFilter,
    inferItem,
    inferInquiryQueueTab,
    filterItems,
    countByTab,
    tabsHtml,
    selectedHint,
    afterStatusChange,
    lifecycleToQueueTab,
  };
})();
