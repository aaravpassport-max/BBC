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

function photoWrapClass(voiceState: VoiceState, submitting: boolean): string {
  if (submitting || voiceState === 'thinking') return 'ia-photo-wrap--thinking';
  if (voiceState === 'speaking') return 'ia-photo-wrap--speaking';
  if (voiceState === 'listening') return 'ia-photo-wrap--listening';
  if (voiceState === 'transcribing') return 'ia-photo-wrap--thinking';
  return '';
}

function statusLabel(voiceState: VoiceState, submitting: boolean, voiceMode: boolean): string {
  if (!voiceMode) return '';
  if (submitting) return 'Thinking…';
  if (voiceState === 'speaking') return 'Speaking';
  if (voiceState === 'listening') return 'Listening…';
  if (voiceState === 'transcribing') return 'Processing your response…';
  if (voiceState === 'thinking') return 'Thinking…';
  return 'Click the mic button below to start';
}

function statusClass(voiceState: VoiceState, submitting: boolean): string {
  if (submitting || voiceState === 'thinking' || voiceState === 'transcribing') return 'ia-photo-status--thinking';
  if (voiceState === 'speaking') return 'ia-photo-status--speaking';
  if (voiceState === 'listening') return 'ia-photo-status--listening';
  return '';
}

function PriyaVideoPanel({
  voiceState,
  submitting,
  voiceMode,
}: {
  voiceState: VoiceState;
  submitting: boolean;
  voiceMode: boolean;
}) {
  const wrapMod = photoWrapClass(voiceState, submitting);
  const label = statusLabel(voiceState, submitting, voiceMode);

  return (
    <div className="ia-live-video-panel">
      <div className={`ia-photo-wrap ${wrapMod}`}>
        <div className="ia-photo-ring" aria-hidden="true" />
        <img
          className="ia-priya-photo ia-photo-img"
          src={config.priyaAvatarUrl}
          alt="Priya, AI HR Recruiter"
        />
        {voiceMode && voiceState === 'speaking' && (
          <div className="ia-photo-eq ia-priya-eq" aria-hidden="true">
            <span /><span /><span /><span /><span />
          </div>
        )}
        {voiceMode && voiceState === 'listening' && (
          <div className="ia-photo-listen-overlay" aria-hidden="true" />
        )}
        {voiceMode && (voiceState === 'transcribing' || submitting || voiceState === 'thinking') && (
          <div className="ia-photo-think-overlay" aria-hidden="true" />
        )}
        <div className="ia-photo-nameplate">
          <span className="ia-photo-name">Priya</span>
          <span className="ia-photo-title">AI HR Recruiter</span>
        </div>
      </div>
      {voiceMode && label && (
        <p className={`ia-photo-status ${statusClass(voiceState, submitting)}`} role="status" aria-live="polite">
          {voiceState === 'speaking' ? 'Priya is speaking…' :
           voiceState === 'listening' ? 'Listening… speak your answer' :
           voiceState === 'transcribing' ? 'Processing your response…' :
           submitting ? 'Priya is thinking…' :
           label}
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
  const dgSocketRef = useRef<WebSocket | null>(null);
  const streamingRef = useRef(false);

  const maxMinutes = state.maxMinutes ?? 60;

  const [started, setStarted] = useState(false);
  const [voiceMode, setVoiceMode] = useState(true);
  const [voiceState, setVoiceState] = useState<VoiceState>('idle');
  const [voiceUnavailable, setVoiceUnavailable] = useState<string | null>(null);
  const [showTextFallback, setShowTextFallback] = useState(false);
  const audioRef = useRef<HTMLAudioElement | null>(null);
  const primedAudioRef = useRef<HTMLAudioElement | null>(null);
  const voiceModeRef = useRef(true);
  useEffect(() => { voiceModeRef.current = voiceMode; }, [voiceMode]);

  const aiTurns = turns.filter((t) => t.role === 'ai');
  const currentAiLine = aiTurns.length ? aiTurns[aiTurns.length - 1].text : '';
  const historyTurns = turns.slice(0, -1);

  useEffect(() => {
    interviewsApi.start(interviewId).catch(() => {});
  }, [interviewId]);

  const speak = useCallback(async (text: string): Promise<void> => {
    if (!voiceModeRef.current) return;
    setVoiceState('speaking');
    let url: string | null = null;
    try {
      const r = await speechApi.synthesize(text, interviewId);
      const blob = await (await fetch(`data:${r.mime};base64,${r.audio_base64}`)).blob();
      url = URL.createObjectURL(blob);
      const audio = primedAudioRef.current ?? new Audio();
      audioRef.current = audio;
      const playUrl = url;
      await new Promise<void>((resolve) => {
        audio.onended = () => resolve();
        audio.onerror = () => resolve();
        audio.src = playUrl;
        audio.currentTime = 0;
        void audio.play().catch(() => {
          setVoiceUnavailable("Your browser blocked Priya's voice from playing automatically — continuing with text. You can still use the mic manually below.");
          setVoiceMode(false);
          resolve();
        });
      });
    } catch (err) {
      const apiErr = err as ApiError;
      if (apiErr?.code === 'ia_cfg') {
        setVoiceMode(false);
        setVoiceUnavailable('Voice is not set up on this site yet — continuing with text. (Missing ElevenLabs/Deepgram API key in admin settings.)');
      } else {
        setVoiceUnavailable("Couldn't play Priya's voice — continuing with text. " + (apiErr?.message || ''));
        setVoiceMode(false);
      }
    } finally {
      if (url) URL.revokeObjectURL(url);
      setVoiceState('idle');
    }
  }, [interviewId]);

  const stopStreaming = useCallback(() => {
    streamingRef.current = false;
    if (dgSocketRef.current) {
      try {
        if (dgSocketRef.current.readyState === WebSocket.OPEN) {
          dgSocketRef.current.send(JSON.stringify({ type: 'CloseStream' }));
        }
        dgSocketRef.current.close();
      } catch { /* ignore */ }
      dgSocketRef.current = null;
    }
    if (mediaRecorderRef.current?.state === 'recording') {
      mediaRecorderRef.current.stop();
    }
    mediaRecorderRef.current = null;
    if (streamRef.current) {
      streamRef.current.getTracks().forEach((t) => t.stop());
      streamRef.current = null;
    }
  }, []);

  const startListening = useCallback(async () => {
    if (!voiceModeRef.current) return;
    setDraft('');
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      streamRef.current = stream;

      // Attempt live streaming transcription via short-lived Deepgram token.
      // Falls back to record-then-transcribe if token/stream setup fails.
      try {
        const tokenRes = await speechApi.streamToken(interviewId);
        const wsUrl = `wss://api.deepgram.com/v1/listen?model=nova-2&smart_format=true&language=en&interim_results=true&punctuate=true`;
        const ws = new WebSocket(wsUrl, ['token', tokenRes.token]);
        dgSocketRef.current = ws;
        streamingRef.current = true;

        await new Promise<void>((resolve, reject) => {
          const timeout = setTimeout(() => reject(new Error('stream timeout')), 8000);
          ws.onopen = () => { clearTimeout(timeout); resolve(); };
          ws.onerror = () => { clearTimeout(timeout); reject(new Error('ws error')); };
        });

        ws.onmessage = (ev) => {
          try {
            const data = JSON.parse(ev.data as string);
            const alt = data?.channel?.alternatives?.[0];
            const text = alt?.transcript as string | undefined;
            if (!text) return;
            if (data.is_final) {
              setDraft((prev) => (prev ? `${prev} ${text}` : text).trim());
            } else {
              setDraft((prev) => {
                const base = prev.replace(/\s*\[…\]$/, '');
                return text ? `${base} ${text} […]`.trim() : prev;
              });
            }
          } catch { /* ignore malformed frames */ }
        };

        const mr = new MediaRecorder(stream, { mimeType: MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : 'audio/webm' });
        chunksRef.current = [];
        mr.ondataavailable = (e) => {
          if (e.data.size > 0 && ws.readyState === WebSocket.OPEN) {
            ws.send(e.data);
          }
          chunksRef.current.push(e.data);
        };
        mr.start(250);
        mediaRecorderRef.current = mr;
        setRecording(true);
        setVoiceState('listening');
        return;
      } catch {
        // Streaming unavailable — secure fallback: record clip, transcribe server-side.
        // See api/class-api-speech.php::stt_stream_token() for why this may fail
        // (e.g. Deepgram project lookup or temp-key minting not configured).
        stopStreaming();
        const mr = new MediaRecorder(stream);
        chunksRef.current = [];
        mr.ondataavailable = (e) => chunksRef.current.push(e.data);
        mr.start();
        mediaRecorderRef.current = mr;
        mr.addEventListener('stop', () => {
          stream.getTracks().forEach((t) => t.stop());
        });
        setRecording(true);
        setVoiceState('listening');
      }
    } catch {
      setVoiceMode(false);
      setVoiceUnavailable('Microphone access denied. Allow it in browser settings and refresh.');
      setVoiceState('idle');
    }
  }, [interviewId, stopStreaming]);

  const stopListeningAndTranscribe = useCallback(async () => {
    const mr = mediaRecorderRef.current;
    if (!mr || mr.state === 'inactive') return;
    setVoiceState('transcribing');
    setTranscribing(true);

    if (streamingRef.current) {
      stopStreaming();
      setRecording(false);
      const transcript = draft.replace(/\s*\[…\]$/, '').trim();
      setTranscribing(false);
      setVoiceState('idle');
      if (transcript) {
        const turnNumber = pendingTurnNumber ?? nextTurnNumber.current;
        setPendingTurnNumber(turnNumber);
        await submitTurnRef.current(transcript, turnNumber);
      } else {
        setError({ code: 'ia_no_speech', message: "Didn't catch that — please try speaking again, or type your answer below.", status: 0, retryable: false });
      }
      return;
    }

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
        setVoiceMode(false);
        setVoiceUnavailable('Voice connection issue — check Deepgram API key in admin settings.');
      } else {
        setError(apiErr);
      }
    } finally {
      setTranscribing(false);
      setVoiceState('idle');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [interviewId, pendingTurnNumber, draft, stopStreaming]);

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
        if (voiceModeRef.current) {
          await speak(r.ai_response);
          if (voiceModeRef.current) void startListening();
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

  useEffect(() => () => stopStreaming(), [stopStreaming]);

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
    stopStreaming();
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
    if (voiceMode && !submitting && voiceState !== 'speaking') {
      void startListening();
    }
  }

  async function toggleRecording() {
    if (recording) {
      await stopListeningAndTranscribe();
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const mr = new MediaRecorder(stream);
      chunksRef.current = [];
      mr.ondataavailable = (e) => chunksRef.current.push(e.data);
      mr.onstop = async () => {
        stream.getTracks().forEach((t) => t.stop());
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
    } catch {
      setError({ code: 'ia_mic', message: 'Microphone access denied. Allow it in browser settings and refresh.', status: 0, retryable: false });
    }
  }

  async function onBeginVoice() {
    const primed = new Audio(SILENT_AUDIO_DATA_URI);
    primedAudioRef.current = primed;
    const primePlay = primed.play();
    setStarted(true);
    try {
      await primePlay;
      primed.pause();
    } catch { /* fall through to text mode */ }
    if (state.openingLine) {
      await speak(state.openingLine);
      if (voiceModeRef.current) void startListening();
    }
  }

  function onBeginTextOnly() {
    setStarted(true);
    setVoiceMode(false);
  }

  if (!interviewId) return <ErrorBlock error={{ code: 'ia_bad_route', message: 'Invalid interview.', status: 400, retryable: false }} />;

  if (!started) {
    return (
      <div className="ia-page ia-before-begin">
        <PriyaVideoPanel voiceState="idle" submitting={false} voiceMode={true} />
        <h1>Before you begin</h1>
        <p style={{ color: 'var(--ia-text-secondary)', marginBottom: 'var(--ia-space-5)' }}>
          Find a quiet spot with a good microphone
        </p>
        <p style={{ color: 'var(--ia-text-secondary)', marginBottom: 'var(--ia-space-5)' }}>
          Priya will greet you, then mic activates
        </p>
        <button className="ia-btn ia-btn-primary ia-btn-block" onClick={() => void onBeginVoice()} style={{ marginBottom: 'var(--ia-space-3)' }}>
          Hear Priya &amp; Start
        </button>
        <p className="ia-state-hint" style={{ marginBottom: 'var(--ia-space-4)' }}>
          Click to activate microphone &amp; begin
        </p>
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
    <div className="ia-page-wide ia-live-shell">
      <header className="ia-topbar">
        <strong>Live interview</strong>
        <div style={{ display: 'flex', gap: 'var(--ia-space-3)', alignItems: 'center' }}>
          <span className="ia-badge ia-badge-info">Up to {maxMinutes} min</span>
          <button className="ia-btn ia-btn-ghost" onClick={() => void onEndEarly()} disabled={ending || complete}>
            {ending ? 'Ending…' : 'End interview'}
          </button>
        </div>
      </header>

      {voiceUnavailable && (
        <div className="ia-state ia-state-error" style={{ margin: 'var(--ia-space-3) 0', padding: 'var(--ia-space-3)' }}>
          <p style={{ margin: 0 }}>{voiceUnavailable}</p>
        </div>
      )}

      <div className="ia-live-main">
        <PriyaVideoPanel voiceState={voiceState} submitting={submitting} voiceMode={voiceMode} />

        {currentAiLine && (
          <div className="ia-live-question">
            <div className="ia-live-question-label">Priya asked</div>
            <p className="ia-live-question-text">{currentAiLine}</p>
          </div>
        )}

        {voiceMode && (
          <div
            className={`ia-live-transcript ${recording || draft ? 'ia-live-transcript--active' : ''}`}
            role="log"
            aria-live="polite"
            aria-label="Your spoken answer"
          >
            {transcriptPlaceholder}
          </div>
        )}

        {voiceMode && !complete && (
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 'var(--ia-space-3)' }}>
            <button
              type="button"
              className={`ia-mic-btn ${recording ? 'ia-mic-btn--recording' : ''}`}
              onClick={() => void onMicClick()}
              disabled={submitting || transcribing || voiceState === 'speaking'}
              aria-label={recording ? 'Done speaking' : 'Start Speaking'}
              title={recording ? 'Done speaking' : 'Start Speaking'}
            >
              {recording ? '■' : '🎤'}
            </button>
            {recording ? (
              <button className="ia-btn ia-btn-danger" onClick={() => void stopListeningAndTranscribe()} disabled={transcribing}>
                {transcribing ? 'Processing…' : 'Done speaking'}
              </button>
            ) : (
              <p className="ia-state-hint">{voiceState === 'idle' ? 'Click the mic button below to start' : 'Start Speaking'}</p>
            )}
          </div>
        )}

        {historyTurns.length > 0 && (
          <div className="ia-live-history" aria-label="Earlier conversation">
            {historyTurns.map((t) => (
              <div key={`${t.turnNumber}-${t.role}`} className={`ia-live-history-item ia-live-history-item--${t.role}`}>
                <div className="ia-live-history-item-label">{t.role === 'ai' ? 'Priya' : 'You'}</div>
                {t.text}
              </div>
            ))}
          </div>
        )}

        {error && (
          <div style={{ width: '100%', maxWidth: 560 }}>
            <ErrorBlock error={error} onRetry={pendingTurnNumber != null ? onRetry : undefined} />
          </div>
        )}

        {!complete && (
          <div className="ia-live-fallback">
            <button
              type="button"
              className="ia-btn ia-btn-ghost"
              style={{ marginBottom: 'var(--ia-space-3)' }}
              onClick={() => setShowTextFallback((v) => !v)}
            >
              {showTextFallback ? 'Hide typed answer' : 'Type answer instead'}
            </button>
            {showTextFallback && (
              <>
                <div className="ia-field">
                  <textarea
                    className="ia-input"
                    rows={3}
                    placeholder="Type your answer…"
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    disabled={submitting}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); onSend(); }
                    }}
                  />
                </div>
                <div style={{ display: 'flex', gap: 'var(--ia-space-3)' }}>
                  <button
                    className={`ia-btn ${recording ? 'ia-btn-danger' : 'ia-btn-secondary'}`}
                    onClick={() => void toggleRecording()}
                    disabled={submitting || transcribing}
                  >
                    {transcribing ? 'Transcribing…' : recording ? '● Stop' : '🎤 Record'}
                  </button>
                  <button className="ia-btn ia-btn-primary" style={{ flex: 1 }} onClick={onSend} disabled={submitting || !draft.trim()}>
                    {submitting ? 'Sending…' : 'Send answer'}
                  </button>
                </div>
              </>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
