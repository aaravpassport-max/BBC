// Browser copy of reminder-voice.js (keep in sync)
(function () {
  function clean(text) {
    if (!text) return '';
    return String(text).replace(/\s+/g, ' ').replace(/[""]/g, '"').trim();
  }

  function build(item, type = 'reminder') {
    if (!item) return '';
    const isPrivate = Number(item.is_private) === 1;
    if (isPrivate) {
      return 'This is a private reminder for you. Please open ILRS and check when you get a moment.';
    }
    const title = clean(item.title || item.name || 'your reminder');
    const why = clean(item.why_it_matters || item.notes || '');
    const priority = item.priority || 'normal';

    if (type === 'medicine') {
      const name = clean(item.name || title);
      let line = `Time to take your medicine, ${name}.`;
      if (item.food_timing === 'before') line += ' Please have it before food.';
      else if (item.food_timing === 'after') line += ' Please have it after food.';
      else if (item.food_timing === 'with') line += ' Please have it with food.';
      if (why) line += ` ${why}`;
      return line;
    }
    if (type === 'bill') {
      const billName = clean(item.name || title);
      let line = `Reminder about your ${billName} bill.`;
      if (item.amount) line += ` The amount is ${item.amount} rupees.`;
      line += ' Please pay it on time so you do not get any late fee.';
      if (why) line += ` ${why}`;
      return line;
    }
    if (item.task_type === 'habit' || type === 'habit') {
      return why
        ? `Time for your habit, ${title}. ${why}. Keep it going.`
        : `Time for your habit, ${title}. A small step every day makes a big difference.`;
    }
    if (priority === 'critical') {
      if (why) return `Important reminder. ${title}. ${why}. Please take care of this right away.`;
      return `Important reminder. ${title}. This is urgent, so please do not delay.`;
    }
    if (priority === 'important') {
      if (why) return `Reminder for you. ${title}. ${why}. Please do not forget.`;
      return `Reminder for you. ${title}. Please do not forget.`;
    }
    if (why) return `Just a reminder. ${title}. ${why}.`;
    return `Just a reminder. ${title}. Time to take care of this.`;
  }

  function display(item, type = 'reminder') {
    const spoken = build(item, type);
    return spoken || clean(item.why_it_matters) || clean(item.title) || 'Time for your reminder.';
  }

  window.ILRSVoiceText = { build, display };
})();
