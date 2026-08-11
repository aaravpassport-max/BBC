import { useState, useEffect, useMemo, useCallback } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { PortMyStuffHeader } from '../components/PortMyStuffHeader';
import { Button } from '../components/Button';
import { LiveMap } from '../components/LiveMap';
import { SkeletonRowList } from '../components/Skeleton';
import { getQuote, getErrorMessage } from '../api';
import {
  listVehicleCategories,
  mergeQuotesWithCategories,
  type BookingDraft,
  type VehicleQuoteOption,
} from '../api/vehicles';
import {
  HOME_SERVICE_TILES,
  VEHICLE_GROUPS,
  getVehicleMeta,
  isRecommendedForWeight,
} from '../constants/vehicleCatalog';
import type { BookingFlowState } from '../lib/bookingFlow';
import styles from './VehicleSelectPage.module.css';

function formatStop(point: BookingDraft['pickup']) {
  const name = point.contactName;
  const phone = point.contactPhone;
  const head = name && phone ? `${name} · ${phone}` : name || phone || point.label;
  return { head, sub: point.addressLine ?? point.label };
}

export function VehicleSelectPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const initialDraft = location.state as BookingDraft | undefined;

  const [tripDraft, setTripDraft] = useState<BookingDraft | undefined>(initialDraft);
  const [options, setOptions] = useState<VehicleQuoteOption[] | null>(null);
  const [selectedQuoteId, setSelectedQuoteId] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    if (initialDraft) setTripDraft(initialDraft);
  }, [initialDraft]);

  const loadQuotes = useCallback(async (draft: BookingDraft, cancelled: () => boolean) => {
    setLoading(true);
    setError('');
    try {
      const [categories, quoteRes] = await Promise.all([
        listVehicleCategories(draft.pickup.lat, draft.pickup.lng),
        getQuote({
          pickup: { lat: draft.pickup.lat, lng: draft.pickup.lng },
          drops: draft.drops.map((d) => ({ lat: d.lat, lng: d.lng })),
          coupon_code: draft.couponCode,
          loyalty_points_to_redeem: draft.loyaltyToRedeem,
          item_details: {
            goods_category: draft.goodsCategory,
            weight_band: draft.weightBand,
            helper_needed: draft.helperNeeded,
          },
        }),
      ]);

      if (cancelled()) return;

      const merged = mergeQuotesWithCategories(quoteRes.quotes, categories);
      if (merged.length === 0) {
        setError('No vehicles available for this route. Try a different pickup or drop.');
        setOptions([]);
        return;
      }

      setOptions(merged);
      const inGroup = merged.filter((o) => getVehicleMeta(o.quote.vehicle_category).group === draft.vehicleGroup);
      const pool = inGroup.length > 0 ? inGroup : merged;
      const recommended = pool.find((o) => isRecommendedForWeight(o.quote.vehicle_category, draft.weightBand));
      const pick = recommended ?? pool[0];
      setSelectedQuoteId(pick.quote.quote_id);
    } catch (err) {
      if (!cancelled()) setError(getErrorMessage(err, 'Could not load vehicles for this route.'));
    } finally {
      if (!cancelled()) setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!tripDraft?.pickup || !tripDraft.drops?.length || !tripDraft.vehicleGroup) {
      navigate('/home', { replace: true });
      return;
    }

    let cancelled = false;
    void loadQuotes(tripDraft, () => cancelled);
    return () => {
      cancelled = true;
    };
  }, [tripDraft, navigate, loadQuotes]);

  const filtered = useMemo(() => {
    if (!options || !tripDraft) return [];
    return options.filter((o) => getVehicleMeta(o.quote.vehicle_category).group === tripDraft.vehicleGroup);
  }, [options, tripDraft]);

  const selected = options?.find((o) => o.quote.quote_id === selectedQuoteId);

  function swapLocations() {
    if (!tripDraft?.pickup || tripDraft.drops.length === 0) return;
    setTripDraft({
      ...tripDraft,
      pickup: tripDraft.drops[0],
      drops: [tripDraft.pickup, ...tripDraft.drops.slice(1)],
    });
  }

  function handleContinue() {
    if (!tripDraft || !selected) return;
    navigate('/confirm', {
      state: {
        quote: selected.quote,
        pickup: tripDraft.pickup,
        drops: tripDraft.drops,
        goodsCategory: tripDraft.goodsCategory,
        weightBand: tripDraft.weightBand,
        helperNeeded: tripDraft.helperNeeded,
        couponCode: tripDraft.couponCode,
        scheduledFor: tripDraft.scheduledFor,
        vehicleGroup: tripDraft.vehicleGroup,
        serviceId: tripDraft.serviceId,
      },
    });
  }

  if (!tripDraft?.pickup) return null;

  const pickupFmt = formatStop(tripDraft.pickup);
  const dropFmt = formatStop(tripDraft.drops[0]);
  const serviceLabel =
    HOME_SERVICE_TILES.find((t) => t.id === tripDraft.serviceId)?.label ?? tripDraft.serviceId;

  return (
    <div className={styles.page}>
      <div className={styles.header}>
        <button
          type="button"
          className={styles.backBtn}
          onClick={() => navigate('/book/drop-details', { state: tripDraft })}
        >
          ← Back
        </button>
        <PortMyStuffHeader />
      </div>

      <h1 className={styles.pageTitle}>Select Vehicle</h1>

      <div className={styles.tripCard}>
        <div className={styles.tripCardBody}>
          <div className={styles.tripStops}>
            <div className={styles.tripRow}>
              <span className={`${styles.tripDot} ${styles.tripDotPickup}`} />
              <div className={styles.tripText}>
                <div className={styles.tripHead}>{pickupFmt.head}</div>
                <div className={styles.tripSub}>{pickupFmt.sub}</div>
              </div>
              <button
                type="button"
                className={styles.editBtn}
                onClick={() =>
                  navigate('/book/sender-details', {
                    state: { draft: tripDraft, returnTo: 'vehicles' } satisfies BookingFlowState,
                  })
                }
              >
                Edit
              </button>
            </div>
            <div className={styles.tripRow}>
              <span className={`${styles.tripDot} ${styles.tripDotDrop}`} />
              <div className={styles.tripText}>
                <div className={styles.tripHead}>{dropFmt.head}</div>
                <div className={styles.tripSub}>{dropFmt.sub}</div>
              </div>
              <button
                type="button"
                className={styles.editBtn}
                onClick={() =>
                  navigate('/book/drop-details', {
                    state: { draft: tripDraft, returnTo: 'vehicles', dropIndex: 0 } satisfies BookingFlowState,
                  })
                }
              >
                Edit
              </button>
            </div>
          </div>
          <button type="button" className={styles.swapBtn} onClick={swapLocations} aria-label="Switch pickup and drop">
            ⇅
          </button>
        </div>
        <div className={styles.tripActions}>
          <button
            type="button"
            className={styles.tripAction}
            onClick={() => navigate('/book', { state: { serviceId: tripDraft.serviceId, draft: tripDraft } })}
          >
            Edit locations
          </button>
        </div>
      </div>

      <div className={styles.summaryMeta}>
        {serviceLabel} · {tripDraft.goodsCategory} · {tripDraft.weightBand.replace(/_/g, ' ')} weight
        {tripDraft.helperNeeded ? ' · helper' : ''}
      </div>

      <LiveMap pickup={tripDraft.pickup} drops={tripDraft.drops} driver={null} />

      <div className={styles.groupTabs}>
        {VEHICLE_GROUPS.filter((g) => g.id === tripDraft.vehicleGroup).map((g) => (
          <button key={g.id} type="button" className={`${styles.groupTab} ${styles.groupTabActive}`} disabled>
            {g.label}
          </button>
        ))}
      </div>

      {error && <p className={styles.inlineError}>{error}</p>}
      {loading && <SkeletonRowList count={4} />}
      {!loading && filtered.length === 0 && !error && (
        <div className={styles.empty}>No vehicles in this category for your route.</div>
      )}

      <div className={styles.list}>
        {filtered.map((opt) => {
          const meta = getVehicleMeta(opt.quote.vehicle_category);
          const recommended = isRecommendedForWeight(opt.quote.vehicle_category, tripDraft.weightBand);
          const isSelected = selectedQuoteId === opt.quote.quote_id;

          return (
            <button
              key={opt.quote.quote_id}
              type="button"
              className={`${styles.card} ${isSelected ? styles.cardSelected : ''}`}
              onClick={() => setSelectedQuoteId(opt.quote.quote_id)}
            >
              <div className={styles.cardTop}>
                <div className={styles.cardMain}>
                  <span className={styles.icon}>{meta.icon}</span>
                  <div>
                    <div className={styles.title}>{meta.label}</div>
                    <div className={styles.subtitle}>
                      {meta.capacity || opt.category.capacity_descriptor}
                      {recommended ? ' · Recommended' : ''}
                    </div>
                  </div>
                </div>
                <div className={styles.price}>₹{opt.quote.fare_breakdown.final_fare.toFixed(0)}</div>
              </div>
            </button>
          );
        })}
      </div>

      <div className={styles.footer}>
        <Button disabled={!selected} onClick={handleContinue}>
          {selected
            ? `Proceed with ${getVehicleMeta(selected.quote.vehicle_category).label}`
            : 'Select a vehicle'}
        </Button>
      </div>
    </div>
  );
}
