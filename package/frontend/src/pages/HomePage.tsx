import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Geolocation } from '@capacitor/geolocation';
import { PortMyStuffHeader } from '../components/PortMyStuffHeader';
import { Button } from '../components/Button';
import { PromoBanners } from '../components/PromoBanners';
import { LocationPicker } from '../components/LocationPicker';
import { MapPicker } from '../components/MapPicker';
import { LiveMap } from '../components/LiveMap';
import { getQuote, getErrorMessage, listAddresses, type Quote } from '../api';
import { PRESET_LOCATIONS, sameLocation, type LocationPoint } from '../lib/locations';
import type { SavedAddress } from '../api/profile';
import styles from './HomePage.module.css';

const CATEGORY_DISPLAY: Record<string, { icon: string; blurb: string }> = {
  two_wheeler: { icon: '🛵', blurb: 'Small parcels, up to 20kg' },
  three_wheeler: { icon: '🛺', blurb: 'Up to 500kg' },
  mini_truck: { icon: '🚚', blurb: 'Up to 750kg' },
  pickup_truck: { icon: '🚛', blurb: 'Up to 1500kg' },
  large_truck: { icon: '🚛', blurb: 'Up to 5000kg' },
};

const WEIGHT_BANDS = [
  { id: 'light', label: 'Light (up to 20 kg)' },
  { id: 'medium', label: 'Medium (20–100 kg)' },
  { id: 'heavy', label: 'Heavy (100–500 kg)' },
  { id: 'bulk', label: 'Bulk (500+ kg)' },
];

export function HomePage() {
  const navigate = useNavigate();
  const [pickup, setPickup] = useState<LocationPoint | null>(PRESET_LOCATIONS[0]);
  const [drops, setDrops] = useState<LocationPoint[]>([PRESET_LOCATIONS[1]]);
  const [savedAddresses, setSavedAddresses] = useState<SavedAddress[]>([]);
  const [mapTarget, setMapTarget] = useState<'pickup' | number | null>(null);
  const [goodsCategory, setGoodsCategory] = useState('Furniture');
  const [weightBand, setWeightBand] = useState('medium');
  const [helperNeeded, setHelperNeeded] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [quotes, setQuotes] = useState<Quote[] | null>(null);
  const [choosingVehicle, setChoosingVehicle] = useState(false);
  const [scheduledFor, setScheduledFor] = useState('');
  const [couponCode, setCouponCode] = useState('');

  const minScheduleValue = new Date(Date.now() + 35 * 60 * 1000).toISOString().slice(0, 16);
  const maxScheduleValue = new Date(Date.now() + 6.5 * 24 * 60 * 60 * 1000).toISOString().slice(0, 16);

  useEffect(() => {
    listAddresses()
      .then(setSavedAddresses)
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    void Geolocation.requestPermissions().catch(() => undefined);
  }, []);

  async function handleGetQuote() {
    setError('');
    if (!pickup) {
      setError('Select a pickup location.');
      return;
    }
    if (drops.some((d) => sameLocation(pickup, d))) {
      setError('Pickup and drop cannot be the same.');
      return;
    }
    setLoading(true);
    try {
      const res = await getQuote({
        pickup: { lat: pickup.lat, lng: pickup.lng },
        drops: drops.map((d) => ({ lat: d.lat, lng: d.lng })),
        coupon_code: couponCode.trim() || undefined,
        item_details: {
          goods_category: goodsCategory,
          weight_band: weightBand,
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
    if (!pickup) return;
    navigate('/confirm', {
      state: {
        quote,
        pickup,
        drops,
        goodsCategory,
        weightBand,
        helperNeeded,
        couponCode: couponCode.trim() || undefined,
        scheduledFor: scheduledFor ? new Date(scheduledFor).toISOString() : undefined,
      },
    });
  }

  function updateDrop(index: number, loc: LocationPoint) {
    setDrops((prev) => prev.map((d, i) => (i === index ? loc : d)));
  }

  return (
    <div className={styles.page}>
      <PortMyStuffHeader />
      <div className={styles.body}>
        <PromoBanners />
        <div className={styles.card}>
          {pickup && drops.length > 0 && (
            <LiveMap pickup={pickup} drops={drops} driver={null} />
          )}

          <div className={styles.locationCard}>
            <div className={styles.locationRow}>
              <span className={`${styles.dot} ${styles.dotPickup}`} />
              <div className={styles.fieldBlock}>
                <div className={styles.fieldLabel}>Pickup from</div>
                <LocationPicker
                  value={pickup}
                  onChange={setPickup}
                  savedAddresses={savedAddresses}
                  onPickOnMap={() => setMapTarget('pickup')}
                />
              </div>
            </div>
            <div className={styles.connector} />
            {drops.map((drop, idx) => (
              <div key={idx} className={styles.locationRow}>
                <span className={`${styles.dot} ${styles.dotDrop}`} />
                <div className={styles.fieldBlock}>
                  <div className={styles.fieldLabel}>Drop {drops.length > 1 ? idx + 1 : 'at'}</div>
                  <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start' }}>
                    <div style={{ flex: 1 }}>
                      <LocationPicker
                        value={drop}
                        onChange={(loc) => updateDrop(idx, loc)}
                        savedAddresses={savedAddresses}
                        onPickOnMap={() => setMapTarget(idx)}
                      />
                    </div>
                    {drops.length > 1 && (
                      <button
                        type="button"
                        onClick={() => setDrops(drops.filter((_, i) => i !== idx))}
                        style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', marginTop: 8 }}
                      >
                        ✕
                      </button>
                    )}
                  </div>
                </div>
              </div>
            ))}
            {drops.length < 3 && (
              <button
                type="button"
                className={styles.gpsLink}
                onClick={() => setDrops([...drops, PRESET_LOCATIONS[(drops.length + 2) % PRESET_LOCATIONS.length]])}
              >
                + Add another drop
              </button>
            )}
          </div>

          <div className={styles.section}>
            <span className={styles.sectionLabel}>Goods type</span>
            <select value={goodsCategory} onChange={(e) => setGoodsCategory(e.target.value)} className={styles.select}>
              <option>Furniture</option>
              <option>Electronics</option>
              <option>Boxes</option>
              <option>Appliances</option>
              <option>Other</option>
            </select>
          </div>

          <div className={styles.section}>
            <span className={styles.sectionLabel}>Weight</span>
            <select value={weightBand} onChange={(e) => setWeightBand(e.target.value)} className={styles.select}>
              {WEIGHT_BANDS.map((w) => (
                <option key={w.id} value={w.id}>{w.label}</option>
              ))}
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

          <Button onClick={() => void handleGetQuote()} loading={loading}>
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

      {mapTarget !== null && (
        <MapPicker
          initial={
            mapTarget === 'pickup'
              ? pickup ?? undefined
              : drops[mapTarget as number] ?? undefined
          }
          onConfirm={(loc) => {
            if (mapTarget === 'pickup') setPickup(loc);
            else updateDrop(mapTarget as number, loc);
            setMapTarget(null);
          }}
          onClose={() => setMapTarget(null)}
        />
      )}
    </div>
  );
}
