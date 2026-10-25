import { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Button } from '../components/Button';
import { LiveMap } from '../components/LiveMap';
import { reverseGeocode } from '../lib/places';
import type { LocationPoint } from '../lib/locations';
import type { SharedLocationNavState } from '../lib/bookingFlow';
import styles from './SharedLocationRolePage.module.css';

type LocationRole = 'pickup' | 'drop';

export function SharedLocationRolePage() {
  const navigate = useNavigate();
  const location = useLocation();
  const nav = location.state as SharedLocationNavState | undefined;

  const [point, setPoint] = useState<LocationPoint | null>(nav?.point ?? null);
  const [role, setRole] = useState<LocationRole>('drop');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!nav?.point) {
      navigate('/home', { replace: true });
      return;
    }
    setPoint(nav.point);
    if (!nav.point.addressLine || nav.point.label === 'Shared location') {
      void reverseGeocode(nav.point.lat, nav.point.lng).then((address) => {
        setPoint((prev) =>
          prev
            ? {
                ...prev,
                label: address.split(',')[0]?.trim() || prev.label,
                addressLine: address,
              }
            : prev
        );
      });
    }
  }, [nav, navigate]);

  if (!point) return null;

  function handleProceed() {
    if (!point) return;
    setError('');
    if (!role) {
      setError('Choose whether this location is for pickup or drop.');
      return;
    }
    setLoading(true);
    navigate('/book/shared-details', { state: { point, role } satisfies SharedLocationNavState });
  }

  const mapPickup = role === 'pickup' ? point : null;
  const mapDrops = role === 'drop' ? [point] : [];

  return (
    <div className={styles.rolePage}>
      <div className={styles.mapSection}>
        <LiveMap pickup={mapPickup} drops={mapDrops} driver={null} />
        <div className={styles.mapHint}>
          {role === 'pickup' ? 'Your goods will be picked up here' : 'Your goods will be dropped here'}
        </div>
      </div>

      <div className={styles.sheet}>
        <div className={styles.addressCard}>
          <span className={role === 'pickup' ? styles.dotPickup : styles.dotDrop} aria-hidden />
          <div className={styles.addressCopy}>
            <div className={styles.placeName}>{point.label}</div>
            <div className={styles.placeSub}>{point.addressLine ?? point.label}</div>
          </div>
        </div>

        <h2 className={styles.sectionTitle}>Choose the location as</h2>

        <div className={styles.options} role="radiogroup" aria-label="Location role">
          <button
            type="button"
            role="radio"
            aria-checked={role === 'pickup'}
            className={`${styles.option} ${role === 'pickup' ? styles.optionActive : ''}`}
            onClick={() => setRole('pickup')}
          >
            <span className={`${styles.optionIcon} ${styles.iconPickup}`} aria-hidden>
              ↑
            </span>
            <span className={styles.optionBody}>
              <div className={styles.optionTitle}>Pick-up</div>
              <div className={styles.optionDesc}>Goods will be picked-up from the location pinned on map</div>
            </span>
            <span className={`${styles.radio} ${role === 'pickup' ? styles.radioActive : ''}`} aria-hidden />
          </button>

          <button
            type="button"
            role="radio"
            aria-checked={role === 'drop'}
            className={`${styles.option} ${role === 'drop' ? styles.optionActive : ''}`}
            onClick={() => setRole('drop')}
          >
            <span className={`${styles.optionIcon} ${styles.iconDrop}`} aria-hidden>
              ↓
            </span>
            <span className={styles.optionBody}>
              <div className={styles.optionTitle}>Drop</div>
              <div className={styles.optionDesc}>Goods will be dropped at the location pinned on map</div>
            </span>
            <span className={`${styles.radio} ${role === 'drop' ? styles.radioActive : ''}`} aria-hidden />
          </button>
        </div>

        {error && <p className={styles.error}>{error}</p>}
        <Button loading={loading} onClick={handleProceed}>
          Proceed
        </Button>
      </div>
    </div>
  );
}
