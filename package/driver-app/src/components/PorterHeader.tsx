import styles from './PorterHeader.module.css';

export function PorterHeader({ variant = 'app' }: { variant?: 'app' | 'auth' }) {
  return (
    <header className={`${styles.header} ${variant === 'auth' ? styles.authHeader : ''}`}>
      <h1 className={styles.brand}>Porter Partner</h1>
      <p className={styles.tagline}>Deliver. Earn. Grow.</p>
    </header>
  );
}
