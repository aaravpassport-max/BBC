import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { MenuRow } from '../components/MenuRow';
import { useAuth } from '../context/AuthContext';

import { BRAND, getDisplayName } from '../constants/brand';
export function ProfilePage() {
  const navigate = useNavigate();
  const { logout } = useAuth();

  function handleLogout() {
    logout();
    navigate('/login', { replace: true });
  }

  return (
    <Screen eyebrow="Account" title="Profile" withNav>
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: 14,
          padding: '16px 4px 20px',
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
          {getDisplayName().charAt(0).toUpperCase()}
        </div>
        <div>
          <div style={{ fontSize: 18, fontWeight: 700 }}>{getDisplayName()}</div>
          <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>Manage your {BRAND.name} account</div>
        </div>
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        <MenuRow icon="📍" label="Saved addresses" onClick={() => navigate('/addresses')} />
        <MenuRow icon="🏢" label="Corporate billing" onClick={() => navigate('/corporate')} />
        <MenuRow icon="🔔" label="Notifications" hint="Trip updates and offers" onClick={() => navigate('/notifications')} />
        <MenuRow icon="🎁" label="Refer & earn" hint="Invite friends, earn rewards" onClick={() => navigate('/referral')} />
        <MenuRow icon="⭐" label={BRAND.plus} hint="Membership benefits" onClick={() => navigate('/subscription')} />
        <MenuRow icon="❓" label="Help & support" hint="FAQs and support tickets" onClick={() => navigate('/help')} />
        <MenuRow icon="⚙️" label="Settings" hint="Notification preferences" onClick={() => navigate('/settings')} />
        <MenuRow icon="🚪" label="Log out" onClick={handleLogout} danger />
      </div>
    </Screen>
  );
}
