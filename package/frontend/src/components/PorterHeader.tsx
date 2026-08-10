import styles from './PorterHeader.module.css';

export function PorterHeader({ variant = 'app' }: { variant?: 'app' | 'auth' }) {
  return (
    <header className={`${styles.header} ${variant === 'auth' ? styles.authHeader : ''}`}>
      <h1 className={styles.brand}>Porter</h1>
      <p className={styles.tagline}>Delivery hai? #HoJayega!</p>
    </header>
  );
}
