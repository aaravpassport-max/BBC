import { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { LiveMap } from '../components/LiveMap';
import { getProfile, createAddress } from '../api';
import type { BookingDraft } from '../api/vehicles';
import type { AddressSaveAs, LocationPoint } from '../lib/locations';
import styles from './DropDetailsPage.module.css';

const SAVE_AS_OPTIONS: { id: AddressSaveAs; label: string; icon: string }[] = [
  { id: 'home', label: 'Home', icon: '🏠' },
  { id: 'shop', label: 'Shop', icon: '🏪' },
  { id: 'other', label: 'Other', icon: '❤️' },
];

export function DropDetailsPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const incoming = location.state as BookingDraft | undefined;

  const [draft, setDraft] = useState<BookingDraft | null>(incoming ?? null);
  const [dropIndex, setDropIndex] = useState(0);
  const [unitDetail, setUnitDetail] = useState('');
  const [contactName, setContactName] = useState('');
  const [contactPhone, setContactPhone] = useState('');
  const [saveAs, setSaveAs] = useState<AddressSaveAs>('other');
  const [useMyPhone, setUseMyPhone] = useState(false);
  const [myPhone, setMyPhone] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!incoming?.pickup || !incoming.drops?.length) {
      navigate('/home', { replace: true });
      return;
    }
    setDraft(incoming);
  }, [incoming, navigate]);

  useEffect(() => {
    getProfile()
      .then((p) => setMyPhone(p.phone))
      .catch(() => undefined);
  }, []);

  const drop = draft?.drops[dropIndex];

  useEffect(() => {
    if (!drop) return;
    setUnitDetail(drop.unitDetail ?? '');
    setContactName(drop.contactName ?? '');
    setContactPhone(drop.contactPhone ?? '');
    setSaveAs(drop.saveAs ?? 'other');
    setUseMyPhone(false);
  }, [drop?.lat, drop?.lng, dropIndex]);

  if (!draft?.pickup || !drop) return null;

  function patchDrop(fields: Partial<LocationPoint>): BookingDraft {
    const drops = draft!.drops.map((d, i) => (i === dropIndex ? { ...d, ...fields } : d));
    return { ...draft!, drops };
  }

  async function handleConfirm() {
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
      unitDetail: unitDetail.trim() || undefined,
      contactName: contactName.trim(),
      contactPhone: phone,
      saveAs,
    });

    if (dropIndex < updated.drops.length - 1) {
      setDraft(updated);
      setDropIndex((i) => i + 1);
      return;
    }

    const activeDrop = drop;
    if (!activeDrop) return;

    setSaving(true);
    try {
      await createAddress({
        label: activeDrop.label,
        address_line: activeDrop.addressLine ?? activeDrop.label,
        lat: activeDrop.lat,
        lng: activeDrop.lng,
        landmark: unitDetail.trim() || null,
        contact_name: contactName.trim(),
        contact_phone: phone,
        is_default: false,
      }).catch(() => undefined);
      navigate('/vehicles', { state: updated });
    } finally {
      setSaving(false);
    }
  }

  return (
    <Screen
      eyebrow={draft.drops.length > 1 ? `Drop ${dropIndex + 1} of ${draft.drops.length}` : 'Drop-off'}
      title="Drop-off details"
      onBack={() => {
        if (dropIndex > 0) setDropIndex((i) => i - 1);
        else navigate('/book', { state: { serviceId: draft.serviceId } });
      }}
      footer={
        <>
          {error && <p className={styles.error}>{error}</p>}
          <Button loading={saving} onClick={() => void handleConfirm()}>
            {dropIndex < draft.drops.length - 1 ? 'Next drop' : 'Confirm and proceed'}
          </Button>
        </>
      }
    >
      <LiveMap pickup={draft.pickup} drops={[drop]} driver={null} />

      <div className={styles.addressCard}>
        <span className={styles.dropDot} aria-hidden />
        <div className={styles.addressCopy}>
          <div className={styles.placeName}>{drop.label}</div>
          <div className={styles.placeSub}>{drop.addressLine ?? drop.label}</div>
        </div>
        <button
          type="button"
          className={styles.changeBtn}
          onClick={() => navigate('/book', { state: { serviceId: draft.serviceId } })}
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
          <input
            type="checkbox"
            checked={useMyPhone}
            onChange={(e) => setUseMyPhone(e.target.checked)}
          />
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
