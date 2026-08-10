import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { MenuRow } from '../components/MenuRow';
import { useAuth } from '../context/AuthContext';
import { getDriverProfile } from '../api';
import { BRAND } from '../constants/brand';

export function ProfilePage() {
  const navigate = useNavigate();
  const { logout } = useAuth();
  const [name, setName] = useState('Partner');
  const [rating, setRating] = useState<number | null>(null);

  useEffect(() => {
    getDriverProfile()
      .then((p) => {
        setName(p.name || BRAND.partnerName);
        setRating(p.rating_avg);
      })
      .catch(() => undefined);
  }, []);

  function handleLogout() {
    logout();
    navigate('/login', { replace: true });
  }

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
          {rating != null && (
            <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>★ {rating.toFixed(1)} rating</div>
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
