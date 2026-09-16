import { type ReactNode } from 'react';
import styles from './FareCard.module.css';

interface FareCardProps {
  label: string;
  id?: string;
  children: ReactNode;
}

export function FareCard({ label, id, children }: FareCardProps) {
  return (
    <div className={styles.wrapper}>
      <div className={styles.perforation} aria-hidden="true" />
      <div className={styles.card}>
        <div className={styles.header}>
          <span className={styles.label}>{label}</span>
          {id && <span className={styles.id}>#{id}</span>}
        </div>
        {children}
      </div>
    </div>
  );
}

export function FareCardLine({
  label,
  value,
  emphasis,
  muted,
}: {
  label: string;
  value: string;
  emphasis?: boolean;
  muted?: boolean;
}) {
  return (
    <div className={`${styles.line} ${emphasis ? styles.lineEmphasis : ''} ${muted ? styles.lineMuted : ''}`}>
      <span>{label}</span>
      <span className={styles.lineValue}>{value}</span>
    </div>
  );
}

export function FareCardDivider() {
  return <div className={styles.divider} aria-hidden="true" />;
}
