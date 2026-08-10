import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { MenuRow } from '../components/MenuRow';
import { useAuth } from '../context/AuthContext';
import { getDriverProfile } from '../api';
import { BRAND } from '../constants/brand';

function StatusBadge({ label, ok }: { label: string; ok: boolean }) {
  return (
    <span
      style={{
        fontSize: 11,
        fontWeight: 600,
        padding: '3px 8px',
        borderRadius: 20,
        background: ok ? 'var(--success-soft, #f0fff4)' : 'var(--border)',
        color: ok ? 'var(--success)' : 'var(--text-muted)',
      }}
    >
      {label}
    </span>
  );
}

export function ProfilePage() {
  const navigate = useNavigate();
  const { logout } = useAuth();
  const [profile, setProfile] = useState<Awaited<ReturnType<typeof getDriverProfile>> | null>(null);

  useEffect(() => {
    getDriverProfile().then(setProfile).catch(() => undefined);
  }, []);

  function handleLogout() {
    logout();
    navigate('/login', { replace: true });
  }

  const name = profile?.name || BRAND.partnerName;

  return (
    <Screen eyebrow="Account" title="Profile" withNav>
      <div style={{ display: 'flex', alignItems: 'center', gap: 14, padding: '8px 4px 16px' }}>
        <div
          style={{
            width: 56,
            height: 56,
            borderRadius: '50%',
            background: 'linear-gradient(135deg, #0d9f4f, #087a3d)',
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
          {profile?.phone && <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>{profile.phone}</div>}
          {profile?.email && <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>{profile.email}</div>}
          {profile?.rating_avg != null && (
            <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>
              ★ {profile.rating_avg.toFixed(1)} · {profile.rating_count} ratings
            </div>
          )}
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 8 }}>
            <StatusBadge label={`KYC ${profile?.kyc_status ?? '—'}`} ok={profile?.kyc_status === 'approved'} />
            <StatusBadge label={`Training ${profile?.training_status ?? '—'}`} ok={profile?.training_status === 'passed'} />
            <StatusBadge label={profile?.online_status ? 'Online' : 'Offline'} ok={!!profile?.online_status} />
          </div>
          {profile?.vehicle && (
            <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 8 }}>
              🚚 {profile.vehicle.plate} · {profile.vehicle.category.replace(/_/g, ' ')}
            </div>
          )}
        </div>
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        <MenuRow icon="✏️" label="Edit profile" onClick={() => navigate('/profile/edit')} />
        <MenuRow icon="🚚" label="My vehicle" hint="Register or update vehicle" onClick={() => navigate('/vehicle')} />
        <MenuRow icon="💰" label="Incentives & missions" hint="Daily bonuses and progress" onClick={() => navigate('/incentives')} />
        <MenuRow icon="⚠️" label="Penalties" hint="View and dispute charges" onClick={() => navigate('/penalties')} />
        <MenuRow icon="🔔" label="Notifications" onClick={() => navigate('/notifications')} />
        <MenuRow icon="🎁" label="Refer & earn" onClick={() => navigate('/referral')} />
        <MenuRow icon="❓" label="Help & support" onClick={() => navigate('/help')} />
        <MenuRow icon="⚙️" label="Settings" onClick={() => navigate('/settings')} />
        <MenuRow icon="🚪" label="Log out" onClick={handleLogout} danger />
      </div>
    </Screen>
  );
}
