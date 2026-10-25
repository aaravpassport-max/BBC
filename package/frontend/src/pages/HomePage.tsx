import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PortMyStuffHeader } from '../components/PortMyStuffHeader';
import { PromoBanners } from '../components/PromoBanners';
import { listBookings } from '../api';
import { HOME_PARCEL_TILES, HOME_RIDE_TILE, type ServiceId } from '../constants/vehicleCatalog';
import styles from './HomePage.module.css';

const ACTIVE_STATUSES = new Set(['scheduled', 'searching', 'driver_assigned', 'driver_arriving', 'driver_arrived', 'in_progress']);

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

  function selectParcelService(serviceId: ServiceId) {
    navigate('/book', { state: { serviceId } });
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

        <h2 className={styles.sectionTitle}>Book a ride</h2>
        <p className={styles.sectionHint}>Travel from pickup to drop</p>
        <button type="button" className={styles.rideHero} onClick={() => navigate('/ride')}>
          <span className={styles.rideHeroIcon} aria-hidden>
            {HOME_RIDE_TILE.icon}
          </span>
          <div className={styles.rideHeroCopy}>
            <div className={styles.rideHeroLabel}>{HOME_RIDE_TILE.label}</div>
            <div className={styles.rideHeroDesc}>{HOME_RIDE_TILE.description}</div>
          </div>
          <span className={styles.chevron} aria-hidden>
            ›
          </span>
        </button>

        <h2 className={styles.sectionTitle}>Send a parcel</h2>
        <p className={styles.sectionHint}>Goods delivery — Porter style</p>

        <div className={styles.serviceGrid}>
          {HOME_PARCEL_TILES.map((tile) => (
            <button
              key={tile.id}
              type="button"
              className={`${styles.serviceTile} ${tile.wide ? styles.serviceTileWide : ''}`}
              onClick={() => selectParcelService(tile.id)}
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
