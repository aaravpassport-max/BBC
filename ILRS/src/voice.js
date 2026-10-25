// ILRS voice announcements — natural Indian English via Web Speech API
(function () {
  function loadVoices() {
    return window.speechSynthesis ? speechSynthesis.getVoices() : [];
  }

  if (window.speechSynthesis) {
    speechSynthesis.onvoiceschanged = () => loadVoices();
    loadVoices();
  }

  function pickIndianEnglishVoice() {
    const voices = loadVoices();
    const ranked = [
      (v) => v.lang === 'en-IN',
      (v) => /en-in/i.test(v.lang),
      (v) => /india|heera|neerja|ravi|priya|kalpana/i.test(v.name),
      (v) => v.lang.startsWith('en') && v.localService,
      (v) => v.lang.startsWith('en-GB'),
      (v) => v.lang.startsWith('en'),
    ];
    for (const test of ranked) {
      const match = voices.find(test);
      if (match) return match;
    }
    return voices[0] || null;
  }

  function speak(text) {
    if (!text || !window.speechSynthesis) return Promise.resolve(false);

    return new Promise((resolve) => {
      try {
        speechSynthesis.cancel();
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = 'en-IN';
        utterance.rate = 0.94;
        utterance.pitch = 1;
        utterance.volume = 1;

        const voice = pickIndianEnglishVoice();
        if (voice) {
          utterance.voice = voice;
          utterance.lang = voice.lang || 'en-IN';
        }

        utterance.onend = () => resolve(true);
        utterance.onerror = () => resolve(false);
        speechSynthesis.speak(utterance);
      } catch (_) {
        resolve(false);
      }
    });
  }

  function announceReminder(reminder) {
    const style = window.App?.settings?.notification_style || 'sound-popup';
    const itemStyle = reminder?.alert_style || '';
    if (itemStyle === 'silent' || style === 'silent') return Promise.resolve(false);
    if (window.App?.settings?.voice_announcements === '0') return Promise.resolve(false);

    const type = reminder._type || reminder.task_type || 'reminder';
    const text = window.ILRSVoiceText?.build(reminder, type);
    if (!text) return Promise.resolve(false);
    return speak(text);
  }

  window.ILRSVoice = { speak, announceReminder, pickIndianEnglishVoice };
})();
