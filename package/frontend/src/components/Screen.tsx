import { type ReactNode } from 'react';
import styles from './Screen.module.css';

export function Screen({
  title,
  eyebrow,
  children,
  footer,
  withNav = false,
}: {
  title?: string;
  eyebrow?: string;
  children: ReactNode;
  footer?: ReactNode;
  withNav?: boolean;
}) {
  return (
    <div className={`${styles.shell} ${withNav ? styles.withNav : ''}`}>
      <main className={styles.main}>
        {eyebrow && <div className={styles.eyebrow}>{eyebrow}</div>}
        {title && <h1 className={styles.title}>{title}</h1>}
        <div className={styles.content}>{children}</div>
      </main>
      {footer && <div className={styles.footer}>{footer}</div>}
    </div>
  );
}
