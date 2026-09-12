import { useLocation, useNavigate } from 'react-router-dom';
import styles from './BottomNav.module.css';

const TABS = [
  { path: '/home', label: 'Home', icon: '🏠' },
  { path: '/history', label: 'Trips', icon: '📋' },
  { path: '/wallet', label: 'Wallet', icon: '💳' },
  { path: '/profile', label: 'Account', icon: '👤' },
] as const;

export function BottomNav() {
  const location = useLocation();
  const navigate = useNavigate();

  return (
    <nav className={styles.nav} aria-label="Main navigation">
      {TABS.map((tab) => {
        const active = location.pathname === tab.path || location.pathname.startsWith(`${tab.path}/`);
        return (
          <button
            key={tab.path}
            type="button"
            className={`${styles.tab} ${active ? styles.tabActive : ''}`}
            onClick={() => navigate(tab.path)}
          >
            <span className={styles.icon} aria-hidden>
              {tab.icon}
            </span>
            {tab.label}
          </button>
        );
      })}
    </nav>
  );
}
