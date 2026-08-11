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
  VEHICLE_GROUPS,
  getVehicleMeta,
  isRecommendedForWeight,
  bookingTypeLabel,
} from '../constants/vehicleCatalog';
import type { VehicleGroupId } from '../constants/vehicleCatalog';
import { swapBookingParties } from '../lib/bookingDraft';
import type { BookingFlowState } from '../lib/bookingFlow';
import styles from './VehicleSelectPage.module.css';

function formatStop(point: BookingDraft['pickup'], isRide: boolean) {
  const name = point.contactName;
  const phone = point.contactPhone;
  const head = isRide
    ? point.label
    : name && phone
      ? `${name} · ${phone}`
      : name || phone || point.label;
  return { head, sub: point.addressLine ?? point.label };
}

export function VehicleSelectPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const initialDraft = location.state as BookingDraft | undefined;

  const [tripDraft, setTripDraft] = useState<BookingDraft | undefined>(initialDraft);
  const [options, setOptions] = useState<VehicleQuoteOption[] | null>(null);
  const [selectedQuoteId, setSelectedQuoteId] = useState<string | null>(null);
  const [activeGroup, setActiveGroup] = useState<VehicleGroupId | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const isRide = tripDraft?.bookingType === 'ride';

  useEffect(() => {
    if (initialDraft) {
      setTripDraft(initialDraft);
      setActiveGroup(initialDraft.vehicleGroup);
    }
  }, [initialDraft]);

  const loadQuotes = useCallback(async (draft: BookingDraft, cancelled: () => boolean) => {
    setLoading(true);
    setError('');
    try {
      const bookingType = draft.bookingType ?? 'parcel';
      const [categories, quoteRes] = await Promise.all([
        listVehicleCategories(draft.pickup.lat, draft.pickup.lng, bookingType),
        getQuote({
          pickup: { lat: draft.pickup.lat, lng: draft.pickup.lng },
          drops: draft.drops.map((d) => ({ lat: d.lat, lng: d.lng })),
          booking_type: bookingType,
          coupon_code: draft.couponCode,
          loyalty_points_to_redeem: draft.loyaltyToRedeem,
          item_details: isRide
            ? undefined
            : {
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

      const groupsWithVehicles = VEHICLE_GROUPS.filter((g) =>
        merged.some((o) => getVehicleMeta(o.quote.vehicle_category).group === g.id)
      );
      const preferredGroup =
        groupsWithVehicles.find((g) => g.id === draft.vehicleGroup)?.id ??
        groupsWithVehicles[0]?.id ??
        draft.vehicleGroup;
      setActiveGroup(preferredGroup);

      const inGroup = merged.filter((o) => getVehicleMeta(o.quote.vehicle_category).group === preferredGroup);
      const pool = inGroup.length > 0 ? inGroup : merged;
      const recommended = isRide
        ? pool[0]
        : pool.find((o) => isRecommendedForWeight(o.quote.vehicle_category, draft.weightBand));
      const pick = recommended ?? pool[0];
      setSelectedQuoteId(pick.quote.quote_id);
    } catch (err) {
      if (!cancelled()) setError(getErrorMessage(err, 'Could not load vehicles for this route.'));
    } finally {
      if (!cancelled()) setLoading(false);
    }
  }, [isRide]);

  useEffect(() => {
    if (!tripDraft?.pickup || !tripDraft.drops?.length) {
      navigate('/home', { replace: true });
      return;
    }

    let cancelled = false;
    void loadQuotes(tripDraft, () => cancelled);
    return () => {
      cancelled = true;
    };
  }, [tripDraft, navigate, loadQuotes]);

  const availableGroups = useMemo(() => {
    if (!options) return [];
    return VEHICLE_GROUPS.filter((g) =>
      options.some((o) => getVehicleMeta(o.quote.vehicle_category).group === g.id)
    );
  }, [options]);

  const filtered = useMemo(() => {
    if (!options) return [];
    if (!activeGroup) return options;
    const inGroup = options.filter((o) => getVehicleMeta(o.quote.vehicle_category).group === activeGroup);
    return inGroup.length > 0 ? inGroup : options;
  }, [options, activeGroup]);

  const selected = options?.find((o) => o.quote.quote_id === selectedQuoteId);

  function swapLocations() {
    if (!tripDraft?.pickup || tripDraft.drops.length === 0) return;
    setTripDraft(swapBookingParties(tripDraft));
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
        bookingType: tripDraft.bookingType,
        passengerCount: tripDraft.passengerCount,
      },
    });
  }

  if (!tripDraft?.pickup) return null;

  const pickupFmt = formatStop(tripDraft.pickup, isRide);
  const dropFmt = formatStop(tripDraft.drops[0], isRide);

  return (
    <div className={styles.page}>
      <div className={styles.header}>
        <button
          type="button"
          className={styles.backBtn}
          onClick={() => navigate(isRide ? '/ride' : '/book/drop-details', { state: tripDraft })}
        >
          ← Back
        </button>
        <PortMyStuffHeader />
      </div>

      <h1 className={styles.pageTitle}>
        {isRide ? 'Choose your ride' : 'Select Vehicle'}
      </h1>

      <div className={styles.tripCard}>
        <div className={styles.tripCardBody}>
          <div className={styles.tripStops}>
            <div className={styles.tripRow}>
              <span className={`${styles.tripDot} ${styles.tripDotPickup}`} />
              <div className={styles.tripText}>
                <div className={styles.tripHead}>{pickupFmt.head}</div>
                <div className={styles.tripSub}>{pickupFmt.sub}</div>
              </div>
              {!isRide && (
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
              )}
            </div>
            <div className={styles.tripRow}>
              <span className={`${styles.tripDot} ${styles.tripDotDrop}`} />
              <div className={styles.tripText}>
                <div className={styles.tripHead}>{dropFmt.head}</div>
                <div className={styles.tripSub}>{dropFmt.sub}</div>
              </div>
              {!isRide && (
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
              )}
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
            onClick={() =>
              navigate(isRide ? '/ride' : '/book', { state: { serviceId: tripDraft.serviceId, draft: tripDraft } })
            }
          >
            Edit locations
          </button>
        </div>
      </div>

      {!isRide && (
        <div className={styles.summaryMeta}>
          {bookingTypeLabel('parcel')} · {tripDraft.goodsCategory} · {tripDraft.weightBand.replace(/_/g, ' ')} weight
          {tripDraft.helperNeeded ? ' · helper' : ''}
        </div>
      )}
      {isRide && tripDraft.passengerCount && (
        <div className={styles.summaryMeta}>
          {tripDraft.passengerCount} passenger{tripDraft.passengerCount === 1 ? '' : 's'}
        </div>
      )}

      <LiveMap pickup={tripDraft.pickup} drops={tripDraft.drops} driver={null} />

      {availableGroups.length > 1 && (
        <div className={styles.groupTabs}>
          {availableGroups.map((g) => (
            <button
              key={g.id}
              type="button"
              className={`${styles.groupTab} ${activeGroup === g.id ? styles.groupTabActive : ''}`}
              onClick={() => {
                setActiveGroup(g.id);
                const inGroup = options?.filter((o) => getVehicleMeta(o.quote.vehicle_category).group === g.id);
                if (inGroup?.[0]) setSelectedQuoteId(inGroup[0].quote.quote_id);
              }}
            >
              {g.label}
            </button>
          ))}
        </div>
      )}

      {error && <p className={styles.inlineError}>{error}</p>}
      {loading && <SkeletonRowList count={4} />}
      {!loading && filtered.length === 0 && !error && (
        <div className={styles.empty}>No vehicles available for your route.</div>
      )}

      <div className={styles.list}>
        {filtered.map((opt) => {
          const meta = getVehicleMeta(opt.quote.vehicle_category);
          const recommended =
            !isRide && isRecommendedForWeight(opt.quote.vehicle_category, tripDraft.weightBand);
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
            ? isRide
              ? `Book ${getVehicleMeta(selected.quote.vehicle_category).label}`
              : `Proceed with ${getVehicleMeta(selected.quote.vehicle_category).label}`
            : 'Select a vehicle'}
        </Button>
      </div>
    </div>
  );
}
