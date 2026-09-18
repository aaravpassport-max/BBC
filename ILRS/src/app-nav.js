// ILRS — Primary top navigation + contextual sidebar (section-based IA)
(function () {
  const SECTIONS = [
    {
      id: 'focus',
      label: 'Focus',
      defaultPage: 'today',
      pages: new Set(['today', 'dashboard', 'tomorrow', 'upcoming', 'overdue', 'postponed', 'completed']),
      sidebar: [
        ['today', '☀️', 'Today'],
        ['tomorrow', '🌅', 'Tomorrow'],
        ['overdue', '⚠️', 'Overdue'],
        ['postponed', '📅', 'Postponed'],
        ['upcoming', '📆', 'Upcoming'],
        ['completed', '✅', 'Done'],
      ],
    },
    {
      id: 'inquiries',
      label: 'Inquiries',
      defaultPage: 'inquiries',
      pages: new Set(['inquiries', 'inquiry-detail', 'pipeline', 'inquiry-followups', 'work-schedule']),
      sidebar: [
        ['inquiries', '📋', 'List'],
        ['pipeline', '📊', 'Pipeline'],
        ['inquiry-followups', '📞', 'Follow-ups'],
        ['work-schedule', '🗓', 'Schedule'],
      ],
    },
    {
      id: 'tasks',
      label: 'Tasks & reminders',
      defaultPage: 'tasks',
      pages: new Set(['tasks', 'reminders', 'calendar']),
      sidebar: [
        ['tasks', '✅', 'Tasks'],
        ['reminders', '🔔', 'All reminders'],
        ['calendar', '📅', 'Calendar'],
      ],
    },
    {
      id: 'life',
      label: 'Life',
      defaultPage: 'medicine',
      pages: new Set(['medicine', 'bills', 'habits', 'family', 'checklists', 'rewards']),
      sidebar: [
        ['medicine', '💊', 'Medicine'],
        ['bills', '💸', 'Bills'],
        ['habits', '🔁', 'Habits'],
        ['family', '👨‍👩‍👧', 'Family'],
        ['checklists', '📝', 'Checklists'],
      ],
    },
    {
      id: 'more',
      label: 'More',
      defaultPage: 'settings',
      pages: new Set(['settings', 'clients', 'work-reports', 'pipeline-settings', 'reports']),
      sidebar: [
        ['clients', '👤', 'Clients'],
        ['work-reports', '📈', 'Work analytics'],
        ['pipeline-settings', '🏷', 'Workflow stages'],
        ['settings', '⚙️', 'Settings'],
      ],
    },
  ];

  function sectionForPage(page) {
    for (const s of SECTIONS) {
      if (s.pages.has(page)) return s;
    }
    return SECTIONS[0];
  }

  function navItem(page, icon, label, badge = '') {
    return `<button type="button" class="nav-item" data-page="${page}">
      <span class="nav-icon">${icon}</span>
      <span class="nav-label">${label}</span>
      ${badge ? `<span class="nav-badge">${badge}</span>` : ''}
    </button>`;
  }

  function primaryNavHtml(currentPage) {
    const section = sectionForPage(currentPage);
    return `<nav class="primary-nav" aria-label="Main">
      ${SECTIONS.map((s) => {
        const active = s.id === section.id;
        return `<button type="button" class="primary-nav-item ${active ? 'active' : ''}"
          data-section="${s.id}" data-default-page="${s.defaultPage}"
          onclick="ILRSAppNav.goSection('${s.id}')">${s.label}</button>`;
      }).join('')}
    </nav>`;
  }

  function contextualSidebarHtml(currentPage) {
    const section = sectionForPage(currentPage);
    let items = section.sidebar.map(([page, icon, label]) => navItem(page, icon, label));
    if (section.id === 'life' && App?.settings?.rewards_enabled === '1') {
      items.push(navItem('rewards', '🏅', 'Rewards'));
    }
    if (section.id === 'inquiries') {
      items.unshift(`<button type="button" class="btn btn-primary sidebar-add-btn" onclick="showInquirySheet()">＋ New inquiry</button>`);
    } else if (section.id === 'focus' || section.id === 'tasks') {
      items.unshift(`<button type="button" class="btn btn-primary sidebar-add-btn" onclick="typeof showQuickAddMenu==='function'?showQuickAddMenu():showCaptureSheet()">＋ New</button>`);
    }
    const footer = `<div class="sidebar-footer">ILRS v${App?.appVersion || ''}</div>`;
    return items.join('') + footer;
  }

  function goSection(sectionId) {
    const section = SECTIONS.find((s) => s.id === sectionId);
    if (!section || typeof navigate !== 'function') return;
    if (section.pages.has(App.currentPage)) {
      refreshShellNav();
      return;
    }
    navigate(section.defaultPage);
  }

  function refreshShellNav() {
    const top = document.getElementById('primary-nav-slot');
    const side = document.getElementById('context-sidebar-slot');
    if (top) top.innerHTML = primaryNavHtml(App.currentPage);
    if (side) {
      side.innerHTML = contextualSidebarHtml(App.currentPage);
      side.querySelectorAll('.nav-item').forEach((el) => {
        el.classList.toggle('active', el.dataset.page === App.currentPage);
        el.addEventListener('click', () => navigate(el.dataset.page));
      });
    }
    document.querySelectorAll('#primary-nav-slot .primary-nav-item').forEach((el) => {
      el.classList.toggle('active', el.dataset.section === sectionForPage(App.currentPage).id);
    });
  }

  function wireAfterShellRender() {
    refreshShellNav();
  }

  function onNavigate(page) {
    refreshShellNav();
  }

  window.ILRSAppNav = {
    SECTIONS,
    sectionForPage,
    primaryNavHtml,
    contextualSidebarHtml,
    goSection,
    refreshShellNav,
    wireAfterShellRender,
    onNavigate,
  };
})();
