import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PortMyStuffHeader } from '../components/PortMyStuffHeader';
import { PromoBanners } from '../components/PromoBanners';
import { listBookings } from '../api';
import { HOME_SERVICE_TILES, type VehicleGroupId } from '../constants/vehicleCatalog';
import styles from './HomePage.module.css';

const ACTIVE_STATUSES = new Set(['scheduled', 'searching', 'driver_assigned', 'in_progress']);

export function HomePage() {
  const navigate = useNavigate();
  const [activeTripId, setActiveTripId] = useState<string | null>(null);

  useEffect(() => {
    listBookings({ page: 1, page_size: 5 })
      .then((res) => {
        const active = res.items.find((b) => ACTIVE_STATUSES.has(b.status));
        if (active) setActiveTripId(active.id);
      })
      .catch(() => undefined);
  }, []);

  function selectVehicle(group: VehicleGroupId) {
    navigate('/book', { state: { vehicleGroup: group } });
  }

  return (
    <div className={styles.page}>
      <PortMyStuffHeader />
      <div className={styles.body}>
        {activeTripId && (
          <button
            type="button"
            onClick={() => navigate(`/track/${activeTripId}`)}
            className={styles.activeTrip}
          >
            🚚 Active trip in progress — tap to track
          </button>
        )}

        <PromoBanners />

        <h2 className={styles.sectionTitle}>What do you need?</h2>
        <p className={styles.sectionHint}>Choose a vehicle type to get started</p>

        <div className={styles.serviceGrid}>
          {HOME_SERVICE_TILES.map((tile) => (
            <button
              key={tile.id}
              type="button"
              className={`${styles.serviceTile} ${tile.wide ? styles.serviceTileWide : ''}`}
              onClick={() => selectVehicle(tile.id)}
            >
              <span className={styles.serviceIcon} aria-hidden>
                {tile.icon}
              </span>
              <div className={styles.serviceCopy}>
                <div className={styles.serviceLabel}>{tile.label}</div>
                <div className={styles.serviceDesc}>{tile.description}</div>
              </div>
              <span className={styles.chevron} aria-hidden>
                ›
              </span>
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}
