import { useState, useEffect } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Geolocation } from '@capacitor/geolocation';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { LocationPicker } from '../components/LocationPicker';
import { MapPicker } from '../components/MapPicker';
import { LiveMap } from '../components/LiveMap';
import { listAddresses, getLoyaltySummary } from '../api';
import { checkServiceability } from '../api/features';
import { PRESET_LOCATIONS, sameLocation, type LocationPoint } from '../lib/locations';
import type { SavedAddress } from '../api/profile';
import type { BookingDraft } from '../api/vehicles';
import { HOME_SERVICE_TILES, type VehicleGroupId } from '../constants/vehicleCatalog';
import styles from './BookingLocationPage.module.css';

const WEIGHT_BANDS = [
  { id: 'light', label: 'Light (up to 20 kg)' },
  { id: 'medium', label: 'Medium (20–100 kg)' },
  { id: 'heavy', label: 'Heavy (100–500 kg)' },
  { id: 'bulk', label: 'Bulk (500+ kg)' },
];

interface BookingLocationState {
  vehicleGroup: VehicleGroupId;
}

function serviceLabel(group: VehicleGroupId): string {
  return HOME_SERVICE_TILES.find((t) => t.id === group)?.label ?? group.replace(/_/g, ' ');
}

export function BookingLocationPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const vehicleGroup = (location.state as BookingLocationState | undefined)?.vehicleGroup;

  const [pickup, setPickup] = useState<LocationPoint | null>(PRESET_LOCATIONS[0]);
  const [drops, setDrops] = useState<LocationPoint[]>([PRESET_LOCATIONS[1]]);
  const [savedAddresses, setSavedAddresses] = useState<SavedAddress[]>([]);
  const [mapTarget, setMapTarget] = useState<'pickup' | number | null>(null);
  const [goodsCategory, setGoodsCategory] = useState('Furniture');
  const [weightBand, setWeightBand] = useState('medium');
  const [helperNeeded, setHelperNeeded] = useState(false);
  const [error, setError] = useState('');
  const [scheduledFor, setScheduledFor] = useState('');
  const [couponCode, setCouponCode] = useState('');
  const [loyaltyBalance, setLoyaltyBalance] = useState(0);
  const [loyaltyToRedeem, setLoyaltyToRedeem] = useState(0);
  const [serviceable, setServiceable] = useState<boolean | null>(null);
  const [serviceCity, setServiceCity] = useState<string | null>(null);

  const minScheduleValue = new Date(Date.now() + 35 * 60 * 1000).toISOString().slice(0, 16);
  const maxScheduleValue = new Date(Date.now() + 6.5 * 24 * 60 * 60 * 1000).toISOString().slice(0, 16);

  useEffect(() => {
    if (!vehicleGroup) {
      navigate('/home', { replace: true });
    }
  }, [vehicleGroup, navigate]);

  useEffect(() => {
    listAddresses()
      .then(setSavedAddresses)
      .catch(() => undefined);
    getLoyaltySummary()
      .then((s) => setLoyaltyBalance(s.balance))
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    void Geolocation.requestPermissions().catch(() => undefined);
    void Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 10000 })
      .then((pos) => {
        const loc: LocationPoint = {
          label: 'Current location',
          lat: pos.coords.latitude,
          lng: pos.coords.longitude,
        };
        setPickup(loc);
      })
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    if (!pickup) return;
    checkServiceability(pickup.lat, pickup.lng)
      .then((r) => {
        setServiceable(r.serviceable);
        setServiceCity(r.city_name ?? null);
      })
      .catch(() => setServiceable(null));
  }, [pickup?.lat, pickup?.lng]);

  if (!vehicleGroup) return null;

  function handleContinueToVehicles() {
    setError('');
    if (!pickup) {
      setError('Select a pickup location.');
      return;
    }
    if (drops.some((d) => sameLocation(pickup, d))) {
      setError('Pickup and drop cannot be the same.');
      return;
    }
    if (serviceable === false) {
      setError('Pickup is outside our service area. Try a different location.');
      return;
    }

    const draft: BookingDraft = {
      vehicleGroup: vehicleGroup!,
      pickup,
      drops,
      goodsCategory,
      weightBand,
      helperNeeded,
      couponCode: couponCode.trim() || undefined,
      loyaltyToRedeem: loyaltyToRedeem > 0 ? loyaltyToRedeem : undefined,
      scheduledFor: scheduledFor ? new Date(scheduledFor).toISOString() : undefined,
    };
    navigate('/vehicles', { state: draft });
  }

  function updateDrop(index: number, loc: LocationPoint) {
    setDrops((prev) => prev.map((d, i) => (i === index ? loc : d)));
  }

  return (
    <Screen
      eyebrow={serviceLabel(vehicleGroup)}
      title="Pickup & drop"
      onBack={() => navigate('/home')}
      footer={
        <>
          {error && <p className={styles.error}>{error}</p>}
          <Button onClick={() => void handleContinueToVehicles()}>Choose vehicle & get fare</Button>
        </>
      }
    >
      {serviceable === false && (
        <p className={styles.warning}>This pickup location is outside our service area.</p>
      )}
      {serviceable && serviceCity && (
        <p className={styles.success}>✓ Serviceable in {serviceCity}</p>
      )}

      {pickup && drops.length > 0 && <LiveMap pickup={pickup} drops={drops} driver={null} />}

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
              <div className={styles.dropRow}>
                <div className={styles.dropField}>
                  <LocationPicker
                    value={drop}
                    onChange={(loc) => updateDrop(idx, loc)}
                    savedAddresses={savedAddresses}
                    onPickOnMap={() => setMapTarget(idx)}
                    searchBias={pickup ? { lat: pickup.lat, lng: pickup.lng } : undefined}
                    placeholder="Where is your drop?"
                  />
                </div>
                {drops.length > 1 && (
                  <button
                    type="button"
                    className={styles.removeDrop}
                    onClick={() => setDrops(drops.filter((_, i) => i !== idx))}
                    aria-label="Remove drop"
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
            className={styles.addDrop}
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
            <option key={w.id} value={w.id}>
              {w.label}
            </option>
          ))}
        </select>
      </div>

      <label className={styles.checkboxRow}>
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

      {loyaltyBalance > 0 && (
        <div className={styles.section}>
          <span className={styles.sectionLabel}>
            Loyalty points ({loyaltyBalance} available · 10 pts = ₹1)
          </span>
          <input
            type="number"
            min={0}
            max={loyaltyBalance}
            step={10}
            value={loyaltyToRedeem || ''}
            onChange={(e) =>
              setLoyaltyToRedeem(Math.min(loyaltyBalance, Math.max(0, parseInt(e.target.value, 10) || 0)))
            }
            placeholder="Points to redeem"
            className={styles.select}
          />
        </div>
      )}

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

      {mapTarget !== null && (
        <MapPicker
          initial={mapTarget === 'pickup' ? pickup ?? undefined : drops[mapTarget as number] ?? undefined}
          onConfirm={(loc) => {
            if (mapTarget === 'pickup') setPickup(loc);
            else updateDrop(mapTarget as number, loc);
            setMapTarget(null);
          }}
          onClose={() => setMapTarget(null)}
        />
      )}
    </Screen>
  );
}
