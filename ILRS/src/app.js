// ═══════════════════════════════════════════════════════════════
//  ILRS — Intelligent Life Reminder System
//  app.js — Complete Frontend Application
// ═══════════════════════════════════════════════════════════════

const api = window.ilrs;

// ── State ────────────────────────────────────────────────────────
const App = {
  currentPage: 'dashboard',
  reminders: [],
  medicines: [],
  bills: [],
  habits: [],
  family: [],
  settings: {},
  pausedUntil: null,
  focusMode: false,
  alertQueue: [],
  isProcessingAlert: false,
};

// ── Utilities ─────────────────────────────────────────────────────
function uuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.random() * 16 | 0;
    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
  });
}

function formatTime(timeStr) {
  if (!timeStr) return '';
  const [h, m] = timeStr.split(':').map(Number);
  const ampm = h >= 12 ? 'PM' : 'AM';
  const hour = h % 12 || 12;
  return `${hour}:${String(m).padStart(2, '0')} ${ampm}`;
}

function formatDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
}

function todayStr() {
  const n = new Date();
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}-${String(n.getDate()).padStart(2, '0')}`;
}

function nowTimeStr() {
  return new Date().toTimeString().slice(0, 5);
}

function toLocalFireISO(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}T${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}:${String(date.getSeconds()).padStart(2, '0')}`;
}

function computeNextFireLocal(startDate, time, repeatType = 'once') {
  if (!startDate || !time) return '';
  const [h, m] = time.split(':').map(Number);
  const [y, mo, d] = startDate.split('-').map(Number);
  const now = new Date();
  let fire = new Date(y, mo - 1, d, h, m || 0, 0, 0);

  if (fire.getTime() <= now.getTime()) {
    switch (repeatType) {
      case 'daily': fire.setDate(fire.getDate() + 1); break;
      case 'weekly': fire.setDate(fire.getDate() + 7); break;
      case 'monthly': fire.setMonth(fire.getMonth() + 1); break;
      default: fire = new Date(now.getTime() + 30000); break;
    }
  }
  return toLocalFireISO(fire);
}

function isReminderOverdue(nextFire) {
  if (!nextFire) return false;
  const clean = String(nextFire).trim().replace(' ', 'T');
  if (clean.includes('Z')) return new Date(clean).getTime() < Date.now();
  const [datePart, timePart = '00:00:00'] = clean.split('T');
  const [y, m, d] = datePart.split('-').map(Number);
  const [hh, mm] = timePart.split(':').map(Number);
  return new Date(y, m - 1, d, hh, mm || 0, 0, 0).getTime() < Date.now();
}

function localNowISO() {
  return toLocalFireISO(new Date());
}

function daysUntil(dateStr) {
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const target = new Date(dateStr); target.setHours(0, 0, 0, 0);
  return Math.round((target - today) / 86400000);
}

function toast(msg, type = 'success', duration = 3000) {
  const container = document.getElementById('toast-container');
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<span>${type === 'success' ? '✅' : type === 'critical' ? '🚨' : '⚠️'}</span><div><strong>${msg}</strong></div>`;
  container.appendChild(el);
  setTimeout(() => { el.style.animation = 'toastIn 0.25s ease reverse'; setTimeout(() => el.remove(), 250); }, duration);
}

async function db(sql, params = []) {
  const result = await api.query(sql, params);
  if (!result.success) {
    console.error('DB Error:', result.error, sql);
    return null;
  }
  return result.data;
}

async function dbRun(sql, params = []) {
  const result = await api.query(sql, params);
  if (!result.success) {
    console.error('DB Error:', result.error, sql);
    toast(`Save failed: ${result.error || 'database error'}`, 'critical');
    return false;
  }
  return true;
}

// ── Settings ───────────────────────────────────────────────────────
async function loadSettings() {
  const rows = await db('SELECT key, value FROM settings');
  if (rows) rows.forEach(r => App.settings[r.key] = r.value);
}

async function saveSetting(key, value) {
  App.settings[key] = String(value);
  await db('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', [key, String(value)]);
}

// ── Data Loaders ───────────────────────────────────────────────────
async function loadAllData() {
  const [reminders, medicines, bills, habits, family] = await Promise.all([
    db("SELECT * FROM reminders WHERE status != 'deleted' ORDER BY priority DESC, next_fire ASC"),
    db("SELECT * FROM medicines WHERE status = 'active' ORDER BY name"),
    db("SELECT * FROM bills WHERE status = 'active' ORDER BY due_day"),
    db("SELECT * FROM habits WHERE status = 'active' ORDER BY name"),
    db('SELECT * FROM family_members ORDER BY name'),
  ]);
  App.reminders = reminders || [];
  App.medicines = medicines || [];
  App.bills = bills || [];
  App.habits = habits || [];
  App.family = family || [];
}

// ── Navigation ─────────────────────────────────────────────────────
const PAGES = {
  dashboard: renderDashboard,
  reminders: renderReminders,
  add: renderAddReminder,
  medicine: renderMedicine,
  bills: renderBills,
  family: renderFamily,
  habits: renderHabits,
  calendar: renderCalendar,
  checklists: renderChecklists,
  reports: renderReports,
  settings: renderSettings,
  rewards: renderRewards,
};

async function navigate(page) {
  App.currentPage = page;
  document.querySelectorAll('.nav-item').forEach(el => {
    el.classList.toggle('active', el.dataset.page === page);
  });
  const content = document.getElementById('content');
  content.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text-muted)">Loading...</div>';
  await loadAllData();
  if (PAGES[page]) await PAGES[page](content);
  content.scrollTop = 0;
}

// ── App Shell ──────────────────────────────────────────────────────
function renderShell() {
  const app = document.getElementById('main-app');
  const name = App.settings.user_name || 'Friend';

  app.innerHTML = `
    <!-- Toast Container -->
    <div id="toast-container"></div>

    <!-- Top Bar -->
    <div class="topbar">
      <div class="topbar-logo">ILRS <span>Life Reminder System</span></div>
      <div class="quick-add-bar">
        <input type="text" id="quick-input" placeholder="⚡ Quick add: 'Take medicine at 8pm daily' or 'Pay electricity bill on 5th'..." />
        <button class="quick-add-btn" onclick="handleQuickAdd()">+</button>
      </div>
      <div class="topbar-actions">
        <button class="topbar-btn" id="focus-btn" onclick="toggleFocusMode()" title="Focus Mode">🎯 Focus</button>
        <button class="topbar-btn" onclick="showAlertCount()" title="Pending Alerts">
          🔔 <span id="alert-badge" class="notif-badge" style="display:none">0</span>
        </button>
        <button class="topbar-btn" onclick="window.ilrs.minimizeToTray()" title="Minimize to Tray">⬇</button>
      </div>
    </div>

    <!-- Sidebar -->
    <nav class="sidebar">
      <div class="sidebar-section-label">Main</div>
      ${navItem('dashboard', '🏠', 'Dashboard')}
      ${navItem('reminders', '🔔', 'Reminders')}
      ${navItem('calendar', '📅', 'Calendar')}
      ${navItem('add', '➕', 'Add New')}

      <div class="sidebar-section-label">Modules</div>
      ${navItem('medicine', '💊', 'Medicine')}
      ${navItem('bills', '💸', 'Bills')}
      ${navItem('family', '👨‍👩‍👧', 'Family')}
      ${navItem('habits', '🔁', 'Habits')}
      ${navItem('checklists', '✅', 'Checklists')}

      <div class="sidebar-section-label">Insights</div>
      ${navItem('reports', '📊', 'Reports')}
      ${navItem('rewards', '🏅', 'Rewards')}

      <div class="sidebar-section-label">System</div>
      ${navItem('settings', '⚙️', 'Settings')}

      <div style="margin-top:auto;padding:12px 8px">
        <div style="font-size:11px;color:var(--text-muted);text-align:center">
          Hello, ${name}! 👋
        </div>
      </div>
    </nav>

    <!-- Main Content -->
    <main class="content" id="content"></main>
  `;

  // Nav click handlers
  document.querySelectorAll('.nav-item').forEach(el => {
    el.addEventListener('click', () => navigate(el.dataset.page));
  });

  // Quick add on Enter
  document.getElementById('quick-input').addEventListener('keydown', e => {
    if (e.key === 'Enter') handleQuickAdd();
  });
}

function navItem(page, icon, label, badge = '') {
  return `<button class="nav-item" data-page="${page}">
    <span class="nav-icon">${icon}</span>
    <span>${label}</span>
    ${badge ? `<span class="nav-badge">${badge}</span>` : ''}
  </button>`;
}

// ── Dashboard ──────────────────────────────────────────────────────
async function renderDashboard(el) {
  const today = todayStr();
  const now = nowTimeStr();

  const todayReminders = App.reminders.filter(r => r.status === 'active' && (!r.start_date || r.start_date <= today));
  const overdue = App.reminders.filter(r => r.status === 'active' && isReminderOverdue(r.next_fire));
  const critical = App.reminders.filter(r => r.priority === 'critical' && r.status === 'active');
  const completedToday = await db("SELECT COUNT(*) as c FROM reminder_logs WHERE action='completed' AND date(timestamp)=?", [today]);
  const cCount = completedToday?.[0]?.c || 0;

  // Today's medicine doses
  const medDoses = [];
  for (const med of App.medicines) {
    const times = JSON.parse(med.dose_times || '[]');
    times.forEach(t => medDoses.push({ med, time: t, past: t <= now }));
  }
  medDoses.sort((a, b) => a.time.localeCompare(b.time));

  // Bills due soon
  const billsDue = App.bills.filter(b => {
    const day = parseInt(b.due_day);
    const todayDay = new Date().getDate();
    const diff = day - todayDay;
    return (diff >= 0 && diff <= parseInt(b.warning_days || 3)) || diff < 0;
  });

  el.innerHTML = `
    <!-- Stats Bar -->
    <div class="stats-bar">
      <div class="stat-card purple"><div class="stat-value">${todayReminders.length}</div><div class="stat-label">Today's Tasks</div></div>
      <div class="stat-card green"><div class="stat-value">${cCount}</div><div class="stat-label">Completed Today</div></div>
      <div class="stat-card red"><div class="stat-value">${overdue.length}</div><div class="stat-label">Overdue</div></div>
      <div class="stat-card orange"><div class="stat-value">${critical.length}</div><div class="stat-label">Critical</div></div>
    </div>

    ${overdue.length > 0 ? `
    <div class="attention-banner">
      <span style="font-size:24px">🚨</span>
      <div>
        <strong style="color:var(--critical)">${overdue.length} overdue reminder${overdue.length > 1 ? 's' : ''}</strong>
        <div style="font-size:12px;color:var(--text-secondary);margin-top:2px">${overdue.map(r => r.title).slice(0, 3).join(', ')}${overdue.length > 3 ? '...' : ''}</div>
      </div>
      <button class="btn btn-danger btn-sm" style="margin-left:auto" onclick="navigate('reminders')">View All</button>
    </div>` : ''}

    <div class="dashboard-grid">
      <div>
        <!-- Today's Reminders -->
        <div class="section-header">
          <div class="section-title">📋 Today's Reminders</div>
          <button class="btn btn-primary btn-sm" onclick="navigate('add')">+ Add</button>
        </div>
        <div class="reminder-list" id="dashboard-reminders">
          ${todayReminders.length === 0 ? `<div class="empty-state"><div class="empty-icon">🎉</div><h3>All clear!</h3><p>No reminders for today. Add one!</p></div>` :
            todayReminders.slice(0, 8).map(r => reminderCard(r)).join('')}
        </div>
        ${todayReminders.length > 8 ? `<div style="text-align:center;margin-top:12px"><button class="btn btn-ghost btn-sm" onclick="navigate('reminders')">View all ${todayReminders.length} reminders</button></div>` : ''}

        <!-- Habits today -->
        ${App.habits.length > 0 ? `
        <div class="section-header" style="margin-top:24px">
          <div class="section-title">🔁 Today's Habits</div>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px">
          ${App.habits.slice(0, 4).map(h => habitMiniCard(h)).join('')}
        </div>` : ''}
      </div>

      <div>
        <!-- Medicine Today -->
        <div class="coming-up-panel" style="margin-bottom:16px">
          <div class="section-header">
            <div class="section-title">💊 Medicine Today</div>
            <button class="btn btn-ghost btn-sm" onclick="navigate('medicine')">All</button>
          </div>
          ${medDoses.length === 0 ? `<p style="color:var(--text-muted);font-size:13px">No medicines scheduled.</p>` :
            medDoses.slice(0, 5).map(d => `
            <div class="dose-row">
              <div class="dose-status ${d.past ? 'taken' : 'due'}">${d.past ? '✅' : '🕐'}</div>
              <div style="flex:1">
                <div style="font-size:13px;font-weight:600">${d.med.name}</div>
                <div style="font-size:11px;color:var(--text-muted)">${d.med.condition}</div>
              </div>
              <div style="font-family:var(--font-mono);font-size:12px;color:var(--text-secondary)">${formatTime(d.time)}</div>
            </div>`).join('')}
        </div>

        <!-- Bills Due -->
        <div class="coming-up-panel" style="margin-bottom:16px">
          <div class="section-header">
            <div class="section-title">💸 Bills Due Soon</div>
            <button class="btn btn-ghost btn-sm" onclick="navigate('bills')">All</button>
          </div>
          ${billsDue.length === 0 ? `<p style="color:var(--text-muted);font-size:13px">No bills due soon. ✨</p>` :
            billsDue.slice(0, 4).map(b => {
              const day = parseInt(b.due_day);
              const todayDay = new Date().getDate();
              const diff = day - todayDay;
              const status = diff < 0 ? 'overdue' : diff === 0 ? 'pending' : 'pending';
              return `<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
                <span style="font-size:20px">${billIcon(b.bill_type)}</span>
                <div style="flex:1">
                  <div style="font-size:13px;font-weight:600">${b.name}</div>
                  <div style="font-size:11px;color:var(--text-muted)">Due ${diff < 0 ? Math.abs(diff)+' days ago' : diff === 0 ? 'TODAY' : 'in '+diff+' days'}</div>
                </div>
                <span class="bill-status ${status}">${diff < 0 ? 'Overdue' : diff === 0 ? 'Due Today' : 'Upcoming'}</span>
              </div>`;
            }).join('')}
        </div>

        <!-- Quick Stats -->
        <div class="coming-up-panel">
          <div class="section-title" style="margin-bottom:12px">📈 This Week</div>
          <div style="display:flex;flex-direction:column;gap:8px">
            <div style="display:flex;justify-content:space-between;font-size:13px">
              <span style="color:var(--text-secondary)">Medicines taken</span>
              <span style="color:var(--medicine-color);font-weight:600">${App.medicines.length * 7} doses</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:13px">
              <span style="color:var(--text-secondary)">Active habits</span>
              <span style="color:var(--habit-color);font-weight:600">${App.habits.length}</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:13px">
              <span style="color:var(--text-secondary)">Family members</span>
              <span style="color:var(--family-color);font-weight:600">${App.family.length}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  `;
}

// ── Reminder Card ──────────────────────────────────────────────────
function reminderCard(r) {
  const isOverdue = isReminderOverdue(r.next_fire);
  const tags = JSON.parse(r.tags || '[]');
  return `
    <div class="reminder-card ${r.priority} ${isOverdue ? 'overdue' : ''}" id="rcard-${r.id}">
      <div class="reminder-check ${r.status === 'completed' ? 'done' : ''}" onclick="completeReminder('${r.id}')">
        ${r.status === 'completed' ? '✓' : ''}
      </div>
      <div class="reminder-body">
        <div class="reminder-title">${r.title}</div>
        ${r.why_it_matters ? `<div class="reminder-why">${r.why_it_matters}</div>` : ''}
        <div class="reminder-meta">
          <span class="tag ${r.category}">${categoryIcon(r.category)} ${r.category}</span>
          <span class="tag ${r.priority}">${priorityLabel(r.priority)}</span>
          ${tags.slice(0, 2).map(t => `<span class="tag">#${t}</span>`).join('')}
          <span class="reminder-time ${isOverdue ? 'overdue' : ''}">
            ${r.reminder_time ? formatTime(r.reminder_time) : r.repeat_type !== 'once' ? '🔁 ' + r.repeat_type : ''}
            ${isOverdue ? ' ⚠️ Overdue' : ''}
          </span>
        </div>
      </div>
      <div class="reminder-actions">
        <button class="action-btn done" onclick="completeReminder('${r.id}')">✓ Done</button>
        <button class="action-btn snooze" onclick="snoozeReminder('${r.id}')">💤 Snooze</button>
        <button class="action-btn" onclick="editReminder('${r.id}')">✏️</button>
        <button class="action-btn" onclick="deleteReminder('${r.id}')" style="color:var(--critical)">🗑</button>
      </div>
    </div>`;
}

function habitMiniCard(h) {
  const pct = Math.round(h.completion_rate || 0);
  return `
    <div class="habit-card" style="padding:12px 16px">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="flex:1">
          <div style="font-size:13px;font-weight:600">${h.name}</div>
          <div style="font-size:11px;color:var(--text-muted)">${h.frequency} • ${formatTime(h.target_time)}</div>
        </div>
        <span class="streak-badge">🔥 ${h.streak}d</span>
        <button class="btn btn-sm btn-primary" onclick="logHabit('${h.id}')">Done</button>
      </div>
      <div class="progress-bar"><div class="progress-fill green" style="width:${pct}%"></div></div>
    </div>`;
}

// ── Reminders Page ─────────────────────────────────────────────────
async function renderReminders(el) {
  const filter = { status: 'all', category: 'all', priority: 'all' };

  const render = (reminders) => {
    const filtered = reminders.filter(r => {
      if (filter.status !== 'all' && r.status !== filter.status) return false;
      if (filter.category !== 'all' && r.category !== filter.category) return false;
      if (filter.priority !== 'all' && r.priority !== filter.priority) return false;
      return true;
    });

    document.getElementById('reminders-list').innerHTML = filtered.length === 0
      ? `<div class="empty-state"><div class="empty-icon">📭</div><h3>No reminders found</h3><p>Try changing filters or add a new reminder.</p></div>`
      : filtered.map(r => reminderCard(r)).join('');
    document.getElementById('reminder-count').textContent = filtered.length + ' reminder' + (filtered.length !== 1 ? 's' : '');
  };

  el.innerHTML = `
    <div class="page-header">
      <div><div class="page-title">🔔 All Reminders</div><div class="page-subtitle" id="reminder-count"></div></div>
      <button class="btn btn-primary" onclick="navigate('add')">➕ Add Reminder</button>
    </div>

    <!-- Filters -->
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px">
      <select class="form-select" style="width:auto" onchange="filter.status=this.value;render(App.reminders)" id="f-status">
        <option value="all">All Status</option>
        <option value='active'>Active</option>
        <option value='completed'>Completed</option>
        <option value="paused">Paused</option>
      </select>
      <select class="form-select" style="width:auto" onchange="filter.category=this.value;render(App.reminders)">
        <option value="all">All Categories</option>
        <option value="general">General</option>
        <option value="medicine">Medicine</option>
        <option value="bills">Bills</option>
        <option value="family">Family</option>
        <option value="work">Work</option>
        <option value="health">Health</option>
        <option value="personal">Personal</option>
      </select>
      <select class="form-select" style="width:auto" onchange="filter.priority=this.value;render(App.reminders)">
        <option value="all">All Priorities</option>
        <option value="critical">🚨 Critical</option>
        <option value="important">⚠️ Important</option>
        <option value="normal">✅ Normal</option>
      </select>
      <input type="text" class="form-input" style="width:200px" placeholder="🔍 Search..." oninput="searchReminders(this.value,App.reminders,render)"/>
    </div>

    <div class="reminder-list" id="reminders-list"></div>
  `;

  // expose filter to closures
  window.filter = filter;
  window.render = render;
  render(App.reminders);
}

function searchReminders(query, reminders, renderFn) {
  const q = query.toLowerCase();
  const filtered = reminders.filter(r => r.title.toLowerCase().includes(q) || (r.why_it_matters || '').toLowerCase().includes(q) || (r.category || '').toLowerCase().includes(q));
  renderFn(filtered);
}

// ── Add / Edit Reminder ────────────────────────────────────────────
async function renderAddReminder(el, existing = null) {
  const isEdit = !!existing;
  const r = existing || {
    id: uuid(), task_type: 'reminder', category: 'general', priority: 'normal',
    urgency_quadrant: 'important-not-urgent', repeat_type: 'once', alert_style: 'sound-popup',
    snooze_duration: 10, is_private: 0, reminder_time: '', start_date: todayStr(),
    end_date: '', title: '', why_it_matters: '', notes: '', tags: '[]',
    assigned_to: 'me', time_mode: 'exact', reminder_times: '[]'
  };

  el.innerHTML = `
    <div class="page-header">
      <div><div class="page-title">${isEdit ? '✏️ Edit Reminder' : '➕ Add New Reminder'}</div></div>
      <button class="btn btn-ghost" onclick="navigate('reminders')">← Back</button>
    </div>

    <div style="display:grid;grid-template-columns:1fr 340px;gap:20px">
      <div>
        <!-- Basic Info -->
        <div class="card" style="margin-bottom:16px">
          <div style="font-size:15px;font-weight:700;margin-bottom:16px">📝 Basic Information</div>
          <div class="form-grid">
            <div class="form-group full">
              <label class="form-label">Task Type</label>
              <div style="display:flex;gap:8px;flex-wrap:wrap">
                ${['reminder','task','habit','event','checklist','routine'].map(t => `
                  <button class="priority-option ${r.task_type === t ? 'selected-normal' : ''}" onclick="selectTaskType(this,'${t}')">${taskTypeIcon(t)} ${t}</button>
                `).join('')}
              </div>
              <input type="hidden" id="f-task-type" value="${r.task_type}"/>
            </div>
            <div class="form-group full">
              <label class="form-label">Title *</label>
              <input type="text" class="form-input" id="f-title" value="${r.title}" placeholder="e.g. Take Blood Pressure Medicine" />
            </div>
            <div class="form-group full">
              <label class="form-label">Why does this matter? <span style="color:var(--text-muted)">(motivational context)</span></label>
              <input type="text" class="form-input" id="f-why" value="${r.why_it_matters}" placeholder="e.g. To keep BP under control" />
            </div>
            <div class="form-group">
              <label class="form-label">Category</label>
              <select class="form-select" id="f-category">
                ${['general','medicine','bills','family','work','health','personal','shopping','education','fitness'].map(c =>
                  `<option value="${c}" ${r.category === c ? 'selected' : ''}>${categoryIcon(c)} ${c}</option>`
                ).join('')}
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Assigned To</label>
              <select class="form-select" id="f-assigned">
                <option value="me" ${r.assigned_to === 'me' ? 'selected' : ''}>👤 Me</option>
                ${App.family.map(f => `<option value="${f.id}" ${r.assigned_to === f.id ? 'selected' : ''}>${f.name}</option>`).join('')}
              </select>
            </div>
            <div class="form-group full">
              <label class="form-label">Notes</label>
              <textarea class="form-textarea" id="f-notes" placeholder="Any extra details...">${r.notes}</textarea>
            </div>
            <div class="form-group full">
              <label class="form-label">Tags <span style="color:var(--text-muted)">(comma separated)</span></label>
              <input type="text" class="form-input" id="f-tags" value="${JSON.parse(r.tags || '[]').join(', ')}" placeholder="health, family, urgent" />
            </div>
          </div>
        </div>

        <!-- Schedule -->
        <div class="card" style="margin-bottom:16px">
          <div style="font-size:15px;font-weight:700;margin-bottom:16px">🗓 Schedule</div>
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Repeat</label>
              <select class="form-select" id="f-repeat">
                <option value="once" ${r.repeat_type==='once'?'selected':''}>One-time</option>
                <option value="daily" ${r.repeat_type==='daily'?'selected':''}>Daily</option>
                <option value="weekly" ${r.repeat_type==='weekly'?'selected':''}>Weekly</option>
                <option value="monthly" ${r.repeat_type==='monthly'?'selected':''}>Monthly</option>
                <option value="custom" ${r.repeat_type==='custom'?'selected':''}>Custom</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Time</label>
              <input type="time" class="form-input" id="f-time" value="${r.reminder_time}" />
            </div>
            <div class="form-group">
              <label class="form-label">Start Date</label>
              <input type="date" class="form-input" id="f-start" value="${r.start_date || todayStr()}" />
            </div>
            <div class="form-group">
              <label class="form-label">End Date <span style="color:var(--text-muted)">(optional)</span></label>
              <input type="date" class="form-input" id="f-end" value="${r.end_date}" />
            </div>
          </div>
        </div>
      </div>

      <div>
        <!-- Priority -->
        <div class="card" style="margin-bottom:16px">
          <div style="font-size:15px;font-weight:700;margin-bottom:12px">⚡ Priority</div>
          <div class="priority-selector" id="priority-selector">
            ${['normal','important','critical'].map(p => `
              <button class="priority-option ${r.priority === p ? 'selected-' + p : ''}" onclick="selectPriority(this,'${p}')">${priorityLabel(p)}</button>
            `).join('')}
          </div>
          <input type="hidden" id="f-priority" value="${r.priority}" />
        </div>

        <!-- Urgency Matrix -->
        <div class="card" style="margin-bottom:16px">
          <div style="font-size:15px;font-weight:700;margin-bottom:12px">📊 Urgency Matrix</div>
          <div class="quadrant-grid" id="quadrant-grid">
            ${[
              {v:'urgent-important',icon:'🔥',l:'DO NOW',d:'Urgent + Important'},
              {v:'important-not-urgent',icon:'📌',l:'SCHEDULE',d:'Important, Not Urgent'},
              {v:'urgent-not-important',icon:'🏃',l:'DELEGATE',d:'Urgent, Not Important'},
              {v:'not-urgent-not-important',icon:'🗑',l:'ELIMINATE',d:'Neither'},
            ].map(q => `
              <button class="quadrant-option ${r.urgency_quadrant === q.v ? 'selected' : ''}" onclick="selectQuadrant(this,'${q.v}')">
                <span class="q-icon">${q.icon}</span>${q.l}<br><span style="font-weight:400;color:var(--text-muted)">${q.d}</span>
              </button>
            `).join('')}
          </div>
          <input type="hidden" id="f-quadrant" value="${r.urgency_quadrant}" />
        </div>

        <!-- Alert Settings -->
        <div class="card" style="margin-bottom:16px">
          <div style="font-size:15px;font-weight:700;margin-bottom:12px">🔔 Alert Settings</div>
          <div class="form-group" style="margin-bottom:10px">
            <label class="form-label">Alert Style</label>
            <select class="form-select" id="f-alert">
              <option value="sound-popup" ${r.alert_style==='sound-popup'?'selected':''}>🔊 Sound + Popup</option>
              <option value="popup-only" ${r.alert_style==='popup-only'?'selected':''}>💬 Popup Only</option>
              <option value="silent" ${r.alert_style==='silent'?'selected':''}>🔕 Silent</option>
              <option value="escalate" ${r.alert_style==='escalate'?'selected':''}>📢 Escalate if Missed</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Snooze Duration (minutes)</label>
            <input type="number" class="form-input" id="f-snooze" value="${r.snooze_duration}" min="1" max="60" />
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px">
            <span class="form-label">Private (hide title in notification)</span>
            <div class="toggle ${r.is_private ? 'on' : ''}" id="t-private" onclick="this.classList.toggle('on');document.getElementById('f-private').value=this.classList.contains('on')?1:0"></div>
            <input type="hidden" id="f-private" value="${r.is_private}" />
          </div>
        </div>

        <!-- Save -->
        <button class="btn btn-primary" style="width:100%;justify-content:center;padding:14px" onclick="saveReminder('${r.id}',${isEdit})">
          ${isEdit ? '💾 Update Reminder' : '✅ Save Reminder'}
        </button>
        ${isEdit ? `<button class="btn btn-ghost" style="width:100%;justify-content:center;margin-top:8px" onclick="navigate('reminders')">Cancel</button>` : ''}
      </div>
    </div>
  `;
}

// Selector helpers
function selectPriority(btn, val) {
  document.querySelectorAll('#priority-selector .priority-option').forEach(b => b.className = 'priority-option');
  btn.className = `priority-option selected-${val}`;
  document.getElementById('f-priority').value = val;
}
function selectQuadrant(btn, val) {
  document.querySelectorAll('#quadrant-grid .quadrant-option').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('f-quadrant').value = val;
}
function selectTaskType(btn, val) {
  document.querySelectorAll('#f-task-type').forEach(() => {});
  btn.parentElement.querySelectorAll('.priority-option').forEach(b => b.className = 'priority-option');
  btn.className = 'priority-option selected-normal';
  document.getElementById('f-task-type').value = val;
}

async function saveReminder(id, isEdit) {
  const title = document.getElementById('f-title').value.trim();
  if (!title) { toast('Please enter a title', 'warning'); return; }

  const tags = document.getElementById('f-tags').value.split(',').map(t => t.trim()).filter(Boolean);
  const time = document.getElementById('f-time').value;
  const startDate = document.getElementById('f-start').value || todayStr();
  const repeatType = document.getElementById('f-repeat').value;

  // Compute next_fire in local time (matches main-process alarm scheduler)
  const nextFire = computeNextFireLocal(startDate, time, repeatType);

  const params = [
    id,
    title,
    document.getElementById('f-task-type').value,
    document.getElementById('f-category').value,
    document.getElementById('f-why').value,
    repeatType,
    time,
    startDate,
    document.getElementById('f-end').value,
    document.getElementById('f-priority').value,
    document.getElementById('f-quadrant').value,
    document.getElementById('f-alert').value,
    parseInt(document.getElementById('f-snooze').value) || 10,
    document.getElementById('f-assigned').value,
    parseInt(document.getElementById('f-private').value) || 0,
    document.getElementById('f-notes').value,
    JSON.stringify(tags),
    nextFire,
  ];

  let ok;
  if (isEdit) {
    ok = await dbRun(`UPDATE reminders SET title=?,task_type=?,category=?,why_it_matters=?,repeat_type=?,reminder_time=?,start_date=?,end_date=?,priority=?,urgency_quadrant=?,alert_style=?,snooze_duration=?,assigned_to=?,is_private=?,notes=?,tags=?,next_fire=?,updated_at=? WHERE id=?`,
      [...params.slice(1), new Date().toISOString(), id]);
  } else {
    ok = await dbRun(`INSERT INTO reminders (id,title,task_type,category,why_it_matters,repeat_type,reminder_time,start_date,end_date,priority,urgency_quadrant,alert_style,snooze_duration,assigned_to,is_private,notes,tags,next_fire,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',datetime('now'),datetime('now'))`,
      params);
  }

  if (!ok) return;
  toast(isEdit ? 'Reminder updated!' : 'Reminder saved!');
  navigate('reminders');
}

async function completeReminder(id) {
  await db("UPDATE reminders SET status='completed', alarm_rings=0, last_completed=?, updated_at=? WHERE id=?",
    [new Date().toISOString(), new Date().toISOString(), id]);
  await db("INSERT INTO reminder_logs (id,reminder_id,action,timestamp) VALUES (?,?,'completed',datetime('now'))",
    [uuid(), id]);
  toast('✅ Marked as complete!');
  const card = document.getElementById(`rcard-${id}`);
  if (card) { card.style.opacity = '0.4'; card.style.pointerEvents = 'none'; }
  await loadAllData();
  updateBadges();
}

async function snoozeReminder(id) {
  const duration = parseInt(App.settings.snooze_duration) || 10;
  const newFire = toLocalFireISO(new Date(Date.now() + duration * 60000));
  await db("UPDATE reminders SET next_fire=?, alarm_rings=0, snooze_count=snooze_count+1, updated_at=? WHERE id=?",
    [newFire, new Date().toISOString(), id]);
  await db("INSERT INTO reminder_logs (id,reminder_id,action,timestamp) VALUES (?,?,'snoozed',datetime('now'))", [uuid(), id]);
  toast(`💤 Snoozed for ${duration} minutes`);
}

async function deleteReminder(id) {
  if (!confirm('Delete this reminder?')) return;
  await db("UPDATE reminders SET status='deleted' WHERE id=?", [id]);
  toast('Reminder deleted', 'warning');
  navigate('reminders');
}

function editReminder(id) {
  const r = App.reminders.find(x => x.id === id);
  if (r) renderAddReminder(document.getElementById('content'), r);
}

// ── Medicine Module ────────────────────────────────────────────────
async function renderMedicine(el) {
  const today = todayStr();
  const logs = await db('SELECT * FROM medicine_logs WHERE log_date=?', [today]) || [];

  el.innerHTML = `
    <div class="page-header">
      <div><div class="page-title">💊 Medicine Tracker</div><div class="page-subtitle">Track all your medications</div></div>
      <button class="btn btn-primary" onclick="showAddMedicine()">➕ Add Medicine</button>
    </div>

    <div class="tabs">
      <button class="tab-btn active" onclick="switchMedTab(this,'today')">Today's Doses</button>
      <button class="tab-btn" onclick="switchMedTab(this,'all')">All Medicines</button>
      <button class="tab-btn" onclick="switchMedTab(this,'history')">History</button>
    </div>

    <div id="med-tab-today">
      ${App.medicines.length === 0
        ? `<div class="empty-state"><div class="empty-icon">💊</div><h3>No medicines added</h3><p>Add your first medicine to start tracking.</p></div>`
        : App.medicines.map(med => {
          const times = JSON.parse(med.dose_times || '["08:00"]');
          const medLogs = logs.filter(l => l.medicine_id === med.id);
          return `
          <div class="medicine-card" style="margin-bottom:12px">
            <div style="display:flex;align-items:flex-start;justify-content:space-between">
              <div>
                <div style="font-size:15px;font-weight:700">${med.name}</div>
                <div style="font-size:12px;color:var(--text-muted)">${med.condition} • ${med.food_timing} food • ${times.length}x daily</div>
              </div>
              <div style="display:flex;gap:6px">
                <button class="action-btn" onclick="editMedicine('${med.id}')">✏️ Edit</button>
                <button class="action-btn" onclick="deleteMedicine('${med.id}')" style="color:var(--critical)">🗑</button>
              </div>
            </div>
            ${times.map((t, i) => {
              const log = medLogs.find(l => l.dose_time === t);
              const isPast = t <= nowTimeStr();
              const status = log ? log.status : isPast ? 'missed' : 'upcoming';
              return `
              <div class="dose-row">
                <div class="dose-status ${status}">
                  ${status === 'taken' ? '✅' : status === 'missed' ? '❌' : isPast ? '⏰' : '🕐'}
                </div>
                <div style="flex:1">
                  <div style="font-size:13px;font-weight:600">Dose ${i + 1}</div>
                  <div style="font-size:11px;color:var(--text-muted)">${formatTime(t)}</div>
                </div>
                ${status !== 'taken' ? `<button class="btn btn-primary btn-sm" onclick="markDoseTaken('${med.id}','${t}')">Mark Taken</button>` : `<span style="color:var(--normal);font-size:12px;font-weight:600">✓ Taken</span>`}
              </div>`;
            }).join('')}
          </div>`;
        }).join('')}
    </div>
    <div id="med-tab-all" style="display:none">
      ${App.medicines.map(med => `
        <div class="card card-sm" style="margin-bottom:10px;display:flex;align-items:center;gap:12px">
          <span style="font-size:24px">💊</span>
          <div style="flex:1">
            <div style="font-weight:700">${med.name}</div>
            <div style="font-size:12px;color:var(--text-muted)">${med.condition} • Started ${formatDate(med.start_date)} ${med.end_date ? '• Ends '+formatDate(med.end_date) : ''}</div>
            <div style="font-size:12px;color:var(--text-secondary);margin-top:4px">
              ${JSON.parse(med.dose_times || '[]').map(t => formatTime(t)).join(', ')} • ${med.food_timing} food
            </div>
          </div>
          <button class="action-btn" onclick="editMedicine('${med.id}')">✏️ Edit</button>
          <button class="action-btn" onclick="deleteMedicine('${med.id}')" style="color:var(--critical)">🗑</button>
        </div>
      `).join('')}
    </div>
    <div id="med-tab-history" style="display:none">
      <p style="color:var(--text-muted)">Loading history...</p>
    </div>
  `;
}

function switchMedTab(btn, tab) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  ['today', 'all', 'history'].forEach(t => {
    const el = document.getElementById(`med-tab-${t}`);
    if (el) el.style.display = t === tab ? '' : 'none';
  });
}

async function markDoseTaken(medId, doseTime) {
  const logId = uuid();
  await db(`INSERT OR REPLACE INTO medicine_logs (id,medicine_id,dose_time,scheduled_time,status,taken_at,log_date) VALUES (?,?,?,?,?,?,?)`,
    [logId, medId, doseTime, doseTime, 'taken', new Date().toISOString(), todayStr()]);
  toast('💊 Dose marked as taken!');
  navigate('medicine');
}

function showAddMedicine() {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'med-modal';
  overlay.innerHTML = `
    <div class="modal">
      <div class="modal-header">
        <div class="modal-title">💊 Add Medicine</div>
        <button class="modal-close" onclick="document.getElementById('med-modal').remove()">✕</button>
      </div>
      <div class="form-grid">
        <div class="form-group full"><label class="form-label">Medicine Name *</label><input type="text" class="form-input" id="m-name" placeholder="e.g. Metformin 500mg" /></div>
        <div class="form-group full"><label class="form-label">Condition / Purpose</label><input type="text" class="form-input" id="m-condition" placeholder="e.g. Diabetes" /></div>
        <div class="form-group"><label class="form-label">Doses per Day</label><input type="number" class="form-input" id="m-doses" value="1" min="1" max="8" onchange="updateDoseTimes(this.value)"/></div>
        <div class="form-group"><label class="form-label">Food Timing</label>
          <select class="form-select" id="m-food"><option value="before">Before Food</option><option value="after" selected>After Food</option><option value="with">With Food</option><option value="empty">Empty Stomach</option></select>
        </div>
        <div class="form-group full" id="dose-times-container">
          <label class="form-label">Dose Times</label>
          <input type="time" class="form-input" id="m-time-0" value="08:00" style="margin-bottom:6px"/>
        </div>
        <div class="form-group"><label class="form-label">Start Date</label><input type="date" class="form-input" id="m-start" value="${todayStr()}"/></div>
        <div class="form-group"><label class="form-label">End Date (optional)</label><input type="date" class="form-input" id="m-end" /></div>
        <div class="form-group full"><label class="form-label">Notes</label><textarea class="form-textarea" id="m-notes" placeholder="Dosage info, doctor's note..."></textarea></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="document.getElementById('med-modal').remove()">Cancel</button>
        <button class="btn btn-primary" onclick="saveMedicine()">💊 Save Medicine</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
}

function updateDoseTimes(count) {
  const container = document.getElementById('dose-times-container');
  const defaults = ['08:00', '14:00', '20:00', '22:00', '06:00', '12:00', '18:00', '23:00'];
  let html = '<label class="form-label">Dose Times</label>';
  for (let i = 0; i < parseInt(count); i++) {
    html += `<input type="time" class="form-input" id="m-time-${i}" value="${defaults[i] || '08:00'}" style="margin-bottom:6px"/>`;
  }
  container.innerHTML = html;
}

async function saveMedicine() {
  const name = document.getElementById('m-name').value.trim();
  if (!name) { toast('Enter medicine name', 'warning'); return; }
  const doses = parseInt(document.getElementById('m-doses').value) || 1;
  const times = [];
  for (let i = 0; i < doses; i++) {
    const t = document.getElementById(`m-time-${i}`);
    if (t) times.push(t.value);
  }
  const id = uuid();
  if (!await dbRun(`INSERT INTO medicines (id,name,condition,doses_per_day,dose_times,food_timing,start_date,end_date,notes,status) VALUES (?,?,?,?,?,?,?,?,?,?)`,
    [id, name, document.getElementById('m-condition').value, doses, JSON.stringify(times),
     document.getElementById('m-food').value, document.getElementById('m-start').value,
     document.getElementById('m-end').value, '', 'active'])) return;

  const startDate = document.getElementById('m-start').value || todayStr();
  for (const t of times) {
    const rid = uuid();
    if (!await dbRun(`INSERT INTO reminders (id,title,task_type,category,why_it_matters,repeat_type,reminder_time,start_date,priority,alert_style,status,next_fire,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))`,
      [rid, `Take ${name}`, 'reminder', 'medicine', document.getElementById('m-condition').value || 'Medication', 'daily', t,
       startDate, 'important', 'sound-popup', 'active', computeNextFireLocal(startDate, t, 'daily')])) return;
  }

  document.getElementById('med-modal').remove();
  toast(`💊 ${name} added!`);
  navigate('medicine');
}

async function deleteMedicine(id) {
  if (!confirm('Remove this medicine?')) return;
  await db("UPDATE medicines SET status='inactive' WHERE id=?", [id]);
  toast('Medicine removed', 'warning');
  navigate('medicine');
}

function editMedicine(id) {
  toast('Edit medicine — coming soon', 'warning');
}

// ── Bills Module ───────────────────────────────────────────────────
async function renderBills(el) {
  const todayDay = new Date().getDate();

  el.innerHTML = `
    <div class="page-header">
      <div><div class="page-title">💸 Bills & Payments</div><div class="page-subtitle">Never miss a payment</div></div>
      <button class="btn btn-primary" onclick="showAddBill()">➕ Add Bill</button>
    </div>

    <div style="display:flex;flex-direction:column;gap:10px" id="bills-list">
      ${App.bills.length === 0
        ? `<div class="empty-state"><div class="empty-icon">💸</div><h3>No bills added</h3><p>Add your recurring bills to track payment due dates.</p></div>`
        : App.bills.map(b => {
          const diff = parseInt(b.due_day) - todayDay;
          const status = diff < 0 ? 'overdue' : diff <= parseInt(b.warning_days || 3) ? 'pending' : 'paid';
          const label = diff < 0 ? `${Math.abs(diff)}d overdue` : diff === 0 ? 'Due TODAY' : `Due in ${diff}d`;
          return `
          <div class="bill-card">
            <span class="bill-icon">${billIcon(b.bill_type)}</span>
            <div class="bill-info">
              <div class="bill-name">${b.name}</div>
              <div class="bill-due">${label} • Day ${b.due_day} every month ${b.amount > 0 ? '• ₹' + b.amount.toLocaleString('en-IN') : ''}</div>
            </div>
            <span class="bill-status ${status}">${diff < 0 ? '🔴 Overdue' : diff === 0 ? '⚠️ Due Today' : diff <= 3 ? '⏳ Due Soon' : '✅ OK'}</span>
            <div style="display:flex;gap:6px">
              <button class="btn btn-primary btn-sm" onclick="markBillPaid('${b.id}')">✓ Paid</button>
              <button class="action-btn" onclick="deleteBill('${b.id}')" style="color:var(--critical)">🗑</button>
            </div>
          </div>`;
        }).join('')}
    </div>
  `;
}

function billIcon(type) {
  const icons = { electricity: '⚡', water: '💧', gas: '🔥', internet: '🌐', phone: '📱', rent: '🏠', insurance: '🛡️', credit: '💳', emi: '🏦', subscription: '📺', other: '📄' };
  return icons[type] || '📄';
}

function showAddBill() {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'bill-modal';
  overlay.innerHTML = `
    <div class="modal">
      <div class="modal-header">
        <div class="modal-title">💸 Add Bill</div>
        <button class="modal-close" onclick="document.getElementById('bill-modal').remove()">✕</button>
      </div>
      <div class="form-grid">
        <div class="form-group full"><label class="form-label">Bill Name *</label><input type="text" class="form-input" id="b-name" placeholder="e.g. BSES Electricity" /></div>
        <div class="form-group"><label class="form-label">Bill Type</label>
          <select class="form-select" id="b-type">
            ${['electricity','water','gas','internet','phone','rent','insurance','credit','emi','subscription','other'].map(t => `<option value="${t}">${billIcon(t)} ${t}</option>`).join('')}
          </select>
        </div>
        <div class="form-group"><label class="form-label">Amount (₹)</label><input type="number" class="form-input" id="b-amount" placeholder="0" min="0"/></div>
        <div class="form-group"><label class="form-label">Due Day of Month</label><input type="number" class="form-input" id="b-due" value="1" min="1" max="31"/></div>
        <div class="form-group"><label class="form-label">Warn Me (days before)</label><input type="number" class="form-input" id="b-warn" value="3" min="1" max="14"/></div>
        <div class="form-group full"><label class="form-label">Account / Notes</label><input type="text" class="form-input" id="b-notes" placeholder="Account number, bank, etc."/></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="document.getElementById('bill-modal').remove()">Cancel</button>
        <button class="btn btn-primary" onclick="saveBill()">💾 Save Bill</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
}

async function saveBill() {
  const name = document.getElementById('b-name').value.trim();
  if (!name) { toast('Enter bill name', 'warning'); return; }
  const dueDay = parseInt(document.getElementById('b-due').value) || 1;
  const id = uuid();
  if (!await dbRun(`INSERT INTO bills (id,name,bill_type,amount,due_day,warning_days,account_info,status) VALUES (?,?,?,?,?,?,?,?)`,
    [id, name, document.getElementById('b-type').value, parseFloat(document.getElementById('b-amount').value) || 0,
     dueDay, parseInt(document.getElementById('b-warn').value) || 3, document.getElementById('b-notes').value, 'active'])) return;

  const rid = uuid();
  const nextFire = computeNextFireLocal(todayStr(), '09:00', 'monthly');
  if (!await dbRun(`INSERT INTO reminders (id,title,task_type,category,why_it_matters,repeat_type,reminder_time,start_date,priority,alert_style,status,next_fire,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))`,
    [rid, `Pay ${name}`, 'reminder', 'bills', 'Avoid late payment charges', 'monthly', '09:00', todayStr(), 'important', 'sound-popup', 'active', nextFire])) return;

  document.getElementById('bill-modal').remove();
  toast(`💸 ${name} added!`);
  navigate('bills');
}

async function markBillPaid(id) {
  const logId = uuid();
  await db(`INSERT INTO bill_history (id,bill_id,paid_date,amount) VALUES (?,?,?,0)`, [logId, id, todayStr()]);
  await db(`UPDATE bills SET payment_status='paid' WHERE id=?`, [id]);
  toast('✅ Bill marked as paid!');
  navigate('bills');
}

async function deleteBill(id) {
  if (!confirm('Remove this bill?')) return;
  await db("UPDATE bills SET status='inactive' WHERE id=?", [id]);
  toast('Bill removed', 'warning');
  navigate('bills');
}

// ── Family Module ──────────────────────────────────────────────────
async function renderFamily(el) {
  el.innerHTML = `
    <div class="page-header">
      <div><div class="page-title">👨‍👩‍👧 Family</div><div class="page-subtitle">Manage reminders for loved ones</div></div>
      <button class="btn btn-primary" onclick="showAddFamily()">➕ Add Member</button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px">
      ${App.family.length === 0
        ? `<div class="empty-state" style="grid-column:1/-1"><div class="empty-icon">👨‍👩‍👧</div><h3>No family members</h3><p>Add family members to assign reminders to them.</p></div>`
        : App.family.map(f => `
          <div class="card">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
              <div style="width:44px;height:44px;border-radius:50%;background:var(--accent-dim);display:flex;align-items:center;justify-content:center;font-size:20px">${roleIcon(f.role)}</div>
              <div>
                <div style="font-size:15px;font-weight:700">${f.name}</div>
                <div style="font-size:12px;color:var(--text-muted)">${f.role}</div>
              </div>
              ${f.is_emergency_contact ? `<span class="tag critical" style="margin-left:auto">🚨 Emergency</span>` : ''}
            </div>
            ${f.phone ? `<div style="font-size:12px;color:var(--text-secondary);margin-bottom:4px">📱 ${f.phone}</div>` : ''}
            ${f.email ? `<div style="font-size:12px;color:var(--text-secondary);margin-bottom:12px">✉️ ${f.email}</div>` : ''}
            <div style="display:flex;gap:8px">
              <button class="btn btn-ghost btn-sm" onclick="viewFamilyReminders('${f.id}','${f.name}')">📋 Reminders</button>
              <button class="action-btn" onclick="deleteFamily('${f.id}')" style="color:var(--critical)">🗑</button>
            </div>
          </div>
        `).join('')}
    </div>
  `;
}

function roleIcon(role) {
  const icons = { father: '👨', mother: '👩', son: '👦', daughter: '👧', spouse: '💑', sibling: '👫', grandparent: '👴', other: '🧑' };
  return icons[role] || '🧑';
}

function showAddFamily() {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'fam-modal';
  overlay.innerHTML = `
    <div class="modal">
      <div class="modal-header">
        <div class="modal-title">👨‍👩‍👧 Add Family Member</div>
        <button class="modal-close" onclick="document.getElementById('fam-modal').remove()">✕</button>
      </div>
      <div class="form-grid">
        <div class="form-group full"><label class="form-label">Name *</label><input type="text" class="form-input" id="fam-name" placeholder="e.g. Mom" /></div>
        <div class="form-group"><label class="form-label">Role</label>
          <select class="form-select" id="fam-role">
            ${['father','mother','son','daughter','spouse','sibling','grandparent','other'].map(r => `<option value="${r}">${roleIcon(r)} ${r}</option>`).join('')}
          </select>
        </div>
        <div class="form-group"><label class="form-label">Phone</label><input type="tel" class="form-input" id="fam-phone" placeholder="+91 9999999999"/></div>
        <div class="form-group full"><label class="form-label">Email</label><input type="email" class="form-input" id="fam-email" placeholder="email@example.com"/></div>
        <div class="form-group full" style="flex-direction:row;align-items:center;justify-content:space-between">
          <div><div class="form-label">Emergency Contact</div><div class="form-hint">Show on critical alert popup</div></div>
          <div class="toggle" id="t-emergency" onclick="this.classList.toggle('on')"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="document.getElementById('fam-modal').remove()">Cancel</button>
        <button class="btn btn-primary" onclick="saveFamily()">💾 Save Member</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
}

async function saveFamily() {
  const name = document.getElementById('fam-name').value.trim();
  if (!name) { toast('Enter a name', 'warning'); return; }
  const id = uuid();
  if (!await dbRun(`INSERT INTO family_members (id,name,role,phone,email,is_emergency_contact) VALUES (?,?,?,?,?,?)`,
    [id, name, document.getElementById('fam-role').value, document.getElementById('fam-phone').value,
     document.getElementById('fam-email').value, document.getElementById('t-emergency').classList.contains('on') ? 1 : 0])) return;
  document.getElementById('fam-modal').remove();
  toast(`👨‍👩‍👧 ${name} added!`);
  navigate('family');
}

async function deleteFamily(id) {
  if (!confirm('Remove this family member?')) return;
  await db("DELETE FROM family_members WHERE id=?", [id]);
  toast('Removed', 'warning');
  navigate('family');
}

function viewFamilyReminders(memberId, name) {
  const memberReminders = App.reminders.filter(r => r.assigned_to === memberId);
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'famr-modal';
  overlay.innerHTML = `
    <div class="modal">
      <div class="modal-header"><div class="modal-title">📋 ${name}'s Reminders</div><button class="modal-close" onclick="document.getElementById('famr-modal').remove()">✕</button></div>
      ${memberReminders.length === 0
        ? `<div class="empty-state"><div class="empty-icon">📭</div><h3>No reminders assigned</h3><p>Create a reminder and assign it to ${name}.</p></div>`
        : memberReminders.map(r => `<div style="padding:10px 0;border-bottom:1px solid var(--border)"><div style="font-weight:600">${r.title}</div><div style="font-size:12px;color:var(--text-muted)">${r.category} • ${r.repeat_type}</div></div>`).join('')}
    </div>
  `;
  document.body.appendChild(overlay);
}

// ── Habits Module ──────────────────────────────────────────────────
async function renderHabits(el) {
  el.innerHTML = `
    <div class="page-header">
      <div><div class="page-title">🔁 Habits</div><div class="page-subtitle">Build life-changing habits</div></div>
      <button class="btn btn-primary" onclick="showAddHabit()">➕ Add Habit</button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px">
      ${App.habits.length === 0
        ? `<div class="empty-state" style="grid-column:1/-1"><div class="empty-icon">🔁</div><h3>No habits yet</h3><p>Add your first habit to start building streaks!</p></div>`
        : App.habits.map(h => {
          const pct = Math.round(h.completion_rate || 0);
          return `
          <div class="habit-card">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px">
              <div>
                <div style="font-size:15px;font-weight:700">${h.name}</div>
                <div style="font-size:12px;color:var(--text-muted)">${h.frequency} • ${formatTime(h.target_time)}</div>
              </div>
              <span class="streak-badge">🔥 ${h.streak}d</span>
            </div>
            <div style="font-size:12px;color:var(--text-secondary);margin-bottom:8px">Best: ${h.best_streak}d • Rate: ${pct}%</div>
            <div class="progress-bar"><div class="progress-fill green" style="width:${pct}%"></div></div>
            <div style="display:flex;gap:8px;margin-top:12px">
              <button class="btn btn-primary btn-sm" style="flex:1;justify-content:center" onclick="logHabit('${h.id}')">✅ Done Today</button>
              <button class="action-btn" onclick="deleteHabit('${h.id}')" style="color:var(--critical)">🗑</button>
            </div>
          </div>`;
        }).join('')}
    </div>
  `;
}

function showAddHabit() {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'hab-modal';
  overlay.innerHTML = `
    <div class="modal">
      <div class="modal-header"><div class="modal-title">🔁 Add Habit</div><button class="modal-close" onclick="document.getElementById('hab-modal').remove()">✕</button></div>
      <div class="form-grid">
        <div class="form-group full"><label class="form-label">Habit Name *</label><input type="text" class="form-input" id="h-name" placeholder="e.g. Morning Walk, Meditation, Read 30 mins"/></div>
        <div class="form-group"><label class="form-label">Frequency</label>
          <select class="form-select" id="h-freq">
            <option value="daily">Daily</option><option value="weekdays">Weekdays</option>
            <option value="weekends">Weekends</option><option value="weekly">Weekly</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Target Time</label><input type="time" class="form-input" id="h-time" value="07:00"/></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="document.getElementById('hab-modal').remove()">Cancel</button>
        <button class="btn btn-primary" onclick="saveHabit()">🔁 Start Habit</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
}

async function saveHabit() {
  const name = document.getElementById('h-name').value.trim();
  if (!name) { toast('Enter habit name', 'warning'); return; }
  const id = uuid();
  const time = document.getElementById('h-time').value;
  const freq = document.getElementById('h-freq').value;

  if (!await dbRun(`INSERT INTO habits (id,name,frequency,target_time,streak,best_streak,completion_rate,status) VALUES (?,?,?,?,0,0,0,'active')`,
    [id, name, freq, time])) return;

  const rid = uuid();
  const nextFire = computeNextFireLocal(todayStr(), time, freq === 'daily' ? 'daily' : 'weekly');
  if (!await dbRun(`INSERT INTO reminders (id,title,task_type,category,why_it_matters,repeat_type,reminder_time,start_date,priority,alert_style,status,next_fire,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))`,
    [rid, name, 'habit', 'personal', 'Build a positive habit', freq === 'daily' ? 'daily' : 'weekly', time, todayStr(), 'normal', 'sound-popup', 'active', nextFire])) return;

  document.getElementById('hab-modal').remove();
  toast(`🔁 Habit started!`);
  navigate('habits');
}

async function logHabit(id) {
  const logId = uuid();
  await db(`INSERT OR REPLACE INTO habit_logs (id,habit_id,log_date,completed) VALUES (?,?,?,1)`, [logId, id, todayStr()]);

  // Update streak
  const habit = App.habits.find(h => h.id === id);
  if (habit) {
    const newStreak = (habit.streak || 0) + 1;
    const bestStreak = Math.max(newStreak, habit.best_streak || 0);
    await db(`UPDATE habits SET streak=?,best_streak=?,last_completed=?,completion_rate=MIN(100,completion_rate+3) WHERE id=?`,
      [newStreak, bestStreak, todayStr(), id]);
  }
  toast('🔥 Habit logged! Streak growing!');
  navigate('habits');
}

async function deleteHabit(id) {
  if (!confirm('Delete this habit? Your streak will be lost.')) return;
  await db("UPDATE habits SET status='inactive' WHERE id=?", [id]);
  toast('Habit removed', 'warning');
  navigate('habits');
}

// ── Calendar ───────────────────────────────────────────────────────
async function renderCalendar(el) {
  const now = new Date();
  let viewYear = now.getFullYear();
  let viewMonth = now.getMonth();

  const renderCal = () => {
    const firstDay = new Date(viewYear, viewMonth, 1);
    const lastDay = new Date(viewYear, viewMonth + 1, 0);
    const startWeekday = firstDay.getDay();
    const monthName = firstDay.toLocaleString('default', { month: 'long' });

    // Get events for this month
    const monthStr = `${viewYear}-${String(viewMonth + 1).padStart(2, '0')}`;
    const monthReminders = App.reminders.filter(r => r.start_date && r.start_date.startsWith(monthStr));

    let calHtml = '';
    // Header days
    ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(d => {
      calHtml += `<div style="text-align:center;font-size:11px;font-weight:700;color:var(--text-muted);padding:4px">${d}</div>`;
    });
    // Empty cells
    for (let i = 0; i < startWeekday; i++) calHtml += '<div></div>';
    // Days
    for (let d = 1; d <= lastDay.getDate(); d++) {
      const isToday = d === now.getDate() && viewMonth === now.getMonth() && viewYear === now.getFullYear();
      const dayStr = `${monthStr}-${String(d).padStart(2, '0')}`;
      const dayReminders = monthReminders.filter(r => r.start_date === dayStr);
      calHtml += `
        <div class="calendar-day ${isToday ? 'today' : ''}" onclick="showDayEvents('${dayStr}')">
          <div style="font-size:13px;font-weight:${isToday ? '700' : '400'}">${d}</div>
          ${dayReminders.slice(0, 3).map(r => `<div class="calendar-dot" style="background:${r.priority === 'critical' ? 'var(--critical)' : r.priority === 'important' ? 'var(--important)' : 'var(--accent)'}"></div>`).join('')}
        </div>`;
    }

    document.getElementById('cal-grid').innerHTML = calHtml;
    document.getElementById('cal-title').textContent = `${monthName} ${viewYear}`;
  };

  el.innerHTML = `
    <div class="page-header">
      <div><div class="page-title">📅 Calendar</div></div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-ghost btn-sm" onclick="viewMonth--;if(viewMonth<0){viewMonth=11;viewYear--;}renderCal()">◀</button>
        <span id="cal-title" style="font-size:15px;font-weight:700;padding:6px 12px"></span>
        <button class="btn btn-ghost btn-sm" onclick="viewMonth++;if(viewMonth>11){viewMonth=0;viewYear++;}renderCal()">▶</button>
      </div>
    </div>
    <div class="card">
      <div class="calendar-grid" id="cal-grid"></div>
    </div>
    <div id="day-events" style="margin-top:20px"></div>
  `;

  window.viewYear = viewYear;
  window.viewMonth = viewMonth;
  window.renderCal = renderCal;
  renderCal();
}

function showDayEvents(dateStr) {
  const dayReminders = App.reminders.filter(r => r.start_date === dateStr);
  const container = document.getElementById('day-events');
  container.innerHTML = `
    <div class="section-header"><div class="section-title">📋 ${formatDate(dateStr)}</div><button class="btn btn-primary btn-sm" onclick="navigate('add')">+ Add</button></div>
    ${dayReminders.length === 0
      ? `<p style="color:var(--text-muted)">No reminders on this day. <a href="#" onclick="navigate('add')" style="color:var(--accent)">Add one?</a></p>`
      : dayReminders.map(r => reminderCard(r)).join('')}
  `;
}

// ── Checklists ─────────────────────────────────────────────────────
async function renderChecklists(el) {
  const lists = await db("SELECT * FROM checklists WHERE status='active' ORDER BY created_at DESC") || [];

  el.innerHTML = `
    <div class="page-header">
      <div><div class="page-title">✅ Checklists</div><div class="page-subtitle">Grouped task lists</div></div>
      <button class="btn btn-primary" onclick="showAddChecklist()">➕ New List</button>
    </div>
    <div style="display:flex;flex-direction:column;gap:12px">
      ${lists.length === 0
        ? `<div class="empty-state"><div class="empty-icon">✅</div><h3>No checklists</h3><p>Create checklists for packing, shopping, projects and more.</p></div>`
        : lists.map(l => `
          <div class="card">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
              <div style="flex:1">
                <div style="font-size:15px;font-weight:700">${l.name}</div>
                <div style="font-size:12px;color:var(--text-muted)">${l.progress}/${l.total} completed</div>
              </div>
              <button class="action-btn" onclick="deleteChecklist('${l.id}')" style="color:var(--critical)">🗑</button>
            </div>
            <div class="progress-bar" style="margin-bottom:12px">
              <div class="progress-fill" style="width:${l.total > 0 ? Math.round(l.progress / l.total * 100) : 0}%"></div>
            </div>
            <div id="cl-items-${l.id}">Loading...</div>
            <div style="display:flex;gap:8px;margin-top:10px">
              <input type="text" class="form-input" id="cl-new-${l.id}" placeholder="Add item..." style="flex:1"/>
              <button class="btn btn-primary btn-sm" onclick="addChecklistItem('${l.id}')">Add</button>
            </div>
          </div>`).join('')}
    </div>
  `;

  // Load items for each list
  for (const l of lists) {
    const items = await db('SELECT * FROM checklist_items WHERE checklist_id=? ORDER BY sort_order', [l.id]) || [];
    const container = document.getElementById(`cl-items-${l.id}`);
    if (container) {
      container.innerHTML = items.map(item => `
        <div class="checklist-item" id="cli-${item.id}">
          <div class="checklist-check ${item.done ? 'checked' : ''}" onclick="toggleChecklistItem('${item.id}','${l.id}',${item.done})">${item.done ? '✓' : ''}</div>
          <span class="checklist-text ${item.done ? 'done' : ''}">${item.title}</span>
          <button class="action-btn btn-sm" onclick="deleteChecklistItem('${item.id}','${l.id}')" style="color:var(--critical)">✕</button>
        </div>
      `).join('') || '<p style="color:var(--text-muted);font-size:13px">No items yet.</p>';
    }
  }
}

function showAddChecklist() {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'cl-modal';
  overlay.innerHTML = `
    <div class="modal">
      <div class="modal-header"><div class="modal-title">✅ New Checklist</div><button class="modal-close" onclick="document.getElementById('cl-modal').remove()">✕</button></div>
      <div class="form-group">
        <label class="form-label">List Name *</label>
        <input type="text" class="form-input" id="cl-name" placeholder="e.g. Grocery List, Packing, Project Tasks"/>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="document.getElementById('cl-modal').remove()">Cancel</button>
        <button class="btn btn-primary" onclick="saveChecklist()">✅ Create List</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
}

async function saveChecklist() {
  const name = document.getElementById('cl-name').value.trim();
  if (!name) { toast('Enter list name', 'warning'); return; }
  if (!await dbRun(`INSERT INTO checklists (id,name,progress,total,status,created_at) VALUES (?,?,0,0,'active',datetime('now'))`, [uuid(), name])) return;
  document.getElementById('cl-modal').remove();
  toast('✅ Checklist created!');
  navigate('checklists');
}

async function addChecklistItem(listId) {
  const input = document.getElementById(`cl-new-${listId}`);
  const title = input.value.trim();
  if (!title) return;
  const id = uuid();
  await db(`INSERT INTO checklist_items (id,checklist_id,title,done,sort_order) VALUES (?,?,?,0,(SELECT COALESCE(MAX(sort_order),0)+1 FROM checklist_items WHERE checklist_id=?))`,
    [id, listId, title, listId]);
  await db(`UPDATE checklists SET total=total+1 WHERE id=?`, [listId]);
  input.value = '';
  navigate('checklists');
}

async function toggleChecklistItem(itemId, listId, currentDone) {
  const newDone = currentDone ? 0 : 1;
  await db(`UPDATE checklist_items SET done=? WHERE id=?`, [newDone, itemId]);
  await db(`UPDATE checklists SET progress=progress+? WHERE id=?`, [newDone ? 1 : -1, listId]);
  navigate('checklists');
}

async function deleteChecklistItem(itemId, listId) {
  const item = await db('SELECT done FROM checklist_items WHERE id=?', [itemId]);
  await db('DELETE FROM checklist_items WHERE id=?', [itemId]);
  const wasDone = item?.[0]?.done;
  await db(`UPDATE checklists SET total=MAX(0,total-1), progress=MAX(0,progress-?) WHERE id=?`, [wasDone ? 1 : 0, listId]);
  navigate('checklists');
}

async function deleteChecklist(id) {
  if (!confirm('Delete this checklist?')) return;
  await db("UPDATE checklists SET status='inactive' WHERE id=?", [id]);
  navigate('checklists');
}

// ── Reports ────────────────────────────────────────────────────────
async function renderReports(el) {
  const today = todayStr();
  const weekAgoDate = new Date();
  weekAgoDate.setDate(weekAgoDate.getDate() - 7);
  const weekAgo = `${weekAgoDate.getFullYear()}-${String(weekAgoDate.getMonth() + 1).padStart(2, '0')}-${String(weekAgoDate.getDate()).padStart(2, '0')}`;

  const [totalR, completedR, snoozedR, missedR, habitLogs, medLogs] = await Promise.all([
    db("SELECT COUNT(*) as c FROM reminders WHERE status != 'deleted'"),
    db("SELECT COUNT(*) as c FROM reminder_logs WHERE action='completed' AND date(timestamp) >= ?", [weekAgo]),
    db("SELECT COUNT(*) as c FROM reminder_logs WHERE action='snoozed' AND date(timestamp) >= ?", [weekAgo]),
    db("SELECT COUNT(*) as c FROM reminders WHERE status='active' AND next_fire != '' AND next_fire <= ?", [localNowISO()]),
    db('SELECT COUNT(*) as c FROM habit_logs WHERE log_date >= ?', [weekAgo]),
    db("SELECT COUNT(*) as c FROM medicine_logs WHERE status='taken' AND log_date >= ?", [weekAgo]),
  ]);

  const completionRate = totalR?.[0]?.c > 0 ? Math.round((completedR?.[0]?.c || 0) / Math.max(totalR?.[0]?.c, 1) * 100) : 0;

  el.innerHTML = `
    <div class="page-header">
      <div><div class="page-title">📊 Reports & Insights</div><div class="page-subtitle">Last 7 days performance</div></div>
      <button class="btn btn-ghost" onclick="exportReport()">📤 Export</button>
    </div>

    <div class="stats-bar" style="grid-template-columns:repeat(3,1fr)">
      <div class="stat-card green"><div class="stat-value">${completedR?.[0]?.c || 0}</div><div class="stat-label">Completed</div></div>
      <div class="stat-card orange"><div class="stat-value">${snoozedR?.[0]?.c || 0}</div><div class="stat-label">Snoozed</div></div>
      <div class="stat-card red"><div class="stat-value">${missedR?.[0]?.c || 0}</div><div class="stat-label">Overdue Now</div></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">
      <div class="card">
        <div class="section-title">📈 Completion Rate</div>
        <div style="font-size:48px;font-weight:700;font-family:var(--font-mono);color:${completionRate >= 70 ? 'var(--normal)' : completionRate >= 40 ? 'var(--important)' : 'var(--critical)'};margin:16px 0">${completionRate}%</div>
        <div class="progress-bar"><div class="progress-fill ${completionRate >= 70 ? 'green' : ''}" style="width:${completionRate}%"></div></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:8px">${totalR?.[0]?.c || 0} total reminders</div>
      </div>

      <div class="card">
        <div class="section-title">🏥 Medicine Adherence</div>
        <div style="font-size:48px;font-weight:700;font-family:var(--font-mono);color:var(--medicine-color);margin:16px 0">${medLogs?.[0]?.c || 0}</div>
        <div style="font-size:13px;color:var(--text-secondary)">doses taken this week</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:8px">${App.medicines.length} active medicines</div>
      </div>

      <div class="card">
        <div class="section-title">🔁 Habit Activity</div>
        <div style="font-size:48px;font-weight:700;font-family:var(--font-mono);color:var(--habit-color);margin:16px 0">${habitLogs?.[0]?.c || 0}</div>
        <div style="font-size:13px;color:var(--text-secondary)">habit completions this week</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:8px">${App.habits.length} active habits</div>
      </div>

      <div class="card">
        <div class="section-title">📂 By Category</div>
        ${['medicine','bills','family','work','personal','general'].map(cat => {
          const count = App.reminders.filter(r => r.category === cat).length;
          return count > 0 ? `
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
              <span class="tag ${cat}">${categoryIcon(cat)} ${cat}</span>
              <div class="progress-bar" style="flex:1;margin:0"><div class="progress-fill" style="width:${Math.min(100, count * 10)}%"></div></div>
              <span style="font-size:12px;font-family:var(--font-mono);color:var(--text-secondary)">${count}</span>
            </div>` : '';
        }).join('')}
      </div>
    </div>
  `;
}

async function exportReport() {
  const data = {
    exported: new Date().toISOString(),
    reminders: App.reminders,
    medicines: App.medicines,
    bills: App.bills,
    habits: App.habits,
  };
  await api.exportData({ format: 'json', data: JSON.stringify(data, null, 2) });
}

// ── Rewards ────────────────────────────────────────────────────────
async function renderRewards(el) {
  const completedLogs = await db("SELECT COUNT(*) as c FROM reminder_logs WHERE action='completed'") || [{ c: 0 }];
  const total = completedLogs[0]?.c || 0;

  const badges = [
    { id: 'first', icon: '🌟', name: 'First Step', desc: 'Complete your first reminder', earned: total >= 1 },
    { id: 'ten', icon: '🔥', name: 'On Fire', desc: 'Complete 10 reminders', earned: total >= 10 },
    { id: 'fifty', icon: '💪', name: 'Warrior', desc: 'Complete 50 reminders', earned: total >= 50 },
    { id: 'hundred', icon: '🏆', name: 'Champion', desc: 'Complete 100 reminders', earned: total >= 100 },
    { id: 'medicine', icon: '💊', name: 'Health Hero', desc: 'Add 3+ medicines', earned: App.medicines.length >= 3 },
    { id: 'habit', icon: '🔁', name: 'Habit Builder', desc: 'Start 3+ habits', earned: App.habits.length >= 3 },
    { id: 'family', icon: '❤️', name: 'Family First', desc: 'Add family members', earned: App.family.length >= 1 },
    { id: 'streak7', icon: '🔥', name: '7-Day Streak', desc: 'Maintain a 7-day habit streak', earned: App.habits.some(h => h.streak >= 7) },
    { id: 'streak30', icon: '🌙', name: 'Month Master', desc: 'Maintain a 30-day habit streak', earned: App.habits.some(h => h.streak >= 30) },
    { id: 'bills', icon: '💸', name: 'Bill Buster', desc: 'Track 5+ bills', earned: App.bills.length >= 5 },
  ];

  const earnedCount = badges.filter(b => b.earned).length;

  el.innerHTML = `
    <div class="page-header">
      <div><div class="page-title">🏅 Rewards & Badges</div><div class="page-subtitle">${earnedCount}/${badges.length} earned • ${total} tasks completed</div></div>
    </div>

    <div style="background:var(--accent-dim);border:1px solid var(--accent);border-radius:var(--radius-lg);padding:20px;margin-bottom:24px;text-align:center">
      <div style="font-size:48px;margin-bottom:8px">🎯</div>
      <div style="font-size:32px;font-weight:700;font-family:var(--font-mono);color:var(--accent-light)">${total}</div>
      <div style="color:var(--text-secondary)">Total tasks completed</div>
      <div class="progress-bar" style="max-width:300px;margin:12px auto 0">
        <div class="progress-fill" style="width:${Math.min(100, (total / 100) * 100)}%"></div>
      </div>
      <div style="font-size:12px;color:var(--text-muted);margin-top:6px">${100 - Math.min(100, total)} more to Champion</div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px">
      ${badges.map(b => `
        <div class="badge-card ${b.earned ? 'earned' : ''}">
          <div class="badge-icon" style="${!b.earned ? 'filter:grayscale(1);opacity:0.4' : ''}">${b.icon}</div>
          <div class="badge-name">${b.name}</div>
          <div class="badge-desc">${b.desc}</div>
          ${b.earned ? `<div style="margin-top:8px;font-size:10px;font-weight:700;color:var(--important)">✓ EARNED</div>` : `<div style="margin-top:8px;font-size:10px;color:var(--text-muted)">🔒 Locked</div>`}
        </div>
      `).join('')}
    </div>
  `;
}

// ── Settings ───────────────────────────────────────────────────────
async function renderSettings(el) {
  const s = App.settings;

  el.innerHTML = `
    <div class="page-header">
      <div><div class="page-title">⚙️ Settings</div></div>
      <button class="btn btn-primary" onclick="saveSettings()">💾 Save Settings</button>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
      <div>
        <div class="card" style="margin-bottom:16px">
          <div class="settings-section-title">👤 Profile</div>
          <div class="form-group" style="margin-bottom:12px">
            <label class="form-label">Your Name</label>
            <input type="text" class="form-input" id="s-name" value="${s.user_name || ''}" placeholder="Enter your name"/>
          </div>
          <div class="form-group">
            <label class="form-label">Language</label>
            <select class="form-select" id="s-lang">
              <option value="en" ${s.language==='en'?'selected':''}>English</option>
              <option value="hi" ${s.language==='hi'?'selected':''}>हिंदी</option>
            </select>
          </div>
        </div>

        <div class="card" style="margin-bottom:16px">
          <div class="settings-section-title">🔔 Notifications</div>
          <div class="setting-row">
            <div class="setting-info">
              <div class="setting-label">Notification Style</div>
            </div>
            <select class="form-select" style="width:auto" id="s-notif">
              <option value="sound-popup" ${s.notification_style==='sound-popup'?'selected':''}>🔊 Sound + Popup</option>
              <option value="popup-only" ${s.notification_style==='popup-only'?'selected':''}>💬 Popup Only</option>
              <option value="silent" ${s.notification_style==='silent'?'selected':''}>🔕 Silent</option>
            </select>
          </div>
          <div class="setting-row">
            <div class="setting-info">
              <div class="setting-label">Notification Sound</div>
              <div class="setting-desc">Loud alert tone for reminders</div>
            </div>
            <select class="form-select" style="width:auto" id="s-tone" onchange="previewSound(this.value)">
              <option value="air-horn" ${s.reminder_tone==='air-horn'?'selected':''}>📢 Air Horn</option>
              <option value="siren" ${s.reminder_tone==='siren'?'selected':''}>🚨 Siren</option>
              <option value="alarm-clock" ${s.reminder_tone==='alarm-clock'?'selected':''}>⏰ Alarm Clock</option>
              <option value="digital-beep" ${s.reminder_tone==='digital-beep'?'selected':''}>🔊 Digital Beep</option>
              <option value="buzzer" ${s.reminder_tone==='buzzer'?'selected':''}>🔔 Buzzer</option>
              <option value="emergency-alert" ${s.reminder_tone==='emergency-alert'?'selected':''}>🆘 Emergency Alert</option>
              <option value="doorbell" ${s.reminder_tone==='doorbell'?'selected':''}>🚪 Doorbell</option>
              <option value="loud-chime" ${(s.reminder_tone==='loud-chime'||s.reminder_tone==='friendly'||!s.reminder_tone)?'selected':''}>🎵 Loud Chime</option>
              <option value="train-whistle" ${s.reminder_tone==='train-whistle'?'selected':''}>🚂 Train Whistle</option>
              <option value="foghorn" ${s.reminder_tone==='foghorn'?'selected':''}>🌫️ Foghorn</option>
              <option value="old-telephone-ring" ${s.reminder_tone==='old-telephone-ring'?'selected':''}>☎️ Old Telephone Ring</option>
            </select>
          </div>
          <div class="setting-row">
            <div class="setting-info">
              <div class="setting-label">Preview Sound</div>
            </div>
            <button class="btn btn-ghost btn-sm" onclick="previewSound(document.getElementById('s-tone').value)">▶ Play</button>
          </div>
          <div class="setting-row">
            <div class="setting-info">
              <div class="setting-label">Snooze Duration</div>
              <div class="setting-desc">Default snooze time in minutes</div>
            </div>
            <input type="number" class="form-input" style="width:80px" id="s-snooze" value="${s.snooze_duration || 10}" min="1" max="60"/>
          </div>
          <div class="setting-row">
            <div class="setting-info">
              <div class="setting-label">Snooze Limit</div>
              <div class="setting-desc">Max times a reminder can be snoozed</div>
            </div>
            <input type="number" class="form-input" style="width:80px" id="s-slimit" value="${s.snooze_limit || 3}" min="1" max="10"/>
          </div>
          <div class="setting-row">
            <div class="setting-info">
              <div class="setting-label">Test Desktop Notification</div>
              <div class="setting-desc">Verify system notifications are working</div>
            </div>
            <button class="btn btn-ghost btn-sm" onclick="testDesktopNotification()">Send Test</button>
          </div>
          <div class="setting-row">
            <div class="setting-info">
              <div class="setting-label">Test Alarm (1 minute)</div>
              <div class="setting-desc">Schedules a real reminder alarm in 60 seconds — keep ILRS running in the tray</div>
            </div>
            <button class="btn btn-primary btn-sm" onclick="scheduleTestAlarm()">⏰ Test Alarm</button>
          </div>
        </div>

        <div class="card">
          <div class="settings-section-title">🌙 Quiet Hours</div>
          <div class="setting-row">
            <div class="setting-info"><div class="setting-label">Enable Quiet Hours</div><div class="setting-desc">Suppress non-critical alerts</div></div>
            <div class="toggle ${s.quiet_hours_enabled==='1'?'on':''}" id="t-quiet" onclick="this.classList.toggle('on')"></div>
          </div>
          <div class="setting-row">
            <div class="setting-info"><div class="setting-label">Start Time</div></div>
            <input type="time" class="form-input" style="width:120px" id="s-qstart" value="${s.quiet_hours_start || '23:00'}"/>
          </div>
          <div class="setting-row">
            <div class="setting-info"><div class="setting-label">End Time</div></div>
            <input type="time" class="form-input" style="width:120px" id="s-qend" value="${s.quiet_hours_end || '06:00'}"/>
          </div>
          <div class="setting-row">
            <div class="setting-info"><div class="setting-label">Critical Alerts Override</div><div class="setting-desc">Allow critical reminders in quiet hours</div></div>
            <div class="toggle ${s.critical_override==='1'?'on':''}" id="t-crit" onclick="this.classList.toggle('on')"></div>
          </div>
        </div>
      </div>

      <div>
        <div class="card" style="margin-bottom:16px">
          <div class="settings-section-title">🎨 Appearance</div>
          <div class="setting-row">
            <div class="setting-info"><div class="setting-label">Theme</div></div>
            <select class="form-select" style="width:auto" id="s-theme">
              <option value="dark" ${s.appearance==='dark'?'selected':''}>🌙 Dark</option>
              <option value="light" ${s.appearance==='light'?'selected':''}>☀️ Light (coming soon)</option>
            </select>
          </div>
          <div class="setting-row">
            <div class="setting-info"><div class="setting-label">Density</div></div>
            <select class="form-select" style="width:auto" id="s-density">
              <option value="comfortable" ${s.layout_density==='comfortable'?'selected':''}>Comfortable</option>
              <option value="compact" ${s.layout_density==='compact'?'selected':''}>Compact</option>
            </select>
          </div>
        </div>

        <div class="card" style="margin-bottom:16px">
          <div class="settings-section-title">💾 Data & Backup</div>
          <div class="setting-row">
            <div class="setting-info"><div class="setting-label">Auto Local Backup</div><div class="setting-desc">Daily backup at 2 AM</div></div>
            <div class="toggle ${s.local_backup==='1'?'on':''}" id="t-backup" onclick="this.classList.toggle('on')"></div>
          </div>
          <div class="setting-row">
            <div class="setting-info"><div class="setting-label">Data Cleanup After (days)</div></div>
            <input type="number" class="form-input" style="width:80px" id="s-cleanup" value="${s.data_cleanup_days || 90}" min="30"/>
          </div>
          <div style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
            <button class="btn btn-ghost" onclick="performManualBackup()">🗄️ Backup Now</button>
            <button class="btn btn-ghost" onclick="exportAllData()">📤 Export All Data</button>
            <button class="btn btn-danger btn-sm" onclick="clearOldData()">🗑 Clear Old Data</button>
          </div>
        </div>

        <div class="card">
          <div class="settings-section-title">🔒 Security</div>
          <div class="setting-row">
            <div class="setting-info"><div class="setting-label">App Lock (PIN)</div><div class="setting-desc">Require PIN to open ILRS</div></div>
            <div class="toggle ${s.app_lock==='1'?'on':''}" id="t-lock" onclick="this.classList.toggle('on')"></div>
          </div>
          <div class="setting-row">
            <div class="setting-info"><div class="setting-label">Rewards System</div></div>
            <div class="toggle ${s.rewards_enabled==='1'?'on':''}" id="t-rewards" onclick="this.classList.toggle('on')"></div>
          </div>
          <div class="setting-row">
            <div class="setting-info"><div class="setting-label">Start with Windows</div></div>
            <div class="toggle ${s.auto_start==='1'?'on':''}" id="t-autostart" onclick="this.classList.toggle('on')"></div>
          </div>
        </div>
      </div>
    </div>
  `;
}

async function saveSettings() {
  const updates = {
    user_name: document.getElementById('s-name')?.value || '',
    language: document.getElementById('s-lang')?.value || 'en',
    notification_style: document.getElementById('s-notif')?.value || 'sound-popup',
    reminder_tone: document.getElementById('s-tone')?.value || 'loud-chime',
    snooze_duration: document.getElementById('s-snooze')?.value || '10',
    snooze_limit: document.getElementById('s-slimit')?.value || '3',
    quiet_hours_enabled: document.getElementById('t-quiet')?.classList.contains('on') ? '1' : '0',
    quiet_hours_start: document.getElementById('s-qstart')?.value || '23:00',
    quiet_hours_end: document.getElementById('s-qend')?.value || '06:00',
    critical_override: document.getElementById('t-crit')?.classList.contains('on') ? '1' : '0',
    appearance: document.getElementById('s-theme')?.value || 'dark',
    layout_density: document.getElementById('s-density')?.value || 'comfortable',
    local_backup: document.getElementById('t-backup')?.classList.contains('on') ? '1' : '0',
    data_cleanup_days: document.getElementById('s-cleanup')?.value || '90',
    app_lock: document.getElementById('t-lock')?.classList.contains('on') ? '1' : '0',
    rewards_enabled: document.getElementById('t-rewards')?.classList.contains('on') ? '1' : '0',
    auto_start: document.getElementById('t-autostart')?.classList.contains('on') ? '1' : '0',
  };

  for (const [k, v] of Object.entries(updates)) {
    await saveSetting(k, v);
  }
  toast('✅ Settings saved!');
}

async function testDesktopNotification() {
  const result = await api.testNotification();
  previewSound(App.settings.reminder_tone || 'loud-chime');
  if (result?.success) {
    toast('Test notification sent — check your system tray');
  } else {
    toast('Could not send test notification', 'warning');
  }
}

async function scheduleTestAlarm() {
  const result = await api.scheduleTestAlarm();
  if (result?.success) {
    toast(`⏰ Test alarm scheduled for ${result.fireAt.slice(11, 16)} — keep ILRS running`);
    navigate('reminders');
  } else {
    toast(`Could not schedule test alarm: ${result?.error || 'unknown error'}`, 'critical');
  }
}

function previewSound(soundId) {
  window.ILRSSounds?.previewSound(soundId);
}

async function performManualBackup() {
  const paths = await api.getAppPath();
  api.openBackupFolder(paths.userData + '/backups');
  toast('📦 Backup folder opened');
}

async function exportAllData() {
  const data = { reminders: App.reminders, medicines: App.medicines, bills: App.bills, habits: App.habits, family: App.family, exportedAt: new Date().toISOString() };
  const result = await api.exportData({ format: 'json', data: JSON.stringify(data, null, 2) });
  if (result?.success) toast(`✅ Exported to ${result.path}`);
}

async function clearOldData() {
  const days = parseInt(App.settings.data_cleanup_days) || 90;
  if (!confirm(`Delete reminder logs older than ${days} days?`)) return;
  const cutoff = new Date(Date.now() - days * 86400000).toISOString();
  await db('DELETE FROM reminder_logs WHERE timestamp < ?', [cutoff]);
  await db('DELETE FROM medicine_logs WHERE log_date < ?', [cutoff.split('T')[0]]);
  await db('DELETE FROM habit_logs WHERE log_date < ?', [cutoff.split('T')[0]]);
  toast('🗑 Old data cleared');
}

// ── Quick Add (NLP-like) ───────────────────────────────────────────
async function handleQuickAdd() {
  const input = document.getElementById('quick-input');
  const text = input.value.trim();
  if (!text) return;

  // Parse intent from text
  let title = text;
  let category = 'general';
  let repeatType = 'once';
  let priority = 'normal';
  let reminderTime = '';

  const lower = text.toLowerCase();

  // Detect category
  if (lower.includes('medicine') || lower.includes('tablet') || lower.includes('capsule') || lower.includes('pill')) category = 'medicine';
  else if (lower.includes('bill') || lower.includes('pay') || lower.includes('electricity') || lower.includes('rent')) category = 'bills';
  else if (lower.includes('family') || lower.includes('mom') || lower.includes('dad') || lower.includes('wife') || lower.includes('husband')) category = 'family';
  else if (lower.includes('work') || lower.includes('meeting') || lower.includes('office') || lower.includes('client')) category = 'work';

  // Detect priority
  if (lower.includes('urgent') || lower.includes('critical') || lower.includes('asap') || lower.includes('emergency')) priority = 'critical';
  else if (lower.includes('important')) priority = 'important';

  // Detect repeat
  if (lower.includes('daily') || lower.includes('every day') || lower.includes('रोज')) repeatType = 'daily';
  else if (lower.includes('weekly') || lower.includes('every week')) repeatType = 'weekly';
  else if (lower.includes('monthly') || lower.includes('every month')) repeatType = 'monthly';

  // Detect time
  const timeMatch = text.match(/at\s+(\d{1,2}):?(\d{2})?\s*(am|pm)?/i);
  if (timeMatch) {
    let h = parseInt(timeMatch[1]);
    const m = parseInt(timeMatch[2] || '0');
    const ampm = (timeMatch[3] || '').toLowerCase();
    if (ampm === 'pm' && h < 12) h += 12;
    if (ampm === 'am' && h === 12) h = 0;
    reminderTime = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
  }

  const id = uuid();
  const nextFire = reminderTime ? computeNextFireLocal(todayStr(), reminderTime, repeatType) : computeNextFireLocal(todayStr(), nowTimeStr(), repeatType);

  if (!await dbRun(`INSERT INTO reminders (id,title,task_type,category,repeat_type,reminder_time,start_date,priority,alert_style,status,next_fire,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))`,
    [id, title, 'reminder', category, repeatType, reminderTime, todayStr(), priority, 'sound-popup', 'active', nextFire])) return;

  input.value = '';
  toast(`✅ Added: "${title}"`);
  await loadAllData();
  updateBadges();
  if (App.currentPage === 'dashboard' || App.currentPage === 'reminders') navigate(App.currentPage);
}

// ── Focus Mode ─────────────────────────────────────────────────────
function toggleFocusMode() {
  App.focusMode = !App.focusMode;
  document.body.classList.toggle('focus-mode', App.focusMode);
  const btn = document.getElementById('focus-btn');
  if (btn) btn.classList.toggle('focus-active', App.focusMode);
  toast(App.focusMode ? '🎯 Focus mode ON — only critical alerts' : '🔔 Focus mode OFF');
}

// ── Badge Count ────────────────────────────────────────────────────
async function updateBadges() {
  const overdue = App.reminders.filter(r => r.status === 'active' && isReminderOverdue(r.next_fire));
  const badge = document.getElementById('alert-badge');
  if (badge) {
    badge.textContent = overdue.length;
    badge.style.display = overdue.length > 0 ? '' : 'none';
  }
}

function showAlertCount() {
  const overdue = App.reminders.filter(r => r.status === 'active' && isReminderOverdue(r.next_fire));
  if (overdue.length === 0) { toast('✅ No pending alerts!'); return; }
  navigate('reminders');
}

// ── Helper Labels ──────────────────────────────────────────────────
function categoryIcon(cat) {
  const icons = { general: '📌', medicine: '💊', bills: '💸', family: '👨‍👩‍👧', work: '💼', health: '❤️', personal: '🧘', shopping: '🛒', education: '📚', fitness: '🏋️' };
  return icons[cat] || '📌';
}

function priorityLabel(p) {
  const labels = { critical: '🚨 Critical', important: '⚠️ Important', normal: '✅ Normal' };
  return labels[p] || p;
}

function taskTypeIcon(t) {
  const icons = { reminder: '🔔', task: '✅', habit: '🔁', event: '🎉', checklist: '📋', routine: '⏰' };
  return icons[t] || '🔔';
}

// ── IPC Listeners ──────────────────────────────────────────────────
function setupListeners() {
  api.onNavigate(page => navigate(page));

  api.onReminderDue(reminder => {
    App.alertQueue.push(reminder);
    if (!App.isProcessingAlert) processAlertQueue();
  });

  api.onPauseAlerts(minutes => {
    App.pausedUntil = Date.now() + minutes * 60000;
    toast(`🔕 Alerts paused for ${minutes} minutes`);
  });

  api.onPlaySound(soundId => {
    const repeats = 2;
    window.ILRSSounds?.playAlertSound(soundId || App.settings.reminder_tone || 'loud-chime', { repeat: repeats });
  });
}

function processAlertQueue() {
  if (App.alertQueue.length === 0) { App.isProcessingAlert = false; return; }
  App.isProcessingAlert = true;
  const reminder = App.alertQueue.shift();

  if (App.pausedUntil && Date.now() < App.pausedUntil && reminder.priority !== 'critical') {
    processAlertQueue();
    return;
  }

  showInAppAlert(reminder);
  const tone = reminder.alert_tone || App.settings.reminder_tone || 'loud-chime';
  const repeats = reminder.priority === 'critical' ? 4 : 3;
  if (reminder.alert_style !== 'silent' && reminder.alert_style !== 'popup-only') {
    window.ILRSSounds?.playAlertSound(tone, { repeat: repeats });
  }
}

function showInAppAlert(reminder) {
  const existing = document.getElementById('alert-popup');
  if (existing) existing.remove();

  const isPrivate = Number(reminder.is_private) === 1;
  const title = isPrivate ? 'Private Reminder' : reminder.title;
  const body = isPrivate ? 'You have a scheduled reminder.' : (reminder.why_it_matters || 'Time for action!');
  const isAlarm = reminder.priority === 'critical' || reminder._type === 'reminder';

  const overlay = document.createElement('div');
  overlay.className = reminder.priority === 'critical' ? 'alert-popup' : 'modal-overlay';
  overlay.id = 'alert-popup';
  overlay.innerHTML = `
    <div class="alert-box" style="${isAlarm ? 'border:2px solid var(--critical);box-shadow:0 0 30px rgba(255,80,80,0.35)' : ''}">
      <div class="alert-icon">${reminder.priority === 'critical' ? '🚨' : '⏰'}</div>
      <h2>${title}</h2>
      <p>${body}</p>
      <p style="font-size:12px;color:var(--text-muted);margin-top:8px">${isAlarm ? 'Alarm active — mark done or snooze to stop alerts.' : ''}</p>
      <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin-bottom:16px">
        <span class="tag ${reminder.category}">${categoryIcon(reminder.category)} ${reminder.category}</span>
        <span class="tag ${reminder.priority}">${priorityLabel(reminder.priority)}</span>
      </div>
      <div class="alert-buttons">
        <button class="btn btn-primary" onclick="completeFromAlert('${reminder.id}')">✅ Done</button>
        <button class="btn btn-ghost" onclick="snoozeFromAlert('${reminder.id}')">💤 Snooze</button>
        <button class="btn btn-ghost" onclick="dismissAlert()">✕ Dismiss</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
}

async function completeFromAlert(id) {
  await completeReminder(id);
  dismissAlert();
}

async function snoozeFromAlert(id) {
  await snoozeReminder(id);
  dismissAlert();
}

function dismissAlert() {
  const el = document.getElementById('alert-popup');
  if (el) el.remove();
  setTimeout(processAlertQueue, 500);
}

// ── Bootstrap ──────────────────────────────────────────────────────
async function init() {
  try {
    await loadSettings();
    await loadAllData();
    renderShell();
    setupListeners();
    await navigate('dashboard');
    updateBadges();

    // Hide loader, show app
    document.getElementById('loading-screen').style.display = 'none';
    document.getElementById('main-app').style.display = 'grid';

    // Check onboarding
    if (App.settings.onboarding_done !== '1') {
      setTimeout(showOnboarding, 800);
    }
  } catch (err) {
    console.error('Init error:', err);
    document.getElementById('loading-screen').innerHTML = `
      <div style="text-align:center;padding:40px;color:var(--critical)">
        <div style="font-size:48px;margin-bottom:16px">⚠️</div>
        <h2>Startup Error</h2>
        <p style="color:var(--text-secondary);margin-top:8px">${err.message}</p>
        <p style="color:var(--text-muted);margin-top:8px">Make sure you ran <code>npm install</code></p>
      </div>`;
  }
}

// ── Onboarding ─────────────────────────────────────────────────────
function showOnboarding() {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'onboard-modal';
  overlay.innerHTML = `
    <div class="modal" style="text-align:center;max-width:480px">
      <div style="font-size:56px;margin-bottom:16px">🧠</div>
      <div class="modal-title" style="font-size:22px;margin-bottom:8px">Welcome to ILRS!</div>
      <p style="color:var(--text-secondary);margin-bottom:20px">Your Intelligent Life Reminder System is ready. Let's get started!</p>
      <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:24px">
        <div class="card card-sm" style="display:flex;align-items:center;gap:12px;text-align:left">
          <span style="font-size:24px">💊</span>
          <div><div style="font-weight:600">Medicine Module</div><div style="font-size:12px;color:var(--text-muted)">Never miss a dose again</div></div>
          <button class="btn btn-primary btn-sm" style="margin-left:auto" onclick="closeOnboard('medicine')">Set Up</button>
        </div>
        <div class="card card-sm" style="display:flex;align-items:center;gap:12px;text-align:left">
          <span style="font-size:24px">💸</span>
          <div><div style="font-weight:600">Bills Tracker</div><div style="font-size:12px;color:var(--text-muted)">No more late payment charges</div></div>
          <button class="btn btn-primary btn-sm" style="margin-left:auto" onclick="closeOnboard('bills')">Set Up</button>
        </div>
        <div class="card card-sm" style="display:flex;align-items:center;gap:12px;text-align:left">
          <span style="font-size:24px">🔁</span>
          <div><div style="font-weight:600">Habit Builder</div><div style="font-size:12px;color:var(--text-muted)">Build consistent daily habits</div></div>
          <button class="btn btn-primary btn-sm" style="margin-left:auto" onclick="closeOnboard('habits')">Set Up</button>
        </div>
      </div>
      <button class="btn btn-ghost" style="width:100%;justify-content:center" onclick="closeOnboard('dashboard')">Skip — Go to Dashboard</button>
    </div>
  `;
  document.body.appendChild(overlay);
}

async function closeOnboard(page) {
  await saveSetting('onboarding_done', '1');
  document.getElementById('onboard-modal')?.remove();
  navigate(page);
}

// ── Start ──────────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', init);
