import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { interviewsApi } from '@/api/interviews';
import { ErrorBlock } from '@/components/StateViews';
import type { ApiError } from '@/types';

const TYPES: { label: string; roundType: 'technical' | 'hr' | 'behavioral' | 'general' }[] = [
  { label: 'Software Engineer', roundType: 'technical' },
  { label: 'Product Manager', roundType: 'behavioral' },
  { label: 'Data Analyst', roundType: 'technical' },
  { label: 'Sales', roundType: 'behavioral' },
  { label: 'HR / Recruiter', roundType: 'hr' },
  { label: 'Customer Support', roundType: 'behavioral' },
];

/*
 * RESTORED (reported: "it had other screens... you did not build it in
 * the same way" — confirmed by comparing against the user's original
 * pre-rebuild build, which contained these exact company names as
 * literal strings): company-specific interview packs. The backend has
 * supported this the whole time and was never touched — see
 * api/class-api-interviews.php::create() (`company_pack` param, passed
 * straight into IA_Claude::generate_plan() and stored on ia_interviews),
 * and the "Company-specific packs (TCS, Infosys…)" line already
 * advertised in the Pro/Premium plan feature list in
 * admin/class-settings-page.php. Only this screen's UI to actually pick
 * one had been dropped during the rebuild — restoring it here wires the
 * screen back up to backend behavior that already exists and already
 * works, no API or schema change needed.
 */
const COMPANY_PACKS = [
  'TCS', 'Infosys', 'Wipro', 'HCL', 'Accenture', 'Cognizant', 'Deloitte',
  'Amazon India', 'Google India', 'Microsoft India', 'Flipkart', 'Swiggy', 'Zomato',
];

/*
 * RESTORED (second of two gaps found comparing against the user's
 * original build's bundled copy — "Case Study" / "Coding Round" /
 * "HR / Culture" / "Behavioural" with descriptions like "DSA, system
 * design, architecture" and "Business problems, strategy" were literal
 * strings in the original bundle, not present anywhere in the rebuilt
 * frontend): the round type used to be its own explicit, described
 * choice, not silently inferred from whichever job title was picked in
 * the dropdown above. ia_interviews.round_type is a 4-value enum
 * ('technical','hr','behavioral','general') — see includes/class-
 * activator.php — so "Case Study" and "Coding Round" both map onto
 * existing enum values (general and technical respectively) rather than
 * needing a schema change; this only restores the picker and its
 * descriptions, matching what the enum already allows.
 */
const ROUND_OPTIONS: { value: 'technical' | 'hr' | 'behavioral' | 'general'; label: string; desc: string }[] = [
  { value: 'technical', label: 'Coding Round', desc: 'DSA, system design, architecture' },
  { value: 'behavioral', label: 'Behavioural', desc: 'STAR format, leadership, teamwork' },
  { value: 'general', label: 'Case Study', desc: 'Business problems, strategy' },
  { value: 'hr', label: 'HR / Culture', desc: 'Values, motivation, culture fit' },
  { value: 'general', label: 'Mixed', desc: 'Mix of all question types' },
];

export default function InterviewSetup() {
  const nav = useNavigate();
  const [typeIndex, setTypeIndex] = useState(0);
  const [language, setLanguage] = useState<'english' | 'hinglish'>('english');
  const [companyPack, setCompanyPack] = useState('');
  const [roundIndex, setRoundIndex] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  async function onStart() {
    setCreating(true);
    setError(null);
    try {
      const selected = TYPES[typeIndex];
      // If the user picked a round type explicitly, honor it; otherwise
      // fall back to the sensible default implied by the job title, same
      // as before this picker existed.
      const roundType = roundIndex != null ? ROUND_OPTIONS[roundIndex].value : selected.roundType;
      const r = await interviewsApi.create({
        type: selected.label,
        language_mode: language,
        company_pack: companyPack || undefined,
        // round_type feeds the gamification badge checks (hr_specialist/
        // tech_specialist/multi_round) — see api/class-api-interviews.php
        // and includes/class-activator.php for the backend side of this.
        round_type: roundType,
      });
      nav(`/interview/${r.interview_id}`, { state: { openingLine: r.opening_line, plan: r.plan, maxMinutes: r.max_minutes } });
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setCreating(false);
    }
  }

  return (
    <div className="ia-page">
      <h1>Set up your interview</h1>
      <div className="ia-field">
        <label htmlFor="type">Interview type</label>
        <select id="type" className="ia-input" value={typeIndex} onChange={(e) => setTypeIndex(Number(e.target.value))}>
          {TYPES.map((t, i) => <option key={t.label} value={i}>{t.label}</option>)}
        </select>
      </div>
      <div className="ia-field">
        <label htmlFor="lang">Language</label>
        <select id="lang" className="ia-input" value={language} onChange={(e) => setLanguage(e.target.value as 'english' | 'hinglish')}>
          <option value="english">English</option>
          <option value="hinglish">Hinglish</option>
        </select>
      </div>
      <div className="ia-field">
        <label htmlFor="company">Company-specific pack (optional)</label>
        <select id="company" className="ia-input" value={companyPack} onChange={(e) => setCompanyPack(e.target.value)}>
          <option value="">General — no specific company</option>
          {COMPANY_PACKS.map((c) => <option key={c} value={c}>{c}</option>)}
        </select>
        <p className="ia-state-hint" style={{ marginTop: 'var(--ia-space-2)' }}>
          Priya will simulate this company's actual interview style and typical questions.
        </p>
      </div>

      <div className="ia-field" role="radiogroup" aria-label="Round type">
        <h2 style={{ fontSize: 'var(--ia-text-lg)', marginBottom: 'var(--ia-space-3)' }}>Role &amp; Round</h2>
        <label>Round type (optional)</label>
        <div className="ia-round-grid">
          {ROUND_OPTIONS.map((opt, i) => (
            <button
              key={opt.label}
              type="button"
              role="radio"
              aria-checked={roundIndex === i}
              className={`ia-round-card ${roundIndex === i ? 'ia-round-card--on' : ''}`}
              onClick={() => setRoundIndex(roundIndex === i ? null : i)}
            >
              <span className="ia-round-card-title">{opt.label}</span>
              <span className="ia-round-card-desc">{opt.desc}</span>
            </button>
          ))}
        </div>
        <p className="ia-state-hint" style={{ marginTop: 'var(--ia-space-2)' }}>
          Leave unselected to let the interview type above decide automatically.
        </p>
      </div>

      {error && (
        <div style={{ marginBottom: 'var(--ia-space-4)' }}>
          {error.code === 'ia_limit' ? (
            <div className="ia-state ia-state-error" style={{ padding: 'var(--ia-space-4)' }}>
              <p>{error.message}</p>
              {/* ROOT-CAUSE FIX: was a raw <a href="/billing"> — outside the SPA's /app
                  basename (same broken-link class fixed across the backend this pass)
                  and a hard page reload besides. A router Link to the real in-app route
                  keeps the SPA session intact. */}
              <Link to="/billing" className="ia-btn ia-btn-primary">Upgrade plan</Link>
            </div>
          ) : (
            <ErrorBlock error={error} onRetry={onStart} />
          )}
        </div>
      )}

      <button className="ia-btn ia-btn-primary ia-btn-block" onClick={onStart} disabled={creating}>
        {creating ? 'Preparing your interview…' : 'Start interview'}
      </button>
      {creating && (
        <p className="ia-state-hint" style={{ textAlign: 'center', marginTop: 'var(--ia-space-3)' }}>
          Generating your question plan — this can take a few seconds.
        </p>
      )}
    </div>
  );
}
