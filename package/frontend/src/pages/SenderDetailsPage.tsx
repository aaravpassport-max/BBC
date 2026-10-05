import { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { LiveMap } from '../components/LiveMap';
import { LocationPicker } from '../components/LocationPicker';
import { MapPicker } from '../components/MapPicker';
import { getProfile, createAddress, listAddresses } from '../api';
import type { SavedAddress } from '../api/profile';
import type { BookingDraft } from '../api/vehicles';
import type { AddressSaveAs, LocationPoint } from '../lib/locations';
import type { BookingFlowState } from '../lib/bookingFlow';
import { swapBookingParties } from '../lib/bookingDraft';
import styles from './DropDetailsPage.module.css';

const SAVE_AS_OPTIONS: { id: AddressSaveAs; label: string; icon: string }[] = [
  { id: 'home', label: 'Home', icon: '🏠' },
  { id: 'shop', label: 'Shop', icon: '🏪' },
  { id: 'other', label: 'Other', icon: '❤️' },
];

function finishAfterSender(
  navigate: ReturnType<typeof useNavigate>,
  flow: BookingFlowState,
  updated: BookingDraft
) {
  if (flow.returnTo === 'confirm' && flow.confirmState) {
    navigate('/confirm', {
      state: { ...flow.confirmState, pickup: updated.pickup, drops: updated.drops },
    });
    return;
  }
  if (flow.returnTo === 'vehicles') {
    navigate('/vehicles', { state: updated });
    return;
  }
  navigate('/book/drop-details', { state: updated });
}

export function SenderDetailsPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const flow = location.state as BookingFlowState | undefined;
  const draft = flow?.draft;

  const [pickupLoc, setPickupLoc] = useState<LocationPoint | null>(draft?.pickup ?? null);
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
    if (!draft?.pickup || !draft.drops?.length) {
      navigate('/home', { replace: true });
    }
  }, [draft, navigate]);

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
        if (!draft?.pickup?.contactPhone) setUseMyPhone(!!p.phone);
      })
      .catch(() => setProfileLoaded(true));
  }, [draft?.pickup?.contactPhone]);

  useEffect(() => {
    if (!draft?.pickup) return;
    setPickupLoc(draft.pickup);
    setUnitDetail(draft.pickup.unitDetail ?? '');
    setContactName(draft.pickup.contactName ?? '');
    setContactPhone(draft.pickup.contactPhone ?? '');
    setSaveAs(draft.pickup.saveAs ?? 'other');
  }, [draft?.pickup?.lat, draft?.pickup?.lng]);

  useEffect(() => {
    if (!profileLoaded || !pickupLoc) return;
    setContactName((prev) => prev || pickupLoc.contactName || profileName);
    if (!pickupLoc.contactPhone && !contactPhone && myPhone) {
      setUseMyPhone(true);
    }
  }, [profileLoaded, profileName, pickupLoc?.lat, myPhone]);

  if (!draft?.pickup || !flow || !pickupLoc) return null;

  function buildUpdatedDraft(): BookingDraft {
    const phone = useMyPhone ? myPhone : contactPhone.trim();
    const activePickup = pickupLoc!;
    return {
      ...draft!,
      pickup: {
        ...activePickup,
        label: activePickup.label,
        lat: activePickup.lat,
        lng: activePickup.lng,
        unitDetail: unitDetail.trim() || undefined,
        contactName: contactName.trim(),
        contactPhone: phone,
        saveAs,
      },
    };
  }

  async function handleConfirm() {
    setError('');
    const phone = useMyPhone ? myPhone : contactPhone.trim();
    if (!contactName.trim()) {
      setError("Enter the sender's name.");
      return;
    }
    if (!phone || phone.length < 10) {
      setError('Enter a valid mobile number.');
      return;
    }

    const updated = buildUpdatedDraft();

    setSaving(true);
    try {
      await createAddress({
        label: updated.pickup.label,
        address_line: updated.pickup.addressLine ?? updated.pickup.label,
        lat: updated.pickup.lat,
        lng: updated.pickup.lng,
        landmark: unitDetail.trim() || null,
        contact_name: contactName.trim(),
        contact_phone: phone,
        is_default: false,
      }).catch(() => undefined);
      finishAfterSender(navigate, flow!, updated);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Screen
      eyebrow="Pickup"
      title="Sender details"
      onBack={() => {
        if (flow.returnTo === 'confirm' && flow.confirmState) {
          navigate('/confirm', { state: flow.confirmState });
          return;
        }
        if (flow.returnTo === 'vehicles') {
          navigate('/vehicles', { state: draft });
          return;
        }
        navigate('/book', { state: { serviceId: draft.serviceId, draft } });
      }}
      footer={
        <>
          <button
            type="button"
            className={styles.switchBtn}
            onClick={() => {
              const swapped = swapBookingParties(draft);
              navigate('/book/sender-details', {
                state: flow.returnTo
                  ? { ...flow, draft: swapped }
                  : { draft: swapped },
              });
            }}
          >
            ⇅ Switch sender & receiver
          </button>
          {error && <p className={styles.error}>{error}</p>}
          <Button loading={saving} onClick={() => void handleConfirm()}>
            {flow.returnTo ? 'Save changes' : 'Continue to drop-off'}
          </Button>
        </>
      }
    >
      <LiveMap pickup={pickupLoc} drops={draft.drops} driver={null} />

      <div className={styles.addressCard}>
        <span className={styles.pickupDot} aria-hidden />
        <div className={styles.addressCopy}>
          <LocationPicker
            value={pickupLoc}
            onChange={(loc) => {
              setPickupLoc(loc);
              if (loc.contactName) setContactName(loc.contactName);
              if (loc.contactPhone) {
                setContactPhone(loc.contactPhone);
                setUseMyPhone(false);
              }
              if (loc.unitDetail) setUnitDetail(loc.unitDetail);
            }}
            savedAddresses={savedAddresses}
            onPickOnMap={() => setShowMapPicker(true)}
            placeholder="Search pickup address"
          />
        </div>
      </div>

      <label className={styles.field}>
        <span className={styles.label}>House / Apartment / Shop (optional)</span>
        <input
          className={styles.input}
          value={unitDetail}
          onChange={(e) => setUnitDetail(e.target.value)}
          placeholder="Floor, unit, shop name"
        />
      </label>

      <label className={styles.field}>
        <span className={styles.label}>Sender&apos;s name</span>
        <input
          className={styles.input}
          value={contactName}
          onChange={(e) => setContactName(e.target.value)}
          placeholder="Who is sending the goods?"
        />
      </label>

      <label className={styles.field}>
        <span className={styles.label}>Sender&apos;s mobile number</span>
        <input
          className={styles.input}
          type="tel"
          value={useMyPhone ? myPhone : contactPhone}
          onChange={(e) => setContactPhone(e.target.value.replace(/\D/g, '').slice(0, 10))}
          disabled={useMyPhone}
          placeholder="10-digit mobile"
        />
      </label>

      {myPhone && (
        <label className={styles.checkboxRow}>
          <input type="checkbox" checked={useMyPhone} onChange={(e) => setUseMyPhone(e.target.checked)} />
          Use my mobile number: {myPhone}
        </label>
      )}

      <div className={styles.saveAs}>
        <span className={styles.label}>Save as (optional)</span>
        <div className={styles.chips}>
          {SAVE_AS_OPTIONS.map((opt) => (
            <button
              key={opt.id}
              type="button"
              className={`${styles.chip} ${saveAs === opt.id ? styles.chipActive : ''}`}
              onClick={() => setSaveAs(opt.id)}
            >
              <span aria-hidden>{opt.icon}</span> {opt.label}
            </button>
          ))}
        </div>
      </div>

      {showMapPicker && (
        <MapPicker
          initial={pickupLoc}
          onConfirm={(loc) => {
            setPickupLoc((prev) => {
              if (!prev) return loc;
              return {
                ...loc,
                contactName: prev.contactName,
                contactPhone: prev.contactPhone,
                unitDetail: prev.unitDetail,
                saveAs: prev.saveAs,
              };
            });
            setShowMapPicker(false);
          }}
          onClose={() => setShowMapPicker(false)}
        />
      )}
    </Screen>
  );
}
