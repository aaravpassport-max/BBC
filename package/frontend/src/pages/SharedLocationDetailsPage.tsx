import { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { LiveMap } from '../components/LiveMap';
import { LocationPicker } from '../components/LocationPicker';
import { MapPicker } from '../components/MapPicker';
import { getProfile, createAddress, listAddresses } from '../api';
import type { SavedAddress } from '../api/profile';
import { PRESET_LOCATIONS, type AddressSaveAs, type LocationPoint } from '../lib/locations';
import { serviceDefaults, serviceToVehicleGroup } from '../constants/vehicleCatalog';
import type { BookingDraft } from '../api/vehicles';
import type { SharedLocationNavState } from '../lib/bookingFlow';
import detailStyles from './DropDetailsPage.module.css';
import styles from './SharedLocationRolePage.module.css';

const SAVE_AS_OPTIONS: { id: AddressSaveAs; label: string; icon: string }[] = [
  { id: 'home', label: 'Home', icon: '🏠' },
  { id: 'shop', label: 'Shop', icon: '🏪' },
  { id: 'other', label: 'Other', icon: '❤️' },
];

export function SharedLocationDetailsPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const nav = location.state as SharedLocationNavState | undefined;

  const role = nav?.role ?? 'drop';
  const isPickup = role === 'pickup';

  const [loc, setLoc] = useState<LocationPoint | null>(nav?.point ?? null);
  const [savedAddresses, setSavedAddresses] = useState<SavedAddress[]>([]);
  const [showMapPicker, setShowMapPicker] = useState(false);
  const [unitDetail, setUnitDetail] = useState('');
  const [contactName, setContactName] = useState('');
  const [contactPhone, setContactPhone] = useState('');
  const [saveAs, setSaveAs] = useState<AddressSaveAs>('other');
  const [useMyPhone, setUseMyPhone] = useState(false);
  const [myPhone, setMyPhone] = useState('');
  const [profileName, setProfileName] = useState('');
  const [profileLoaded, setProfileLoaded] = useState(false);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!nav?.point || !nav.role) {
      navigate('/home', { replace: true });
      return;
    }
    setLoc(nav.point);
    setUnitDetail(nav.point.unitDetail ?? '');
    setContactName(nav.point.contactName ?? '');
    setContactPhone(nav.point.contactPhone ?? '');
    setSaveAs(nav.point.saveAs ?? 'other');
  }, [nav, navigate]);

  useEffect(() => {
    listAddresses()
      .then(setSavedAddresses)
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    getProfile()
      .then((p) => {
        setMyPhone(p.phone);
        if (p.name) setProfileName(p.name);
        setProfileLoaded(true);
        if (isPickup) {
          setContactName((prev) => prev || p.name || '');
          if (!nav?.point?.contactPhone) setUseMyPhone(!!p.phone);
        }
      })
      .catch(() => setProfileLoaded(true));
  }, [isPickup, nav?.point?.contactPhone]);

  useEffect(() => {
    if (!profileLoaded || !isPickup) return;
    setContactName((prev) => prev || profileName);
  }, [profileLoaded, profileName, isPickup]);

  if (!loc || !nav?.role) return null;

  async function handleContinue() {
    if (!loc) return;
    setError('');
    const phone = useMyPhone ? myPhone : contactPhone.trim();
    if (!contactName.trim()) {
      setError(isPickup ? "Enter the sender's name." : "Enter the receiver's name.");
      return;
    }
    if (!phone || phone.length < 10) {
      setError('Enter a valid mobile number.');
      return;
    }

    const enriched: LocationPoint = {
      ...loc,
      label: loc.label,
      lat: loc.lat,
      lng: loc.lng,
      unitDetail: unitDetail.trim() || undefined,
      contactName: contactName.trim(),
      contactPhone: phone,
      saveAs,
    };

    setSaving(true);
    try {
      await createAddress({
        label: enriched.label,
        address_line: enriched.addressLine ?? enriched.label,
        lat: enriched.lat,
        lng: enriched.lng,
        landmark: unitDetail.trim() || null,
        contact_name: contactName.trim(),
        contact_phone: phone,
        is_default: false,
      }).catch(() => undefined);

      const defaults = serviceDefaults('two_wheeler');
      const draft: BookingDraft = isPickup
        ? {
            serviceId: 'two_wheeler',
            vehicleGroup: serviceToVehicleGroup('two_wheeler'),
            pickup: enriched,
            drops: [PRESET_LOCATIONS[1]],
            ...defaults,
          }
        : {
            serviceId: 'two_wheeler',
            vehicleGroup: serviceToVehicleGroup('two_wheeler'),
            pickup: PRESET_LOCATIONS[0],
            drops: [enriched],
            ...defaults,
          };

      navigate('/book', {
        state: {
          serviceId: 'two_wheeler',
          draft,
          deepLinkFilled: role,
        },
      });
    } finally {
      setSaving(false);
    }
  }

  const mapPickup = isPickup ? loc : null;
  const mapDrops = isPickup ? [] : [loc];

  return (
    <Screen
      eyebrow={isPickup ? 'Pickup' : 'Drop-off'}
      title={isPickup ? 'Sender details' : 'Receiver details'}
      onBack={() => navigate('/book/from-link', { state: { point: loc } })}
      footer={
        <>
          {error && <p className={detailStyles.error}>{error}</p>}
          <Button loading={saving} onClick={() => void handleContinue()}>
            {isPickup ? 'Continue to drop location' : 'Continue to pickup location'}
          </Button>
        </>
      }
    >
      <div className={styles.mapSection}>
        <LiveMap pickup={mapPickup} drops={mapDrops} driver={null} />
        <div className={styles.mapHint}>
          {isPickup ? 'Your goods will be picked up here' : 'Your goods will be dropped here'}
        </div>
      </div>

      <div className={detailStyles.addressCard}>
        <span className={isPickup ? detailStyles.pickupDot : detailStyles.dropDot} aria-hidden />
        <div className={detailStyles.addressCopy}>
          <LocationPicker
            value={loc}
            onChange={setLoc}
            savedAddresses={savedAddresses}
            onPickOnMap={() => setShowMapPicker(true)}
            placeholder="Search or change address"
          />
        </div>
      </div>

      <label className={detailStyles.field}>
        <span className={detailStyles.label}>House / Apartment / Shop (optional)</span>
        <input
          className={detailStyles.input}
          value={unitDetail}
          onChange={(e) => setUnitDetail(e.target.value)}
          placeholder="Floor, unit, shop name"
        />
      </label>

      <label className={detailStyles.field}>
        <span className={detailStyles.label}>{isPickup ? "Sender's name" : "Receiver's name"}</span>
        <input
          className={detailStyles.input}
          value={contactName}
          onChange={(e) => setContactName(e.target.value)}
          placeholder={isPickup ? 'Who is sending the goods?' : 'Who will receive the goods?'}
        />
      </label>

      <label className={detailStyles.field}>
        <span className={detailStyles.label}>{isPickup ? "Sender's mobile number" : "Receiver's mobile number"}</span>
        <input
          className={detailStyles.input}
          type="tel"
          value={useMyPhone ? myPhone : contactPhone}
          onChange={(e) => setContactPhone(e.target.value.replace(/\D/g, '').slice(0, 10))}
          disabled={useMyPhone}
          placeholder="10-digit mobile"
        />
      </label>

      {myPhone && (
        <label className={detailStyles.checkboxRow}>
          <input type="checkbox" checked={useMyPhone} onChange={(e) => setUseMyPhone(e.target.checked)} />
          Use my mobile number: {myPhone}
        </label>
      )}

      <div className={detailStyles.saveAs}>
        <span className={detailStyles.label}>Save as (optional)</span>
        <div className={detailStyles.chips}>
          {SAVE_AS_OPTIONS.map((opt) => (
            <button
              key={opt.id}
              type="button"
              className={`${detailStyles.chip} ${saveAs === opt.id ? detailStyles.chipActive : ''}`}
              onClick={() => setSaveAs(opt.id)}
            >
              <span aria-hidden>{opt.icon}</span> {opt.label}
            </button>
          ))}
        </div>
      </div>

      {showMapPicker && (
        <MapPicker
          initial={loc}
          onConfirm={(picked) => {
            setLoc((prev) => ({ ...picked, contactName: prev?.contactName, contactPhone: prev?.contactPhone }));
            setShowMapPicker(false);
          }}
          onClose={() => setShowMapPicker(false)}
        />
      )}
    </Screen>
  );
}
