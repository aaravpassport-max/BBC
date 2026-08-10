import styles from './StatusBadge.module.css';

const LABELS: Record<string, string> = {
  scheduled: 'Scheduled',
  searching: 'Finding a driver',
  driver_assigned: 'Driver on the way',
  in_progress: 'In transit',
  completed: 'Delivered',
  cancelled: 'Cancelled',
  no_drivers_found: 'No drivers nearby',
};

const TONES: Record<string, 'active' | 'success' | 'danger' | 'neutral'> = {
  scheduled: 'neutral',
  searching: 'active',
  driver_assigned: 'active',
  in_progress: 'active',
  completed: 'success',
  cancelled: 'danger',
  no_drivers_found: 'danger',
};

export function StatusBadge({ status }: { status: string }) {
  const tone = TONES[status] || 'neutral';
  return (
    <span className={`${styles.badge} ${styles[tone]}`}>
      <span className={styles.dot} />
      {LABELS[status] || status}
    </span>
  );
}
