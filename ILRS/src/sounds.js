// ILRS notification sound player (renderer)
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

let currentAudio = null;

function resolveSoundId(settingValue) {
  return SOUND_MAP[settingValue] || settingValue || 'loud-chime';
}

function playAlertSound(soundId, { repeat = 1 } = {}) {
  const file = resolveSoundId(soundId);
  const style = window.App?.settings?.notification_style || 'sound-popup';
  if (style === 'silent' || style === 'popup-only') return;

  try {
    if (currentAudio) {
      currentAudio.pause();
      currentAudio = null;
    }
    const audio = new Audio(`../assets/sounds/${file}.wav`);
    audio.volume = 1.0;
    let plays = 0;
    audio.addEventListener('ended', () => {
      plays += 1;
      if (plays < repeat) {
        audio.currentTime = 0;
        audio.play().catch(() => {});
      }
    });
    currentAudio = audio;
    audio.play().catch((err) => console.warn('Sound play failed:', err.message));
  } catch (err) {
    console.warn('Sound error:', err.message);
  }
}

function previewSound(soundId) {
  playAlertSound(soundId, { repeat: 1 });
}

window.ILRSSounds = { playAlertSound, previewSound, resolveSoundId, SOUND_MAP };
