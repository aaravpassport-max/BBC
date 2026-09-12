import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { getBooking, getProfile, downloadInvoicePdf, getErrorMessage, type Booking } from '../api';
import { BRAND } from '../constants/brand';
import { Skeleton } from '../components/Skeleton';

function money(n: number): string {
  return `₹${n.toFixed(2)}`;
}

/**
 * A real digital receipt (P2 gap-analysis item) — previously the app only
 * showed an inline fare summary on the tracking screen itself, with no
 * standalone, shareable, downloadable document. This is a genuinely
 * printable invoice: "Print / Save as PDF" uses the browser's own native
 * print pipeline (window.print(), with print-specific CSS below) rather
 * than pulling in a client-side PDF-generation library — every browser,
 * on every platform including the Android app's WebView, already has a
 * real "Save as PDF" option in its print dialog, so this works everywhere
 * with zero new dependencies.
 */
export function ReceiptPage() {
  const { bookingId } = useParams<{ bookingId: string }>();
  const navigate = useNavigate();
  const [booking, setBooking] = useState<Booking | null>(null);
  const [gstin, setGstin] = useState<string | null>(null);
  const [businessName, setBusinessName] = useState<string | null>(null);
  const [error, setError] = useState('');
  const [downloading, setDownloading] = useState(false);

  useEffect(() => {
    if (!bookingId) return;
    Promise.all([getBooking(bookingId), getProfile()])
      .then(([b, profile]) => {
        setBooking(b);
        setGstin(profile.gstin);
        setBusinessName(profile.business_name);
      })
      .catch((err) => setError(getErrorMessage(err, 'Could not load this receipt.')));
  }, [bookingId]);

  if (error) {
    return (
      <Screen eyebrow="Receipt" title="Could not load receipt">
        <p style={{ color: 'var(--danger)', fontSize: 14 }}>{error}</p>
        <Button variant="ghost" onClick={() => navigate(-1)}>
          ← Back
        </Button>
      </Screen>
    );
  }

  if (!booking) {
    return (
      <Screen eyebrow="Receipt" title="Receipt">
        <div style={{ border: '1px solid var(--border)', borderRadius: 16, background: 'var(--surface)', padding: 28 }}>
          <div style={{ textAlign: 'center', marginBottom: 20 }}>
            <Skeleton width={140} height={22} style={{ margin: '0 auto 8px' }} />
            <Skeleton width={100} height={12} style={{ margin: '0 auto' }} />
          </div>
          <Skeleton width="100%" height={14} style={{ marginBottom: 10 }} />
          <Skeleton width="100%" height={14} style={{ marginBottom: 10 }} />
          <Skeleton width="100%" height={14} style={{ marginBottom: 20 }} />
          <Skeleton width={120} height={20} />
        </div>
      </Screen>
    );
  }

  if (booking.status !== 'completed') {
    return (
      <Screen eyebrow="Receipt" title="Not available yet">
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>
          A receipt is generated once this delivery is completed.
        </p>
        <Button variant="ghost" onClick={() => navigate(-1)}>
          ← Back
        </Button>
      </Screen>
    );
  }

  const fb = booking.fare_breakdown;
  const date = new Date(booking.created_at);

  return (
    <>
      <style>{`
        @media print {
          .no-print { display: none !important; }
          .receipt-print-area {
            background: white !important;
            color: black !important;
            box-shadow: none !important;
            border: 1px solid #ccc !important;
          }
          .receipt-print-area * { color: black !important; }
          body { background: white !important; }
        }
      `}</style>
      <Screen eyebrow="Receipt" title="Trip receipt">
        <div className="no-print" style={{ display: 'flex', justifyContent: 'space-between' }}>
          <Button variant="ghost" style={{ width: 'auto', padding: '4px 0' }} onClick={() => navigate(-1)}>
            ← Back
          </Button>
          <div style={{ display: 'flex', gap: 8 }}>
            <Button style={{ width: 'auto', padding: '8px 18px' }} onClick={() => window.print()}>
              🖨️ Print
            </Button>
            <Button
              variant="ghost"
              style={{ width: 'auto', padding: '8px 18px' }}
              loading={downloading}
              onClick={() => {
                if (!bookingId) return;
                setDownloading(true);
                void downloadInvoicePdf(bookingId)
                  .then((blob) => {
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `invoice-${bookingId.slice(0, 8)}.pdf`;
                    a.click();
                    URL.revokeObjectURL(url);
                  })
                  .catch((err) => setError(getErrorMessage(err, 'Could not download GST invoice.')))
                  .finally(() => setDownloading(false));
              }}
            >
              📄 GST PDF
            </Button>
          </div>
        </div>

        <div
          className="receipt-print-area"
          style={{
            border: '1px solid var(--border)',
            borderRadius: 16,
            background: 'var(--surface)',
            padding: 28,
          }}
        >
          <div style={{ textAlign: 'center', marginBottom: 20 }}>
            <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 22 }}>{BRAND.name}</div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Trip receipt / tax invoice</div>
          </div>

          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginBottom: 16 }}>
            <div>
              <div style={{ color: 'var(--text-muted)' }}>Receipt #</div>
              <div style={{ fontFamily: 'var(--font-mono)' }}>{booking.id.slice(0, 8).toUpperCase()}</div>
            </div>
            <div style={{ textAlign: 'right' }}>
              <div style={{ color: 'var(--text-muted)' }}>Date</div>
              <div>{date.toLocaleDateString()}</div>
            </div>
          </div>

          {(businessName || gstin) && (
            <div style={{ fontSize: 13, marginBottom: 16, padding: '10px 12px', background: 'var(--bg)', borderRadius: 8 }}>
              {businessName && <div style={{ fontWeight: 600 }}>{businessName}</div>}
              {gstin && <div style={{ color: 'var(--text-muted)', marginTop: 4 }}>GSTIN: {gstin}</div>}
            </div>
          )}

          <div style={{ borderTop: '1px dashed var(--border)', paddingTop: 16, display: 'flex', flexDirection: 'column', gap: 10 }}>
            <ReceiptLine label="Base fare" value={money(fb.base_fare)} />
            <ReceiptLine label="Distance" value={money(fb.distance_charge)} />
            {fb.time_charge > 0 && <ReceiptLine label="Time" value={money(fb.time_charge)} />}
            {fb.waiting_charge > 0 && <ReceiptLine label="Waiting" value={money(fb.waiting_charge)} />}
            {fb.night_surcharge > 0 && <ReceiptLine label="Night surcharge" value={money(fb.night_surcharge)} />}
            <ReceiptLine label="Platform fee" value={money(fb.platform_fee)} />
            {fb.coupon_discount > 0 && <ReceiptLine label="Coupon discount" value={`−${money(fb.coupon_discount)}`} />}
            {fb.subscription_benefit > 0 && (
              <ReceiptLine label="Membership benefit" value={`−${money(fb.subscription_benefit)}`} />
            )}
            <ReceiptLine label="Tax" value={money(fb.tax)} />
          </div>

          <div
            style={{
              borderTop: '1px solid var(--border)',
              marginTop: 14,
              paddingTop: 14,
              display: 'flex',
              justifyContent: 'space-between',
              fontSize: 17,
              fontWeight: 700,
            }}
          >
            <span>Total paid</span>
            <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--accent-strong)' }}>{money(fb.final_fare)}</span>
          </div>

          <div style={{ marginTop: 20, fontSize: 11, color: 'var(--text-muted)', textAlign: 'center' }}>
            This is a computer-generated receipt and does not require a signature.
          </div>
        </div>
      </Screen>
    </>
  );
}

function ReceiptLine({ label, value }: { label: string; value: string }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 14 }}>
      <span style={{ color: 'var(--text-muted)' }}>{label}</span>
      <span style={{ fontFamily: 'var(--font-mono)' }}>{value}</span>
    </div>
  );
}
