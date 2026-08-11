import { useState, useEffect, useMemo } from 'react';
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
  type VehicleGroupId,
} from '../constants/vehicleCatalog';
import styles from './VehicleSelectPage.module.css';

export function VehicleSelectPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const draft = location.state as BookingDraft | undefined;

  const [activeGroup, setActiveGroup] = useState<VehicleGroupId>(draft?.vehicleGroup ?? 'two_wheeler');
  const [options, setOptions] = useState<VehicleQuoteOption[] | null>(null);
  const [selectedQuoteId, setSelectedQuoteId] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!draft?.pickup || !draft.drops?.length || !draft.vehicleGroup) {
      navigate('/home', { replace: true });
      return;
    }

    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');
      try {
        const [categories, quoteRes] = await Promise.all([
          listVehicleCategories(draft!.pickup.lat, draft!.pickup.lng),
          getQuote({
            pickup: { lat: draft!.pickup.lat, lng: draft!.pickup.lng },
            drops: draft!.drops.map((d) => ({ lat: d.lat, lng: d.lng })),
            coupon_code: draft!.couponCode,
            loyalty_points_to_redeem: draft!.loyaltyToRedeem,
            item_details: {
              goods_category: draft!.goodsCategory,
              weight_band: draft!.weightBand,
              helper_needed: draft!.helperNeeded,
            },
          }),
        ]);

        if (cancelled) return;

        const merged = mergeQuotesWithCategories(quoteRes.quotes, categories);
        if (merged.length === 0) {
          setError('No vehicles available for this route. Try a different pickup or drop.');
          setOptions([]);
          return;
        }

        setOptions(merged);

        const recommended = merged.find((o) => isRecommendedForWeight(o.quote.vehicle_category, draft!.weightBand));
        const cheapest = merged[0];
        const pick = recommended ?? cheapest;
        setSelectedQuoteId(pick.quote.quote_id);

        const group = draft!.vehicleGroup ?? getVehicleMeta(pick.quote.vehicle_category).group;
        setActiveGroup(group);
      } catch (err) {
        if (!cancelled) setError(getErrorMessage(err, 'Could not load vehicles for this route.'));
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    void load();

    return () => {
      cancelled = true;
    };
  }, [draft, navigate]);

  const filtered = useMemo(() => {
    if (!options) return [];
    return options.filter((o) => getVehicleMeta(o.quote.vehicle_category).group === activeGroup);
  }, [options, activeGroup]);

  const selected = options?.find((o) => o.quote.quote_id === selectedQuoteId);

  function handleContinue() {
    if (!draft || !selected) return;
    navigate('/confirm', {
      state: {
        quote: selected.quote,
        pickup: draft.pickup,
        drops: draft.drops,
        goodsCategory: draft.goodsCategory,
        weightBand: draft.weightBand,
        helperNeeded: draft.helperNeeded,
        couponCode: draft.couponCode,
        scheduledFor: draft.scheduledFor,
        vehicleGroup: draft.vehicleGroup,
      },
    });
  }

  if (!draft?.pickup) return null;

  return (
    <div className={styles.page}>
      <div className={styles.header}>
        <button
          type="button"
          className={styles.backBtn}
          onClick={() => navigate('/book', { state: { vehicleGroup: draft.vehicleGroup } })}
        >
          ← Back
        </button>
        <PortMyStuffHeader />
      </div>

      <div className={styles.summary}>
        <div>
          <strong>Service:</strong> {VEHICLE_GROUPS.find((g) => g.id === draft.vehicleGroup)?.label ?? draft.vehicleGroup}
        </div>
        <div>
          <strong>Pickup:</strong> {draft.pickup.label}
        </div>
        <div>
          <strong>Drop:</strong> {draft.drops.map((d) => d.label).join(' → ')}
        </div>
        <div style={{ marginTop: 6 }}>
          {draft.goodsCategory} · {draft.weightBand.replace(/_/g, ' ')} weight
          {draft.helperNeeded ? ' · helper requested' : ''}
        </div>
      </div>

      <LiveMap pickup={draft.pickup} drops={draft.drops} driver={null} />

      <h1 style={{ fontSize: 20, margin: '16px 16px 8px', fontWeight: 700 }}>Choose your vehicle</h1>
      <p style={{ fontSize: 13, color: 'var(--text-muted)', margin: '0 16px 12px', lineHeight: 1.45 }}>
        Select the vehicle type and size that fits your load. Prices include estimated distance and current demand.
      </p>

      <div className={styles.groupTabs}>
        {VEHICLE_GROUPS.filter((g) => g.id === draft.vehicleGroup).map((g) => {
          const count = options?.filter((o) => getVehicleMeta(o.quote.vehicle_category).group === g.id).length ?? 0;
          if (options && count === 0) return null;
          return (
            <button
              key={g.id}
              type="button"
              className={`${styles.groupTab} ${styles.groupTabActive}`}
              disabled
            >
              {g.label}
            </button>
          );
        })}
      </div>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13, padding: '0 16px' }}>{error}</p>}

      {loading && <SkeletonRowList count={4} />}

      {!loading && filtered.length === 0 && !error && (
        <div className={styles.empty}>No vehicles in this category for your route.</div>
      )}

      <div className={styles.list}>
        {filtered.map((opt) => {
          const meta = getVehicleMeta(opt.quote.vehicle_category);
          const recommended = isRecommendedForWeight(opt.quote.vehicle_category, draft.weightBand);
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
                    <div className={styles.subtitle}>{meta.blurb}</div>
                    {meta.examples && (
                      <div className={styles.subtitle} style={{ marginTop: 2 }}>
                        e.g. {meta.examples}
                      </div>
                    )}
                    {recommended && <span className={styles.badge}>Recommended for your load</span>}
                  </div>
                </div>
                <div className={styles.price}>₹{opt.quote.fare_breakdown.final_fare.toFixed(0)}</div>
              </div>
              <div className={styles.specs}>
                <span className={styles.spec}>{meta.capacity || opt.category.capacity_descriptor}</span>
                {opt.category.permit_required && <span className={styles.spec}>Permit required</span>}
                {opt.quote.surge_multiplier > 1 && (
                  <span className={styles.spec}>Surge {opt.quote.surge_multiplier}×</span>
                )}
              </div>
            </button>
          );
        })}
      </div>

      <div className={styles.footer}>
        <Button disabled={!selected} onClick={handleContinue}>
          {selected
            ? `Continue with ${getVehicleMeta(selected.quote.vehicle_category).label} · ₹${selected.quote.fare_breakdown.final_fare.toFixed(0)}`
            : 'Select a vehicle'}
        </Button>
      </div>
    </div>
  );
}
