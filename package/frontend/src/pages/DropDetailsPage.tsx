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

function finishAfterDrop(
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
  navigate('/vehicles', { state: updated });
}

export function DropDetailsPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const incoming = location.state as BookingFlowState | BookingDraft | undefined;

  const flow: BookingFlowState | null =
    incoming && 'draft' in incoming ? incoming : incoming ? { draft: incoming } : null;

  const [draft, setDraft] = useState<BookingDraft | null>(flow?.draft ?? null);
  const [dropIndex, setDropIndex] = useState(flow?.dropIndex ?? 0);
  const [dropLoc, setDropLoc] = useState<LocationPoint | null>(null);
  const [savedAddresses, setSavedAddresses] = useState<SavedAddress[]>([]);
  const [showMapPicker, setShowMapPicker] = useState(false);
  const [unitDetail, setUnitDetail] = useState('');
  const [contactName, setContactName] = useState('');
  const [contactPhone, setContactPhone] = useState('');
  const [saveAs, setSaveAs] = useState<AddressSaveAs>('other');
  const [useMyPhone, setUseMyPhone] = useState(false);
  const [myPhone, setMyPhone] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!flow?.draft?.pickup || !flow.draft.drops?.length) {
      navigate('/home', { replace: true });
      return;
    }
    setDraft(flow.draft);
    if (flow.dropIndex != null) setDropIndex(flow.dropIndex);
  }, [flow, navigate]);

  useEffect(() => {
    listAddresses()
      .then(setSavedAddresses)
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    getProfile()
      .then((p) => setMyPhone(p.phone))
      .catch(() => undefined);
  }, []);

  const drop = draft?.drops[dropIndex];

  useEffect(() => {
    if (!drop) return;
    setDropLoc(drop);
    setUnitDetail(drop.unitDetail ?? '');
    setContactName(drop.contactName ?? '');
    setContactPhone(drop.contactPhone ?? '');
    setSaveAs(drop.saveAs ?? 'other');
    setUseMyPhone(false);
  }, [drop?.lat, drop?.lng, dropIndex]);

  if (!draft?.pickup || !drop || !flow || !dropLoc) return null;

  function patchDrop(fields: Partial<LocationPoint>): BookingDraft {
    const drops = draft!.drops.map((d, i) => (i === dropIndex ? { ...d, ...fields } : d));
    return { ...draft!, drops };
  }

  async function handleConfirm() {
    const activeFlow = flow!;
    setError('');
    const phone = useMyPhone ? myPhone : contactPhone.trim();
    if (!contactName.trim()) {
      setError("Enter the receiver's name.");
      return;
    }
    if (!phone || phone.length < 10) {
      setError('Enter a valid mobile number.');
      return;
    }

    const updated = patchDrop({
      ...dropLoc!,
      unitDetail: unitDetail.trim() || undefined,
      contactName: contactName.trim(),
      contactPhone: phone,
      saveAs,
    });

    if (!activeFlow.returnTo && dropIndex < updated.drops.length - 1) {
      setDraft(updated);
      setDropIndex((i) => i + 1);
      return;
    }

    setSaving(true);
    try {
      await createAddress({
        label: dropLoc!.label,
        address_line: dropLoc!.addressLine ?? dropLoc!.label,
        lat: dropLoc!.lat,
        lng: dropLoc!.lng,
        landmark: unitDetail.trim() || null,
        contact_name: contactName.trim(),
        contact_phone: phone,
        is_default: false,
      }).catch(() => undefined);
      finishAfterDrop(navigate, activeFlow, updated);
    } finally {
      setSaving(false);
    }
  }

  function handleBack() {
    const activeFlow = flow!;
    if (activeFlow.returnTo === 'confirm' && activeFlow.confirmState) {
      navigate('/confirm', { state: activeFlow.confirmState });
      return;
    }
    if (activeFlow.returnTo === 'vehicles') {
      navigate('/vehicles', { state: draft });
      return;
    }
    if (dropIndex > 0) {
      setDropIndex((i) => i - 1);
      return;
    }
    navigate('/book/sender-details', { state: { draft } });
  }

  const saveLabel = flow.returnTo
    ? 'Save changes'
    : dropIndex < draft.drops.length - 1
      ? 'Next drop'
      : 'Confirm and proceed';

  return (
    <Screen
      eyebrow={draft.drops.length > 1 ? `Drop ${dropIndex + 1} of ${draft.drops.length}` : 'Drop-off'}
      title="Receiver details"
      onBack={handleBack}
      footer={
        <>
          <button
            type="button"
            className={styles.switchBtn}
            onClick={() => {
              const swapped = swapBookingParties(draft);
              navigate('/book/drop-details', {
                state: flow.returnTo
                  ? { ...flow, draft: swapped, dropIndex: 0 }
                  : { draft: swapped, dropIndex: 0 },
              });
            }}
          >
            ⇅ Switch sender & receiver
          </button>
          {error && <p className={styles.error}>{error}</p>}
          <Button loading={saving} onClick={() => void handleConfirm()}>
            {saveLabel}
          </Button>
        </>
      }
    >
      <LiveMap pickup={draft.pickup} drops={[dropLoc]} driver={null} />

      <div className={styles.addressCard}>
        <span className={styles.dropDot} aria-hidden />
        <div className={styles.addressCopy}>
          <LocationPicker
            value={dropLoc}
            onChange={(loc) => {
              setDropLoc(loc);
              if (loc.contactName) setContactName(loc.contactName);
              if (loc.contactPhone) {
                setContactPhone(loc.contactPhone);
                setUseMyPhone(false);
              }
              if (loc.unitDetail) setUnitDetail(loc.unitDetail);
            }}
            savedAddresses={savedAddresses}
            onPickOnMap={() => setShowMapPicker(true)}
            placeholder="Search drop address"
            searchBias={{ lat: draft.pickup.lat, lng: draft.pickup.lng }}
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
        <span className={styles.label}>Receiver&apos;s name</span>
        <input
          className={styles.input}
          value={contactName}
          onChange={(e) => setContactName(e.target.value)}
          placeholder="Who will receive the goods?"
        />
      </label>

      <label className={styles.field}>
        <span className={styles.label}>Receiver&apos;s mobile number</span>
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
          initial={dropLoc}
          onConfirm={(loc) => {
            setDropLoc((prev) => {
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
