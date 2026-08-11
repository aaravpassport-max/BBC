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
import { getVehicleMeta } from '../constants/vehicleCatalog';
import type { BookingDraft } from '../api/vehicles';
import { buildRebookSnapshot, swapBookingParties, draftTripLabel } from '../lib/bookingDraft';
import { saveBookingTemplate } from '../api/rebook';
import type { BookingFlowState, ConfirmNavState } from '../lib/bookingFlow';
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

interface LocationState extends ConfirmNavState {}

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
  const [gstin, setGstin] = useState<string | null>(null);
  const [couponOpen, setCouponOpen] = useState(false);
  const [tripPickup, setTripPickup] = useState(state?.pickup);
  const [tripDrops, setTripDrops] = useState(state?.drops ?? []);
  const [savingFavourite, setSavingFavourite] = useState(false);

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

  if (!state?.quote || !tripPickup || !tripDrops?.length || !quote) {
    navigate('/home');
    return null;
  }

  const pickup = tripPickup;
  const drops = tripDrops;
  const { goodsCategory, weightBand, helperNeeded, scheduledFor, vehicleGroup, serviceId, bookingType, passengerCount } = state;
  const fb = quote.fare_breakdown;
  const vehicleMeta = getVehicleMeta(quote.vehicle_category);
  const hasCorporate = corporateAccounts.length > 0;
  const availableMethods = PAYMENT_METHODS.filter((m) => m.id !== 'corporate_bill' || hasCorporate);
  const walletInsufficient = paymentMethod === 'wallet' && walletBalance != null && walletBalance < fb.final_fare;
  const maxCoinsDiscount = Math.floor(loyaltyBalance / 10);

  function buildDraft(): BookingDraft {
    return {
      bookingType: bookingType ?? 'parcel',
      serviceId,
      vehicleGroup,
      pickup,
      drops,
      goodsCategory,
      weightBand: weightBand ?? 'medium',
      helperNeeded,
      passengerCount,
      couponCode: couponCode || undefined,
      loyaltyToRedeem: loyaltyToRedeem > 0 ? loyaltyToRedeem : undefined,
      scheduledFor,
    };
  }

  function buildConfirmState(): ConfirmNavState {
    return {
      quote: quote!,
      pickup,
      drops,
      goodsCategory,
      weightBand,
      helperNeeded,
      couponCode: couponCode || undefined,
      loyaltyToRedeem: loyaltyToRedeem > 0 ? loyaltyToRedeem : undefined,
      scheduledFor,
      vehicleGroup,
      serviceId,
      bookingType,
      passengerCount,
    };
  }

  function editSender() {
    const flow: BookingFlowState = {
      draft: buildDraft(),
      returnTo: 'confirm',
      confirmState: buildConfirmState(),
    };
    navigate('/book/sender-details', { state: flow });
  }

  function editReceiver(index = 0) {
    const flow: BookingFlowState = {
      draft: buildDraft(),
      returnTo: 'confirm',
      confirmState: buildConfirmState(),
      dropIndex: index,
    };
    navigate('/book/drop-details', { state: flow });
  }

  function backToVehicles() {
    navigate('/vehicles', { state: buildDraft() });
  }

  function switchParties() {
    const swapped = swapBookingParties(buildDraft());
    setTripPickup(swapped.pickup);
    setTripDrops(swapped.drops);
    void refreshQuote(couponCode.trim(), loyaltyToRedeem, swapped.pickup, swapped.drops);
  }

  async function handleSaveFavourite() {
    setSavingFavourite(true);
    setError('');
    try {
      const draft = buildDraft();
      await saveBookingTemplate(draftTripLabel(draft), draft);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not save favourite route.'));
    } finally {
      setSavingFavourite(false);
    }
  }

  async function refreshQuote(
    nextCoupon: string,
    nextLoyalty: number,
    nextPickup = pickup,
    nextDrops = drops
  ) {
    if (!quote) return;
    setQuoteLoading(true);
    setError('');
    try {
      const res = await getQuote({
        pickup: { lat: nextPickup.lat, lng: nextPickup.lng },
        drops: nextDrops.map((d) => ({ lat: d.lat, lng: d.lng })),
        vehicle_category: quote.vehicle_category,
        booking_type: bookingType ?? 'parcel',
        coupon_code: nextCoupon || undefined,
        loyalty_points_to_redeem: nextLoyalty > 0 ? nextLoyalty : undefined,
        item_details:
          bookingType === 'ride'
            ? undefined
            : {
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
        savedId,
        buildRebookSnapshot(buildDraft()) as unknown as Record<string, unknown>,
        passengerCount
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
            </div>
          </div>
          <div className={styles.infoBanner} style={{ marginTop: 10 }}>
            <span aria-hidden>🕐</span>
            Free loading-unloading time included for your vehicle category.
          </div>
        </section>

        <section className={styles.section}>
          <h2 className={styles.sectionTitle}>Sender & Receiver</h2>
          <ContactCard
            label="Sender"
            name={pickup.contactName}
            phone={pickup.contactPhone}
            place={pickup.label}
            address={pickup.unitDetail || pickup.addressLine}
            onEdit={editSender}
          />
          {drops.map((drop, i) => (
            <ContactCard
              key={i}
              label={drops.length > 1 ? `Receiver ${i + 1}` : 'Receiver'}
              name={drop.contactName}
              phone={drop.contactPhone}
              place={drop.label}
              address={drop.unitDetail || drop.addressLine}
              onEdit={() => editReceiver(i)}
            />
          ))}
          <button type="button" className={styles.switchBtn} onClick={switchParties}>
            ⇅ Switch sender & receiver
          </button>
          <button
            type="button"
            className={styles.switchBtn}
            onClick={() => void handleSaveFavourite()}
            disabled={savingFavourite}
          >
            {savingFavourite ? 'Saving…' : '★ Save as favourite route'}
          </button>
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

function ContactCard({
  label,
  name,
  phone,
  place,
  address,
  onEdit,
}: {
  label: string;
  name?: string;
  phone?: string;
  place: string;
  address?: string;
  onEdit: () => void;
}) {
  return (
    <div className={styles.contactCard}>
      <div className={styles.contactHeader}>
        <span className={styles.contactLabel}>{label}</span>
        <button type="button" className={styles.contactEdit} onClick={onEdit}>
          Edit
        </button>
      </div>
      <div className={styles.contactName}>{name || '—'}</div>
      <div className={styles.contactPhone}>{phone || 'No phone added'}</div>
      <div className={styles.contactAddress}>{place}</div>
      {address && address !== place && <div className={styles.contactSub}>{address}</div>}
    </div>
  );
}
