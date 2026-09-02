# 🧠 ILRS — Intelligent Life Reminder System

A complete desktop application for managing all your life reminders — medicine, bills, habits, family, tasks and more.

---

## 🚀 Quick Start (Windows)

### Step 1 — Install Node.js
1. Go to **https://nodejs.org**
2. Download the **LTS version** (e.g. 20.x LTS)
3. Run the installer — click Next → Next → Install
4. Restart your computer

### Step 2 — Install ILRS
1. Open the **ILRS** folder
2. Double-click **`INSTALL.bat`**
3. Wait for it to finish (2–5 minutes)

### Step 3 — Start ILRS
1. Double-click **`START ILRS.bat`**
2. ILRS opens! 🎉

---

## 🖥️ How to Run on Mac / Linux

```bash
# Make sure Node.js is installed: https://nodejs.org

# In Terminal, go to the ILRS folder:
cd /path/to/ILRS

# Install:
npm install

# Start:
npm start
```

---

## 📦 Build a Real Installable App (.exe / .dmg)

```bash
# Windows:
BUILD.bat
# or: npm run build:win

# Mac:
npm run build:mac

# Linux:
npm run build:linux
```

The installer will be in the **`dist/`** folder.

---

## ✨ Features

### 🔔 Reminders & Tasks
- Add reminders with title, category, priority, time
- Repeat: daily, weekly, monthly, custom
- 4-quadrant urgency matrix (Eisenhower)
- Snooze, complete, skip
- "Why it matters" motivational context
- Private mode (hide title in notification)
- Assign to family members

### 💊 Medicine Module
- Track medicines with dose times
- Food timing (before/after/with food)
- Mark doses as taken
- Daily dose schedule view
- Auto-creates reminder for each dose

### 💸 Bills Tracker
- Track recurring bills (electricity, water, rent, etc.)
- Due date alerts with customizable warning days
- Mark bills as paid
- Payment history

### 👨‍👩‍👧 Family
- Add family members (father, mother, spouse, etc.)
- Assign reminders to family members
- Emergency contact flag

### 🔁 Habits
- Build daily/weekly habits
- Streak tracking
- Completion rate progress bar
- One-click daily logging

### 📅 Calendar
- Monthly view with all reminders
- Color-coded by priority
- Click a day to see/add events

### ✅ Checklists
- Create named checklists
- Check off items
- Progress tracking

### 📊 Reports
- Completion rate
- Medicine adherence
- Habit activity stats
- Export to JSON

### 🏅 Rewards
- Earn badges for milestones
- Streak achievements
- Total completions tracker

### ⚙️ Settings
- Quiet hours (suppress non-critical alerts)
- Snooze duration & limit
- Dark theme
- Auto backup
- Export all data
- App lock (PIN)

### 🖥️ System Tray
- Runs in background
- Quick-add from tray
- Notification support
- Auto-start with Windows

### ⚡ Quick Add
Type in the top bar and press Enter:
- `"Take medicine at 8pm daily"` → creates medicine reminder
- `"Pay electricity bill"` → creates bill reminder category
- `"Morning walk daily at 7am"` → creates habit
- `"Meeting with client urgent"` → creates critical work reminder

---

## 🗂️ File Structure

```
ILRS/
├── main.js          → Electron main process
├── preload.js       → Secure IPC bridge
├── package.json     → Dependencies
├── src/
│   ├── index.html   → App shell
│   ├── styles.css   → All CSS
│   └── app.js       → Complete app logic
├── assets/          → Icons (add icon.ico, icon.png here)
├── INSTALL.bat      → Windows installer
├── START ILRS.bat   → Windows launcher
└── BUILD.bat        → Build .exe installer
```

---

## 🔧 Troubleshooting

**"node is not recognized"** → Install Node.js from nodejs.org and restart PC

**"npm install fails"** → Check internet connection, try: `npm install --legacy-peer-deps`

**App opens blank** → Check the developer console (Ctrl+Shift+I) for errors

**Notifications not showing** → Check Windows notification settings for "ILRS"

---

## 📋 System Requirements

- Windows 10/11, macOS 10.15+, or Linux (Ubuntu 20+)
- Node.js 18+ (LTS recommended)
- 200MB disk space
- 512MB RAM

---

*ILRS v1.0.0 — Built with Electron + SQLite*
