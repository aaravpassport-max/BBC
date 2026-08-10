import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Geolocation } from '@capacitor/geolocation';
import { PorterHeader } from '../components/PorterHeader';
import { Button } from '../components/Button';
import { PromoBanners } from '../components/PromoBanners';
import { getQuote, getErrorMessage, type Quote } from '../api';
import styles from './HomePage.module.css';

const LOCATIONS = [
  { label: 'Koramangala Warehouse', lat: 12.952, lng: 77.602 },
  { label: 'Indiranagar Depot', lat: 12.97, lng: 77.62 },
  { label: 'HSR Layout Store', lat: 12.912, lng: 77.638 },
  { label: 'Whitefield Yard', lat: 12.969, lng: 77.75 },
];

const CATEGORY_DISPLAY: Record<string, { icon: string; blurb: string }> = {
  two_wheeler: { icon: '🛵', blurb: 'Small parcels, up to 20kg' },
  three_wheeler: { icon: '🛺', blurb: 'Up to 500kg' },
  mini_truck: { icon: '🚚', blurb: 'Up to 750kg' },
  pickup_truck: { icon: '🚛', blurb: 'Up to 1500kg' },
  large_truck: { icon: '🚛', blurb: 'Up to 5000kg' },
};

export function HomePage() {
  const navigate = useNavigate();
  const [pickupIndex, setPickupIndex] = useState(0);
  const [dropIndex, setDropIndex] = useState(1);
  const [goodsCategory, setGoodsCategory] = useState('Furniture');
  const [helperNeeded, setHelperNeeded] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [locating, setLocating] = useState(false);
  const [devicePickup, setDevicePickup] = useState<{ lat: number; lng: number; label: string } | null>(null);
  const [quotes, setQuotes] = useState<Quote[] | null>(null);
  const [choosingVehicle, setChoosingVehicle] = useState(false);
  const [scheduledFor, setScheduledFor] = useState('');
  const [couponCode, setCouponCode] = useState('');

  const minScheduleValue = new Date(Date.now() + 35 * 60 * 1000).toISOString().slice(0, 16);
  const maxScheduleValue = new Date(Date.now() + 6.5 * 24 * 60 * 60 * 1000).toISOString().slice(0, 16);

  async function handleUseMyLocation() {
    setError('');
    setLocating(true);
    try {
      const permission = await Geolocation.requestPermissions();
      if (permission.location !== 'granted' && permission.coarseLocation !== 'granted') {
        setError('Location permission denied. Pick a location from the list.');
        return;
      }
      const position = await Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 10000 });
      setDevicePickup({
        lat: position.coords.latitude,
        lng: position.coords.longitude,
        label: 'Current location',
      });
    } catch {
      setError('Could not get location. Pick from the list instead.');
    } finally {
      setLocating(false);
    }
  }

  async function handleGetQuote() {
    setError('');
    const pickup = devicePickup ?? LOCATIONS[pickupIndex];
    const drop = LOCATIONS[dropIndex];
    if (!devicePickup && pickupIndex === dropIndex) {
      setError('Pickup and drop cannot be the same.');
      return;
    }
    setLoading(true);
    try {
      const res = await getQuote({
        pickup: { lat: pickup.lat, lng: pickup.lng },
        drops: [{ lat: drop.lat, lng: drop.lng }],
        coupon_code: couponCode.trim() || undefined,
        item_details: {
          goods_category: goodsCategory,
          helper_needed: helperNeeded,
        },
      });
      if (res.quotes.length === 0) {
        setError('No vehicles available for this route.');
        return;
      }
      setQuotes(res.quotes);
      setChoosingVehicle(true);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not get fare. Please try again.'));
    } finally {
      setLoading(false);
    }
  }

  function handleChooseVehicle(quote: Quote) {
    const pickup = devicePickup ?? LOCATIONS[pickupIndex];
    const drop = LOCATIONS[dropIndex];
    navigate('/confirm', {
      state: {
        quote,
        pickupLabel: pickup.label,
        dropLabel: drop.label,
        goodsCategory,
        helperNeeded,
        couponCode: couponCode.trim() || undefined,
        scheduledFor: scheduledFor ? new Date(scheduledFor).toISOString() : undefined,
      },
    });
  }

  return (
    <div className={styles.page}>
      <PorterHeader />
      <div className={styles.body}>
        <PromoBanners />
        <div className={styles.card}>
          <div className={styles.locationCard}>
            <div className={styles.locationRow}>
              <span className={`${styles.dot} ${styles.dotPickup}`} />
              <div className={styles.fieldBlock}>
                <div className={styles.fieldLabel}>Pickup from</div>
                {devicePickup ? (
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
                    <span style={{ fontWeight: 600 }}>{devicePickup.label}</span>
                    <button type="button" className={styles.gpsLink} onClick={() => setDevicePickup(null)}>
                      Change
                    </button>
                  </div>
                ) : (
                  <>
                    <select
                      value={pickupIndex}
                      onChange={(e) => setPickupIndex(Number(e.target.value))}
                      className={styles.select}
                    >
                      {LOCATIONS.map((loc, i) => (
                        <option key={loc.label} value={i}>
                          {loc.label}
                        </option>
                      ))}
                    </select>
                    <button type="button" className={styles.gpsLink} onClick={() => void handleUseMyLocation()} disabled={locating}>
                      {locating ? 'Getting location…' : '📍 Use current location'}
                    </button>
                  </>
                )}
              </div>
            </div>
            <div className={styles.connector} />
            <div className={styles.locationRow}>
              <span className={`${styles.dot} ${styles.dotDrop}`} />
              <div className={styles.fieldBlock}>
                <div className={styles.fieldLabel}>Drop at</div>
                <select value={dropIndex} onChange={(e) => setDropIndex(Number(e.target.value))} className={styles.select}>
                  {LOCATIONS.map((loc, i) => (
                    <option key={loc.label} value={i}>
                      {loc.label}
                    </option>
                  ))}
                </select>
              </div>
            </div>
          </div>

          <div className={styles.section}>
            <span className={styles.sectionLabel}>Goods type</span>
            <select value={goodsCategory} onChange={(e) => setGoodsCategory(e.target.value)} className={styles.select}>
              <option>Furniture</option>
              <option>Electronics</option>
              <option>Boxes</option>
              <option>Other</option>
            </select>
          </div>

          <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 14, color: 'var(--text-muted)' }}>
            <input type="checkbox" checked={helperNeeded} onChange={(e) => setHelperNeeded(e.target.checked)} />
            Need helper for loading/unloading
          </label>

          <div className={styles.section}>
            <span className={styles.sectionLabel}>Promo code</span>
            <input
              type="text"
              value={couponCode}
              onChange={(e) => setCouponCode(e.target.value.toUpperCase())}
              placeholder="Enter coupon code"
              className={styles.select}
            />
          </div>

          <div className={styles.section}>
            <span className={styles.sectionLabel}>When do you need it?</span>
            <div className={styles.toggleRow}>
              <button
                type="button"
                className={`${styles.toggleBtn} ${!scheduledFor ? styles.toggleBtnActive : ''}`}
                onClick={() => setScheduledFor('')}
              >
                Now
              </button>
              <button
                type="button"
                className={`${styles.toggleBtn} ${scheduledFor ? styles.toggleBtnActive : ''}`}
                onClick={() => setScheduledFor((v) => v || minScheduleValue)}
              >
                Schedule
              </button>
            </div>
            {scheduledFor && (
              <input
                type="datetime-local"
                value={scheduledFor}
                min={minScheduleValue}
                max={maxScheduleValue}
                onChange={(e) => setScheduledFor(e.target.value)}
                className={styles.select}
              />
            )}
          </div>

          {error && <p style={{ color: 'var(--danger)', fontSize: 13, margin: 0 }}>{error}</p>}

          <Button onClick={handleGetQuote} loading={loading}>
            Get fare estimate
          </Button>
        </div>

        {choosingVehicle && quotes && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 16 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <h2 style={{ fontSize: 17 }}>Select vehicle</h2>
              <button
                type="button"
                onClick={() => setChoosingVehicle(false)}
                style={{ background: 'none', border: 'none', color: 'var(--accent)', fontWeight: 600, fontSize: 13, cursor: 'pointer' }}
              >
                Edit
              </button>
            </div>
            {quotes
              .slice()
              .sort((a, b) => a.fare_breakdown.final_fare - b.fare_breakdown.final_fare)
              .map((q) => {
                const display = CATEGORY_DISPLAY[q.vehicle_category] ?? { icon: '🚐', blurb: '' };
                return (
                  <button key={q.quote_id} type="button" className={styles.vehicleCard} onClick={() => handleChooseVehicle(q)}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                      <span className={styles.vehicleIcon}>{display.icon}</span>
                      <div>
                        <div className={styles.vehicleName}>{q.vehicle_category.replace(/_/g, ' ')}</div>
                        <div className={styles.vehicleBlurb}>{display.blurb}</div>
                      </div>
                    </div>
                    <div className={styles.vehiclePrice}>₹{q.fare_breakdown.final_fare.toFixed(0)}</div>
                  </button>
                );
              })}
          </div>
        )}
      </div>
    </div>
  );
}
