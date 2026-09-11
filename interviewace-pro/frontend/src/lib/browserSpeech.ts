/**
 * Browser-native speech fallbacks documented in README.md:
 * - No ElevenLabs → Web Speech Synthesis (Priya speaks via browser voice)
 * - No Deepgram → Web Speech Recognition (mic transcript via browser)
 */

function getSpeechRecognitionCtor(): (new () => SpeechRecognition) | null {
  const w = window as Window & {
    SpeechRecognition?: new () => SpeechRecognition;
    webkitSpeechRecognition?: new () => SpeechRecognition;
  };
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

async function loadVoices(): Promise<SpeechSynthesisVoice[]> {
  const existing = speechSynthesis.getVoices();
  if (existing.length > 0) return existing;
  return new Promise((resolve) => {
    const done = () => resolve(speechSynthesis.getVoices());
    speechSynthesis.onvoiceschanged = () => {
      speechSynthesis.onvoiceschanged = null;
      done();
    };
    setTimeout(done, 300);
  });
}

function pickVoice(voices: SpeechSynthesisVoice[]): SpeechSynthesisVoice | undefined {
  const en = voices.filter((v) => /en/i.test(v.lang));
  return (
    en.find((v) => /female|zira|samantha|priya|neural|natural/i.test(v.name)) ??
    en.find((v) => v.localService) ??
    en[0] ??
    voices[0]
  );
}

/** Speak text using the browser's built-in TTS (no ElevenLabs required). */
export function speakWithBrowserTts(text: string): Promise<void> {
  return new Promise(async (resolve, reject) => {
    if (!window.speechSynthesis) {
      reject(new Error('Browser speech synthesis is not supported'));
      return;
    }
    window.speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(text);
    try {
      const voices = await loadVoices();
      const voice = pickVoice(voices);
      if (voice) utterance.voice = voice;
    } catch {
      /* voices optional */
    }
    utterance.rate = 1;
    utterance.pitch = 1;
    utterance.onend = () => resolve();
    utterance.onerror = () => reject(new Error('Browser speech synthesis failed'));
    window.speechSynthesis.speak(utterance);
  });
}

export function stopBrowserTts(): void {
  window.speechSynthesis?.cancel();
}

export interface BrowserRecognitionHandle {
  stop: () => void;
}

/** Start browser speech recognition; calls onTranscript with interim/final text. */
export function startBrowserRecognition(
  onTranscript: (text: string, isFinal: boolean) => void,
  onError?: (message: string) => void,
): BrowserRecognitionHandle | null {
  const Ctor = getSpeechRecognitionCtor();
  if (!Ctor) return null;

  const rec = new Ctor();
  rec.continuous = true;
  rec.interimResults = true;
  rec.lang = 'en-IN';

  rec.onresult = (event: SpeechRecognitionEvent) => {
    let interim = '';
    let final = '';
    for (let i = event.resultIndex; i < event.results.length; i++) {
      const chunk = event.results[i][0]?.transcript ?? '';
      if (event.results[i].isFinal) final += chunk;
      else interim += chunk;
    }
    if (final.trim()) onTranscript(final.trim(), true);
    else if (interim.trim()) onTranscript(interim.trim(), false);
  };

  rec.onerror = () => {
    onError?.('Browser speech recognition failed — try typing your answer instead.');
  };

  try {
    rec.start();
  } catch {
    return null;
  }

  return { stop: () => { try { rec.stop(); } catch { /* ignore */ } } };
}

export function browserRecognitionSupported(): boolean {
  return getSpeechRecognitionCtor() !== null;
}

export function browserTtsSupported(): boolean {
  return typeof window !== 'undefined' && !!window.speechSynthesis;
}
