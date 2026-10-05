import { type ReactNode } from 'react';
import { NavLink } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import styles from './Layout.module.css';

const NAV_ITEMS = [
  { to: '/sos', label: 'SOS Queue' },
  { to: '/dispatch', label: 'Dispatch Monitor' },
  { to: '/live-map', label: 'Live Map' },
];

export function Layout({ title, actions, children }: { title: string; actions?: ReactNode; children: ReactNode }) {
  const auth = useAuth();

  return (
    <div className={styles.shell}>
      <aside className={styles.sidebar}>
        <div className={styles.brand}>
          <span className={styles.brandMark} />
          Control Room
        </div>
        <nav className={styles.nav}>
          {NAV_ITEMS.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              className={({ isActive }) => `${styles.navLink} ${isActive ? styles.navLinkActive : ''}`}
            >
              {item.label}
            </NavLink>
          ))}
        </nav>
        <button className={styles.signOut} onClick={auth.logout}>
          Sign out
        </button>
      </aside>
      <main className={styles.main}>
        <header className={styles.header}>
          <h1 className={styles.title}>{title}</h1>
          {actions && <div className={styles.actions}>{actions}</div>}
        </header>
        <div className={styles.content}>{children}</div>
      </main>
    </div>
  );
}
