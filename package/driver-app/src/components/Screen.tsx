import { type ReactNode } from 'react';
import styles from './Screen.module.css';

export function Screen({
  title,
  eyebrow,
  children,
  footer,
  withNav = false,
  onBack,
}: {
  title?: string;
  eyebrow?: string;
  children: ReactNode;
  footer?: ReactNode;
  withNav?: boolean;
  onBack?: () => void;
}) {
  return (
    <div className={`${styles.shell} ${withNav ? styles.withNav : ''}`}>
      <main className={styles.main}>
        {onBack && (
          <button type="button" className={styles.back} onClick={onBack}>
            ← Back
          </button>
        )}
        {eyebrow && <div className={styles.eyebrow}>{eyebrow}</div>}
        {title && <h1 className={styles.title}>{title}</h1>}
        <div className={styles.content}>{children}</div>
      </main>
      {footer && <div className={styles.footer}>{footer}</div>}
    </div>
  );
}
