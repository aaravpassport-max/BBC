import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import styles from './AppShell.module.css';

type Props = {
  title: string;
  subtitle?: string;
  backTo?: string;
  children: ReactNode;
  nav?: Array<{ to: string; label: string }>;
};

export function AppShell({ title, subtitle, backTo, children, nav }: Props) {
  return (
    <div className={styles.shell}>
      <header className={styles.header}>
        <div className={styles.brandRow}>
          {backTo ? (
            <Link to={backTo} className={styles.back}>
              ←
            </Link>
          ) : null}
          <div>
            <div className={styles.brand}>PORTMYSTUFF</div>
            <h1 className={styles.title}>{title}</h1>
            {subtitle ? <p className={styles.subtitle}>{subtitle}</p> : null}
          </div>
        </div>
        {nav ? (
          <nav className={styles.nav}>
            {nav.map((item) => (
              <Link key={item.to} to={item.to} className={styles.navLink}>
                {item.label}
              </Link>
            ))}
          </nav>
        ) : null}
      </header>
      <main className={styles.main}>{children}</main>
    </div>
  );
}
