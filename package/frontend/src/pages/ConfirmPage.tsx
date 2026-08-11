import { useState, useEffect } from 'react';
import { useLocation, useNavigate, Link } from 'react-router-dom';
import {
  confirmBooking,
  verifyBookingPayment,
  devConfirmBookingPayment,
  getWallet,
  getMyCorporateAccounts,
  getSavedPaymentMethods,
  getProfile,
  getLoyaltySummary,
  getQuote,
  ApiError,
  getErrorMessage,
  type Quote,
  type CorporateAccount,
  type GatewaySession,
} from '../api';
import { PAYMENT_METHODS, BRAND, type PaymentMethodId } from '../constants/brand';
import { getVehicleMeta, type ServiceId, type VehicleGroupId } from '../constants/vehicleCatalog';
import type { BookingDraft } from '../api/vehicles';
import type { LocationPoint } from '../lib/locations';
import styles from './ConfirmPage.module.css';

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
  loyaltyToRedeem?: number;
  scheduledFor?: string;
  vehicleGroup: VehicleGroupId;
  serviceId: ServiceId;
}

function money(n: number): string {
  return `₹${Math.round(n)}`;
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

  const [quote, setQuote] = useState<Quote | null>(state?.quote ?? null);
  const [loading, setLoading] = useState(false);
  const [quoteLoading, setQuoteLoading] = useState(false);
  const [error, setError] = useState('');
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethodId>('upi');
  const [walletBalance, setWalletBalance] = useState<number | null>(null);
  const [corporateAccounts, setCorporateAccounts] = useState<CorporateAccount[]>([]);
  const [selectedCorporateId, setSelectedCorporateId] = useState<string | null>(null);
  const [termsAccepted, setTermsAccepted] = useState(false);
  const [selectedSavedMethodId, setSelectedSavedMethodId] = useState<string | null>(null);
  const [couponCode, setCouponCode] = useState(state?.couponCode ?? '');
  const [loyaltyBalance, setLoyaltyBalance] = useState(0);
  const [loyaltyToRedeem, setLoyaltyToRedeem] = useState(state?.loyaltyToRedeem ?? 0);
  const [showBreakup, setShowBreakup] = useState(false);
  const [showAddresses, setShowAddresses] = useState(false);
  const [gstin, setGstin] = useState<string | null>(null);
  const [couponOpen, setCouponOpen] = useState(false);

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
        const defaultMethod = methods.find((m) => m.is_default) ?? methods[0];
        if (defaultMethod) {
          setSelectedSavedMethodId(defaultMethod.id);
          if (defaultMethod.method_type === 'card') setPaymentMethod('card');
          if (defaultMethod.method_type === 'upi') setPaymentMethod('upi');
        }
      })
      .catch(() => undefined);
    getLoyaltySummary()
      .then((s) => setLoyaltyBalance(s.balance))
      .catch(() => undefined);
    getProfile()
      .then((p) => setGstin(p.gstin))
      .catch(() => undefined);
  }, []);

  if (!state?.quote || !state.pickup || !state.drops?.length || !quote) {
    navigate('/home');
    return null;
  }

  const { pickup, drops, goodsCategory, weightBand, helperNeeded, scheduledFor, vehicleGroup, serviceId } = state;
  const fb = quote.fare_breakdown;
  const vehicleMeta = getVehicleMeta(quote.vehicle_category);
  const hasCorporate = corporateAccounts.length > 0;
  const availableMethods = PAYMENT_METHODS.filter((m) => m.id !== 'corporate_bill' || hasCorporate);
  const walletInsufficient = paymentMethod === 'wallet' && walletBalance != null && walletBalance < fb.final_fare;
  const maxCoinsDiscount = Math.floor(loyaltyBalance / 10);

  function backToVehicles() {
    const draft: BookingDraft = {
      serviceId,
      vehicleGroup,
      pickup,
      drops,
      goodsCategory,
      weightBand: weightBand ?? 'medium',
      helperNeeded,
      couponCode: couponCode || undefined,
      loyaltyToRedeem: loyaltyToRedeem > 0 ? loyaltyToRedeem : undefined,
      scheduledFor,
    };
    navigate('/vehicles', { state: draft });
  }

  async function refreshQuote(nextCoupon: string, nextLoyalty: number) {
    if (!quote) return;
    setQuoteLoading(true);
    setError('');
    try {
      const res = await getQuote({
        pickup: { lat: pickup.lat, lng: pickup.lng },
        drops: drops.map((d) => ({ lat: d.lat, lng: d.lng })),
        vehicle_category: quote.vehicle_category,
        coupon_code: nextCoupon || undefined,
        loyalty_points_to_redeem: nextLoyalty > 0 ? nextLoyalty : undefined,
        item_details: {
          goods_category: goodsCategory,
          weight_band: weightBand,
          helper_needed: helperNeeded,
        },
      });
      const match =
        res.quotes.find((q) => q.vehicle_category === quote.vehicle_category) ?? res.quotes[0];
      if (match) setQuote(match);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not update fare with offers.'));
    } finally {
      setQuoteLoading(false);
    }
  }

  async function applyCoupon() {
    await refreshQuote(couponCode.trim(), loyaltyToRedeem);
    setCouponOpen(false);
  }

  async function applyMaxCoins() {
    const pts = Math.min(loyaltyBalance, Math.floor(fb.final_fare * 10));
    const rounded = Math.floor(pts / 10) * 10;
    setLoyaltyToRedeem(rounded);
    await refreshQuote(couponCode.trim(), rounded);
  }

  async function handleConfirm() {
    if (!quote) return;
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
      const booking = await confirmBooking(
        quote.quote_id,
        paymentMethod,
        scheduledFor,
        corporateId,
        savedId
      );

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
    <div className={styles.page}>
      <main className={styles.main}>
        <button type="button" className={styles.back} onClick={backToVehicles}>
          ← Back
        </button>
        <h1 className={styles.title}>Review Booking</h1>

        <section className={styles.section}>
          <div className={styles.vehicleCard}>
            <span className={styles.vehicleIcon}>{vehicleMeta.icon}</span>
            <div>
              <div className={styles.vehicleName}>{vehicleMeta.label}</div>
              <button type="button" className={styles.vehicleLink} onClick={() => setShowAddresses((v) => !v)}>
                View address details
              </button>
            </div>
          </div>
          {showAddresses && (
            <div className={styles.addressModal} style={{ marginTop: 10 }}>
              <div>
                <strong>Pickup</strong>
                {pickup.contactName ? `${pickup.contactName} · ${pickup.contactPhone}` : pickup.label}
                <div>{pickup.addressLine}</div>
              </div>
              {drops.map((drop, i) => (
                <div key={i} style={{ marginTop: 10 }}>
                  <strong>Drop {drops.length > 1 ? i + 1 : ''}</strong>
                  {drop.contactName ? `${drop.contactName} · ${drop.contactPhone}` : drop.label}
                  <div>{drop.addressLine}</div>
                </div>
              ))}
            </div>
          )}
          <div className={styles.infoBanner} style={{ marginTop: 10 }}>
            <span aria-hidden>🕐</span>
            Free loading-unloading time included for your vehicle category.
          </div>
        </section>

        <section className={styles.section}>
          <h2 className={styles.sectionTitle}>GST Details</h2>
          <div className={styles.gstCard}>
            <p className={styles.gstText}>
              {gstin
                ? `GSTIN on file: ${gstin}`
                : 'Have a GST Number? Add it to get invoices with GSTIN for Input Tax Credit.'}
            </p>
            <Link to="/profile/edit">
              <button type="button" className={styles.gstBtn}>
                {gstin ? 'Update GSTIN' : 'Add GSTIN'}
              </button>
            </Link>
          </div>
        </section>

        <section className={styles.section}>
          <h2 className={styles.sectionTitle}>Offers and Discounts</h2>
          <button type="button" className={styles.offerRow} onClick={() => setCouponOpen((v) => !v)}>
            <span className={styles.offerLeft}>
              <span aria-hidden>🏷️</span>
              {couponCode ? `Coupon: ${couponCode}` : 'Apply Coupon'}
            </span>
            <span aria-hidden>›</span>
          </button>
          {couponOpen && (
            <div className={styles.couponInput}>
              <input
                value={couponCode}
                onChange={(e) => setCouponCode(e.target.value.toUpperCase())}
                placeholder="Enter coupon code"
              />
              <button type="button" onClick={() => void applyCoupon()} disabled={quoteLoading}>
                Apply
              </button>
            </div>
          )}
          {loyaltyBalance >= 10 && (
            <div className={styles.coinsRow}>
              <span className={styles.coinsText}>
                Use <strong>{Math.min(loyaltyBalance, Math.floor(fb.final_fare * 10))}</strong> coins to save up to{' '}
                <strong>{money(maxCoinsDiscount)}</strong>
              </span>
              <button type="button" className={styles.useCoinsBtn} onClick={() => void applyMaxCoins()} disabled={quoteLoading}>
                {loyaltyToRedeem > 0 ? 'Applied' : 'Use Coins'}
              </button>
            </div>
          )}
        </section>

        {showBreakup && (
          <section className={styles.section}>
            <h2 className={styles.sectionTitle}>Fare Summary</h2>
            <div className={styles.fareLine}>
              <span className={styles.fareMuted}>Trip fare (incl. tax)</span>
              <span>{money(fb.base_fare + fb.distance_charge + fb.tax)}</span>
            </div>
            {fb.coupon_discount > 0 && (
              <div className={styles.fareLine}>
                <span className={styles.fareMuted}>Coupon</span>
                <span>−{money(fb.coupon_discount)}</span>
              </div>
            )}
            {(fb.loyalty_discount ?? 0) > 0 && (
              <div className={styles.fareLine}>
                <span className={styles.fareMuted}>Coins</span>
                <span>−{money(fb.loyalty_discount!)}</span>
              </div>
            )}
            <div className={`${styles.fareLine} ${styles.fareTotal}`}>
              <span>Net fare</span>
              <span>{money(fb.final_fare)}</span>
            </div>
          </section>
        )}

        <label className={styles.terms}>
          <input type="checkbox" checked={termsAccepted} onChange={(e) => setTermsAccepted(e.target.checked)} />
          <span>
            I agree to the service terms, fare estimate, and cancellation policy. Goods must be legal and properly packed.
          </span>
        </label>
      </main>

      <div className={styles.stickyBar}>
        {error && <p className={styles.error}>{error}</p>}
        <div className={styles.stickyTop}>
          <div className={styles.paymentPick}>
            <span aria-hidden>💵</span>
            <div>
              <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>Payment</div>
              <select
                value={paymentMethod}
                onChange={(e) => setPaymentMethod(e.target.value as PaymentMethodId)}
              >
                {availableMethods.map((m) => (
                  <option key={m.id} value={m.id}>
                    {m.label}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <div className={styles.priceBlock}>
            <div className={styles.price}>{money(fb.final_fare)}</div>
            <button type="button" className={styles.viewBreakup} onClick={() => setShowBreakup((v) => !v)}>
              {showBreakup ? 'Hide breakup' : 'View breakup'}
            </button>
          </div>
        </div>
        <button
          type="button"
          className={styles.bookBtn}
          onClick={() => void handleConfirm()}
          disabled={loading || quoteLoading || !termsAccepted || walletInsufficient}
        >
          {loading ? 'Booking…' : `Book ${vehicleMeta.label}`}
        </button>
      </div>
    </div>
  );
}
