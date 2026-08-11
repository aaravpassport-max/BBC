import { useState, useEffect } from 'react';
import { useLocation, useNavigate, Link } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { FareCard, FareCardLine, FareCardDivider } from '../components/FareCard';
import {
  confirmBooking,
  verifyBookingPayment,
  devConfirmBookingPayment,
  getWallet,
  getMyCorporateAccounts,
  getSavedPaymentMethods,
  ApiError,
  getErrorMessage,
  type Quote,
  type CorporateAccount,
  type GatewaySession,
  type SavedPaymentMethod,
} from '../api';
import { PAYMENT_METHODS, BRAND, type PaymentMethodId } from '../constants/brand';
import { getVehicleMeta, type VehicleGroupId } from '../constants/vehicleCatalog';
import type { BookingDraft } from '../api/vehicles';
import type { LocationPoint } from '../lib/locations';

const RAZORPAY_SCRIPT_URL = 'https://checkout.razorpay.com/v1/checkout.js';

declare global {
  interface Window {
    Razorpay: new (options: Record<string, unknown>) => { open: () => void };
  }
}

function loadRazorpayScript(): Promise<boolean> {
  return new Promise((resolve) => {
    if (window.Razorpay) {
      resolve(true);
      return;
    }
    const script = document.createElement('script');
    script.src = RAZORPAY_SCRIPT_URL;
    script.onload = () => resolve(true);
    script.onerror = () => resolve(false);
    document.body.appendChild(script);
  });
}

interface LocationState {
  quote: Quote;
  pickup: LocationPoint;
  drops: LocationPoint[];
  goodsCategory: string;
  weightBand?: string;
  helperNeeded: boolean;
  couponCode?: string;
  scheduledFor?: string;
  vehicleGroup: VehicleGroupId;
}

function money(n: number): string {
  return `₹${n.toFixed(2)}`;
}

async function completeCardPayment(bookingId: string, session: GatewaySession): Promise<void> {
  if (session.simulated) {
    await devConfirmBookingPayment(bookingId, session.gateway_ref!);
    return;
  }

  const loaded = await loadRazorpayScript();
  if (!loaded) throw new Error('Could not load the payment provider.');

  await new Promise<void>((resolve, reject) => {
    const razorpay = new window.Razorpay({
      key: session.key_id,
      amount: session.amount,
      currency: session.currency,
      order_id: session.order_id,
      name: BRAND.name,
      description: 'Trip payment',
      handler: async (response: { razorpay_order_id: string; razorpay_payment_id: string; razorpay_signature: string }) => {
        try {
          await verifyBookingPayment(bookingId, response);
          resolve();
        } catch (err) {
          reject(err);
        }
      },
      modal: { ondismiss: () => reject(new Error('Payment cancelled.')) },
    });
    razorpay.open();
  });
}

export function ConfirmPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const state = location.state as LocationState | undefined;
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethodId>('wallet');
  const [walletBalance, setWalletBalance] = useState<number | null>(null);
  const [corporateAccounts, setCorporateAccounts] = useState<CorporateAccount[]>([]);
  const [selectedCorporateId, setSelectedCorporateId] = useState<string | null>(null);
  const [termsAccepted, setTermsAccepted] = useState(false);
  const [savedMethods, setSavedMethods] = useState<SavedPaymentMethod[]>([]);
  const [selectedSavedMethodId, setSelectedSavedMethodId] = useState<string | null>(null);

  useEffect(() => {
    getWallet()
      .then((w) => setWalletBalance(w.real_money_balance + w.promotional_credit_balance))
      .catch(() => undefined);
    getMyCorporateAccounts()
      .then((accounts) => {
        setCorporateAccounts(accounts);
        if (accounts.length > 0) setSelectedCorporateId(accounts[0].account_id);
      })
      .catch(() => undefined);
    getSavedPaymentMethods()
      .then((methods) => {
        setSavedMethods(methods);
        const defaultMethod = methods.find((m) => m.is_default) ?? methods[0];
        if (defaultMethod) {
          setSelectedSavedMethodId(defaultMethod.id);
          if (defaultMethod.method_type === 'card') setPaymentMethod('card');
          if (defaultMethod.method_type === 'upi') setPaymentMethod('upi');
        }
      })
      .catch(() => undefined);
  }, []);

  if (!state?.quote || !state.pickup || !state.drops?.length) {
    navigate('/home');
    return null;
  }

  const { quote, pickup, drops, goodsCategory, weightBand, helperNeeded, scheduledFor, vehicleGroup } = state;

  function backToVehicles() {
    if (!state) return;
    const draft: BookingDraft = {
      vehicleGroup,
      pickup,
      drops,
      goodsCategory,
      weightBand: weightBand ?? 'medium',
      helperNeeded,
      couponCode: state.couponCode,
      scheduledFor,
    };
    navigate('/vehicles', { state: draft });
  }
  const fb = quote.fare_breakdown;
  const hasCorporate = corporateAccounts.length > 0;
  const availableMethods = PAYMENT_METHODS.filter((m) => m.id !== 'corporate_bill' || hasCorporate);
  const selectedCorporate = corporateAccounts.find((a) => a.account_id === selectedCorporateId);
  const walletInsufficient = paymentMethod === 'wallet' && walletBalance != null && walletBalance < fb.final_fare;
  const cardSavedMethods = savedMethods.filter((m) => m.method_type === 'card');
  const upiSavedMethods = savedMethods.filter((m) => m.method_type === 'upi');

  async function handleConfirm() {
    if (!termsAccepted) {
      setError('Please accept the terms to continue.');
      return;
    }
    if (walletInsufficient) {
      setError('Insufficient wallet balance. Add money or choose another payment method.');
      return;
    }
    setError('');
    setLoading(true);
    try {
      const corporateId =
        paymentMethod === 'corporate_bill' ? selectedCorporateId ?? undefined : undefined;
      const savedId =
        (paymentMethod === 'card' || paymentMethod === 'upi') && selectedSavedMethodId
          ? selectedSavedMethodId
          : undefined;
      const booking = await confirmBooking(quote.quote_id, paymentMethod, scheduledFor, corporateId, savedId);

      if (booking.payment_required && booking.gateway_session) {
        await completeCardPayment(booking.id, booking.gateway_session);
      }

      navigate(`/track/${booking.id}`);
    } catch (err) {
      if (err instanceof ApiError && err.code === 'QUOTE_EXPIRED') {
        setError('This price has expired. Please get a new quote.');
      } else {
        setError(getErrorMessage(err, 'Could not confirm your booking. Please try again.'));
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen
      eyebrow="Review & confirm"
      title="Confirm your booking"
      onBack={backToVehicles}
      footer={
        <>
          {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginBottom: 10 }}>{error}</p>}
          <Button
            onClick={() => void handleConfirm()}
            loading={loading}
            disabled={!termsAccepted || walletInsufficient}
          >
            Confirm · {money(fb.final_fare)}
          </Button>
        </>
      }
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        <RoutePoint kind="pickup" label={pickup.label} sub={pickup.addressLine} />
        {drops.map((drop, i) => (
          <RoutePoint key={i} kind="drop" label={drop.label} sub={drop.addressLine} index={drops.length > 1 ? i + 1 : undefined} />
        ))}
      </div>

      <div style={{ color: 'var(--text-muted)', fontSize: 13 }}>
        <span style={{ textTransform: 'capitalize' }}>{getVehicleMeta(quote.vehicle_category).label}</span> · {goodsCategory}
        {weightBand ? ` · ${weightBand}` : ''}
        {helperNeeded ? ' · helper requested' : ''}
      </div>

      <div>
        <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 8 }}>Payment method</div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {availableMethods.map((m) => (
            <label
              key={m.id}
              style={{
                display: 'flex',
                gap: 10,
                alignItems: 'flex-start',
                border: `1px solid ${paymentMethod === m.id ? 'var(--accent)' : 'var(--border)'}`,
                borderRadius: 12,
                padding: '12px 14px',
                background: paymentMethod === m.id ? 'var(--accent-soft)' : 'var(--surface)',
                cursor: 'pointer',
              }}
            >
              <input
                type="radio"
                name="payment"
                checked={paymentMethod === m.id}
                onChange={() => setPaymentMethod(m.id)}
                style={{ marginTop: 3 }}
              />
              <span style={{ flex: 1 }}>
                <div style={{ fontSize: 14, fontWeight: 600 }}>{m.label}</div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
                  {m.description}
                  {m.id === 'wallet' && walletBalance != null && ` · Balance ${money(walletBalance)}`}
                  {m.id === 'wallet' && walletInsufficient && (
                    <span style={{ color: 'var(--danger)', display: 'block', marginTop: 4 }}>
                      Insufficient balance for this trip.
                    </span>
                  )}
                </div>
                {m.id === 'card' && paymentMethod === 'card' && cardSavedMethods.length > 0 && (
                  <div style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 6 }}>
                    {cardSavedMethods.map((sm) => (
                      <label key={sm.id} style={{ display: 'flex', gap: 8, fontSize: 12, alignItems: 'center' }}>
                        <input
                          type="radio"
                          name="saved-card"
                          checked={selectedSavedMethodId === sm.id}
                          onChange={() => setSelectedSavedMethodId(sm.id)}
                          onClick={(e) => e.stopPropagation()}
                        />
                        {sm.display_label}
                        {sm.is_default ? ' (default)' : ''}
                      </label>
                    ))}
                  </div>
                )}
                {m.id === 'upi' && paymentMethod === 'upi' && upiSavedMethods.length > 0 && (
                  <div style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 6 }}>
                    {upiSavedMethods.map((sm) => (
                      <label key={sm.id} style={{ display: 'flex', gap: 8, fontSize: 12, alignItems: 'center' }}>
                        <input
                          type="radio"
                          name="saved-upi"
                          checked={selectedSavedMethodId === sm.id}
                          onChange={() => setSelectedSavedMethodId(sm.id)}
                          onClick={(e) => e.stopPropagation()}
                        />
                        {sm.display_label}
                      </label>
                    ))}
                  </div>
                )}
                {m.id === 'corporate_bill' && paymentMethod === 'corporate_bill' && (
                  <div style={{ marginTop: 8 }}>
                    {selectedCorporate && (
                      <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--accent-strong)' }}>
                        Billing to: {selectedCorporate.name}
                      </div>
                    )}
                    {corporateAccounts.length > 1 && (
                      <select
                        value={selectedCorporateId ?? ''}
                        onChange={(e) => setSelectedCorporateId(e.target.value)}
                        style={{ marginTop: 6, width: '100%', padding: 8, borderRadius: 8, border: '1px solid var(--border)' }}
                        onClick={(e) => e.stopPropagation()}
                      >
                        {corporateAccounts.map((a) => (
                          <option key={a.account_id} value={a.account_id}>{a.name}</option>
                        ))}
                      </select>
                    )}
                  </div>
                )}
              </span>
            </label>
          ))}
        </div>
        <p style={{ fontSize: 12, marginTop: 8 }}>
          <Link to="/wallet" style={{ color: 'var(--accent-strong)' }}>Manage saved payment methods</Link>
        </p>
      </div>

      <FareCard label="Fare breakdown" id={quote.quote_id.slice(0, 8).toUpperCase()}>
        <FareCardLine label="Base fare" value={money(fb.base_fare)} />
        <FareCardLine label="Distance" value={money(fb.distance_charge)} />
        {fb.night_surcharge > 0 && <FareCardLine label="Night surcharge" value={money(fb.night_surcharge)} />}
        {fb.surge_multiplier > 1 && <FareCardLine label={`Demand surge · ${fb.surge_multiplier}×`} value="" />}
        <FareCardLine label="Platform fee" value={money(fb.platform_fee)} />
        <FareCardLine label="Tax" value={money(fb.tax)} />
        {fb.coupon_discount > 0 && <FareCardLine label="Coupon discount" value={`−${money(fb.coupon_discount)}`} muted />}
        {fb.subscription_benefit > 0 && <FareCardLine label="Membership benefit" value={`−${money(fb.subscription_benefit)}`} muted />}
        {(fb.loyalty_discount ?? 0) > 0 && <FareCardLine label="Loyalty points" value={`−${money(fb.loyalty_discount!)}`} muted />}
        <FareCardDivider />
        <FareCardLine label="Total" value={money(fb.final_fare)} emphasis />
      </FareCard>

      <p style={{ color: 'var(--text-muted)', fontSize: 12, textAlign: 'center' }}>
        Price locked until {new Date(quote.expires_at).toLocaleTimeString()}.
      </p>

      {scheduledFor && (
        <p style={{ color: 'var(--accent-strong)', fontSize: 13, textAlign: 'center', fontWeight: 600 }}>
          📅 Scheduled for {new Date(scheduledFor).toLocaleString()}
        </p>
      )}

      <label
        style={{
          display: 'flex',
          gap: 10,
          alignItems: 'flex-start',
          border: '1px solid var(--border)',
          borderRadius: 12,
          padding: '12px 14px',
          background: 'var(--surface)',
          cursor: 'pointer',
          fontSize: 13,
          lineHeight: 1.5,
        }}
      >
        <input
          type="checkbox"
          checked={termsAccepted}
          onChange={(e) => setTermsAccepted(e.target.checked)}
          style={{ marginTop: 3 }}
        />
        <span>
          I agree to the service terms, fare estimate, and cancellation policy. Goods must be legal and properly packed for transport.
        </span>
      </label>
    </Screen>
  );
}

function RoutePoint({
  kind,
  label,
  sub,
  index,
}: {
  kind: 'pickup' | 'drop';
  label: string;
  sub?: string;
  index?: number;
}) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
      <span
        style={{
          width: 10,
          height: 10,
          borderRadius: kind === 'pickup' ? '50%' : '2px',
          background: kind === 'pickup' ? 'var(--pickup)' : 'var(--drop)',
          flexShrink: 0,
        }}
      />
      <div>
        <div style={{ fontSize: 11, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.06em' }}>
          {kind === 'pickup' ? 'Pickup' : index ? `Drop ${index}` : 'Drop'}
        </div>
        <div style={{ fontSize: 15 }}>{label}</div>
        {sub && <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{sub}</div>}
      </div>
    </div>
  );
}
