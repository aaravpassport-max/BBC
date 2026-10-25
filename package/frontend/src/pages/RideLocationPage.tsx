import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Geolocation } from '@capacitor/geolocation';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { LocationPicker } from '../components/LocationPicker';
import { MapPicker } from '../components/MapPicker';
import { LiveMap } from '../components/LiveMap';
import { getProfile } from '../api';
import { checkServiceability } from '../api/features';
import { swapBookingParties } from '../lib/bookingDraft';
import { PRESET_LOCATIONS, sameLocation, type LocationPoint } from '../lib/locations';
import type { BookingDraft } from '../api/vehicles';
import { serviceDefaults } from '../constants/vehicleCatalog';
import styles from './BookingLocationPage.module.css';

export function RideLocationPage() {
  const navigate = useNavigate();
  const defaults = serviceDefaults('ride');

  const [pickup, setPickup] = useState<LocationPoint | null>(PRESET_LOCATIONS[0]);
  const [drop, setDrop] = useState<LocationPoint | null>(PRESET_LOCATIONS[1]);
  const [passengerCount, setPassengerCount] = useState(1);
  const [mapTarget, setMapTarget] = useState<'pickup' | 'drop' | null>(null);
  const [error, setError] = useState('');
  const [serviceable, setServiceable] = useState<boolean | null>(null);
  const [serviceCity, setServiceCity] = useState<string | null>(null);

  useEffect(() => {
    void Geolocation.requestPermissions().catch(() => undefined);
    void Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 10000 })
      .then((pos) => {
        const loc: LocationPoint = {
          label: 'Current location',
          lat: pos.coords.latitude,
          lng: pos.coords.longitude,
        };
        setPickup((prev) => (prev?.label === PRESET_LOCATIONS[0].label ? loc : prev));
      })
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    getProfile()
      .then((p) => {
        setPickup((prev) => {
          if (!prev || prev.contactName) return prev;
          return {
            ...prev,
            contactName: p.name ?? undefined,
            contactPhone: p.phone?.replace(/^\+91/, '') ?? undefined,
          };
        });
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

  function swapLocations() {
    if (!pickup || !drop) return;
    const draft: BookingDraft = {
      bookingType: 'ride',
      serviceId: 'ride',
      vehicleGroup: 'ride',
      pickup: drop,
      drops: [pickup],
      goodsCategory: '',
      weightBand: 'light',
      helperNeeded: false,
      passengerCount,
    };
    const swapped = swapBookingParties(draft);
    setPickup(swapped.pickup);
    setDrop(swapped.drops[0] ?? null);
  }

  function handleContinue() {
    setError('');
    if (!pickup || !drop) {
      setError('Enter pickup and drop locations.');
      return;
    }
    if (sameLocation(pickup, drop)) {
      setError('Pickup and drop must be different locations.');
      return;
    }
    if (serviceable === false) {
      setError('Pickup is outside our service area.');
      return;
    }

    const draft: BookingDraft = {
      bookingType: 'ride',
      serviceId: 'ride',
      vehicleGroup: 'ride',
      pickup,
      drops: [drop],
      goodsCategory: defaults.goodsCategory,
      weightBand: defaults.weightBand,
      helperNeeded: false,
      passengerCount,
    };
    navigate('/vehicles', { state: draft });
  }

  return (
    <Screen eyebrow="Ride" title="Where to?" onBack={() => navigate('/home')}>
      <div className={styles.locationBlock}>
        <div className={styles.fieldLabel}>Pickup</div>
        <LocationPicker
          value={pickup}
          onChange={setPickup}
          placeholder="Where from?"
          onPickOnMap={() => setMapTarget('pickup')}
        />
        <button type="button" className={styles.swapBtn} onClick={swapLocations} aria-label="Switch pickup and drop">
          ⇅
        </button>
        <div className={styles.fieldLabel}>Drop</div>
        <LocationPicker
          value={drop}
          onChange={setDrop}
          placeholder="Where to?"
          onPickOnMap={() => setMapTarget('drop')}
        />
      </div>

      {pickup && drop && <LiveMap pickup={pickup} drops={[drop]} driver={null} />}

      <label className={styles.fieldLabel}>
        Passengers
        <select
          value={passengerCount}
          onChange={(e) => setPassengerCount(Number(e.target.value))}
          className={styles.select}
        >
          {[1, 2, 3, 4, 5, 6].map((n) => (
            <option key={n} value={n}>
              {n}
            </option>
          ))}
        </select>
      </label>

      {serviceCity && serviceable && (
        <p className={styles.serviceHint}>Service available in {serviceCity}</p>
      )}
      {error && <p className={styles.error}>{error}</p>}

      <Button onClick={handleContinue}>Choose vehicle</Button>

      {mapTarget && (mapTarget === 'pickup' ? pickup : drop) && (
        <MapPicker
          initial={(mapTarget === 'pickup' ? pickup : drop)!}
          onConfirm={(loc) => {
            if (mapTarget === 'pickup') setPickup(loc);
            else setDrop(loc);
            setMapTarget(null);
          }}
          onClose={() => setMapTarget(null)}
        />
      )}
    </Screen>
  );
}
