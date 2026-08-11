import styles from './StatusBadge.module.css';

const LABELS: Record<string, string> = {
  searching: 'Finding a driver',
  driver_assigned: 'Head to pickup',
  driver_arriving: 'Heading to pickup',
  driver_arrived: 'At pickup',
  in_progress: 'In transit',
  completed: 'Delivered',
  cancelled: 'Cancelled',
  no_drivers_found: 'No drivers nearby',
};

const RIDE_LABELS: Partial<Record<string, string>> = {
  in_progress: 'Ride in progress',
  completed: 'Ride completed',
};

const TONES: Record<string, 'active' | 'success' | 'danger' | 'neutral'> = {
  searching: 'active',
  driver_assigned: 'active',
  driver_arriving: 'active',
  driver_arrived: 'active',
  in_progress: 'active',
  completed: 'success',
  cancelled: 'danger',
  no_drivers_found: 'danger',
};

export function StatusBadge({
  status,
  bookingType = 'parcel',
}: {
  status: string;
  bookingType?: 'parcel' | 'ride';
}) {
  const tone = TONES[status] || 'neutral';
  const label =
    bookingType === 'ride' && RIDE_LABELS[status]
      ? RIDE_LABELS[status]
      : LABELS[status] || status.replace(/_/g, ' ');
  return (
    <span className={`${styles.badge} ${styles[tone]}`}>
      <span className={styles.dot} />
      {label}
    </span>
  );
}
