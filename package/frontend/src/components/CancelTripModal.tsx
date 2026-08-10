import { useState, useEffect } from 'react';
import { Button } from './Button';
import { previewCancellation } from '../api';
import { CANCEL_REASONS, type CancelReasonCode } from '../constants/porter';
import styles from './CancelTripModal.module.css';

export function CancelTripModal({
  open,
  onClose,
  onConfirm,
  loading,
  bookingId,
}: {
  open: boolean;
  onClose: () => void;
  onConfirm: (reason: CancelReasonCode, note?: string) => Promise<{ fee_charged: boolean; fee_amount: number }>;
  loading?: boolean;
  bookingId?: string;
}) {
  const [reason, setReason] = useState<CancelReasonCode>('BOOKED_BY_MISTAKE');
  const [note, setNote] = useState('');
  const [cancelResult, setCancelResult] = useState<{ fee_charged: boolean; fee_amount: number } | null>(null);
  const [livePreview, setLivePreview] = useState<{ fee_charged: boolean; fee_amount: number } | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (open && bookingId) {
      previewCancellation(bookingId)
        .then((p) => setLivePreview({ fee_charged: p.fee_charged, fee_amount: p.fee_amount }))
        .catch(() => setLivePreview(null));
    }
  }, [open, bookingId]);

  if (!open) return null;

  async function handleConfirm() {
    setError('');
    try {
      const result = await onConfirm(reason, note || undefined);
      setCancelResult(result);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not cancel trip.');
    }
  }

  function handleClose() {
    setCancelResult(null);
    setLivePreview(null);
    setNote('');
    setReason('BOOKED_BY_MISTAKE');
    setError('');
    onClose();
  }

  const donePreview = cancelResult;

  return (
    <div className={styles.overlay} role="dialog" aria-modal="true" aria-labelledby="cancel-title">
      <div className={styles.sheet}>
        <h2 id="cancel-title" className={styles.title}>
          {donePreview ? 'Trip cancelled' : 'Cancel trip?'}
        </h2>

        {donePreview ? (
          <>
            <p className={styles.body}>
              {donePreview.fee_charged
                ? `A cancellation fee of ₹${donePreview.fee_amount.toFixed(2)} has been charged.`
                : 'Your trip was cancelled with no fee.'}
            </p>
            <Button onClick={handleClose}>Done</Button>
          </>
        ) : (
          <>
            <p className={styles.body}>Please tell us why you&apos;re cancelling.</p>
            {livePreview && livePreview.fee_charged && (
              <p className={styles.body} style={{ color: 'var(--danger)', fontWeight: 600 }}>
                Cancellation fee: ₹{livePreview.fee_amount.toFixed(2)}
              </p>
            )}
            <div className={styles.reasons}>
              {CANCEL_REASONS.map((r) => (
                <label key={r.code} className={styles.reason}>
                  <input
                    type="radio"
                    name="cancel-reason"
                    value={r.code}
                    checked={reason === r.code}
                    onChange={() => setReason(r.code)}
                  />
                  {r.label}
                </label>
              ))}
            </div>
            {reason === 'OTHER' && (
              <textarea
                className={styles.note}
                placeholder="Tell us more (optional)"
                value={note}
                onChange={(e) => setNote(e.target.value)}
                maxLength={300}
              />
            )}
            {error && <p className={styles.error}>{error}</p>}
            <div className={styles.actions}>
              <Button variant="ghost" onClick={handleClose} disabled={loading}>
                Keep trip
              </Button>
              <Button variant="danger" onClick={() => void handleConfirm()} loading={loading}>
                Cancel trip
              </Button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
