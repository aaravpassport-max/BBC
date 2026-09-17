// ILRS — Horizontal work-status queues (shared across reminders, tasks, inquiries, work)
(function () {
  const LC = () => window.ILRSWorkLifecycle;

  const TAB_DEFS = [
    { id: 'all', label: 'All' },
    { id: 'active', label: 'Active' },
    { id: 'pending', label: 'On hold' },
    { id: 'completed', label: 'Done' },
    { id: 'closed', label: 'Closed' },
  ];

  function ensureFilters() {
    if (!App.lifecycleQueueFilters) App.lifecycleQueueFilters = {};
  }

  function getFilter(entityKey, defaultTab = 'active') {
    ensureFilters();
    return App.lifecycleQueueFilters[entityKey] || defaultTab;
  }

  function setFilter(entityKey, tabId) {
    ensureFilters();
    App.lifecycleQueueFilters[entityKey] = tabId;
    if (typeof navigate === 'function' && App.currentPage) {
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
      return api.inferLifecycleFromInquiry(item);
    }
    return api.inferLifecycleFromReminder(item);
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

  function tabsHtml(entityKey, items, { defaultTab = 'active', extraClass = '' } = {}) {
    const selected = getFilter(entityKey, defaultTab);
    const counts = countByTab(items, entityKey);
    const cls = `lifecycle-queue-tabs smart-tabs ${extraClass}`.trim();
    return `<div class="${cls}" role="tablist" aria-label="Work status queues">
      ${TAB_DEFS.map((t) => {
        const n = counts[t.id] ?? 0;
        const label = t.id === 'all' ? `${t.label}` : `${t.label} · ${n}`;
        return `<button type="button" class="smart-tab ${selected === t.id ? 'active' : ''}" role="tab"
          onclick="ILRSLifecycleQueue.setFilter('${entityKey}','${t.id}')">${label}</button>`;
      }).join('')}
    </div>`;
  }

  /** After user changes status — switch queue tab and refresh so item moves immediately. */
  async function afterStatusChange(entityKey, newLifecycle) {
    ensureFilters();
    const tab = LC()?.normalizeLifecycle(newLifecycle) || newLifecycle;
    App.lifecycleQueueFilters[entityKey] = tab;
    if (typeof loadAllData === 'function') await loadAllData();
    if (typeof updateBadges === 'function') updateBadges();
    if (typeof navigate === 'function' && App.currentPage) {
      navigate(App.currentPage);
    } else if (typeof refreshCurrentView === 'function') {
      refreshCurrentView();
    }
  }

  window.ILRSLifecycleQueue = {
    TAB_DEFS,
    getFilter,
    setFilter,
    inferItem,
    filterItems,
    countByTab,
    tabsHtml,
    afterStatusChange,
  };
})();
