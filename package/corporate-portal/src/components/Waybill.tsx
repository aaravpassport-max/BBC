import { type ReactNode } from 'react';
import styles from './Waybill.module.css';

interface WaybillProps {
  label: string;
  id?: string;
  children: ReactNode;
}

/**
 * The app's signature visual element: a shipping-waybill / cargo-manifest
 * styled card, used everywhere money or trip-status figures are shown
 * (fare breakdown, trip receipt). The perforated top edge and monospace
 * ledger lines are meant to evoke a physical waybill stapled to a crate —
 * appropriate for a goods-transport app in a way a generic rounded card
 * wouldn't be.
 */
export function Waybill({ label, id, children }: WaybillProps) {
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

export function WaybillLine({
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

export function WaybillDivider() {
  return <div className={styles.divider} aria-hidden="true" />;
}
