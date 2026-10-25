import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { getNotificationPreferences, setNotificationPreference, getErrorMessage, type NotificationPreference } from '../api';
import { BRAND } from '../constants/brand';

const CATEGORY_LABELS: Record<string, string> = {
  trip_updates: 'Trip updates',
  promotions: 'Promotions',
  account_activity: 'Account activity',
  product_news: 'Product news',
  sos: 'Safety alerts (SOS)',
  otp: 'OTP & login codes',
};

const CHANNEL_LABELS: Record<string, string> = {
  push: 'Push',
  email: 'Email',
  sms: 'SMS',
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

  async function toggle(category: string, channel: string, enabled: boolean) {
    setSaving(`${category}:${channel}`);
    setError('');
    try {
      await setNotificationPreference(category, channel, enabled);
      setPrefs((prev) => {
        const existing = (prev || []).find((p) => p.category === category && p.channel === channel);
        if (existing) {
          return (prev || []).map((p) => (p.category === category && p.channel === channel ? { ...p, enabled } : p));
        }
        return [...(prev || []), { category, channel, enabled }];
      });
    } catch (err) {
      setError(getErrorMessage(err, 'Could not update preference.'));
    } finally {
      setSaving(null);
    }
  }

  const categories = Object.keys(CATEGORY_LABELS);
  const channels = ['push', 'email', 'sms'];

  return (
    <Screen eyebrow="Settings" title="Notification settings" onBack={() => navigate('/profile')}>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, marginTop: 0 }}>
        OTP and safety alerts cannot be turned off.
      </p>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}

      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        {categories.map((category) => (
          <div key={category}>
            <div style={{ fontSize: 14, fontWeight: 700, marginBottom: 8 }}>{CATEGORY_LABELS[category]}</div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              {channels.map((channel) => {
                const pref = (prefs || []).find((p) => p.category === category && p.channel === channel);
                const locked = category === 'otp' || category === 'sos';
                return (
                  <label
                    key={channel}
                    style={{
                      display: 'flex',
                      justifyContent: 'space-between',
                      alignItems: 'center',
                      border: '1px solid var(--border)',
                      borderRadius: 12,
                      background: 'var(--surface)',
                      padding: '12px 14px',
                      cursor: locked ? 'not-allowed' : 'pointer',
                      opacity: locked ? 0.6 : 1,
                    }}
                  >
                    <span style={{ fontSize: 14 }}>{CHANNEL_LABELS[channel]}</span>
                    <input
                      type="checkbox"
                      checked={pref?.enabled ?? (channel === 'push')}
                      disabled={locked || saving === `${category}:${channel}`}
                      onChange={(e) => void toggle(category, channel, e.target.checked)}
                    />
                  </label>
                );
              })}
            </div>
          </div>
        ))}
      </div>

      <p style={{ marginTop: 24, fontSize: 12, color: 'var(--text-muted)', textAlign: 'center' }}>
        {BRAND.name} v{(import.meta.env.VITE_APP_VERSION as string | undefined) ?? '1.0.0'}
      </p>
    </Screen>
  );
}
