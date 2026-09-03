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
  const target = parseLocalDateTime(nextFire);
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
  parseLocalDateTime,
  isDue,
  computeNextFire,
  advanceRecurring,
  planAfterFire,
  resolveSoundId,
  SOUND_MAP,
};
