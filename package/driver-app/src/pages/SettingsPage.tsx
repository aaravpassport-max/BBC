import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { getNotificationPreferences, setNotificationPreference, getErrorMessage, type NotificationPreference } from '../api';

const CATEGORY_CONFIG: Record<string, { label: string; description: string }> = {
  trip_updates: {
    label: 'Trip updates',
    description: 'Job offers, pickup alerts, and trip status changes.',
  },
  promotions: {
    label: 'Promotions & incentives',
    description: 'Bonus missions, referral rewards, and partner offers.',
  },
  account_activity: {
    label: 'Account activity',
    description: 'KYC status, payouts, penalties, and profile changes.',
  },
  product_news: {
    label: 'Product news',
    description: 'New features, policy updates, and platform announcements.',
  },
};

export function SettingsPage() {
  const navigate = useNavigate();
  const [prefs, setPrefs] = useState<NotificationPreference[] | null>(null);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState<string | null>(null);

  useEffect(() => {
    getNotificationPreferences()
      .then(setPrefs)
      .catch((err) => setError(getErrorMessage(err, 'Could not load settings.')));
  }, []);

  const pushPrefs = (prefs || []).filter((p) => p.channel === 'push');
  const grouped = Object.keys(CATEGORY_CONFIG).map((category) => ({
    category,
    ...CATEGORY_CONFIG[category],
    pref: pushPrefs.find((p) => p.category === category),
  }));

  async function toggle(category: string, enabled: boolean) {
    setSaving(category);
    setError('');
    try {
      await setNotificationPreference(category, 'push', enabled);
      setPrefs((prev) =>
        (prev || []).map((p) => (p.category === category && p.channel === 'push' ? { ...p, enabled } : p))
      );
    } catch (err) {
      setError(getErrorMessage(err, 'Could not update preference.'));
    } finally {
      setSaving(null);
    }
  }

  return (
    <Screen eyebrow="Settings" title="Notification settings" onBack={() => navigate('/profile')}>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, marginTop: 0 }}>
        Choose which push notifications you receive. OTP and safety alerts cannot be turned off.
      </p>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}

      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        {grouped.map((g) => (
          <label
            key={g.category}
            style={{
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              gap: 12,
              border: '1px solid var(--border)',
              borderRadius: 12,
              background: 'var(--surface)',
              padding: '14px 16px',
              cursor: 'pointer',
            }}
          >
            <div>
              <div style={{ fontSize: 14, fontWeight: 600 }}>{g.label}</div>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4, lineHeight: 1.4 }}>{g.description}</div>
            </div>
            <input
              type="checkbox"
              checked={g.pref?.enabled ?? true}
              disabled={saving === g.category}
              onChange={(e) => void toggle(g.category, e.target.checked)}
            />
          </label>
        ))}
      </div>
    </Screen>
  );
}
