# ILRS — Modern Reminder

A desktop app for reminders, tasks, and life modules — medicine, bills, habits, family, and more. Built with Electron and SQLite.

---

## Quick Start (Windows)

### Step 1 — Install Node.js
1. Go to [https://nodejs.org](https://nodejs.org)
2. Download the **LTS version** (20.x or newer)
3. Run the installer, then restart your computer

### Step 2 — Install ILRS
1. Open the **ILRS** folder
2. Double-click **`INSTALL.bat`**
3. Wait for it to finish (2–5 minutes)

### Step 3 — Start ILRS
1. Double-click **`START ILRS.bat`**
2. ILRS opens

---

## Mac / Linux

```bash
cd /path/to/ILRS
npm install
npm start
```

---

## Build an installer (.exe / .dmg)

```bash
# Windows
BUILD.bat
# or: npm run build:win

# Mac
npm run build:mac

# Linux
npm run build:linux
```

Output goes to the `dist/` folder.

---

## Features

### Focus views (smart inbox)
- **Today** — due reminders plus a **Life Today** section (medicine doses, bills, habits)
- **Tomorrow**, **Upcoming** (7 days), **Overdue**, **Postponed**, **Completed**, **All**
- **Overdue** and **Tomorrow** also surface Life module items (medicine, bills, habits)
- Quick-add bar with natural-language parsing (e.g. `Call John tomorrow at 10am`)
- **Capture sheet** for full reminder/task entry with assignee, repeat, and priority

### Reminders & tasks
- Reminders and tasks share one list; tasks use workflow status (pending → in progress → done)
- Repeat: once, daily, weekly, monthly, or **custom** (every N days or specific weekdays)
- Snooze, complete, postpone (tonight / tomorrow / next week)
- OS toast notifications with **Done** and **Snooze** actions
- Assign to family members; optional private mode (hide title in notification)
- Calendar view keyed to **next fire** time (not just start date)

### Life modules
Life items are managed in their own modules — they do **not** duplicate into the reminders table.

- **Medicine** — dose schedule, food timing, mark taken, edit, dose history
- **Bills** — recurring bills, due-day alerts, mark paid, edit
- **Habits** — daily/weekday/weekend/weekly frequency, consecutive-day streaks, completion rate, one-click logging
- **Family** — members, assignee on capture, emergency contact flag
- **Checklists** — named lists with progress tracking

### Work / Inquiries
- **Pipeline** — table view grouped by stage (not Kanban): ID, client, requirement, stage, follow-up, health, days in stage
- **Inquiries** — list with Active / My / Closed filters; click to open detail
- **Follow-ups** — overdue, today, and upcoming inquiry follow-ups
- **Clients** — contacts auto-created from inquiries; filter inquiries by client
- Fast **New Inquiry** sheet (client + requirement + next action + follow-up)
- Stage change modal, edit inquiry, reopen closed inquiries
- Tasks/reminders can link to an inquiry (`source_type=inquiry`)
- Duplicate detection when creating inquiries

### Keyboard shortcuts
| Shortcut | Action |
|----------|--------|
| `Ctrl+N` | New reminder / task / inquiry |
| `Ctrl+Shift+A` | New reminder / task / inquiry |
| `Ctrl+K` | Search everything (incl. inquiries & clients) |
| `?` | Show shortcuts help |
| `Esc` | Close modal or search |

### System tray
- Runs in background
- Quick-add from tray
- Desktop notifications
- Auto-start with Windows (optional)

### Settings
- Quiet hours (suppress non-critical alerts)
- Snooze duration and limit
- Light / dark theme
- Auto local backup at 2 AM (toggle in settings; manual backup always available)
- Export all data (JSON)
- App lock (PIN)
- Insights / reports (completion stats, medicine adherence, habit activity)
- Rewards badges (optional, off by default in sidebar)

---

## Quick-add examples

Type in the top bar and press Enter:

- `Call John tomorrow at 10am` → reminder for tomorrow 10:00
- `Take medicine daily at 8pm` → medicine category, daily repeat
- `Pay electricity bill` → bills category
- `Meeting with client urgent` → critical priority

---

## File structure

```
ILRS/
├── main.js              → Electron main process, DB, scheduler, IPC
├── preload.js           → Secure IPC bridge
├── notifications.js     → Desktop toast actions
├── reminder-actions.js  → Complete, snooze, postpone logic
├── module-actions.js    → Medicine / bill / habit completion
├── package.json
├── src/
│   ├── index.html
│   ├── styles.css
│   ├── app.js           → UI, navigation, Life modules
│   ├── capture.js       → NLP parsing and smart-view filters
│   └── capture-ui.js    → Capture sheet modal
├── scripts/             → Tests and build helpers
├── assets/              → Icons and sounds
├── INSTALL.bat
├── START ILRS.bat
└── BUILD.bat
```

---

## Troubleshooting

**"node is not recognized"** → Install Node.js from [nodejs.org](https://nodejs.org) and restart

**"npm install fails"** → Check internet; try `npm install --legacy-peer-deps`

**App opens blank** → Open DevTools (`Ctrl+Shift+I`) and check the console

**Notifications not showing** → Enable notifications for ILRS in system settings

**Tests fail on better-sqlite3** → Run `npm rebuild better-sqlite3`

---

## System requirements

- Windows 10/11, macOS 10.15+, or Linux (Ubuntu 20+)
- Node.js 18+ (LTS recommended)
- ~200 MB disk space

---

*ILRS v1.0.22 — Electron + SQLite*
