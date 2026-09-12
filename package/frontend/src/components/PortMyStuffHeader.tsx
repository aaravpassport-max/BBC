import { BRAND } from '../constants/brand';
import styles from './PortMyStuffHeader.module.css';

export function PortMyStuffHeader({ variant = 'app' }: { variant?: 'app' | 'auth' }) {
  return (
    <header className={`${styles.header} ${variant === 'auth' ? styles.authHeader : ''}`}>
      <h1 className={styles.brand}>{BRAND.name}</h1>
      <p className={styles.tagline}>{BRAND.tagline}</p>
    </header>
  );
}
