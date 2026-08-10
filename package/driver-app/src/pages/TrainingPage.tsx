import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { getTrainingStatus, updateTrainingProgress, submitTrainingQuiz, getErrorMessage, type TrainingStatus } from '../api';
import { Skeleton } from '../components/Skeleton';

export function TrainingPage() {
  const navigate = useNavigate();
  const [status, setStatus] = useState<TrainingStatus | null>(null);
  const [error, setError] = useState('');
  const [answers, setAnswers] = useState<number[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState<{ passed: boolean; scorePct: number } | null>(null);

  const refresh = useCallback(async () => {
    try {
      const s = await getTrainingStatus();
      setStatus(s);
      if (s.quiz_questions) setAnswers(new Array(s.quiz_questions.length).fill(-1));
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load training status.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  useEffect(() => {
    if (status?.status === 'passed') {
      navigate('/home');
    }
  }, [status, navigate]);

  // Real video playback isn't wired up in this reference app — a "watch"
  // button simulates completing the video, standing in for the actual
  // player's timeupdate-driven progress reporting the real API expects
  // (flagged in this app's README under Known gaps).
  async function handleWatchVideo() {
    setError('');
    try {
      setStatus(await updateTrainingProgress(100));
    } catch (err) {
      setError(getErrorMessage(err, 'Could not update your progress.'));
    }
  }

  async function handleSubmitQuiz() {
    if (answers.some((a) => a === -1)) {
      setError('Answer every question before submitting.');
      return;
    }
    setError('');
    setSubmitting(true);
    try {
      const res = await submitTrainingQuiz(answers);
      setResult(res);
      // Refresh regardless of pass/fail — on pass, this updates `status` to
      // 'passed', which is what the redirect useEffect below actually
      // watches. An earlier version only refreshed on failure, leaving the
      // "redirecting…" message on the pass screen permanently true but
      // never actually redirecting, since `status` stayed stale.
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not submit the quiz.'));
    } finally {
      setSubmitting(false);
    }
  }

  if (!status) {
    return (
      <Screen eyebrow="Onboarding" title="Loading your training…">
        {error && <p style={{ color: 'var(--danger)' }}>{error}</p>}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          <Skeleton width="55%" height={14} />
          <Skeleton width="100%" height={60} radius={12} />
        </div>
      </Screen>
    );
  }

  if (status.status === 'locked_for_review') {
    return (
      <Screen eyebrow="Onboarding" title="Training under review">
        <p style={{ color: 'var(--text-muted)', fontSize: 15 }}>
          You've reached the maximum quiz attempts. Our team will review your application manually — please contact
          support for next steps.
        </p>
      </Screen>
    );
  }

  if (status.status === 'not_started' || status.status === 'in_progress') {
    return (
      <Screen eyebrow="Onboarding" title="Platform training">
        <p style={{ color: 'var(--text-muted)', fontSize: 15 }}>
          Watch the safety and platform-policy video before you can take the quiz. ({status.video_watched_pct}%
          watched)
        </p>
        <div
          style={{
            aspectRatio: '16/9',
            background: 'var(--surface)',
            border: '1px solid var(--border)',
            borderRadius: 12,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            color: 'var(--text-muted)',
            fontSize: 13,
          }}
        >
          Training video player
        </div>
        {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
        <Button onClick={handleWatchVideo}>Mark video as watched</Button>
      </Screen>
    );
  }

  if (result) {
    return (
      <Screen eyebrow="Onboarding" title={result.passed ? 'You passed!' : 'Not quite'}>
        <p style={{ color: 'var(--text-muted)', fontSize: 15 }}>Score: {result.scorePct}%</p>
        {result.passed ? (
          <p style={{ color: 'var(--success)', fontSize: 14 }}>Training complete — redirecting…</p>
        ) : (
          <>
            <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>
              You need 80% to pass. You can retake the quiz after a short cooldown.
            </p>
            <Button
              onClick={() => {
                setResult(null);
                void refresh();
              }}
            >
              Back to training
            </Button>
          </>
        )}
      </Screen>
    );
  }

  return (
    <Screen eyebrow="Onboarding" title="Quiz">
      {status.quiz_questions?.map((q, qi) => (
        <div key={qi} style={{ border: '1px solid var(--border)', borderRadius: 12, background: 'var(--surface)', padding: 16 }}>
          <p style={{ fontSize: 14, marginBottom: 10 }}>{q.question}</p>
          {q.options.map((opt, oi) => (
            <label key={oi} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, padding: '6px 0' }}>
              <input
                type="radio"
                name={`q${qi}`}
                checked={answers[qi] === oi}
                onChange={() => {
                  const next = [...answers];
                  next[qi] = oi;
                  setAnswers(next);
                }}
              />
              {opt}
            </label>
          ))}
        </div>
      ))}
      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      <Button onClick={handleSubmitQuiz} loading={submitting}>
        Submit quiz
      </Button>
    </Screen>
  );
}
