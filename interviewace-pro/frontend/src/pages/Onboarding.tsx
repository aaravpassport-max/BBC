import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { profileApi } from '@/api/profile';
import { useAuth } from '@/context/AuthContext';
import { ErrorBlock } from '@/components/StateViews';
import type { ApiError } from '@/types';

/**
 * ROOT-CAUSE FIX (reported: "onboarding loop / pre-populated options
 * missing / cannot complete or submit"):
 *
 * The previous version of this screen only ever collected `target_role`
 * and `current_role`, both as free-text inputs — it never asked for
 * `experience_level`, `industry`, or `language_pref` at all, even though
 * every one of those fields exists on ia_profiles (see
 * includes/class-activator.php) and is explicitly validated by the
 * backend (api/class-api-profile.php::update() — experience_level must be
 * one of a fixed set, language_pref must be 'english' or 'hinglish').
 * A schema clearly built around a fixed set of choices was being driven
 * entirely by typed text, which is what "the pre-populated options were
 * removed" was describing. Restored as three click-to-select steps below
 * (experience level, target role — now with clickable suggestion chips
 * alongside the text field, industry, and language), each posting values
 * the backend already accepts unchanged — no backend change was needed,
 * only the missing UI.
 *
 * Separately, the "stuck in a loop, can't submit" symptom is a real race:
 * finish() used to call `await refreshUser()` (an async network round
 * trip) and only THEN `nav('/dashboard')`. ProtectedRoute.tsx reads
 * `user.onboarding_complete` on every render to decide whether to bounce
 * a route back to /onboarding. If that redirect to /dashboard rendered
 * before the refreshed `user` had fully propagated through context — a
 * real possibility once refreshUser's own network request is in the mix,
 * not just a state update — ProtectedRoute would see the still-stale
 * `onboarding_complete: false` on that very first render of /dashboard
 * and immediately bounce back to /onboarding, which looks exactly like a
 * loop: the URL flips to /dashboard and instantly flips back. Fixed by
 * updating the local user object SYNCHRONOUSLY (via setUser) the moment
 * the server confirms the save, before navigating at all — so
 * ProtectedRoute's very first read of `user.onboarding_complete` for
 * /dashboard is already true, with no network round trip in between.
 * refreshUser() still runs afterward to reconcile with the server, but
 * navigation no longer waits on it or depends on its timing.
 */

const EXPERIENCE_OPTIONS: { value: string; label: string }[] = [
  { value: 'fresher', label: 'Fresher (0 yrs)' },
  { value: '0-2', label: '0–2 years' },
  { value: '2-5', label: '2–5 years' },
  { value: '5-10', label: '5–10 years' },
  { value: '10+', label: '10+ years' },
];

const ROLE_SUGGESTIONS = [
  'Software Engineer',
  'Product Manager',
  'Data Analyst',
  'Sales Executive',
  'HR Specialist',
  'Customer Support',
];

const INDUSTRY_OPTIONS = [
  'Technology',
  'Finance & Banking',
  'Healthcare',
  'E-commerce & Retail',
  'Education',
  'Manufacturing',
  'Consulting',
  'Other',
];

const STEPS = ['experience', 'role', 'industry', 'resume', 'jd'] as const;
type Step = (typeof STEPS)[number];

export default function Onboarding() {
  const nav = useNavigate();
  const { refreshUser, user, setUser } = useAuth();
  const [step, setStep] = useState<Step>('experience');
  const [experienceLevel, setExperienceLevel] = useState('');
  const [targetRole, setTargetRole] = useState('');
  const [currentRole, setCurrentRole] = useState('');
  const [industry, setIndustry] = useState('');
  const [languagePref, setLanguagePref] = useState<'english' | 'hinglish'>('english');
  const [resumeFile, setResumeFile] = useState<File | null>(null);
  const [jdText, setJdText] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  function goNext() {
    const idx = STEPS.indexOf(step);
    if (idx < STEPS.length - 1) setStep(STEPS[idx + 1]);
    else void finish();
  }

  async function onExperienceSubmit() {
    setError(null);
    if (!experienceLevel) { goNext(); return; }
    setSubmitting(true);
    try {
      await profileApi.update({ experience_level: experienceLevel });
      goNext();
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setSubmitting(false);
    }
  }

  async function onRoleSubmit() {
    setError(null);
    if (!targetRole.trim()) { goNext(); return; }
    setSubmitting(true);
    try {
      await profileApi.update({ target_role: targetRole, current_role: currentRole || undefined });
      goNext();
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setSubmitting(false);
    }
  }

  async function onIndustrySubmit() {
    setError(null);
    if (!industry) { goNext(); return; }
    setSubmitting(true);
    try {
      await profileApi.update({ industry, language_pref: languagePref });
      goNext();
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setSubmitting(false);
    }
  }

  async function onResumeSubmit() {
    setError(null);
    if (!resumeFile) { goNext(); return; }
    setSubmitting(true);
    try {
      await profileApi.uploadResume(resumeFile);
      goNext();
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setSubmitting(false);
    }
  }

  async function onJdSubmit() {
    setError(null);
    if (jdText.trim().length < 50) { await finish(); return; }
    setSubmitting(true);
    try {
      await profileApi.parseJd(jdText);
      await finish();
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setSubmitting(false);
    }
  }

  async function finish() {
    setError(null);
    setSubmitting(true);
    /*
     * ROOT-CAUSE FIX ("submission succeeds, then loops back to
     * onboarding"): this used to ignore whatever profileApi.update()
     * actually returned (and even swallowed a thrown error entirely),
     * then optimistically navigate to /dashboard regardless. If the save
     * had genuinely failed on the server — which api/class-api-profile.php
     * now detects and reports explicitly instead of lying about (see its
     * own root-cause note) — this screen had no way to know, so it sent
     * the user to /dashboard anyway. The optimistic local update made
     * that first render LOOK successful, right up until refreshUser()'s
     * real server check landed a moment later, saw onboarding_complete
     * was still actually false, and bounced the user straight back to
     * /onboarding with no explanation — a save that silently failed,
     * dressed up as a random loop. Now the actual response is checked:
     * only a confirmed onboarding_complete:true from the server allows
     * navigating away. A real failure shows a clear on-screen error and
     * keeps the user right here, with a Continue button they can retry,
     * instead of bouncing them around with no visible cause.
     */
    try {
      const r = await profileApi.update({ onboarding_complete: true });
      if (!r.profile.onboarding_complete) {
        setError({ code: 'ia_not_saved', message: "Your save didn't go through. Please try again.", status: 500, retryable: true });
        setSubmitting(false);
        return;
      }
      if (user) setUser({ ...user, onboarding_complete: true });
      nav('/dashboard', { replace: true });
      void refreshUser();
    } catch (err) {
      setError(err as ApiError);
      setSubmitting(false);
    }
  }

  const stepIndex = STEPS.indexOf(step);

  return (
    <div className="ia-page">
      <div style={{ display: 'flex', gap: 'var(--ia-space-1)', marginBottom: 'var(--ia-space-6)' }} aria-label={`Step ${stepIndex + 1} of ${STEPS.length}`}>
        {STEPS.map((s, i) => (
          <div key={s} style={{ flex: 1, height: 4, borderRadius: 2, background: i <= stepIndex ? 'var(--ia-accent)' : 'var(--ia-border)' }} />
        ))}
      </div>

      {error && <div style={{ marginBottom: 'var(--ia-space-4)' }}><ErrorBlock error={error} /></div>}

      {step === 'experience' && (
        <div>
          <h1>What's your experience level?</h1>
          <p style={{ color: 'var(--ia-text-secondary)', marginBottom: 'var(--ia-space-5)' }}>This helps us pitch questions at the right difficulty.</p>
          <div className="ia-field" role="radiogroup" aria-label="Experience level" style={{ display: 'flex', flexDirection: 'column', gap: 'var(--ia-space-2)' }}>
            {EXPERIENCE_OPTIONS.map((opt) => (
              <button
                key={opt.value}
                type="button"
                role="radio"
                aria-checked={experienceLevel === opt.value}
                className={experienceLevel === opt.value ? 'ia-btn ia-btn-primary ia-btn-block' : 'ia-btn ia-btn-secondary ia-btn-block'}
                style={{ justifyContent: 'flex-start', textAlign: 'left' }}
                onClick={() => setExperienceLevel(opt.value)}
              >
                {opt.label}
              </button>
            ))}
          </div>
          <div style={{ display: 'flex', gap: 'var(--ia-space-3)', marginTop: 'var(--ia-space-5)' }}>
            <button className="ia-btn ia-btn-ghost" onClick={goNext} disabled={submitting}>Skip</button>
            <button className="ia-btn ia-btn-primary" style={{ flex: 1 }} onClick={() => void onExperienceSubmit()} disabled={submitting}>
              {submitting ? 'Saving…' : 'Continue'}
            </button>
          </div>
        </div>
      )}

      {step === 'role' && (
        <div>
          <h1>What role are you targeting?</h1>
          <p style={{ color: 'var(--ia-text-secondary)', marginBottom: 'var(--ia-space-4)' }}>This helps us tailor your interview questions.</p>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--ia-space-2)', marginBottom: 'var(--ia-space-4)' }}>
            {ROLE_SUGGESTIONS.map((r) => (
              <button
                key={r}
                type="button"
                className="ia-badge ia-badge-info"
                style={{ cursor: 'pointer', border: 'none' }}
                onClick={() => setTargetRole(r)}
              >
                {r}
              </button>
            ))}
          </div>
          <div className="ia-field">
            <label htmlFor="target">Target role</label>
            <input id="target" className="ia-input" placeholder="e.g. Senior Backend Engineer" value={targetRole} onChange={(e) => setTargetRole(e.target.value)} />
          </div>
          <div className="ia-field">
            <label htmlFor="current">Current role (optional)</label>
            <input id="current" className="ia-input" value={currentRole} onChange={(e) => setCurrentRole(e.target.value)} />
          </div>
          <div style={{ display: 'flex', gap: 'var(--ia-space-3)' }}>
            <button className="ia-btn ia-btn-ghost" onClick={goNext} disabled={submitting}>Skip</button>
            <button className="ia-btn ia-btn-primary" style={{ flex: 1 }} onClick={() => void onRoleSubmit()} disabled={submitting}>
              {submitting ? 'Saving…' : 'Continue'}
            </button>
          </div>
        </div>
      )}

      {step === 'industry' && (
        <div>
          <h1>Which industry?</h1>
          <p style={{ color: 'var(--ia-text-secondary)', marginBottom: 'var(--ia-space-5)' }}>And which language would you like to practice in?</p>
          <div className="ia-field" role="radiogroup" aria-label="Industry" style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--ia-space-2)' }}>
            {INDUSTRY_OPTIONS.map((opt) => (
              <button
                key={opt}
                type="button"
                role="radio"
                aria-checked={industry === opt}
                className={industry === opt ? 'ia-btn ia-btn-primary' : 'ia-btn ia-btn-secondary'}
                onClick={() => setIndustry(opt)}
              >
                {opt}
              </button>
            ))}
          </div>
          <div className="ia-field" role="radiogroup" aria-label="Language" style={{ display: 'flex', gap: 'var(--ia-space-2)', marginTop: 'var(--ia-space-5)' }}>
            <button
              type="button"
              role="radio"
              aria-checked={languagePref === 'english'}
              className={languagePref === 'english' ? 'ia-btn ia-btn-primary' : 'ia-btn ia-btn-secondary'}
              onClick={() => setLanguagePref('english')}
            >
              English
            </button>
            <button
              type="button"
              role="radio"
              aria-checked={languagePref === 'hinglish'}
              className={languagePref === 'hinglish' ? 'ia-btn ia-btn-primary' : 'ia-btn ia-btn-secondary'}
              onClick={() => setLanguagePref('hinglish')}
            >
              Hinglish
            </button>
          </div>
          <div style={{ display: 'flex', gap: 'var(--ia-space-3)', marginTop: 'var(--ia-space-5)' }}>
            <button className="ia-btn ia-btn-ghost" onClick={goNext} disabled={submitting}>Skip</button>
            <button className="ia-btn ia-btn-primary" style={{ flex: 1 }} onClick={() => void onIndustrySubmit()} disabled={submitting}>
              {submitting ? 'Saving…' : 'Continue'}
            </button>
          </div>
        </div>
      )}

      {step === 'resume' && (
        <div>
          <h1>Upload your resume</h1>
          <p style={{ color: 'var(--ia-text-secondary)', marginBottom: 'var(--ia-space-5)' }}>
            Optional, but helps the AI ask more relevant questions. PDF or DOCX, up to 5MB.
          </p>
          <div className="ia-field">
            <input
              type="file"
              accept=".pdf,.docx"
              className="ia-input"
              onChange={(e) => setResumeFile(e.target.files?.[0] ?? null)}
            />
          </div>
          <div style={{ display: 'flex', gap: 'var(--ia-space-3)' }}>
            <button className="ia-btn ia-btn-ghost" onClick={goNext} disabled={submitting}>Skip</button>
            <button className="ia-btn ia-btn-primary" style={{ flex: 1 }} onClick={() => void onResumeSubmit()} disabled={submitting}>
              {submitting ? 'Uploading…' : 'Continue'}
            </button>
          </div>
        </div>
      )}

      {step === 'jd' && (
        <div>
          <h1>Got a job description?</h1>
          <p style={{ color: 'var(--ia-text-secondary)', marginBottom: 'var(--ia-space-5)' }}>
            Optional — paste one to focus your practice on the exact skills it asks for.
          </p>
          <div className="ia-field">
            <textarea className="ia-input" rows={8} value={jdText} onChange={(e) => setJdText(e.target.value)} />
          </div>
          <div style={{ display: 'flex', gap: 'var(--ia-space-3)' }}>
            <button className="ia-btn ia-btn-ghost" onClick={() => void finish()} disabled={submitting}>Skip</button>
            <button className="ia-btn ia-btn-primary" style={{ flex: 1 }} onClick={() => void onJdSubmit()} disabled={submitting}>
              {submitting ? 'Saving…' : 'Finish'}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
