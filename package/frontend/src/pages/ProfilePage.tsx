import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { MenuRow } from '../components/MenuRow';
import { useAuth } from '../context/AuthContext';
import { getProfile } from '../api';
import { BRAND, getDisplayName } from '../constants/brand';

export function ProfilePage() {
  const navigate = useNavigate();
  const { logout } = useAuth();
  const [name, setName] = useState(getDisplayName());
  const [email, setEmail] = useState<string | null>(null);
  const [phone, setPhone] = useState<string | null>(null);

  useEffect(() => {
    getProfile()
      .then((p) => {
        if (p.name) setName(p.name);
        setEmail(p.email);
        setPhone(p.phone ? `${p.country_code} ${p.phone}` : null);
      })
      .catch(() => undefined);
  }, []);

  function handleLogout() {
    logout();
    navigate('/login', { replace: true });
  }

  return (
    <Screen eyebrow="Account" title="Profile" withNav>
      <button
        type="button"
        onClick={() => navigate('/profile/edit')}
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: 14,
          padding: '16px 4px 20px',
          background: 'none',
          border: 'none',
          width: '100%',
          textAlign: 'left',
          cursor: 'pointer',
          color: 'inherit',
        }}
      >
        <div
          style={{
            width: 56,
            height: 56,
            borderRadius: '50%',
            background: 'linear-gradient(135deg, #2b6ce6, #1d5fd4)',
            color: 'white',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontSize: 22,
            fontWeight: 700,
          }}
        >
          {name.charAt(0).toUpperCase()}
        </div>
        <div>
          <div style={{ fontSize: 18, fontWeight: 700 }}>{name}</div>
          {phone && <div style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 2 }}>{phone}</div>}
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 8 }}>
            {email && (
              <span
                style={{
                  fontSize: 11,
                  fontWeight: 600,
                  padding: '3px 8px',
                  borderRadius: 20,
                  background: 'var(--accent-soft)',
                  color: 'var(--accent-strong)',
                }}
              >
                ✓ Email verified
              </span>
            )}
            {phone && (
              <span
                style={{
                  fontSize: 11,
                  fontWeight: 600,
                  padding: '3px 8px',
                  borderRadius: 20,
                  background: 'var(--bg)',
                  color: 'var(--text-muted)',
                  border: '1px solid var(--border)',
                }}
              >
                ✓ Phone verified
              </span>
            )}
          </div>
          <div style={{ fontSize: 13, color: 'var(--accent)', marginTop: 8 }}>Edit profile →</div>
        </div>
      </button>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        <MenuRow icon="📍" label="Saved addresses" onClick={() => navigate('/addresses')} />
        <MenuRow icon="🏢" label="Corporate billing" onClick={() => navigate('/corporate')} />
        <MenuRow icon="🛡️" label="Safety center" hint="SOS, OTP tips, and trip safety" onClick={() => navigate('/safety')} />
        <MenuRow icon="🔔" label="Notifications" hint="Trip updates and offers" onClick={() => navigate('/notifications')} />
        <MenuRow icon="🎁" label="Refer & earn" hint="Invite friends, earn rewards" onClick={() => navigate('/referral')} />
        <MenuRow icon="🏆" label="Loyalty points" hint="Earn rewards on every trip" onClick={() => navigate('/loyalty')} />
        <MenuRow icon="🧾" label="Trip invoices" hint="GST invoices for completed trips" onClick={() => navigate('/invoices')} />
        <MenuRow icon="⭐" label={BRAND.plus} hint="Membership benefits" onClick={() => navigate('/subscription')} />
        <MenuRow icon="❓" label="Help & support" hint="FAQs and support tickets" onClick={() => navigate('/help')} />
        <MenuRow icon="⚙️" label="Settings" hint="Notification preferences" onClick={() => navigate('/settings')} />
        <MenuRow icon="🚪" label="Log out" onClick={handleLogout} danger />
      </div>
    </Screen>
  );
}
