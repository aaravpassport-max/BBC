import { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { LiveMap } from '../components/LiveMap';
import { getProfile, createAddress } from '../api';
import type { BookingDraft } from '../api/vehicles';
import type { AddressSaveAs, LocationPoint } from '../lib/locations';
import type { BookingFlowState } from '../lib/bookingFlow';
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

  const [unitDetail, setUnitDetail] = useState('');
  const [contactName, setContactName] = useState('');
  const [contactPhone, setContactPhone] = useState('');
  const [saveAs, setSaveAs] = useState<AddressSaveAs>('other');
  const [useMyPhone, setUseMyPhone] = useState(false);
  const [myPhone, setMyPhone] = useState('');
  const [profileName, setProfileName] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const pickup = draft?.pickup;

  useEffect(() => {
    if (!draft?.pickup || !draft.drops?.length) {
      navigate('/home', { replace: true });
    }
  }, [draft, navigate]);

  useEffect(() => {
    getProfile()
      .then((p) => {
        setMyPhone(p.phone);
        if (p.name) setProfileName(p.name);
      })
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    if (!pickup) return;
    setUnitDetail(pickup.unitDetail ?? '');
    setContactName(pickup.contactName ?? profileName);
    setContactPhone(pickup.contactPhone ?? '');
    setSaveAs(pickup.saveAs ?? 'other');
    setUseMyPhone(false);
  }, [pickup?.lat, pickup?.lng, profileName]);

  if (!draft?.pickup || !flow) return null;

  function patchPickup(fields: Partial<LocationPoint>): BookingDraft {
    return { ...draft!, pickup: { ...draft!.pickup, ...fields } };
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

    const updated = patchPickup({
      unitDetail: unitDetail.trim() || undefined,
      contactName: contactName.trim(),
      contactPhone: phone,
      saveAs,
    });

    setSaving(true);
    try {
      const activeDraft = draft!;
      await createAddress({
        label: activeDraft.pickup.label,
        address_line: activeDraft.pickup.addressLine ?? activeDraft.pickup.label,
        lat: activeDraft.pickup.lat,
        lng: activeDraft.pickup.lng,
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
          {error && <p className={styles.error}>{error}</p>}
          <Button loading={saving} onClick={() => void handleConfirm()}>
            {flow.returnTo ? 'Save changes' : 'Continue to drop-off'}
          </Button>
        </>
      }
    >
      <LiveMap pickup={draft.pickup} drops={draft.drops} driver={null} />

      <div className={styles.addressCard}>
        <span className={styles.pickupDot} aria-hidden />
        <div className={styles.addressCopy}>
          <div className={styles.placeName}>{draft.pickup.label}</div>
          <div className={styles.placeSub}>{draft.pickup.addressLine ?? draft.pickup.label}</div>
        </div>
        <button
          type="button"
          className={styles.changeBtn}
          onClick={() => navigate('/book', { state: { serviceId: draft.serviceId, draft } })}
        >
          Change
        </button>
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
    </Screen>
  );
}
