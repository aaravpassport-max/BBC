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
  currentAlert: null,
  searchIndex: 0,
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

async function computeNextFireForSave(startDate, time, repeatType = 'once') {
  if (api.computeNextFire) {
    const result = await api.computeNextFire(startDate, time, repeatType);
    if (result?.nextFire) return result.nextFire;
  }
  return computeNextFireLocal(startDate, time, repeatType);
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

function applyTheme(theme) {
  const resolved = theme === 'light' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', resolved);
  App.settings.appearance = resolved;
}

// ── Data Loaders ───────────────────────────────────────────────────
async function loadAllData() {
  const [reminders, medicines, bills, habits, family] = await Promise.all([
    db("SELECT * FROM reminders WHERE status != 'deleted' AND (source_type IS NULL OR source_type = '') ORDER BY priority DESC, next_fire ASC"),
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
  today: renderToday,
  dashboard: renderToday,
  tomorrow: () => renderSmartList('tomorrow'),
  upcoming: () => renderSmartList('upcoming'),
  overdue: () => renderSmartList('overdue'),
  postponed: () => renderSmartList('postponed'),
  completed: renderCompleted,
  reminders: renderReminders,
  tasks: renderTasks,
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

function dismissPageModals() {
  ['med-modal', 'bill-modal', 'fam-modal', 'famr-modal', 'hab-modal', 'cl-modal', 'onboard-modal', 'capture-sheet', 'search-palette', 'shortcuts-help'].forEach((id) => {
    document.getElementById(id)?.remove();
  });
  document.querySelectorAll('.modal-overlay').forEach((el) => {
    if (el.id !== 'alert-popup') el.remove();
  });
}

function cap() { return window.ILRSCapture; }

function attachModalDismiss(overlay) {
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) overlay.remove();
  });
}

async function navigate(page) {
  if (page === 'add') {
    showCaptureSheet();
    return;
  }
  dismissPageModals();
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
      <div class="topbar-logo">ILRS <span>Modern Reminder</span></div>
      <div class="quick-add-bar">
        <input type="text" id="quick-input" placeholder="⚡ Call John tomorrow at 10am — press Enter" autocomplete="off" />
        <button class="quick-add-btn" onclick="handleQuickAdd()" title="Add reminder">+</button>
        <div class="quick-add-preview" id="quick-add-preview"></div>
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
      <button class="btn btn-primary sidebar-add-btn" onclick="showCaptureSheet()">＋ New Reminder</button>
      <div class="sidebar-section-label">Focus</div>
      ${navItem('today', '☀️', 'Today')}
      ${navItem('tomorrow', '🌅', 'Tomorrow')}
      ${navItem('upcoming', '📆', 'Upcoming')}
      ${navItem('overdue', '⚠️', 'Overdue', '')}
      ${navItem('postponed', '📅', 'Postponed')}
      ${navItem('completed', '✅', 'Completed')}
      ${navItem('reminders', '📋', 'All')}
      ${navItem('tasks', '✅', 'Tasks')}
      ${navItem('calendar', '📅', 'Calendar')}

      <div class="sidebar-section-label">Life</div>
      ${navItem('medicine', '💊', 'Medicine')}
      ${navItem('bills', '💸', 'Bills')}
      ${navItem('habits', '🔁', 'Habits')}
      ${navItem('family', '👨‍👩‍👧', 'Family')}
      ${navItem('checklists', '📝', 'Checklists')}
      ${App.settings.rewards_enabled === '1' ? navItem('rewards', '🏅', 'Rewards') : ''}

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

  // Quick add on Enter + live parse preview
  const quickInput = document.getElementById('quick-input');
  quickInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') handleQuickAdd();
  });
  quickInput.addEventListener('input', updateQuickAddPreview);

  // Floating add button
  if (!document.getElementById('fab-add')) {
    const fab = document.createElement('button');
    fab.id = 'fab-add';
    fab.className = 'fab-add';
    fab.title = 'New reminder (Ctrl+N)';
    fab.textContent = '+';
    fab.onclick = () => showCaptureSheet();
    document.body.appendChild(fab);
  }
}

function updateQuickAddPreview() {
  const input = document.getElementById('quick-input');
  const preview = document.getElementById('quick-add-preview');
  if (!input || !preview || !cap()) return;
  const text = input.value.trim();
  if (!text) { preview.innerHTML = ''; return; }
  const parsed = cap().parseReminderText(text);
  const chips = [];
  if (parsed.startDate) chips.push(parsed.startDate === todayStr() ? 'Today' : parsed.startDate);
  if (parsed.time) chips.push(formatTime(parsed.time));
  if (parsed.repeatType !== 'once') chips.push(parsed.repeatType);
  if (parsed.priority !== 'normal') chips.push(parsed.priority);
  preview.innerHTML = chips.map(c => `<span class="chip selected">${c}</span>`).join('');
}

function navItem(page, icon, label, badge = '') {
  return `<button class="nav-item" data-page="${page}">
    <span class="nav-icon">${icon}</span>
    <span>${label}</span>
    ${badge ? `<span class="nav-badge">${badge}</span>` : ''}
  </button>`;
}

// ── Life attention helpers ─────────────────────────────────────────
function getPendingMedDoses(medLogs, nowT = nowTimeStr()) {
  const items = [];
  for (const med of App.medicines) {
    const times = JSON.parse(med.dose_times || '[]');
    for (const time of times) {
      const log = medLogs.find(l => l.medicine_id === med.id && l.dose_time === time);
      if (log?.status === 'taken') continue;
      items.push({ med, time, overdue: time < nowT, missed: time < nowT });
    }
  }
  return items.sort((a, b) => a.time.localeCompare(b.time));
}

function getBillsNeedingAttention(todayDay = new Date().getDate()) {
  return App.bills.filter(b => {
    const diff = parseInt(b.due_day) - todayDay;
    const warn = parseInt(b.warning_days || 3);
    return diff <= warn;
  }).map(b => {
    const diff = parseInt(b.due_day) - todayDay;
    return { bill: b, diff, overdue: diff < 0, dueToday: diff === 0 };
  }).sort((a, b) => a.diff - b.diff);
}

function getHabitsDueToday(habitLogs) {
  const day = new Date().getDay();
  return App.habits.filter(h => {
    const freq = h.frequency || 'daily';
    if (freq === 'weekdays' && (day === 0 || day === 6)) return false;
    if (freq === 'weekends' && day !== 0 && day !== 6) return false;
    const done = habitLogs.some(l => l.habit_id === h.id && Number(l.completed) === 1);
    return !done;
  });
}

function lifeAttentionCard(type, id, { title, subtitle, overdue, primaryAction, primaryLabel, icon }) {
  return `
    <div class="reminder-card ${overdue ? 'overdue important' : 'normal'}" id="life-${type}-${id}">
      <div class="reminder-check" onclick="${primaryAction}">${icon}</div>
      <div class="reminder-body">
        <div class="reminder-title">${title}</div>
        <div class="reminder-context ${overdue ? 'overdue' : ''}">${subtitle}</div>
      </div>
      <div class="reminder-actions">
        <button class="action-btn done" onclick="${primaryAction}">${primaryLabel}</button>
      </div>
    </div>`;
}

// ── Today (Home) ───────────────────────────────────────────────────
async function renderToday(el) {
  const C = cap();
  const now = new Date();
  const today = todayStr();
  const nowT = nowTimeStr();
  const todayDay = now.getDate();
  const name = App.settings.user_name || 'Friend';
  const hour = now.getHours();
  const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';

  const [medLogs, habitLogs, weekMedTaken, weekHabitLogs] = await Promise.all([
    db('SELECT * FROM medicine_logs WHERE log_date=?', [today]),
    db('SELECT * FROM habit_logs WHERE log_date=?', [today]),
    db("SELECT COUNT(*) as c FROM medicine_logs WHERE status='taken' AND log_date >= date('now', '-7 days')"),
    db("SELECT COUNT(*) as c FROM habit_logs WHERE completed=1 AND log_date >= date('now', '-7 days')"),
  ]);

  const schedulable = (r) => r.task_type !== 'task' || (r.reminder_time && r.next_fire);
  const dueToday = App.reminders.filter(r => schedulable(r) && (C?.isDueToday(r, now) || (r.status === 'active' && !r.next_fire && r.start_date === today)));
  const overdue = App.reminders.filter(r => schedulable(r) && (C?.isOverdueItem(r, now) || isReminderOverdue(r.next_fire)));
  const dueTomorrow = App.reminders.filter(r => schedulable(r) && C?.isDueTomorrow(r, now));
  const postponedItems = App.reminders.filter(r => C?.isPostponedItem(r));

  const pendingMeds = getPendingMedDoses(medLogs || [], nowT);
  const billsAttention = getBillsNeedingAttention(todayDay);
  const habitsDue = getHabitsDueToday(habitLogs || []);
  const lifeCount = pendingMeds.length + billsAttention.length + habitsDue.length;
  const attention = overdue.length + dueToday.length + lifeCount;

  const medDosesAll = [];
  for (const med of App.medicines) {
    const times = JSON.parse(med.dose_times || '[]');
    times.forEach(t => {
      const log = (medLogs || []).find(l => l.medicine_id === med.id && l.dose_time === t);
      medDosesAll.push({ med, time: t, taken: log?.status === 'taken', past: t <= nowT });
    });
  }
  medDosesAll.sort((a, b) => a.time.localeCompare(b.time));

  const billsDue = getBillsNeedingAttention(todayDay);
  const medTakenWeek = weekMedTaken?.[0]?.c || 0;
  const habitLogsWeek = weekHabitLogs?.[0]?.c || 0;

  el.innerHTML = `
    <div class="greeting-line">${greeting}, ${name}</div>
    <div class="greeting-sub">${attention === 0 ? 'You\'re all caught up!' : `${attention} thing${attention !== 1 ? 's' : ''} need your attention`}${lifeCount > 0 ? ` · ${lifeCount} from Life` : ''}</div>

    <div class="smart-tabs">
      <button class="smart-tab active" onclick="navigate('today')">Today · ${dueToday.length}</button>
      <button class="smart-tab" onclick="navigate('tomorrow')">Tomorrow · ${dueTomorrow.length}</button>
      <button class="smart-tab" onclick="navigate('overdue')">Overdue · ${overdue.length}</button>
      <button class="smart-tab" onclick="navigate('postponed')">Postponed · ${postponedItems.length}</button>
      <button class="smart-tab" onclick="navigate('upcoming')">Upcoming</button>
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
        ${overdue.length > 0 ? `
        <div class="section-header">
          <div class="section-title">⚠️ Overdue</div>
        </div>
        <div class="reminder-list" style="margin-bottom:20px">${overdue.slice(0, 5).map(r => reminderCard(r)).join('')}</div>` : ''}

        <div class="section-header">
          <div class="section-title">☀️ Due Today</div>
          <button class="btn btn-primary btn-sm" onclick="showCaptureSheet()">+ Add</button>
        </div>
        <div class="reminder-list" id="dashboard-reminders">
          ${dueToday.length === 0 ? `<div class="empty-state"><div class="empty-icon">🎉</div><h3>Nothing due today</h3><p>Press <strong>＋ New Reminder</strong> or type in the quick-add bar.</p></div>` :
            dueToday.slice(0, 8).map(r => reminderCard(r)).join('')}
        </div>
        ${dueToday.length > 8 ? `<div style="text-align:center;margin-top:12px"><button class="btn btn-ghost btn-sm" onclick="navigate('reminders')">View all ${dueToday.length}</button></div>` : ''}

        ${lifeCount > 0 ? `
        <div class="section-header" style="margin-top:24px">
          <div class="section-title">🌿 Life Today</div>
          <button class="btn btn-ghost btn-sm" onclick="navigate('medicine')">Life modules</button>
        </div>
        <div class="reminder-list" style="margin-bottom:12px">
          ${pendingMeds.map(d => lifeAttentionCard('med', `${d.med.id}-${d.time}`, {
            icon: '💊',
            title: `Take ${d.med.name}`,
            subtitle: `Medicine · ${formatTime(d.time)}${d.overdue ? ' · overdue' : ''}`,
            overdue: d.overdue,
            primaryLabel: '✓',
            primaryAction: `markDoseTaken('${d.med.id}','${d.time}')`,
          })).join('')}
          ${billsAttention.slice(0, 4).map(({ bill, diff, overdue }) => lifeAttentionCard('bill', bill.id, {
            icon: '💸',
            title: `Pay ${bill.name}`,
            subtitle: `Bill · ${overdue ? `Overdue ${Math.abs(diff)}d` : diff === 0 ? 'Due today' : `Due in ${diff}d`}`,
            overdue,
            primaryLabel: '✓',
            primaryAction: `markBillPaid('${bill.id}')`,
          })).join('')}
          ${habitsDue.slice(0, 4).map(h => lifeAttentionCard('habit', h.id, {
            icon: '🔁',
            title: h.name,
            subtitle: `Habit · ${formatTime(h.target_time)} · 🔥 ${h.streak || 0}d`,
            overdue: false,
            primaryLabel: '✓',
            primaryAction: `logHabit('${h.id}')`,
          })).join('')}
        </div>` : ''}
      </div>

      <div>
        <!-- Medicine Today -->
        <div class="coming-up-panel" style="margin-bottom:16px">
          <div class="section-header">
            <div class="section-title">💊 Medicine Today</div>
            <button class="btn btn-ghost btn-sm" onclick="navigate('medicine')">All</button>
          </div>
          ${medDosesAll.length === 0 ? `<p style="color:var(--text-muted);font-size:13px">No medicines scheduled.</p>` :
            medDosesAll.slice(0, 5).map(d => `
            <div class="dose-row">
              <div class="dose-status ${d.taken ? 'taken' : d.past ? 'missed' : 'due'}">${d.taken ? '✅' : d.past ? '⏰' : '🕐'}</div>
              <div style="flex:1">
                <div style="font-size:13px;font-weight:600">${d.med.name}</div>
                <div style="font-size:11px;color:var(--text-muted)">${d.med.condition}</div>
              </div>
              <div style="font-family:var(--font-mono);font-size:12px;color:var(--text-secondary)">${formatTime(d.time)}</div>
              ${!d.taken ? `<button class="btn btn-sm btn-primary" onclick="markDoseTaken('${d.med.id}','${d.time}')">✓</button>` : ''}
            </div>`).join('')}
        </div>

        <!-- Bills Due -->
        <div class="coming-up-panel" style="margin-bottom:16px">
          <div class="section-header">
            <div class="section-title">💸 Bills Due Soon</div>
            <button class="btn btn-ghost btn-sm" onclick="navigate('bills')">All</button>
          </div>
          ${billsDue.length === 0 ? `<p style="color:var(--text-muted);font-size:13px">No bills due soon. ✨</p>` :
            billsDue.slice(0, 4).map(({ bill, diff, overdue }) => {
              const status = overdue ? 'overdue' : diff === 0 ? 'pending' : 'pending';
              return `<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
                <span style="font-size:20px">${billIcon(bill.bill_type)}</span>
                <div style="flex:1">
                  <div style="font-size:13px;font-weight:600">${bill.name}</div>
                  <div style="font-size:11px;color:var(--text-muted)">Due ${overdue ? Math.abs(diff)+' days ago' : diff === 0 ? 'TODAY' : 'in '+diff+' days'}</div>
                </div>
                <button class="btn btn-sm btn-primary" onclick="markBillPaid('${bill.id}')">✓</button>
              </div>`;
            }).join('')}
        </div>

        <!-- Quick Stats -->
        <div class="coming-up-panel">
          <div class="section-title" style="margin-bottom:12px">📈 This Week</div>
          <div style="display:flex;flex-direction:column;gap:8px">
            <div style="display:flex;justify-content:space-between;font-size:13px">
              <span style="color:var(--text-secondary)">Doses taken (7d)</span>
              <span style="color:var(--medicine-color);font-weight:600">${medTakenWeek}</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:13px">
              <span style="color:var(--text-secondary)">Habits logged (7d)</span>
              <span style="color:var(--habit-color);font-weight:600">${habitLogsWeek}</span>
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

async function renderSmartList(mode) {
  const el = document.getElementById('content');
  const C = cap();
  const now = new Date();
  const schedulable = (r) => r.task_type !== 'task' || (r.reminder_time && r.next_fire);
  let items = [];
  let title = '';
  let subtitle = '';

  switch (mode) {
    case 'tomorrow':
      items = App.reminders.filter(r => schedulable(r) && C?.isDueTomorrow(r, now));
      title = '🌅 Tomorrow';
      subtitle = 'What is coming tomorrow';
      break;
    case 'upcoming':
      items = App.reminders.filter(r => schedulable(r) && C?.isUpcoming(r, now));
      title = '📆 Upcoming';
      subtitle = 'Next 7 days';
      break;
    case 'overdue':
      items = App.reminders.filter(r => schedulable(r) && (C?.isOverdueItem(r, now) || isReminderOverdue(r.next_fire)));
      title = '⚠️ Overdue';
      subtitle = 'Needs your attention';
      break;
    case 'postponed':
      items = App.reminders
        .filter(r => C?.isPostponedItem(r))
        .sort((a, b) => (a.next_fire || '').localeCompare(b.next_fire || ''));
      title = '📅 Postponed';
      subtitle = 'Rescheduled for later';
      break;
    default:
      items = [];
  }

  const postponedCount = App.reminders.filter(r => C?.isPostponedItem(r)).length;

  el.innerHTML = `
    <div class="page-header">
      <div>
        <div class="page-title">${title}</div>
        <div class="page-subtitle">${subtitle} · ${items.length} item${items.length !== 1 ? 's' : ''}</div>
      </div>
      <button class="btn btn-primary" onclick="showCaptureSheet()">＋ New</button>
    </div>
    <div class="smart-tabs">
      <button class="smart-tab" onclick="navigate('today')">Today</button>
      <button class="smart-tab ${mode === 'tomorrow' ? 'active' : ''}" onclick="navigate('tomorrow')">Tomorrow</button>
      <button class="smart-tab ${mode === 'upcoming' ? 'active' : ''}" onclick="navigate('upcoming')">Upcoming</button>
      <button class="smart-tab ${mode === 'overdue' ? 'active' : ''}" onclick="navigate('overdue')">Overdue</button>
      <button class="smart-tab ${mode === 'postponed' ? 'active' : ''}" onclick="navigate('postponed')">Postponed · ${postponedCount}</button>
    </div>
    <div class="reminder-list">
      ${items.length === 0
        ? `<div class="empty-state"><div class="empty-icon">✨</div><h3>Nothing here</h3><p>You're clear for this view.</p></div>`
        : items.map(r => reminderCard(r)).join('')}
    </div>
  `;
}

// ── Reminder Card ──────────────────────────────────────────────────
function assigneeLabel(id) {
  if (!id || id === 'me') return '';
  const member = App.family.find(f => f.id === id);
  return member ? member.name : '';
}

function workflowLabel(status) {
  const labels = {
    pending: 'Pending',
    in_progress: 'In progress',
    postponed: 'Postponed',
    done: 'Done',
  };
  return labels[status] || status || 'Pending';
}

function reminderCard(r) {
  const C = cap();
  const isTask = r.task_type === 'task';
  const wf = r.workflow_status || 'pending';
  const isOverdue = !isTask && (C?.isOverdueItem(r) || isReminderOverdue(r.next_fire));
  const tags = JSON.parse(r.tags || '[]');
  const kind = isTask ? '✅ Task' : '🔔 Reminder';
  const timeLabel = r.reminder_time ? formatTime(r.reminder_time) : '';
  const rel = C?.relativeTimeLabel(r.next_fire) || '';
  const dateShort = C?.formatDateShort(r.next_fire) || '';
  const assignee = assigneeLabel(r.assigned_to);
  const postponed = wf === 'postponed' && r.next_fire
    ? `Postponed → ${C?.formatDateShort(r.next_fire) || formatDate(r.next_fire?.slice(0, 10))}`
    : '';
  const context = [
    isTask ? workflowLabel(wf) : null,
    postponed || null,
    dateShort,
    timeLabel,
    rel,
    assignee ? `👤 ${assignee}` : null,
  ].filter(Boolean).join(' · ');
  const taskActions = isTask ? `
        ${wf === 'pending' || wf === 'postponed' ? `<button class="action-btn" onclick="startTask('${r.id}')" title="Start">▶</button>` : ''}
        ${wf === 'in_progress' ? `<button class="action-btn done" onclick="completeReminder('${r.id}')">✓</button>` : ''}
        ${wf !== 'done' && r.status !== 'completed' ? `<button class="action-btn" onclick="postponeTask('${r.id}')" title="Postpone">📅</button>` : ''}
      ` : `
        <button class="action-btn done" onclick="completeReminder('${r.id}')">✓</button>
        <button class="action-btn snooze" onclick="showSnoozeMenu('${r.id}')">💤</button>
        <button class="action-btn" onclick="postponeTomorrow('${r.id}')" title="Tomorrow">→</button>
      `;
  return `
    <div class="reminder-card ${r.priority} ${isOverdue ? 'overdue' : ''} ${isTask ? `task-${wf}` : ''}" id="rcard-${r.id}">
      <div class="reminder-check ${r.status === 'completed' || wf === 'done' ? 'done' : ''}" onclick="completeReminder('${r.id}')">
        ${r.status === 'completed' || wf === 'done' ? '✓' : ''}
      </div>
      <div class="reminder-body">
        <div class="reminder-title">${r.title}</div>
        <div class="reminder-context ${isOverdue ? 'overdue' : ''}">${kind}${context ? ' · ' + context : ''}</div>
        ${r.why_it_matters ? `<div class="reminder-why">${r.why_it_matters}</div>` : ''}
        <div class="reminder-meta">
          <span class="tag ${r.category}">${categoryIcon(r.category)} ${r.category}</span>
          ${r.priority !== 'normal' ? `<span class="tag ${r.priority}">${priorityLabel(r.priority)}</span>` : ''}
          ${tags.slice(0, 1).map(t => `<span class="tag">#${t}</span>`).join('')}
        </div>
      </div>
      <div class="reminder-actions">
        ${taskActions}
        <button class="action-btn" onclick="editReminder('${r.id}')">✏️</button>
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

// ── Completed Page ─────────────────────────────────────────────────
async function renderCompleted(el) {
  const items = App.reminders
    .filter(r => r.status === 'completed' || r.workflow_status === 'done')
    .sort((a, b) => String(b.last_completed || b.updated_at).localeCompare(String(a.last_completed || a.updated_at)));

  el.innerHTML = `
    <div class="page-header">
      <div>
        <div class="page-title">✅ Completed</div>
        <div class="page-subtitle">${items.length} finished item${items.length !== 1 ? 's' : ''}</div>
      </div>
    </div>
    <div class="reminder-list">
      ${items.length === 0
        ? `<div class="empty-state"><div class="empty-icon">🎉</div><h3>Nothing completed yet</h3><p>Finished reminders and tasks appear here.</p></div>`
        : items.map(r => reminderCard(r)).join('')}
    </div>
  `;
}

// ── Tasks Page ─────────────────────────────────────────────────────
async function renderTasks(el) {
  const tasks = App.reminders.filter(r =>
    r.task_type === 'task' && r.status !== 'completed' && (r.workflow_status || 'pending') !== 'done'
  );
  const pending = tasks.filter(t => (t.workflow_status || 'pending') === 'pending');
  const active = tasks.filter(t => t.workflow_status === 'in_progress');
  const postponed = tasks.filter(t => t.workflow_status === 'postponed');

  el.innerHTML = `
    <div class="page-header">
      <div>
        <div class="page-title">✅ Tasks</div>
        <div class="page-subtitle">Work items without the pressure of a timed alarm</div>
      </div>
      <button class="btn btn-primary" onclick="showCaptureSheet({ task_type: 'task' })">＋ New Task</button>
    </div>
    ${active.length ? `<div class="section-label">In progress</div><div class="reminder-list">${active.map(r => reminderCard(r)).join('')}</div>` : ''}
    ${pending.length ? `<div class="section-label">Pending</div><div class="reminder-list">${pending.map(r => reminderCard(r)).join('')}</div>` : ''}
    ${postponed.length ? `<div class="section-label">Postponed</div><div class="reminder-list">${postponed.map(r => reminderCard(r)).join('')}</div>` : ''}
    ${tasks.length === 0 ? `<div class="empty-state"><div class="empty-icon">✅</div><h3>No open tasks</h3><p>Create a task for things to do without a strict reminder time.</p></div>` : ''}
  `;
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
      <button class="btn btn-primary" onclick="showCaptureSheet()">＋ New</button>
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

// ── Add / Edit Reminder (legacy route → capture sheet) ─────────────
async function renderAddReminder(el) {
  el.innerHTML = '';
  showCaptureSheet();
  if (App.currentPage === 'add') navigate('today');
}

// Selector helpers (legacy form — capture sheet uses chips)
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

  // Compute next_fire using main-process computer clock (matches alarm scheduler)
  const nextFire = await computeNextFireForSave(startDate, time, repeatType);

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
  const result = api.completeReminder
    ? await api.completeReminder(id)
    : null;

  if (result && !result.success) {
    toast(result.error || 'Could not complete reminder', 'warning');
    return;
  }

  if (!result) {
    await db("UPDATE reminders SET status='completed', alarm_rings=0, last_completed=?, updated_at=? WHERE id=?",
      [new Date().toISOString(), new Date().toISOString(), id]);
    await db("INSERT INTO reminder_logs (id,reminder_id,action,timestamp) VALUES (?,?,'completed',datetime('now'))",
      [uuid(), id]);
  }

  const r = App.reminders.find(x => x.id === id);
  const isTask = result?.task || r?.task_type === 'task';
  const recurring = result?.recurring || (r && r.repeat_type && r.repeat_type !== 'once' && !isTask);
  toast(isTask ? '✅ Task completed!' : recurring ? '✅ Done — next occurrence scheduled' : '✅ Marked as complete!');

  const card = document.getElementById(`rcard-${id}`);
  if (card && !recurring) { card.style.opacity = '0.4'; card.style.pointerEvents = 'none'; }
  await loadAllData();
  updateBadges();
}

async function snoozeReminder(id, minutes) {
  const r = App.reminders.find(x => x.id === id);
  const limit = parseInt(App.settings.snooze_limit) || 3;
  const count = parseInt(r?.snooze_count) || 0;
  if (count >= limit) {
    toast(`Snooze limit reached (${limit}). Mark done or postpone.`, 'warning');
    return;
  }

  const duration = minutes || parseInt(r?.snooze_duration) || parseInt(App.settings.snooze_duration) || 10;
  const result = api.snoozeReminder
    ? await api.snoozeReminder(id, duration)
    : null;

  if (result?.error === 'snooze_limit') {
    toast(`Snooze limit reached (${result.limit || limit}). Mark done or postpone.`, 'warning');
    return;
  }
  if (result && !result.success) {
    toast(result.error || 'Could not snooze', 'warning');
    return;
  }

  if (!result) {
    const newFire = toLocalFireISO(new Date(Date.now() + duration * 60000));
    await db("UPDATE reminders SET next_fire=?, alarm_rings=0, snooze_count=snooze_count+1, updated_at=? WHERE id=?",
      [newFire, new Date().toISOString(), id]);
    await db("INSERT INTO reminder_logs (id,reminder_id,action,timestamp) VALUES (?,?,'snoozed',datetime('now'))", [uuid(), id]);
  }

  toast(`💤 Snoozed for ${result?.minutes || duration} minutes`);
  await loadAllData();
  updateBadges();
  if (PAGES[App.currentPage]) navigate(App.currentPage);
}

function showSnoozeMenu(id) {
  document.getElementById('snooze-menu')?.remove();
  const menu = document.createElement('div');
  menu.id = 'snooze-menu';
  menu.className = 'modal-overlay';
  menu.style.zIndex = '1050';
  menu.innerHTML = `
    <div class="capture-sheet" style="width:min(360px,94vw)">
      <div class="capture-header"><h2>Snooze</h2><button class="modal-close" onclick="document.getElementById('snooze-menu').remove()">✕</button></div>
      <div class="chip-row">
        ${[10, 30, 60].map(m => `<button class="chip" onclick="document.getElementById('snooze-menu').remove();snoozeReminder('${id}',${m})">${m} min</button>`).join('')}
      </div>
      <div class="chip-row" style="margin-top:8px">
        <button class="chip" onclick="document.getElementById('snooze-menu').remove();postponeTonight('${id}')">This evening</button>
        <button class="chip" onclick="document.getElementById('snooze-menu').remove();postponeTomorrow('${id}')">Tomorrow</button>
        <button class="chip" onclick="document.getElementById('snooze-menu').remove();postponeNextWeek('${id}')">Next week</button>
      </div>
    </div>`;
  attachModalDismiss(menu);
  menu.querySelector('.capture-sheet')?.addEventListener('click', e => e.stopPropagation());
  document.body.appendChild(menu);
}

async function postponeTo(id, dateStr, timeStr) {
  const r = App.reminders.find(x => x.id === id);
  if (!r) return;

  const result = api.postponeReminder
    ? await api.postponeReminder(id, dateStr, timeStr || r.reminder_time || '09:00')
    : null;

  if (result && !result.success) {
    toast(result.error || 'Could not postpone', 'warning');
    return;
  }

  if (!result) {
    const nextFire = await computeNextFireForSave(dateStr, timeStr || r.reminder_time || '09:00', r.repeat_type || 'once');
    await db("UPDATE reminders SET start_date=?, reminder_time=?, next_fire=?, alarm_rings=0, updated_at=? WHERE id=?",
      [dateStr, timeStr || r.reminder_time, nextFire, new Date().toISOString(), id]);
    await db("INSERT INTO reminder_logs (id,reminder_id,action,timestamp) VALUES (?,?,'postponed',datetime('now'))", [uuid(), id]);
  }

  toast('📅 Postponed');
  await loadAllData();
  updateBadges();
  if (PAGES[App.currentPage]) navigate(App.currentPage);
}

async function postponeTomorrow(id) {
  const C = cap();
  const d = C ? C.dateStr(C.addDays(new Date(), 1)) : todayStr();
  const r = App.reminders.find(x => x.id === id);
  await postponeTo(id, d, r?.reminder_time || '09:00');
}

async function postponeTonight(id) {
  await postponeTo(id, todayStr(), '18:00');
}

async function postponeNextWeek(id) {
  const C = cap();
  const d = C ? C.dateStr(C.addDays(new Date(), 7)) : todayStr();
  const r = App.reminders.find(x => x.id === id);
  await postponeTo(id, d, r?.reminder_time || '09:00');
}

async function startTask(id) {
  const result = api.updateWorkflowStatus
    ? await api.updateWorkflowStatus(id, 'in_progress')
    : null;
  if (!result) {
    await db("UPDATE reminders SET workflow_status='in_progress', updated_at=? WHERE id=?",
      [new Date().toISOString(), id]);
  }
  toast('▶ Task started');
  await loadAllData();
  if (PAGES[App.currentPage]) navigate(App.currentPage);
}

async function postponeTask(id) {
  await postponeTomorrow(id);
  toast('📅 Task postponed');
}

async function deleteReminder(id) {
  if (!confirm('Delete this reminder?')) return;
  await db("UPDATE reminders SET status='deleted' WHERE id=?", [id]);
  toast('Reminder deleted', 'warning');
  navigate('reminders');
}

function editReminder(id) {
  const r = App.reminders.find(x => x.id === id);
  if (r) showCaptureSheet(r);
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

async function switchMedTab(btn, tab) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  ['today', 'all', 'history'].forEach(t => {
    const el = document.getElementById(`med-tab-${t}`);
    if (el) el.style.display = t === tab ? '' : 'none';
  });
  if (tab === 'history') await loadMedHistory();
}

async function loadMedHistory() {
  const el = document.getElementById('med-tab-history');
  if (!el) return;
  const rows = await db(`
    SELECT ml.*, m.name as med_name FROM medicine_logs ml
    LEFT JOIN medicines m ON m.id = ml.medicine_id
    ORDER BY ml.log_date DESC, ml.dose_time DESC LIMIT 40
  `) || [];

  el.innerHTML = rows.length === 0
    ? `<div class="empty-state"><div class="empty-icon">📋</div><h3>No dose history yet</h3><p>Mark doses as taken to build your history.</p></div>`
    : `<div style="display:flex;flex-direction:column;gap:8px">
      ${rows.map(r => `
        <div class="card card-sm" style="display:flex;align-items:center;gap:12px;padding:12px 16px">
          <span style="font-size:20px">${r.status === 'taken' ? '✅' : '⏰'}</span>
          <div style="flex:1">
            <div style="font-weight:600">${r.med_name || 'Medicine'}</div>
            <div style="font-size:12px;color:var(--text-muted)">${formatDate(r.log_date)} · ${formatTime(r.dose_time)} · ${r.status}</div>
          </div>
        </div>`).join('')}
    </div>`;
}

async function markDoseTaken(medId, doseTime) {
  const result = api.completeModuleAction
    ? await api.completeModuleAction('medicine', medId, doseTime || nowTimeStr())
    : null;
  if (!result?.success && result) {
    toast(result.error || 'Could not mark dose', 'warning');
    return;
  }
  if (!result) {
    const logId = uuid();
    await db(`INSERT OR REPLACE INTO medicine_logs (id,medicine_id,dose_time,scheduled_time,status,taken_at,log_date) VALUES (?,?,?,?,?,?,?)`,
      [logId, medId, doseTime, doseTime, 'taken', new Date().toISOString(), todayStr()]);
  }
  toast('💊 Dose marked as taken!');
  await loadAllData();
  navigate(App.currentPage === 'today' ? 'today' : 'medicine');
}

function showAddMedicine() {
  showMedicineModal();
}

function showMedicineModal(existing = null) {
  const med = existing || {};
  const isEdit = !!existing?.id;
  const times = JSON.parse(med.dose_times || '["08:00"]');
  const doses = med.doses_per_day || times.length || 1;

  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'med-modal';
  overlay.innerHTML = `
    <div class="modal">
      <div class="modal-header">
        <div class="modal-title">💊 ${isEdit ? 'Edit' : 'Add'} Medicine</div>
        <button class="modal-close" onclick="document.getElementById('med-modal').remove()">✕</button>
      </div>
      <input type="hidden" id="m-id" value="${med.id || ''}" />
      <div class="form-grid">
        <div class="form-group full"><label class="form-label">Medicine Name *</label><input type="text" class="form-input" id="m-name" value="${med.name || ''}" placeholder="e.g. Metformin 500mg" /></div>
        <div class="form-group full"><label class="form-label">Condition / Purpose</label><input type="text" class="form-input" id="m-condition" value="${med.condition || ''}" placeholder="e.g. Diabetes" /></div>
        <div class="form-group"><label class="form-label">Doses per Day</label><input type="number" class="form-input" id="m-doses" value="${doses}" min="1" max="8" onchange="updateDoseTimes(this.value)"/></div>
        <div class="form-group"><label class="form-label">Food Timing</label>
          <select class="form-select" id="m-food">
            <option value="before" ${(med.food_timing || 'after') === 'before' ? 'selected' : ''}>Before Food</option>
            <option value="after" ${(med.food_timing || 'after') === 'after' ? 'selected' : ''}>After Food</option>
            <option value="with" ${med.food_timing === 'with' ? 'selected' : ''}>With Food</option>
            <option value="empty" ${med.food_timing === 'empty' ? 'selected' : ''}>Empty Stomach</option>
          </select>
        </div>
        <div class="form-group full" id="dose-times-container">
          <label class="form-label">Dose Times</label>
          ${times.map((t, i) => `<input type="time" class="form-input" id="m-time-${i}" value="${t}" style="margin-bottom:6px"/>`).join('')}
        </div>
        <div class="form-group"><label class="form-label">Start Date</label><input type="date" class="form-input" id="m-start" value="${med.start_date || todayStr()}"/></div>
        <div class="form-group"><label class="form-label">End Date (optional)</label><input type="date" class="form-input" id="m-end" value="${med.end_date || ''}" /></div>
        <div class="form-group full"><label class="form-label">Notes</label><textarea class="form-textarea" id="m-notes" placeholder="Dosage info, doctor's note...">${med.notes || ''}</textarea></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="document.getElementById('med-modal').remove()">Cancel</button>
        <button class="btn btn-primary" onclick="saveMedicine()">💊 ${isEdit ? 'Update' : 'Save'} Medicine</button>
      </div>
    </div>
  `;
  attachModalDismiss(overlay);
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
  const editId = document.getElementById('m-id')?.value;
  const params = [
    name,
    document.getElementById('m-condition').value,
    doses,
    JSON.stringify(times),
    document.getElementById('m-food').value,
    document.getElementById('m-start').value,
    document.getElementById('m-end').value,
    document.getElementById('m-notes')?.value || '',
  ];

  let ok;
  if (editId) {
    ok = await dbRun(`UPDATE medicines SET name=?,condition=?,doses_per_day=?,dose_times=?,food_timing=?,start_date=?,end_date=?,notes=? WHERE id=?`,
      [...params, editId]);
  } else {
    ok = await dbRun(`INSERT INTO medicines (id,name,condition,doses_per_day,dose_times,food_timing,start_date,end_date,notes,status) VALUES (?,?,?,?,?,?,?,?,?,?)`,
      [uuid(), ...params, 'active']);
  }
  if (!ok) return;

  document.getElementById('med-modal').remove();
  toast(`💊 ${name} ${editId ? 'updated' : 'added'}!`);
  await loadAllData();
  navigate('medicine');
}

async function deleteMedicine(id) {
  if (!confirm('Remove this medicine?')) return;
  await db("UPDATE medicines SET status='inactive' WHERE id=?", [id]);
  toast('Medicine removed', 'warning');
  navigate('medicine');
}

function editMedicine(id) {
  const med = App.medicines.find(m => m.id === id);
  if (med) showMedicineModal(med);
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
            <div style="display:flex;gap:6px;flex-shrink:0">
              <button class="btn btn-primary btn-sm" onclick="markBillPaid('${b.id}')">✓ Paid</button>
              <button class="action-btn" onclick="editBill('${b.id}')">✏️ Edit</button>
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
  showBillModal();
}

function showBillModal(existing = null) {
  const bill = existing || {};
  const isEdit = !!existing?.id;
  const types = ['electricity','water','gas','internet','phone','rent','insurance','credit','emi','subscription','other'];

  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'bill-modal';
  overlay.innerHTML = `
    <div class="modal">
      <div class="modal-header">
        <div class="modal-title">💸 ${isEdit ? 'Edit' : 'Add'} Bill</div>
        <button class="modal-close" onclick="document.getElementById('bill-modal').remove()">✕</button>
      </div>
      <input type="hidden" id="b-id" value="${bill.id || ''}" />
      <div class="form-grid">
        <div class="form-group full"><label class="form-label">Bill Name *</label><input type="text" class="form-input" id="b-name" value="${bill.name || ''}" placeholder="e.g. BSES Electricity" /></div>
        <div class="form-group"><label class="form-label">Bill Type</label>
          <select class="form-select" id="b-type">
            ${types.map(t => `<option value="${t}" ${(bill.bill_type || 'other') === t ? 'selected' : ''}>${billIcon(t)} ${t}</option>`).join('')}
          </select>
        </div>
        <div class="form-group"><label class="form-label">Amount (₹)</label><input type="number" class="form-input" id="b-amount" value="${bill.amount || ''}" placeholder="0" min="0"/></div>
        <div class="form-group"><label class="form-label">Due Day of Month</label><input type="number" class="form-input" id="b-due" value="${bill.due_day || 1}" min="1" max="31"/></div>
        <div class="form-group"><label class="form-label">Warn Me (days before)</label><input type="number" class="form-input" id="b-warn" value="${bill.warning_days || 3}" min="1" max="14"/></div>
        <div class="form-group full"><label class="form-label">Account / Notes</label><input type="text" class="form-input" id="b-notes" value="${bill.account_info || ''}" placeholder="Account number, bank, etc."/></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="document.getElementById('bill-modal').remove()">Cancel</button>
        <button class="btn btn-primary" onclick="saveBill()">💾 ${isEdit ? 'Update' : 'Save'} Bill</button>
      </div>
    </div>
  `;
  attachModalDismiss(overlay);
  document.body.appendChild(overlay);
}

function editBill(id) {
  const bill = App.bills.find(b => b.id === id);
  if (bill) showBillModal(bill);
}

async function saveBill() {
  const name = document.getElementById('b-name').value.trim();
  if (!name) { toast('Enter bill name', 'warning'); return; }
  const dueDay = parseInt(document.getElementById('b-due').value) || 1;
  const params = [
    name,
    document.getElementById('b-type').value,
    parseFloat(document.getElementById('b-amount').value) || 0,
    dueDay,
    parseInt(document.getElementById('b-warn').value) || 3,
    document.getElementById('b-notes').value,
  ];
  const editId = document.getElementById('b-id')?.value;

  let ok;
  if (editId) {
    ok = await dbRun(`UPDATE bills SET name=?,bill_type=?,amount=?,due_day=?,warning_days=?,account_info=? WHERE id=?`,
      [...params, editId]);
  } else {
    ok = await dbRun(`INSERT INTO bills (id,name,bill_type,amount,due_day,warning_days,account_info,status) VALUES (?,?,?,?,?,?,?,?)`,
      [uuid(), ...params, 'active']);
  }
  if (!ok) return;

  document.getElementById('bill-modal').remove();
  toast(`💸 ${name} ${editId ? 'updated' : 'added'}!`);
  await loadAllData();
  navigate('bills');
}

async function markBillPaid(id) {
  const result = api.completeModuleAction
    ? await api.completeModuleAction('bill', id)
    : null;
  if (!result?.success && result) {
    toast(result.error || 'Could not mark bill paid', 'warning');
    return;
  }
  if (!result) {
    const logId = uuid();
    await db(`INSERT INTO bill_history (id,bill_id,paid_date,amount) VALUES (?,?,?,0)`, [logId, id, todayStr()]);
    await db(`UPDATE bills SET payment_status='paid' WHERE id=?`, [id]);
  }
  toast('✅ Bill marked as paid!');
  await loadAllData();
  navigate(App.currentPage === 'today' ? 'today' : 'bills');
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
  attachModalDismiss(overlay);
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
  attachModalDismiss(overlay);
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
  attachModalDismiss(overlay);
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

  document.getElementById('hab-modal').remove();
  toast(`🔁 Habit started!`);
  navigate('habits');
}

async function logHabit(id) {
  const result = api.completeModuleAction
    ? await api.completeModuleAction('habit', id)
    : null;
  if (!result?.success && result) {
    toast(result.error || 'Could not log habit', 'warning');
    return;
  }
  if (!result) {
    const logId = uuid();
    await db(`INSERT OR REPLACE INTO habit_logs (id,habit_id,log_date,completed) VALUES (?,?,?,1)`, [logId, id, todayStr()]);
    const habit = App.habits.find(h => h.id === id);
    if (habit) {
      const newStreak = (habit.streak || 0) + 1;
      const bestStreak = Math.max(newStreak, habit.best_streak || 0);
      await db(`UPDATE habits SET streak=?,best_streak=?,last_completed=?,completion_rate=MIN(100,completion_rate+3) WHERE id=?`,
        [newStreak, bestStreak, todayStr(), id]);
    }
  }
  toast('🔥 Habit logged! Streak growing!');
  await loadAllData();
  navigate(App.currentPage === 'today' ? 'today' : 'habits');
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

    const C = cap();
    const monthStr = `${viewYear}-${String(viewMonth + 1).padStart(2, '0')}`;
    const monthReminders = App.reminders.filter(r => C?.isScheduledInMonth(r, monthStr));

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
      const dayReminders = monthReminders.filter(r => C?.isScheduledOnDate(r, dayStr));
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
      <div><div class="page-title">📅 Calendar</div><div class="page-subtitle">Scheduled by next fire time</div></div>
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
  const C = cap();
  const dayReminders = App.reminders
    .filter(r => C?.isScheduledOnDate(r, dateStr))
    .sort((a, b) => String(a.next_fire).localeCompare(String(b.next_fire)));
  const container = document.getElementById('day-events');
  container.innerHTML = `
    <div class="section-header"><div class="section-title">📋 ${formatDate(dateStr)}</div><button class="btn btn-primary btn-sm" onclick="navigate('add')">+ Add</button></div>
    ${dayReminders.length === 0
      ? `<p style="color:var(--text-muted)">Nothing scheduled to fire on this day. <a href="#" onclick="navigate('add')" style="color:var(--accent)">Add one?</a></p>`
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
  attachModalDismiss(overlay);
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
let settingsClockTimer;

async function renderSettings(el) {
  const s = App.settings;
  const clock = await api.getSystemClock?.() || { time: nowTimeStr(), date: todayStr(), timezone: 'local' };

  if (settingsClockTimer) clearInterval(settingsClockTimer);

  el.innerHTML = `
    <div class="page-header">
      <div>
        <div class="page-title">⚙️ Settings</div>
        <div class="page-subtitle" id="system-clock-display" style="font-size:13px;color:var(--text-muted);margin-top:4px">
          🕐 Computer time: ${clock.time} · ${clock.date} (${clock.timezone || 'local'})
        </div>
      </div>
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
              <div class="setting-label">Voice Announcement</div>
              <div class="setting-desc">After the alert, ILRS speaks the reminder purpose in natural Indian English</div>
            </div>
            <div class="toggle ${s.voice_announcements==='1'?'on':''}" id="t-voice" onclick="this.classList.toggle('on')"></div>
          </div>
          <div class="setting-row">
            <div class="setting-info">
              <div class="setting-label">Preview Voice</div>
              <div class="setting-desc">Hear a sample Indian English announcement</div>
            </div>
            <button class="btn btn-ghost btn-sm" onclick="previewVoiceAnnouncement()">🗣️ Preview</button>
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
              <div class="setting-desc">Schedules a real alarm in 60 seconds — close the window; it rings from the tray</div>
            </div>
            <button class="btn btn-primary btn-sm" onclick="scheduleTestAlarm()">⏰ Test Alarm</button>
          </div>
          <div class="setting-row">
            <div class="setting-info">
              <div class="setting-label">Background reminders (Windows)</div>
              <div class="setting-desc">Close the window — ILRS stays in the system tray (near the clock). Click the ^ arrow if hidden. Tray → Quit stops all reminders.</div>
            </div>
            <span style="font-size:12px;color:var(--normal);font-weight:700">✓ Enabled</span>
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
              <option value="light" ${s.appearance==='light'?'selected':''}>☀️ Light</option>
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

        <div class="card" style="margin-bottom:16px">
          <div class="settings-section-title">📊 Insights</div>
          <div class="setting-row">
            <div class="setting-info"><div class="setting-label">Weekly Reports</div><div class="setting-desc">View completion stats and trends</div></div>
            <button class="btn btn-ghost btn-sm" onclick="navigate('reports')">Open Reports</button>
          </div>
          <div class="setting-row">
            <div class="setting-info"><div class="setting-label">Rewards & Streaks</div><div class="setting-desc">Show rewards in sidebar when enabled</div></div>
            <div class="toggle ${s.rewards_enabled==='1'?'on':''}" id="t-rewards" onclick="this.classList.toggle('on')"></div>
          </div>
        </div>

        <div class="card">
          <div class="settings-section-title">🖥️ System</div>
          <div class="setting-row">
            <div class="setting-info">
              <div class="setting-label">Start with Windows</div>
              <div class="setting-desc">Launch ILRS in the background when your PC starts (recommended)</div>
            </div>
            <div class="toggle ${s.auto_start==='1'?'on':''}" id="t-autostart" onclick="this.classList.toggle('on')"></div>
          </div>
        </div>
      </div>
    </div>
  `;

  document.getElementById('s-theme')?.addEventListener('change', (e) => {
    applyTheme(e.target.value);
  });

  settingsClockTimer = setInterval(async () => {
    const display = document.getElementById('system-clock-display');
    if (!display || App.currentPage !== 'settings') {
      clearInterval(settingsClockTimer);
      settingsClockTimer = null;
      return;
    }
    const live = await api.getSystemClock?.();
    if (live) {
      display.textContent = `🕐 Computer time: ${live.time} · ${live.date} (${live.timezone || 'local'})`;
    }
  }, 1000);
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
    local_backup: document.getElementById('t-backup')?.classList.contains('on') ? '1' : '0',
    data_cleanup_days: document.getElementById('s-cleanup')?.value || '90',
    rewards_enabled: document.getElementById('t-rewards')?.classList.contains('on') ? '1' : '0',
    auto_start: document.getElementById('t-autostart')?.classList.contains('on') ? '1' : '0',
    voice_announcements: document.getElementById('t-voice')?.classList.contains('on') ? '1' : '0',
  };

  for (const [k, v] of Object.entries(updates)) {
    await saveSetting(k, v);
  }
  applyTheme(updates.appearance);
  await api.applyAutoStart?.(updates.auto_start === '1');
  renderShell();
  toast('✅ Settings saved!');
  if (PAGES[App.currentPage]) navigate(App.currentPage);
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
    toast(`⏰ Test alarm at ${result.fireAt.slice(11, 16)} — you can close the window; ILRS stays in tray`);
    navigate('reminders');
  } else {
    toast(`Could not schedule test alarm: ${result?.error || 'unknown error'}`, 'critical');
  }
}

function previewSound(soundId) {
  window.ILRSSounds?.previewSound(soundId);
}

function previewVoiceAnnouncement() {
  const sample = window.ILRSVoiceText?.build({
    title: 'Take blood pressure medicine',
    why_it_matters: 'It keeps your health on track.',
    priority: 'important',
  }, 'reminder');
  window.ILRSVoice?.speak(sample || 'Just a reminder. Time to take your medicine. Please do not forget.');
  toast('Playing voice preview — uses your system Indian English voice if available');
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

// ── Quick Add (NLP) ────────────────────────────────────────────────
async function handleQuickAdd() {
  const input = document.getElementById('quick-input');
  const text = input.value.trim();
  if (!text) return;

  const parsed = cap()?.parseReminderText(text) || { title: text, startDate: todayStr(), time: '', repeatType: 'once', category: 'general', priority: 'normal' };
  if (!parsed.title) { toast('Please describe what to remember', 'warning'); return; }

  const id = uuid();
  const fireTime = parsed.time || '09:00';
  const nextFire = await computeNextFireForSave(parsed.startDate, fireTime, parsed.repeatType);

  if (!await dbRun(`INSERT INTO reminders (id,title,task_type,category,repeat_type,reminder_time,start_date,priority,alert_style,status,next_fire,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))`,
    [id, parsed.title, 'reminder', parsed.category, parsed.repeatType, parsed.time, parsed.startDate, parsed.priority, 'sound-popup', 'active', nextFire])) return;

  input.value = '';
  updateQuickAddPreview();
  toast(`✅ ${parsed.title}`);
  await loadAllData();
  updateBadges();
  if (PAGES[App.currentPage]) navigate(App.currentPage);
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
  navigate('overdue');
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

// ── Global Search (Ctrl+K) ─────────────────────────────────────────
function buildSearchResults(query) {
  const q = query.trim().toLowerCase();
  if (!q) return [];

  const results = [];

  App.reminders.forEach((r) => {
    const hay = `${r.title} ${r.why_it_matters || ''} ${r.category || ''} ${r.tags || ''}`.toLowerCase();
    if (!hay.includes(q)) return;
    const kind = r.task_type === 'task' ? 'Task' : 'Reminder';
    results.push({
      id: r.id,
      type: 'reminder',
      page: r.task_type === 'task' ? 'tasks' : 'reminders',
      icon: r.task_type === 'task' ? '✅' : '🔔',
      title: r.title,
      subtitle: `${kind} · ${r.status}${r.next_fire ? ' · ' + (cap()?.formatDateShort(r.next_fire) || '') : ''}`,
      action: () => editReminder(r.id),
    });
  });

  App.medicines.forEach((m) => {
    if (!`${m.name} ${m.condition || ''}`.toLowerCase().includes(q)) return;
    results.push({ id: m.id, type: 'medicine', page: 'medicine', icon: '💊', title: m.name, subtitle: 'Medicine', action: () => navigate('medicine') });
  });

  App.bills.forEach((b) => {
    if (!`${b.name} ${b.bill_type || ''}`.toLowerCase().includes(q)) return;
    results.push({ id: b.id, type: 'bill', page: 'bills', icon: '💸', title: b.name, subtitle: 'Bill', action: () => navigate('bills') });
  });

  App.habits.forEach((h) => {
    if (!h.name.toLowerCase().includes(q)) return;
    results.push({ id: h.id, type: 'habit', page: 'habits', icon: '🔁', title: h.name, subtitle: `Habit · ${h.streak || 0}d streak`, action: () => navigate('habits') });
  });

  App.family.forEach((f) => {
    if (!`${f.name} ${f.role || ''}`.toLowerCase().includes(q)) return;
    results.push({ id: f.id, type: 'family', page: 'family', icon: '👨‍👩‍👧', title: f.name, subtitle: f.role || 'Family', action: () => navigate('family') });
  });

  return results.slice(0, 12);
}

function showSearchPalette() {
  dismissPageModals();
  document.getElementById('search-palette')?.remove();
  App.searchIndex = 0;

  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay search-overlay';
  overlay.id = 'search-palette';
  overlay.innerHTML = `
    <div class="search-palette" role="dialog" aria-label="Search">
      <input type="text" class="form-input search-palette-input" id="search-input" placeholder="Search reminders, tasks, medicine, bills…" autocomplete="off" />
      <div class="search-hint">↑↓ navigate · Enter open · Esc close</div>
      <div id="search-results" class="search-results"></div>
    </div>
  `;

  const renderResults = () => {
    const input = document.getElementById('search-input');
    const list = document.getElementById('search-results');
    if (!input || !list) return;
    const results = buildSearchResults(input.value);
    App.searchResults = results;
    if (results.length === 0) {
      list.innerHTML = `<div class="search-empty">${input.value.trim() ? 'No matches' : 'Type to search everything'}</div>`;
      return;
    }
    list.innerHTML = results.map((r, i) => `
      <button type="button" class="search-result ${i === App.searchIndex ? 'active' : ''}" data-idx="${i}">
        <span class="search-result-icon">${r.icon}</span>
        <span class="search-result-body">
          <span class="search-result-title">${r.title}</span>
          <span class="search-result-sub">${r.subtitle}</span>
        </span>
      </button>
    `).join('');
    list.querySelectorAll('.search-result').forEach((btn) => {
      btn.addEventListener('click', () => runSearchResult(parseInt(btn.dataset.idx, 10)));
    });
  };

  const runSearchResult = (idx) => {
    const item = App.searchResults?.[idx];
    if (!item) return;
    overlay.remove();
    item.action();
  };

  overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
  document.body.appendChild(overlay);

  const input = document.getElementById('search-input');
  input.addEventListener('input', () => { App.searchIndex = 0; renderResults(); });
  input.addEventListener('keydown', (e) => {
    const count = App.searchResults?.length || 0;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      App.searchIndex = Math.min(App.searchIndex + 1, Math.max(0, count - 1));
      renderResults();
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      App.searchIndex = Math.max(App.searchIndex - 1, 0);
      renderResults();
    } else if (e.key === 'Enter') {
      e.preventDefault();
      runSearchResult(App.searchIndex);
    } else if (e.key === 'Escape') {
      overlay.remove();
    }
  });
  setTimeout(() => input.focus(), 30);
  renderResults();
}

function isTypingInField(target) {
  if (!target) return false;
  const tag = target.tagName;
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable;
}

function showShortcutsHelp() {
  dismissPageModals();
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay search-overlay';
  overlay.id = 'shortcuts-help';
  const shortcuts = [
    ['Ctrl+K', 'Search everything'],
    ['Ctrl+N', 'New reminder / task'],
    ['Ctrl+Shift+A', 'New reminder / task'],
    ['Enter', 'Submit quick-add bar'],
    ['Esc', 'Close modal or search'],
    ['?', 'Show this help'],
  ];
  overlay.innerHTML = `
    <div class="search-palette" role="dialog" aria-label="Keyboard shortcuts">
      <h2 style="margin:0 0 12px;font-size:18px">⌨️ Keyboard Shortcuts</h2>
      <div class="search-results">
        ${shortcuts.map(([key, desc]) => `
          <div class="search-result" style="cursor:default">
            <span class="search-result-icon" style="font-family:var(--font-mono);font-size:12px;min-width:120px">${key}</span>
            <span class="search-result-body"><span class="search-result-title">${desc}</span></span>
          </div>`).join('')}
      </div>
      <button class="btn btn-ghost btn-sm" style="margin-top:12px;width:100%" onclick="document.getElementById('shortcuts-help').remove()">Close</button>
    </div>
  `;
  overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
  document.body.appendChild(overlay);
}

// ── IPC Listeners ──────────────────────────────────────────────────
function setupListeners() {
  document.addEventListener('keydown', (e) => {
    if (e.key === '?' && !e.ctrlKey && !e.metaKey && !isTypingInField(e.target)) {
      e.preventDefault();
      showShortcutsHelp();
      return;
    }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      showSearchPalette();
      return;
    }
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key.toLowerCase() === 'n' && !isTypingInField(e.target)) {
      e.preventDefault();
      showCaptureSheet();
      return;
    }
    if (e.ctrlKey && e.shiftKey && e.key.toLowerCase() === 'a') {
      e.preventDefault();
      showCaptureSheet();
      return;
    }
    if (e.key !== 'Escape') return;
    const alert = document.getElementById('alert-popup');
    if (alert) return;
    dismissPageModals();
  });

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

  api.onSpeakReminder(({ text }) => {
    if (App.settings.voice_announcements === '0') return;
    window.ILRSVoice?.speak(text);
  });

  api.onNotificationClicked((reminder) => {
    openReminderFromNotificationClick(reminder);
  });

  api.onReminderUpdated?.(async () => {
    await loadAllData();
    updateBadges();
    if (PAGES[App.currentPage]) navigate(App.currentPage);
    dismissAlert();
  });
}

function openReminderFromNotificationClick(reminder) {
  if (!reminder) return;
  App.isProcessingAlert = false;
  showInAppAlert(reminder);
  App.isProcessingAlert = true;
  if (App.currentPage !== 'reminders' && App.currentPage !== 'today' && App.currentPage !== 'dashboard') {
    navigate('today');
  }
}

function processAlertQueue() {
  if (App.alertQueue.length === 0) { App.isProcessingAlert = false; return; }
  App.isProcessingAlert = true;
  const reminder = App.alertQueue.shift();

  if (App.pausedUntil && Date.now() < App.pausedUntil && reminder.priority !== 'critical') {
    processAlertQueue();
    return;
  }

  if (App.focusMode && reminder.priority !== 'critical') {
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

  App.currentAlert = reminder;
  const isPrivate = Number(reminder.is_private) === 1;
  const type = reminder._type || reminder.task_type || 'reminder';
  const title = isPrivate ? 'Private Reminder' : (reminder.title || reminder.name || 'Reminder');
  const body = isPrivate
    ? 'You have a scheduled reminder.'
    : (window.ILRSVoiceText?.display(reminder, type) || reminder.why_it_matters || 'Time for action!');
  const isModule = type === 'medicine' || type === 'bill' || type === 'habit';
  const isAlarm = !isModule && (reminder.priority === 'critical' || type === 'reminder');
  const doneLabel = type === 'medicine' ? '💊 Taken' : type === 'bill' ? '✓ Paid' : type === 'habit' ? '✅ Done' : '✅ Done';
  const snoozeBtn = isModule
    ? ''
    : `<button class="btn btn-ghost" onclick="snoozeFromAlert()">💤 Snooze</button>`;

  const overlay = document.createElement('div');
  overlay.className = reminder.priority === 'critical' ? 'alert-popup' : 'modal-overlay';
  overlay.id = 'alert-popup';
  overlay.innerHTML = `
    <div class="alert-box${isAlarm ? ' alarm-active' : ''}">
      <div class="alert-icon">${reminder.priority === 'critical' ? '🚨' : type === 'medicine' ? '💊' : type === 'bill' ? '💸' : type === 'habit' ? '🔁' : '⏰'}</div>
      <h2>${title}</h2>
      <p>${body}</p>
      <p style="font-size:12px;color:var(--text-muted);margin-top:8px">${isAlarm ? 'Alarm active — mark done or snooze to stop alerts.' : ''}</p>
      <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin-bottom:16px">
        ${reminder.category ? `<span class="tag ${reminder.category}">${categoryIcon(reminder.category)} ${reminder.category}</span>` : ''}
        ${reminder.priority ? `<span class="tag ${reminder.priority}">${priorityLabel(reminder.priority)}</span>` : ''}
      </div>
      <div class="alert-buttons">
        <button class="btn btn-primary" onclick="completeFromAlert()">${doneLabel}</button>
        ${snoozeBtn}
        <button class="btn btn-ghost" onclick="dismissAlert()">✕ Dismiss</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
}

async function completeFromAlert() {
  const item = App.currentAlert;
  if (!item) return;
  const type = item._type || item.task_type || 'reminder';

  if (type === 'medicine') {
    await markDoseTaken(item.id, item._doseTime || nowTimeStr());
  } else if (type === 'bill') {
    await markBillPaid(item.id);
  } else if (type === 'habit') {
    await logHabit(item.id);
  } else {
    await completeReminder(item.id);
  }
  App.currentAlert = null;
  dismissAlert();
  await loadAllData();
  updateBadges();
}

async function snoozeFromAlert() {
  const item = App.currentAlert;
  if (!item?.id) return;
  await snoozeReminder(item.id);
  App.currentAlert = null;
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
    applyTheme(App.settings.appearance || 'dark');
    await loadAllData();
    renderShell();
    setupListeners();
    await navigate('today');
    updateBadges();

    // Hide loader, show app
    document.getElementById('loading-screen')?.remove();
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
      <div style="font-size:56px;margin-bottom:16px">🔔</div>
      <div class="modal-title" style="font-size:22px;margin-bottom:8px">Welcome to ILRS!</div>
      <p style="color:var(--text-secondary);margin-bottom:20px">Your Modern Reminder system is ready. Let's get started!</p>
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
