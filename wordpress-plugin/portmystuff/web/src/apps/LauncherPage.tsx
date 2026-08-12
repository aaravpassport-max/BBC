import { Link } from 'react-router-dom';
import { getConfig } from '@/config';
import styles from './LauncherPage.module.css';

const apps = [
  { to: '/customer', title: 'Customer App', desc: 'Book rides & parcels', emoji: '🚗' },
  { to: '/driver', title: 'Driver Partner', desc: 'Accept trips & earn', emoji: '🛵' },
  { to: '/admin', title: 'Admin Console', desc: 'Bookings, drivers, revenue', emoji: '📊', gated: 'admin' as const },
  { to: '/ops', title: 'Control Room', desc: 'SOS, dispatch, live map', emoji: '🚨', gated: 'ops' as const },
];

export function LauncherPage() {
  const cfg = getConfig();

  return (
    <div className={styles.page}>
      <div className={styles.hero}>
        <div className={styles.logo}>PORTMYSTUFF</div>
        <h1>Logistics platform</h1>
        <p>Rides + parcels — standalone apps powered by WordPress.</p>
      </div>
      <div className={styles.grid}>
        {apps.map((app) => {
          const locked =
            (app.gated === 'admin' && !cfg.canAdmin) || (app.gated === 'ops' && !cfg.canOps);
          return (
            <Link
              key={app.to}
              to={locked ? '#' : app.to}
              className={`${styles.card} ${locked ? styles.locked : ''}`}
              onClick={(e) => {
                if (locked) {
                  e.preventDefault();
                  window.location.href = cfg.wpLoginUrl;
                }
              }}
            >
              <span className={styles.emoji}>{app.emoji}</span>
              <strong>{app.title}</strong>
              <span>{app.desc}</span>
              {locked ? <em>WP login required</em> : null}
            </Link>
          );
        })}
      </div>
    </div>
  );
}
