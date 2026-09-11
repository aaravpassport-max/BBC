import { useCallback, useEffect, useRef, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { interviewsApi } from '@/api/interviews';
import { speechApi } from '@/api/speech';
import { config } from '@/api/client';
import { ErrorBlock } from '@/components/StateViews';
import type { ApiError, InterviewQuestion } from '@/types';

interface ChatTurn { turnNumber: number; role: 'ai' | 'user'; text: string }
interface LocationState { openingLine?: string; plan?: InterviewQuestion[]; maxMinutes?: number }

type VoiceState = 'idle' | 'speaking' | 'listening' | 'transcribing' | 'thinking';

const SILENT_AUDIO_DATA_URI = 'data:audio/wav;base64,UklGRiwAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQgAAAAAAAAAAAAAAA==';

function base64ToBlob(base64: string, mime: string): Blob {
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  return new Blob([bytes], { type: mime });
}

function micErrorMessage(err: unknown): string {
  const e = err as DOMException;
  if (e?.name === 'NotAllowedError' || e?.name === 'PermissionDeniedError') {
    return 'Microphone access denied. Allow it in your browser settings, then tap the mic button below.';
  }
  if (e?.name === 'NotFoundError' || e?.name === 'DevicesNotFoundError') {
    return 'No microphone detected. Connect a microphone or use "Type answer instead" below.';
  }
  if (!window.isSecureContext) {
    return 'Microphone requires HTTPS. Open this site with https:// and try again.';
  }
  if (!navigator.mediaDevices?.getUserMedia) {
    return 'Microphone is not available in this browser.';
  }
  const detail = e?.message ? ` (${e.message})` : '';
  return `Could not start the microphone${detail}. Tap the mic button below to try again.`;
}

function photoWrapClass(voiceState: VoiceState, submitting: boolean): string {
  if (submitting || voiceState === 'thinking') return 'ia-photo-wrap--thinking';
  if (voiceState === 'speaking') return 'ia-photo-wrap--speaking';
  if (voiceState === 'listening') return 'ia-photo-wrap--listening';
  if (voiceState === 'transcribing') return 'ia-photo-wrap--thinking';
  return '';
}

function PriyaVideoPanel({
  voiceState,
  submitting,
  showVoiceUi,
}: {
  voiceState: VoiceState;
  submitting: boolean;
  showVoiceUi: boolean;
}) {
  const wrapMod = photoWrapClass(voiceState, submitting);

  return (
    <div className="ia-live-video-panel">
      <div className={`ia-photo-wrap ${wrapMod}`}>
        <div className="ia-photo-ring" aria-hidden="true" />
        <img
          className="ia-priya-photo ia-photo-img"
          src={config.priyaAvatarUrl}
          alt="Priya, AI HR Recruiter"
        />
        {showVoiceUi && voiceState === 'speaking' && (
          <div className="ia-photo-eq ia-priya-eq" aria-hidden="true">
            <span /><span /><span /><span /><span />
          </div>
        )}
        {showVoiceUi && voiceState === 'listening' && (
          <div className="ia-photo-listen-overlay" aria-hidden="true" />
        )}
        {showVoiceUi && (voiceState === 'transcribing' || submitting || voiceState === 'thinking') && (
          <div className="ia-photo-think-overlay" aria-hidden="true" />
        )}
        <div className="ia-photo-nameplate">
          <span className="ia-photo-name">Priya</span>
          <span className="ia-photo-title">AI HR Recruiter</span>
        </div>
      </div>
      {showVoiceUi && (
        <p className={`ia-photo-status ia-photo-status--${voiceState === 'speaking' ? 'speaking' : voiceState === 'listening' ? 'listening' : 'thinking'}`} role="status" aria-live="polite">
          {voiceState === 'speaking' ? 'Priya is speaking…' :
           voiceState === 'listening' ? 'Listening… speak your answer' :
           voiceState === 'transcribing' ? 'Processing your response…' :
           submitting ? 'Priya is thinking…' :
           voiceState === 'idle' ? 'Click the mic button below to start' : ''}
        </p>
      )}
    </div>
  );
}

export default function LiveInterview() {
  const { id } = useParams<{ id: string }>();
  const interviewId = Number(id);
  const location = useLocation();
  const nav = useNavigate();
  const state = (location.state as LocationState) || {};

  const [turns, setTurns] = useState<ChatTurn[]>(
    state.openingLine ? [{ turnNumber: 0, role: 'ai', text: state.openingLine }] : []
  );
  const [draft, setDraft] = useState('');
  const [pendingTurnNumber, setPendingTurnNumber] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [ending, setEnding] = useState(false);
  const [complete, setComplete] = useState(false);
  const [recording, setRecording] = useState(false);
  const [transcribing, setTranscribing] = useState(false);
  const nextTurnNumber = useRef(1);
  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const chunksRef = useRef<Blob[]>([]);
  const streamRef = useRef<MediaStream | null>(null);

  const maxMinutes = state.maxMinutes ?? 60;

  const [started, setStarted] = useState(false);
  const [textOnly, setTextOnly] = useState(false);
  const [ttsEnabled, setTtsEnabled] = useState(true);
  const [voiceState, setVoiceState] = useState<VoiceState>('idle');
  const [ttsNotice, setTtsNotice] = useState<string | null>(null);
  const [micNotice, setMicNotice] = useState<string | null>(null);
  const [showTextFallback, setShowTextFallback] = useState(false);

  const audioRef = useRef<HTMLAudioElement | null>(null);
  const primedAudioRef = useRef<HTMLAudioElement | null>(null);
  const textOnlyRef = useRef(false);
  const ttsEnabledRef = useRef(true);

  useEffect(() => { textOnlyRef.current = textOnly; }, [textOnly]);
  useEffect(() => { ttsEnabledRef.current = ttsEnabled; }, [ttsEnabled]);

  const aiTurns = turns.filter((t) => t.role === 'ai');
  const currentAiLine = aiTurns.length ? aiTurns[aiTurns.length - 1].text : '';
  const showVoiceUi = !textOnly && ttsEnabled;

  useEffect(() => {
    interviewsApi.start(interviewId).catch(() => {});
  }, [interviewId]);

  const releaseMicStream = useCallback(() => {
    if (mediaRecorderRef.current?.state === 'recording') {
      mediaRecorderRef.current.stop();
    }
    mediaRecorderRef.current = null;
    if (streamRef.current) {
      streamRef.current.getTracks().forEach((t) => t.stop());
      streamRef.current = null;
    }
  }, []);

  const getAudioElement = useCallback((): HTMLAudioElement => {
    if (!primedAudioRef.current) {
      const el = document.createElement('audio');
      el.setAttribute('playsinline', 'true');
      el.setAttribute('webkit-playsinline', 'true');
      el.preload = 'auto';
      el.style.display = 'none';
      document.body.appendChild(el);
      primedAudioRef.current = el;
    }
    return primedAudioRef.current;
  }, []);

  /**
   * Plays Priya's voice. Intentionally independent of microphone state —
   * a mic failure must never prevent TTS from running.
   */
  const speak = useCallback(async (text: string): Promise<void> => {
    if (textOnlyRef.current || !ttsEnabledRef.current || !text.trim()) return;

    releaseMicStream();
    setVoiceState('speaking');
    let url: string | null = null;

    try {
      const r = await speechApi.synthesize(text, interviewId);
      const blob = base64ToBlob(r.audio_base64, r.mime);
      url = URL.createObjectURL(blob);
      const audio = getAudioElement();
      audioRef.current = audio;
      audio.volume = 1;
      audio.muted = false;

      await new Promise<void>((resolve, reject) => {
        const onEnd = () => { cleanup(); resolve(); };
        const onErr = () => { cleanup(); reject(new Error('audio playback failed')); };
        const cleanup = () => {
          audio.removeEventListener('ended', onEnd);
          audio.removeEventListener('error', onErr);
        };
        audio.addEventListener('ended', onEnd);
        audio.addEventListener('error', onErr);
        audio.src = url!;
        audio.currentTime = 0;
        void audio.play().catch(reject);
      });
    } catch (err) {
      const apiErr = err as ApiError;
      if (apiErr?.code === 'ia_cfg') {
        setTtsEnabled(false);
        setTtsNotice('Voice is not set up on this site yet — continuing with text. (Missing ElevenLabs API key in admin settings.)');
      } else {
        setTtsNotice("Couldn't play Priya's voice — you can still read her questions and type your answers. " + (apiErr?.message || ''));
      }
    } finally {
      if (url) URL.revokeObjectURL(url);
      setVoiceState('idle');
    }
  }, [interviewId, getAudioElement, releaseMicStream]);

  const acquireMicStream = useCallback(async (): Promise<MediaStream> => {
    if (streamRef.current?.getAudioTracks().some((t) => t.readyState === 'live')) {
      return streamRef.current;
    }
    if (!window.isSecureContext) {
      throw new DOMException('Microphone requires HTTPS', 'SecurityError');
    }
    const gum = navigator.mediaDevices?.getUserMedia?.bind(navigator.mediaDevices);
    if (!gum) {
      throw new DOMException('Microphone API unavailable', 'NotSupportedError');
    }
    try {
      const stream = await gum({ audio: true });
      streamRef.current = stream;
      return stream;
    } catch (firstErr) {
      try {
        const stream = await gum({
          audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
        });
        streamRef.current = stream;
        return stream;
      } catch {
        throw firstErr;
      }
    }
  }, []);

  const startListening = useCallback(async () => {
    if (textOnlyRef.current) return;
    setDraft('');
    setError(null);
    setMicNotice(null);

    try {
      const stream = await acquireMicStream();
      const mr = new MediaRecorder(stream);
      chunksRef.current = [];
      mr.ondataavailable = (e) => chunksRef.current.push(e.data);
      mr.onstop = () => {
        stream.getTracks().forEach((t) => t.stop());
        streamRef.current = null;
      };
      mr.start();
      mediaRecorderRef.current = mr;
      setRecording(true);
      setVoiceState('listening');
    } catch (err) {
      setMicNotice(micErrorMessage(err));
      setVoiceState('idle');
    }
  }, [acquireMicStream]);

  const stopListeningAndTranscribe = useCallback(async () => {
    const mr = mediaRecorderRef.current;
    if (!mr || mr.state === 'inactive') return;
    setVoiceState('transcribing');
    setTranscribing(true);

    await new Promise<void>((resolve) => {
      mr.addEventListener('stop', () => resolve(), { once: true });
      mr.stop();
    });
    setRecording(false);

    try {
      const blob = new Blob(chunksRef.current, { type: 'audio/webm' });
      const r = await speechApi.transcribe(blob, interviewId);
      if (r.transcript.trim()) {
        setDraft(r.transcript);
        const turnNumber = pendingTurnNumber ?? nextTurnNumber.current;
        setPendingTurnNumber(turnNumber);
        await submitTurnRef.current(r.transcript, turnNumber);
      } else {
        setError({ code: 'ia_no_speech', message: "Didn't catch that — please try speaking again, or type your answer below.", status: 0, retryable: false });
      }
    } catch (err) {
      const apiErr = err as ApiError;
      if (apiErr?.code === 'ia_cfg') {
        setMicNotice('Voice connection issue — check Deepgram API key in admin settings.');
      } else {
        setError(apiErr);
      }
    } finally {
      setTranscribing(false);
      setVoiceState('idle');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [interviewId, pendingTurnNumber]);

  const submitTurn = useCallback(
    async (transcript: string, turnNumber: number) => {
      setSubmitting(true);
      setVoiceState('thinking');
      setError(null);
      try {
        const r = await interviewsApi.submitTurn(interviewId, { transcript, turn_number: turnNumber });
        setTurns((prev) => [
          ...prev,
          { turnNumber, role: 'user', text: transcript },
          { turnNumber: turnNumber + 1, role: 'ai', text: r.ai_response },
        ]);
        setPendingTurnNumber(null);
        setDraft('');
        nextTurnNumber.current = turnNumber + 2;
        if (r.is_complete) {
          setComplete(true);
          void interviewsApi.reportCost(interviewId, {});
          if (r.report_id) {
            nav(`/report/${r.report_id}`, { replace: true });
          }
          return;
        }
        if (!textOnlyRef.current) {
          await speak(r.ai_response);
          if (!textOnlyRef.current) void startListening();
        }
      } catch (err) {
        setError(err as ApiError);
      } finally {
        setSubmitting(false);
        setVoiceState('idle');
      }
    },
    [interviewId, nav, speak, startListening]
  );

  const submitTurnRef = useRef(submitTurn);
  useEffect(() => { submitTurnRef.current = submitTurn; }, [submitTurn]);
  useEffect(() => () => releaseMicStream(), [releaseMicStream]);

  function onSend() {
    const text = draft.trim();
    if (!text || submitting) return;
    const turnNumber = pendingTurnNumber ?? nextTurnNumber.current;
    setPendingTurnNumber(turnNumber);
    void submitTurn(text, turnNumber);
  }

  function onRetry() {
    if (pendingTurnNumber == null) return;
    void submitTurn(draft.trim(), pendingTurnNumber);
  }

  async function onEndEarly() {
    if (!window.confirm('End this interview now? You can still view your report for what you’ve completed so far.')) return;
    audioRef.current?.pause();
    releaseMicStream();
    setEnding(true);
    try {
      const r = await interviewsApi.end(interviewId);
      nav(`/report/${r.report_id}`, { replace: true });
    } catch (err) {
      setError(err as ApiError);
      setEnding(false);
    }
  }

  async function onMicClick() {
    if (recording) {
      await stopListeningAndTranscribe();
      return;
    }
    if (!textOnly && !submitting && voiceState !== 'speaking') {
      setMicNotice(null);
      setError(null);
      void startListening();
    }
  }

  async function toggleRecording() {
    if (recording) {
      await stopListeningAndTranscribe();
      return;
    }
    try {
      const stream = await acquireMicStream();
      const mr = new MediaRecorder(stream);
      chunksRef.current = [];
      mr.ondataavailable = (e) => chunksRef.current.push(e.data);
      mr.onstop = async () => {
        stream.getTracks().forEach((t) => t.stop());
        streamRef.current = null;
        const blob = new Blob(chunksRef.current, { type: 'audio/webm' });
        setTranscribing(true);
        try {
          const r = await speechApi.transcribe(blob, interviewId);
          setDraft((prev) => (prev ? `${prev} ${r.transcript}` : r.transcript));
        } catch (err) {
          setError(err as ApiError);
        } finally {
          setTranscribing(false);
        }
      };
      mr.start();
      mediaRecorderRef.current = mr;
      setRecording(true);
      setVoiceState('listening');
    } catch (err) {
      setError({ code: 'ia_mic', message: micErrorMessage(err), status: 0, retryable: false });
    }
  }

  /**
   * Proven flow from the original working build:
   * 1) prime audio synchronously inside the click (autoplay policy)
   * 2) transition to live screen
   * 3) play Priya's opening line (mic stays OFF)
   * 4) only then arm the microphone
   */
  async function onBeginVoice() {
    const audio = getAudioElement();
    primedAudioRef.current = audio;
    audio.src = SILENT_AUDIO_DATA_URI;
    const primePlay = audio.play();

    setStarted(true);
    setTextOnly(false);
    setTtsNotice(null);
    setMicNotice(null);

    try {
      await primePlay;
      audio.pause();
      audio.currentTime = 0;
    } catch {
      setTtsNotice("Your browser blocked audio autoplay — Priya's voice may not play. You can still read her questions below.");
    }

    if (state.openingLine) {
      await speak(state.openingLine);
      if (!textOnlyRef.current) void startListening();
    }
  }

  function onBeginTextOnly() {
    setStarted(true);
    setTextOnly(true);
  }

  if (!interviewId) return <ErrorBlock error={{ code: 'ia_bad_route', message: 'Invalid interview.', status: 400, retryable: false }} />;

  if (!started) {
    return (
      <div className="ia-live-screen ia-live-screen--gate">
        <PriyaVideoPanel voiceState="idle" submitting={false} showVoiceUi={true} />
        <h1 className="ia-live-fit-title">Before you begin</h1>
        <p className="ia-live-fit-copy">Find a quiet spot with a good microphone. Priya will greet you, then the mic activates.</p>
        <button className="ia-btn ia-btn-primary ia-btn-block" onClick={() => void onBeginVoice()}>
          Hear Priya &amp; Start
        </button>
        <p className="ia-state-hint">Click to activate microphone &amp; begin</p>
        <button className="ia-btn ia-btn-ghost ia-btn-block" onClick={onBeginTextOnly}>
          Skip voice — type my answers instead
        </button>
      </div>
    );
  }

  const transcriptPlaceholder = recording
    ? 'Speak now — your words appear here as you talk…'
    : draft
      ? draft
      : 'Your answer will appear here when you start speaking.';

  return (
    <div className="ia-live-screen">
      <header className="ia-live-topbar">
        <strong>Live interview</strong>
        <div className="ia-live-topbar-actions">
          <span className="ia-badge ia-badge-info">{maxMinutes} min</span>
          <button className="ia-btn ia-btn-ghost ia-btn-compact" onClick={() => void onEndEarly()} disabled={ending || complete}>
            {ending ? 'Ending…' : 'End'}
          </button>
        </div>
      </header>

      {(ttsNotice || micNotice) && (
        <div className="ia-live-notice" role="status">
          {ttsNotice && <p>{ttsNotice}</p>}
          {micNotice && <p>{micNotice}</p>}
        </div>
      )}

      <div className="ia-live-body">
        <PriyaVideoPanel voiceState={voiceState} submitting={submitting} showVoiceUi={showVoiceUi} />

        {currentAiLine && (
          <div className="ia-live-question">
            <div className="ia-live-question-label">Priya asked</div>
            <p className="ia-live-question-text">{currentAiLine}</p>
          </div>
        )}

        {!textOnly && (
          <div className={`ia-live-transcript ${recording || draft ? 'ia-live-transcript--active' : ''}`} role="log" aria-live="polite">
            {transcriptPlaceholder}
          </div>
        )}

        {!textOnly && !complete && (
          <div className="ia-live-mic-row">
            <button
              type="button"
              className={`ia-mic-btn ${recording ? 'ia-mic-btn--recording' : ''}`}
              onClick={() => void onMicClick()}
              disabled={submitting || transcribing || voiceState === 'speaking'}
              aria-label={recording ? 'Done speaking' : 'Start Speaking'}
            >
              {recording ? '■' : '🎤'}
            </button>
            {recording ? (
              <button className="ia-btn ia-btn-danger ia-btn-compact" onClick={() => void stopListeningAndTranscribe()} disabled={transcribing}>
                {transcribing ? 'Processing…' : 'Done speaking'}
              </button>
            ) : (
              <p className="ia-state-hint ia-live-mic-hint">
                {micNotice ? 'Tap the mic button to try again' : 'Start Speaking'}
              </p>
            )}
          </div>
        )}

        {error && (
          <div className="ia-live-error">
            <ErrorBlock error={error} onRetry={pendingTurnNumber != null ? onRetry : undefined} />
          </div>
        )}
      </div>

      {!complete && (
        <div className="ia-live-footer">
          {textOnly && (
            <div className="ia-field ia-live-type-field">
              <textarea
                className="ia-input"
                rows={2}
                placeholder="Type your answer…"
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                disabled={submitting}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); onSend(); }
                }}
              />
              <button className="ia-btn ia-btn-primary ia-btn-block" onClick={onSend} disabled={submitting || !draft.trim()}>
                {submitting ? 'Sending…' : 'Send answer'}
              </button>
            </div>
          )}
          {!textOnly && (
            <button type="button" className="ia-btn ia-btn-ghost ia-btn-compact" onClick={() => setShowTextFallback((v) => !v)}>
              {showTextFallback ? 'Hide typed answer' : 'Type answer instead'}
            </button>
          )}
          {!textOnly && showTextFallback && (
            <div className="ia-field ia-live-type-field">
              <textarea
                className="ia-input"
                rows={2}
                placeholder="Type your answer…"
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                disabled={submitting}
              />
              <div className="ia-live-type-actions">
                <button className="ia-btn ia-btn-secondary ia-btn-compact" onClick={() => void toggleRecording()} disabled={submitting || transcribing}>
                  {transcribing ? '…' : recording ? 'Stop' : 'Record'}
                </button>
                <button className="ia-btn ia-btn-primary ia-btn-compact" style={{ flex: 1 }} onClick={onSend} disabled={submitting || !draft.trim()}>
                  Send
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
