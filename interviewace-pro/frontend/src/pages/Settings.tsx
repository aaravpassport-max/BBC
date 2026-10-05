import { useEffect, useState, type FormEvent } from 'react';
import { profileApi, type ProfileData } from '@/api/profile';
import { useAuth } from '@/context/AuthContext';
import { LoadingBlock, ErrorBlock } from '@/components/StateViews';
import type { ApiError } from '@/types';

const EXPERIENCE_LEVELS = ['fresher', '0-2', '2-5', '5-10', '10+'];

export default function Settings() {
  const { refreshUser } = useAuth();
  const [profile, setProfile] = useState<ProfileData | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<ApiError | null>(null);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);

  // Local editable copies
  const [name, setName] = useState('');
  const [experience, setExperience] = useState('fresher');
  const [targetRole, setTargetRole] = useState('');
  const [currentRole, setCurrentRole] = useState('');
  const [languagePref, setLanguagePref] = useState<'english' | 'hinglish'>('english');

  function load() {
    setLoading(true);
    setLoadError(null);
    profileApi
      .get()
      .then((r) => {
        if (r.profile) {
          setProfile(r.profile);
          setName(r.profile.name || '');
          setExperience(r.profile.experience_level || 'fresher');
          setTargetRole(r.profile.target_role || '');
          setCurrentRole(r.profile.current_role || '');
          setLanguagePref(r.profile.language_pref || 'english');
        }
      })
      .catch((err) => setLoadError(err as ApiError))
      .finally(() => setLoading(false));
  }

  useEffect(load, []);

  async function onSave(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setSaveError(null);
    setSaved(false);
    try {
      await profileApi.update({
        name,
        experience_level: experience,
        target_role: targetRole || undefined,
        current_role: currentRole || undefined,
        language_pref: languagePref,
      });
      await refreshUser();
      setSaved(true);
    } catch (err) {
      setSaveError(err as ApiError);
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <div className="ia-page"><LoadingBlock label="Loading your profile…" /></div>;
  if (loadError) return <div className="ia-page"><ErrorBlock error={loadError} onRetry={load} /></div>;

  return (
    <div className="ia-page">
      <h1>Profile settings</h1>
      <form onSubmit={onSave}>
        <div className="ia-field">
          <label htmlFor="name">Full name</label>
          <input id="name" className="ia-input" value={name} onChange={(e) => setName(e.target.value)} required minLength={2} />
        </div>
        <div className="ia-field">
          <label htmlFor="exp">Experience level</label>
          <select id="exp" className="ia-input" value={experience} onChange={(e) => setExperience(e.target.value)}>
            {EXPERIENCE_LEVELS.map((v) => <option key={v} value={v}>{v}</option>)}
          </select>
        </div>
        <div className="ia-field">
          <label htmlFor="target">Target role</label>
          <input id="target" className="ia-input" value={targetRole} onChange={(e) => setTargetRole(e.target.value)} />
        </div>
        <div className="ia-field">
          <label htmlFor="current">Current role</label>
          <input id="current" className="ia-input" value={currentRole} onChange={(e) => setCurrentRole(e.target.value)} />
        </div>
        <div className="ia-field">
          <label htmlFor="lang">Interview language</label>
          <select id="lang" className="ia-input" value={languagePref} onChange={(e) => setLanguagePref(e.target.value as 'english' | 'hinglish')}>
            <option value="english">English</option>
            <option value="hinglish">Hinglish</option>
          </select>
        </div>
        <div className="ia-field">
          <label>Resume</label>
          <p className="ia-state-hint">{profile?.has_resume ? 'A resume is on file.' : 'No resume uploaded yet.'}</p>
        </div>

        {saveError && <p className="ia-field-error" role="alert" style={{ marginBottom: 'var(--ia-space-4)' }}>{saveError.message}</p>}
        {saved && <p style={{ color: 'var(--ia-success)', fontSize: 'var(--ia-text-sm)', marginBottom: 'var(--ia-space-4)' }}>Saved.</p>}

        <button className="ia-btn ia-btn-primary ia-btn-block" type="submit" disabled={saving}>
          {saving ? 'Saving…' : 'Save changes'}
        </button>
      </form>
    </div>
  );
}
