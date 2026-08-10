import { useState, useEffect } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Waybill, WaybillLine, WaybillDivider } from '../components/Waybill';
import {
  confirmBooking,
  triggerDispatch,
  getWallet,
  getMyCorporateAccounts,
  ApiError,
  type Quote,
  type CorporateAccount,
} from '../api';
import { PAYMENT_METHODS, type PaymentMethodId } from '../constants/porter';

interface LocationState {
  quote: Quote;
  pickupLabel: string;
  dropLabel: string;
  goodsCategory: string;
  helperNeeded: boolean;
  couponCode?: string;
  scheduledFor?: string;
}

function money(n: number): string {
  return `₹${n.toFixed(2)}`;
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

  useEffect(() => {
    getWallet()
      .then((w) => setWalletBalance(w.real_money_balance + w.promotional_credit_balance))
      .catch(() => undefined);
    getMyCorporateAccounts()
      .then(setCorporateAccounts)
      .catch(() => undefined);
  }, []);

  if (!state?.quote) {
    navigate('/home');
    return null;
  }

  const { quote, pickupLabel, dropLabel, goodsCategory, helperNeeded, scheduledFor } = state;
  const fb = quote.fare_breakdown;
  const hasCorporate = corporateAccounts.length > 0;

  const availableMethods = PAYMENT_METHODS.filter((m) => m.id !== 'corporate_bill' || hasCorporate);

  async function handleConfirm() {
    setError('');
    setLoading(true);
    try {
      const booking = await confirmBooking(quote.quote_id, paymentMethod, scheduledFor);
      if (booking.status !== 'scheduled') {
        await triggerDispatch(booking.id).catch(() => undefined);
      }
      navigate(`/track/${booking.id}`);
    } catch (err) {
      if (err instanceof ApiError && err.code === 'QUOTE_EXPIRED') {
        setError('This price has expired. Please get a new quote.');
      } else if (err instanceof ApiError) {
        setError(err.message);
      } else {
        setError('Could not confirm your booking. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen
      eyebrow="Review & confirm"
      title="Confirm your booking"
      onBack={() => navigate('/home')}
      footer={
        <>
          {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginBottom: 10 }}>{error}</p>}
          <Button onClick={handleConfirm} loading={loading}>
            Confirm · {money(fb.final_fare)}
          </Button>
        </>
      }
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        <RoutePoint kind="pickup" label={pickupLabel} />
        <RoutePoint kind="drop" label={dropLabel} />
      </div>

      <div style={{ color: 'var(--text-muted)', fontSize: 13 }}>
        <span style={{ textTransform: 'capitalize' }}>{quote.vehicle_category.replace(/_/g, ' ')}</span> ·{' '}
        {goodsCategory}
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
              <span>
                <div style={{ fontSize: 14, fontWeight: 600 }}>{m.label}</div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
                  {m.description}
                  {m.id === 'wallet' && walletBalance != null && ` · Balance ${money(walletBalance)}`}
                  {m.id === 'corporate_bill' && hasCorporate && ` · ${corporateAccounts[0].name}`}
                </div>
              </span>
            </label>
          ))}
        </div>
      </div>

      <Waybill label="Fare breakdown" id={quote.quote_id.slice(0, 8).toUpperCase()}>
        <WaybillLine label="Base fare" value={money(fb.base_fare)} />
        <WaybillLine label="Distance" value={money(fb.distance_charge)} />
        {fb.night_surcharge > 0 && <WaybillLine label="Night surcharge" value={money(fb.night_surcharge)} />}
        {fb.surge_multiplier > 1 && (
          <WaybillLine label={`Demand surge · ${fb.surge_multiplier}×`} value="" />
        )}
        <WaybillLine label="Platform fee" value={money(fb.platform_fee)} />
        <WaybillLine label="Tax" value={money(fb.tax)} />
        {fb.coupon_discount > 0 && (
          <WaybillLine label="Coupon discount" value={`−${money(fb.coupon_discount)}`} muted />
        )}
        {fb.subscription_benefit > 0 && (
          <WaybillLine label="Membership benefit" value={`−${money(fb.subscription_benefit)}`} muted />
        )}
        <WaybillDivider />
        <WaybillLine label="Total" value={money(fb.final_fare)} emphasis />
      </Waybill>

      <p style={{ color: 'var(--text-muted)', fontSize: 12, textAlign: 'center' }}>
        Price locked until {new Date(quote.expires_at).toLocaleTimeString()}.
      </p>

      {scheduledFor && (
        <p style={{ color: 'var(--accent-strong)', fontSize: 13, textAlign: 'center', fontWeight: 600 }}>
          📅 Scheduled for {new Date(scheduledFor).toLocaleString()}
        </p>
      )}
    </Screen>
  );
}

function RoutePoint({ kind, label }: { kind: 'pickup' | 'drop'; label: string }) {
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
          {kind === 'pickup' ? 'Pickup' : 'Drop'}
        </div>
        <div style={{ fontSize: 15 }}>{label}</div>
      </div>
    </div>
  );
}
