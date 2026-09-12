/**
 * ILRS alarm scheduling utilities (local time — no UTC drift).
 */

const ALARM_REPEAT_MINUTES = 2;
const ALARM_MAX_RINGS = 6;

function pad(n) {
  return String(n).padStart(2, '0');
}

function toLocalISO(date = new Date()) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

function localDateStr(date = new Date()) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function localTimeStr(date = new Date()) {
  return `${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function localMinuteISO(date = new Date()) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function normalizeNextFire(value) {
  if (!value) return '';
  const raw = String(value).trim();
  if (raw.includes('Z') || /[+-]\d{2}:\d{2}$/.test(raw)) {
    const d = new Date(raw);
    if (!Number.isNaN(d.getTime())) return toLocalISO(d);
  }
  if (raw.includes('.')) return raw.split('.')[0].replace(' ', 'T');
  return raw.replace(' ', 'T');
}

function computeNextFireFromReminder(row, now = new Date()) {
  const startDate = row.start_date && !String(row.start_date).includes('Z')
    ? row.start_date
    : localDateStr(now);
  return computeNextFire(startDate, row.reminder_time, row.repeat_type || 'once', now);
}

function alreadyFiredThisMinute(reminder, now = new Date()) {
  if (!reminder.last_fired) return false;
  const last = parseLocalDateTime(normalizeNextFire(reminder.last_fired));
  if (!last) return false;
  return last.getFullYear() === now.getFullYear()
    && last.getMonth() === now.getMonth()
    && last.getDate() === now.getDate()
    && last.getHours() === now.getHours()
    && last.getMinutes() === now.getMinutes();
}

/**
 * Decide if a reminder should ring now, using the computer's local clock.
 * Uses next_fire when set, and falls back to matching reminder_time to system HH:mm.
 */
function shouldFireNow(reminder, now = new Date()) {
  const today = localDateStr(now);
  if (reminder.start_date && reminder.start_date > today) return false;
  if (reminder.end_date && reminder.end_date < today) return false;
  if (alreadyFiredThisMinute(reminder, now)) return false;

  // Snoozed to a custom future time — only fire when that exact time arrives.
  if (reminder.next_fire && isSnoozedFire(reminder.next_fire, reminder.reminder_time, now)) {
    return isDue(reminder.next_fire, now);
  }

  // Primary: stored next_fire moment has arrived (local wall clock).
  if (reminder.next_fire && isDue(reminder.next_fire, now)) return true;

  // Safety net: computer clock HH:mm matches reminder_time (fixes drift/wrong next_fire).
  if (!reminder.reminder_time) return false;
  if (reminder.reminder_time !== localTimeStr(now)) return false;

  const repeat = reminder.repeat_type || 'once';
  if (repeat === 'once') {
    return !reminder.start_date || reminder.start_date <= today;
  }
  if (repeat === 'daily') return true;
  if (repeat === 'weekly') {
    if (!reminder.start_date) return true;
    const anchor = parseLocalDateTime(`${reminder.start_date}T12:00:00`);
    return anchor ? anchor.getDay() === now.getDay() : true;
  }
  if (repeat === 'monthly') {
    if (!reminder.start_date) return true;
    const day = Number(reminder.start_date.split('-')[2]);
    return now.getDate() === day;
  }
  return true;
}

function syncNextFireWithSystemClock(reminder, now = new Date()) {
  if (!reminder.reminder_time) return reminder.next_fire || '';
  if (isSnoozedFire(reminder.next_fire, reminder.reminder_time, now)) {
    return normalizeNextFire(reminder.next_fire);
  }
  const startDate = reminder.start_date && !String(reminder.start_date).includes('Z')
    ? reminder.start_date
    : localDateStr(now);
  return computeNextFire(startDate, reminder.reminder_time, reminder.repeat_type || 'once', now);
}

function getSystemClockInfo(now = new Date()) {
  return {
    localISO: toLocalISO(now),
    date: localDateStr(now),
    time: localTimeStr(now),
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    offsetMinutes: -now.getTimezoneOffset(),
  };
}

function isSnoozedFire(nextFire, reminderTime, now = new Date()) {
  const parsed = parseLocalDateTime(normalizeNextFire(nextFire));
  if (!parsed || parsed.getTime() <= now.getTime()) return false;
  const [rh, rm] = String(reminderTime || '').split(':').map(Number);
  if (Number.isNaN(rh)) return false;
  if (parsed.getHours() === rh && parsed.getMinutes() === rm) return false;
  // Snooze/re-ring fires are minutes ahead — not hours-off timezone drift.
  const maxCustomFireMs = 4 * 60 * 60 * 1000;
  return (parsed.getTime() - now.getTime()) <= maxCustomFireMs;
}

function parseLocalDateTime(value) {
  if (!value) return null;
  const clean = String(value).trim().replace(' ', 'T');
  const [datePart, timePart = '00:00:00'] = clean.split('T');
  const [y, m, d] = datePart.split('-').map(Number);
  const timeBits = timePart.split(':').map(Number);
  const hh = timeBits[0] || 0;
  const mm = timeBits[1] || 0;
  const ss = timeBits[2] || 0;
  if (!y || !m || !d) return null;
  return new Date(y, m - 1, d, hh, mm, ss, 0);
}

function isDue(nextFire, now = new Date()) {
  const target = parseLocalDateTime(normalizeNextFire(nextFire));
  if (!target) return false;
  return target.getTime() <= now.getTime();
}

function computeNextFire(startDate, time, repeatType = 'once', now = new Date()) {
  if (!startDate || !time) return '';
  const [h, m] = time.split(':').map(Number);
  const [y, mo, d] = startDate.split('-').map(Number);
  let fire = new Date(y, mo - 1, d, h, m || 0, 0, 0);

  if (fire.getTime() <= now.getTime()) {
    switch (repeatType) {
      case 'daily':
        fire.setDate(fire.getDate() + 1);
        break;
      case 'weekly':
        fire.setDate(fire.getDate() + 7);
        break;
      case 'monthly':
        fire.setMonth(fire.getMonth() + 1);
        break;
      default:
        // One-time reminder in the past: ring in 30 seconds so user still gets alerted.
        fire = new Date(now.getTime() + 30000);
        break;
    }
  }

  return toLocalISO(fire);
}

function advanceRecurring(reminder, now = new Date()) {
  const time = reminder.reminder_time || '08:00';
  const [h, m] = time.split(':').map(Number);
  let next = new Date(now);

  switch (reminder.repeat_type) {
    case 'daily':
      next.setDate(next.getDate() + 1);
      next.setHours(h, m || 0, 0, 0);
      break;
    case 'weekly':
      next.setDate(next.getDate() + 7);
      next.setHours(h, m || 0, 0, 0);
      break;
    case 'monthly':
      next.setMonth(next.getMonth() + 1);
      next.setHours(h, m || 0, 0, 0);
      break;
    default:
      return null;
  }

  return toLocalISO(next);
}

function planAfterFire(reminder, now = new Date()) {
  const rings = Number(reminder.alarm_rings || 0) + 1;

  if (reminder.repeat_type && reminder.repeat_type !== 'once') {
    const next = advanceRecurring(reminder, now);
    return {
      nextFire: next,
      alarmRings: 0,
      status: 'active',
    };
  }

  if (rings >= ALARM_MAX_RINGS) {
    return {
      nextFire: reminder.next_fire,
      alarmRings: rings,
      status: 'active',
    };
  }

  const repeatAt = new Date(now.getTime() + ALARM_REPEAT_MINUTES * 60 * 1000);
  return {
    nextFire: toLocalISO(repeatAt),
    alarmRings: rings,
    status: 'active',
  };
}

const SOUND_MAP = {
  friendly: 'loud-chime',
  'air-horn': 'air-horn',
  siren: 'siren',
  'alarm-clock': 'alarm-clock',
  'digital-beep': 'digital-beep',
  buzzer: 'buzzer',
  'emergency-alert': 'emergency-alert',
  doorbell: 'doorbell',
  'loud-chime': 'loud-chime',
  'train-whistle': 'train-whistle',
  foghorn: 'foghorn',
  'old-telephone-ring': 'old-telephone-ring',
};

function resolveSoundId(value) {
  return SOUND_MAP[value] || value || 'loud-chime';
}

module.exports = {
  ALARM_REPEAT_MINUTES,
  ALARM_MAX_RINGS,
  toLocalISO,
  localDateStr,
  localTimeStr,
  localMinuteISO,
  normalizeNextFire,
  parseLocalDateTime,
  isDue,
  computeNextFire,
  computeNextFireFromReminder,
  isSnoozedFire,
  shouldFireNow,
  alreadyFiredThisMinute,
  syncNextFireWithSystemClock,
  getSystemClockInfo,
  advanceRecurring,
  planAfterFire,
  resolveSoundId,
  SOUND_MAP,
};
