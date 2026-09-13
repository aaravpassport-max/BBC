// ILRS — Fast capture: NLP parsing & date helpers
(function () {
  const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function dateStr(d) {
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }

  function addDays(d, n) {
    const x = new Date(d);
    x.setDate(x.getDate() + n);
    return x;
  }

  function nextWeekday(from, targetDay /* 0=Sun */) {
    const d = new Date(from);
    const diff = (targetDay - d.getDay() + 7) % 7 || 7;
    d.setDate(d.getDate() + diff);
    return d;
  }

  function parseTimeToken(text) {
    const atMatch = text.match(/\bat\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\b/i);
    if (atMatch) {
      let h = parseInt(atMatch[1], 10);
      const m = parseInt(atMatch[2] || '0', 10);
      const ampm = (atMatch[3] || '').toLowerCase();
      if (ampm === 'pm' && h < 12) h += 12;
      if (ampm === 'am' && h === 12) h = 0;
      return { time: `${pad(h)}:${pad(m)}`, raw: atMatch[0] };
    }
    const noon = text.match(/\b(noon|midday)\b/i);
    if (noon) return { time: '12:00', raw: noon[0] };
    const morning = text.match(/\b(morning|tomorrow morning)\b/i);
    if (morning) return { time: '09:00', raw: morning[0] };
    const evening = text.match(/\b(evening|tonight|this evening)\b/i);
    if (evening) return { time: '18:00', raw: evening[0] };
    return null;
  }

  function parseDateToken(text, now = new Date()) {
    const lower = text.toLowerCase();
    if (/\btoday\b/.test(lower)) return { date: dateStr(now), raw: 'today' };
    if (/\btomorrow\b/.test(lower)) return { date: dateStr(addDays(now, 1)), raw: 'tomorrow' };
    if (/\bnext week\b/.test(lower)) return { date: dateStr(addDays(now, 7)), raw: 'next week' };
    if (/\btonight\b|\bthis evening\b/.test(lower)) return { date: dateStr(now), raw: 'tonight' };

    const weekdays = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    for (let i = 0; i < weekdays.length; i++) {
      const re = new RegExp(`\\b(next\\s+)?${weekdays[i]}\\b`, 'i');
      const m = lower.match(re);
      if (m) {
        const d = m[1] ? nextWeekday(now, i) : (() => {
          const t = new Date(now);
          const diff = (i - t.getDay() + 7) % 7;
          t.setDate(t.getDate() + (diff === 0 ? 0 : diff));
          return t;
        })();
        return { date: dateStr(d), raw: m[0] };
      }
    }

    const inMatch = lower.match(/\bin\s+(\d+)\s*(minute|minutes|min|hour|hours|hr|hrs)\b/);
    if (inMatch) {
      const n = parseInt(inMatch[1], 10);
      const unit = inMatch[2];
      const d = new Date(now);
      if (unit.startsWith('hour') || unit.startsWith('hr')) d.setHours(d.getHours() + n);
      else d.setMinutes(d.getMinutes() + n);
      return { date: dateStr(d), time: `${pad(d.getHours())}:${pad(d.getMinutes())}`, raw: inMatch[0] };
    }

    return null;
  }

  function parseRepeat(text) {
    const lower = text.toLowerCase();
    if (/\b(daily|every day|रोज)\b/.test(lower)) return { repeat: 'daily', raw: 'daily' };
    if (/\b(weekly|every week)\b/.test(lower)) return { repeat: 'weekly', raw: 'weekly' };
    if (/\b(monthly|every month)\b/.test(lower)) return { repeat: 'monthly', raw: 'monthly' };
    return { repeat: 'once', raw: '' };
  }

  function parseCategory(text) {
    const lower = text.toLowerCase();
    if (/medicine|tablet|capsule|pill/.test(lower)) return 'medicine';
    if (/bill|pay|electricity|rent/.test(lower)) return 'bills';
    if (/family|mom|dad|wife|husband/.test(lower)) return 'family';
    if (/work|meeting|office|client/.test(lower)) return 'work';
    return 'general';
  }

  function parsePriority(text) {
    const lower = text.toLowerCase();
    if (/urgent|critical|asap|emergency/.test(lower)) return 'critical';
    if (/important/.test(lower)) return 'important';
    return 'normal';
  }

  function stripToken(title, token) {
    if (!token) return title;
    return title.replace(new RegExp(token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'), ' ').replace(/\s+/g, ' ').trim();
  }

  /**
   * Parse natural-language quick add text.
   * @returns {{ title, startDate, time, repeatType, category, priority, chips: string[] }}
   */
  function parseReminderText(text, now = new Date()) {
    let title = text.trim();
    const chips = [];
    let startDate = dateStr(now);
    let time = '';
    let repeatType = 'once';

    const dateParsed = parseDateToken(title, now);
    if (dateParsed) {
      startDate = dateParsed.date;
      if (dateParsed.time) time = dateParsed.time;
      title = stripToken(title, dateParsed.raw);
      chips.push(dateParsed.raw);
    }

    const timeParsed = parseTimeToken(title);
    if (timeParsed) {
      time = timeParsed.time;
      title = stripToken(title, timeParsed.raw);
      chips.push(timeParsed.raw);
    }

    const repeat = parseRepeat(title);
    repeatType = repeat.repeat;
    if (repeat.raw) {
      title = stripToken(title, repeat.raw);
      chips.push(repeat.raw);
    }

    const category = parseCategory(text);
    const priority = parsePriority(text);

    // Clean leading "at" / filler
    title = title.replace(/^[\s,.-]+|[\s,.-]+$/g, '').trim();

    return { title, startDate, time, repeatType, category, priority, chips };
  }

  function parseNextFireLocal(startDate, time, repeatType, now = new Date()) {
    if (!startDate) startDate = dateStr(now);
    const defaultTime = time || `${pad(now.getHours())}:${pad(now.getMinutes())}`;
    const [h, m] = (time || defaultTime).split(':').map(Number);
    const [y, mo, d] = startDate.split('-').map(Number);
    let fire = new Date(y, mo - 1, d, h, m || 0, 0, 0);
    if (fire.getTime() <= now.getTime()) {
      switch (repeatType) {
        case 'daily': fire.setDate(fire.getDate() + 1); break;
        case 'weekly': fire.setDate(fire.getDate() + 7); break;
        case 'monthly': fire.setMonth(fire.getMonth() + 1); break;
        default: fire = new Date(now.getTime() + 60000); break;
      }
    }
    return `${fire.getFullYear()}-${pad(fire.getMonth() + 1)}-${pad(fire.getDate())}T${pad(fire.getHours())}:${pad(fire.getMinutes())}:${pad(fire.getSeconds())}`;
  }

  function parseLocalDateTime(nextFire) {
    if (!nextFire) return null;
    const clean = String(nextFire).trim().replace(' ', 'T');
    const [datePart, timePart = '00:00:00'] = clean.split('T');
    const [y, m, d] = datePart.split('-').map(Number);
    const [hh, mm, ss] = timePart.split(':').map(Number);
    return new Date(y, m - 1, d, hh || 0, mm || 0, ss || 0, 0);
  }

  function isActiveReminder(r) {
    return r.status === 'active';
  }

  function isDueToday(r, now = new Date()) {
    if (!isActiveReminder(r) || !r.next_fire) return false;
    const d = parseLocalDateTime(r.next_fire);
    if (!d) return false;
    return d.getFullYear() === now.getFullYear()
      && d.getMonth() === now.getMonth()
      && d.getDate() === now.getDate();
  }

  function isDueTomorrow(r, now = new Date()) {
    if (!isActiveReminder(r) || !r.next_fire) return false;
    const t = addDays(now, 1);
    const d = parseLocalDateTime(r.next_fire);
    if (!d) return false;
    return d.getFullYear() === t.getFullYear()
      && d.getMonth() === t.getMonth()
      && d.getDate() === t.getDate();
  }

  function isUpcoming(r, now = new Date()) {
    if (!isActiveReminder(r) || !r.next_fire) return false;
    const d = parseLocalDateTime(r.next_fire);
    if (!d || d.getTime() <= now.getTime()) return false;
    const end = addDays(now, 7);
    end.setHours(23, 59, 59, 999);
    return d.getTime() <= end.getTime() && !isDueToday(r, now) && !isDueTomorrow(r, now);
  }

  function isOverdueItem(r, now = new Date()) {
    if (!isActiveReminder(r) || !r.next_fire) return false;
    const d = parseLocalDateTime(r.next_fire);
    return d && d.getTime() < now.getTime();
  }

  function isPostponedItem(r) {
    return isActiveReminder(r) && (r.workflow_status || 'pending') === 'postponed';
  }

  function relativeTimeLabel(nextFire, now = new Date()) {
    const d = parseLocalDateTime(nextFire);
    if (!d) return '';
    const diffMs = d.getTime() - now.getTime();
    const diffMin = Math.round(diffMs / 60000);
    if (diffMin < -1440) return `Overdue by ${Math.abs(Math.round(diffMin / 1440))} days`;
    if (diffMin < -60) return `Overdue by ${Math.abs(Math.round(diffMin / 60))} hours`;
    if (diffMin < 0) return `Overdue by ${Math.abs(diffMin)} min`;
    if (diffMin < 60) return `in ${diffMin} min`;
    if (diffMin < 1440) return `in ${Math.round(diffMin / 60)} hours`;
    return formatDateShort(nextFire);
  }

  function nextFireDateStr(nextFire) {
    if (!nextFire) return '';
    const clean = String(nextFire).trim().replace(' ', 'T');
    if (clean.includes('Z') || /[+-]\d{2}:\d{2}$/.test(clean)) {
      const d = new Date(clean);
      if (!Number.isNaN(d.getTime())) return dateStr(d);
    }
    return clean.slice(0, 10);
  }

  function isScheduledOnDate(r, dateStr) {
    return isActiveReminder(r) && !!r.next_fire && nextFireDateStr(r.next_fire) === dateStr;
  }

  /** Map an existing reminder/task to a when-chip + preserved start date for edit forms. */
  function resolveWhenFromExisting(reminder, now = new Date()) {
    const start = String(reminder?.start_date || reminder?.next_fire || '').slice(0, 10);
    if (!start || start.length < 10) {
      return { when: 'today', startDate: dateStr(now) };
    }
    const today = dateStr(now);
    const tomorrow = dateStr(addDays(now, 1));
    const nextWeek = dateStr(addDays(now, 7));
    if (start === today) return { when: 'today', startDate: start };
    if (start === tomorrow) return { when: 'tomorrow', startDate: start };
    if (start === nextWeek) return { when: 'next-week', startDate: start };
    return { when: 'custom', startDate: start };
  }

  function isScheduledInMonth(r, monthStr) {
    return isActiveReminder(r) && !!r.next_fire && nextFireDateStr(r.next_fire).startsWith(monthStr);
  }

  function formatDateShort(nextFire) {
    const d = parseLocalDateTime(nextFire);
    if (!d) return '';
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const target = new Date(d);
    target.setHours(0, 0, 0, 0);
    const diff = Math.round((target - today) / 86400000);
    if (diff === 0) return 'Today';
    if (diff === 1) return 'Tomorrow';
    return d.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' });
  }

  window.ILRSCapture = {
    parseReminderText,
    parseNextFireLocal,
    parseLocalDateTime,
    isDueToday,
    isDueTomorrow,
    isUpcoming,
    isOverdueItem,
    isPostponedItem,
    relativeTimeLabel,
    formatDateShort,
    nextFireDateStr,
    isScheduledOnDate,
    isScheduledInMonth,
    dateStr,
    addDays,
    resolveWhenFromExisting,
  };
})();
