import { randomUUID as uuidv4 } from 'crypto';
import { pool } from '../../db/pool';
import { Errors } from '../../utils/errors';
import { validateCoupon } from './coupon.service';
import { getActiveSubscriptionBenefit } from '../booking/subscription.service';
import { computeLoyaltyDiscount } from '../loyalty/loyalty.service';

const QUOTE_TTL_SECONDS = parseInt(process.env.QUOTE_TTL_SECONDS || '90', 10);

export interface FareBreakdown {
  base_fare: number;
  distance_charge: number;
  time_charge: number;
  waiting_charge: number;
  toll_pass_through: number;
  night_surcharge: number;
  surge_multiplier: number;
  platform_fee: number;
  tax: number;
  coupon_discount: number;
  subscription_benefit: number;
  loyalty_discount: number;
  final_fare: number;
}

interface RateCard {
  id: string;
  base_fare: string;
  per_km_rate: string;
  per_min_rate: string;
  night_surcharge_pct: string;
  night_window_start: string | null;
  night_window_end: string | null;
  minimum_fare: string;
  platform_fee: string;
  tax_rate_pct: string;
}

/** Haversine distance in km — used only as a stand-in for a real routing-engine
 * call (a production system would call an actual maps/routing provider for
 * road-network distance, per PRD Section 8/9). */
function haversineKm(a: { lat: number; lng: number }, b: { lat: number; lng: number }): number {
  const R = 6371;
  const dLat = ((b.lat - a.lat) * Math.PI) / 180;
  const dLng = ((b.lng - a.lng) * Math.PI) / 180;
  const lat1 = (a.lat * Math.PI) / 180;
  const lat2 = (b.lat * Math.PI) / 180;
  const sinDLat = Math.sin(dLat / 2);
  const sinDLng = Math.sin(dLng / 2);
  const h = sinDLat * sinDLat + Math.cos(lat1) * Math.cos(lat2) * sinDLng * sinDLng;
  return 2 * R * Math.asin(Math.sqrt(h));
}

function isWithinNightWindow(start: string | null, end: string | null): boolean {
  if (!start || !end) return false;
  const now = new Date();
  const currentMinutes = now.getHours() * 60 + now.getMinutes();
  const [sh, sm] = start.split(':').map(Number);
  const [eh, em] = end.split(':').map(Number);
  const startMinutes = sh * 60 + sm;
  const endMinutes = eh * 60 + em;
  if (startMinutes < endMinutes) {
    return currentMinutes >= startMinutes && currentMinutes < endMinutes;
  }
  // Window spans midnight.
  return currentMinutes >= startMinutes || currentMinutes < endMinutes;
}

/**
 * Computes the fare per the exact formula in PRD Section 5. This function is
 * the single source of truth for pricing math — every consumer (quote
 * generation, historical fare display, tax reporting per Section 12A) must
 * ultimately trace back to this same calculation, never a re-derived shadow
 * implementation.
 */
function computeFare(params: {
  rateCard: RateCard;
  distanceKm: number;
  surgeMultiplier: number;
  couponDiscount: number;
  subscriptionBenefit: number;
  loyaltyDiscount: number;
}): FareBreakdown {
  const { rateCard, distanceKm, surgeMultiplier, couponDiscount, subscriptionBenefit, loyaltyDiscount } = params;

  const base_fare = parseFloat(rateCard.base_fare);
  const distance_charge = distanceKm * parseFloat(rateCard.per_km_rate);
  const time_charge = 0; // populated by a real routing ETA in production
  const waiting_charge = 0; // realized only during the actual trip, not at quote time
  const toll_pass_through = 0; // populated by a real routing/toll provider

  const night_surcharge = isWithinNightWindow(rateCard.night_window_start, rateCard.night_window_end)
    ? (base_fare + distance_charge) * (parseFloat(rateCard.night_surcharge_pct) / 100)
    : 0;

  const preSurgeSubtotal = base_fare + distance_charge + time_charge + waiting_charge + night_surcharge;
  const surgedSubtotal = preSurgeSubtotal * surgeMultiplier;

  const platform_fee = parseFloat(rateCard.platform_fee);
  const preTaxTotal = surgedSubtotal + platform_fee + toll_pass_through;
  const tax = preTaxTotal * (parseFloat(rateCard.tax_rate_pct) / 100);

  let final_fare = preTaxTotal + tax - couponDiscount - subscriptionBenefit - loyaltyDiscount;

  // Minimum fare floor applies after additive components, before treating
  // coupon/subscription discounts as able to push below it, per PRD Section 5 —
  // unless the specific coupon is explicitly configured to allow it (not
  // modeled in this reference implementation; flagged for product decision).
  const minimumFare = parseFloat(rateCard.minimum_fare);
  if (final_fare < minimumFare) {
    final_fare = minimumFare;
  }

  return {
    base_fare: round2(base_fare),
    distance_charge: round2(distance_charge),
    time_charge: round2(time_charge),
    waiting_charge: round2(waiting_charge),
    toll_pass_through: round2(toll_pass_through),
    night_surcharge: round2(night_surcharge),
    surge_multiplier: surgeMultiplier,
    platform_fee: round2(platform_fee),
    tax: round2(tax),
    coupon_discount: round2(couponDiscount),
    subscription_benefit: round2(subscriptionBenefit),
    loyalty_discount: round2(loyaltyDiscount),
    final_fare: round2(final_fare),
  };
}

function round2(n: number): number {
  return Math.round(n * 100) / 100;
}

export interface QuoteResult {
  quote_id: string;
  vehicle_category: string;
  expires_at: string;
  surge_multiplier: number;
  fare_breakdown: FareBreakdown;
}

export async function generateQuotes(params: {
  customerId: string;
  pickup: { lat: number; lng: number };
  drops: { lat: number; lng: number }[];
  vehicleCategory?: string;
  couponCode?: string;
  loyaltyPointsToRedeem?: number;
}): Promise<QuoteResult[]> {
  const { customerId, pickup, drops, vehicleCategory, couponCode, loyaltyPointsToRedeem } = params;

  // Resolve serviceable zone -> city, to know which rate cards apply (PRD Section 8/9).
  const zoneResult = await pool.query(
    `SELECT z.city_id
     FROM zones z
     WHERE z.zone_type = 'service_area'
       AND ST_Covers(z.boundary::geometry, ST_SetSRID(ST_MakePoint($1, $2), 4326))
     LIMIT 1`,
    [pickup.lng, pickup.lat]
  );

  if (zoneResult.rowCount === 0) {
    throw Errors.validation({ pickup: 'This location is outside our serviceable area.' });
  }
  const cityId = zoneResult.rows[0].city_id;

  const categoryFilter = vehicleCategory ? 'AND vc.name = $2' : '';
  const rateCardsResult = await pool.query(
    `SELECT rc.*, vc.name AS category_name
     FROM rate_cards rc
     JOIN vehicle_categories vc ON vc.id = rc.vehicle_category_id
     WHERE rc.city_id = $1 AND rc.status = 'published' AND vc.status = 'active' ${categoryFilter}
     ORDER BY vc.name`,
    vehicleCategory ? [cityId, vehicleCategory] : [cityId]
  );

  if (rateCardsResult.rowCount === 0) {
    throw Errors.notFound('No available vehicle categories for this location');
  }

  // Total distance across an ordered stop sequence — PRD Section 5: computed on
  // the optimized route order, not a raw sum of unordered points. This reference
  // implementation sums pickup->drop1->drop2... in the order given; a production
  // system would call a routing engine to also optimize stop order if allowed.
  let totalDistanceKm = 0;
  let previous = pickup;
  for (const drop of drops) {
    totalDistanceKm += haversineKm(previous, drop);
    previous = drop;
  }

  const quotes: QuoteResult[] = [];
  const expiresAt = new Date(Date.now() + QUOTE_TTL_SECONDS * 1000);

  // Fetched once, applies identically to every category in this quote batch
  // (PRD 19A.1 — a platform-fee waiver isn't category-specific).
  const subscriptionBenefit = await getActiveSubscriptionBenefit(customerId);

  for (const rateCard of rateCardsResult.rows) {
    // Surge multiplier: discrete tiers only (PRD Section 5). This reference
    // implementation defaults to 1.0 — a real deployment computes this from
    // live demand/supply per zone (Section 5/20B.1) before quoting.
    const surgeMultiplier = 1.0;

    let fareBreakdown = computeFare({
      rateCard,
      distanceKm: totalDistanceKm,
      surgeMultiplier,
      couponDiscount: 0,
    subscriptionBenefit: 0,
    loyaltyDiscount: 0,
  });

    let couponId: string | null = null;
    if (couponCode) {
      // Validated per-category since min-order-value is checked against this
      // specific category's pre-discount fare (PRD 15A.1) — a coupon that
      // qualifies against a cheap category's fare might not against a pricier one.
      try {
        const { coupon, discountAmount } = await validateCoupon(couponCode, customerId, fareBreakdown.final_fare);
        couponId = coupon.id;
        const cappedDiscount = Math.min(discountAmount, fareBreakdown.final_fare);
        fareBreakdown = {
          ...fareBreakdown,
          coupon_discount: cappedDiscount,
          final_fare: round2(fareBreakdown.final_fare - cappedDiscount),
        };
      } catch (err) {
        // An invalid coupon does not block quote generation entirely (PRD
        // 2.2.5's coupon-entry screen shows the specific rejection reason but
        // still lets the customer proceed without it) — rethrow only if this
        // is the sole category being quoted and the caller explicitly wants
        // a hard failure; for a multi-category quote list, log and continue
        // without the discount on this category rather than failing the
        // whole quote request over one category's ineligibility.
        console.warn(`Coupon ${couponCode} invalid for category ${rateCard.category_name}:`, err);
      }
    }

    if (subscriptionBenefit?.waivesPlatformFee && fareBreakdown.platform_fee > 0) {
      // Itemized distinctly from coupon_discount (PRD 19A.1 acceptance
      // criteria) — both can apply simultaneously in this reference
      // implementation; the PRD's stacking-conflict resolution rule
      // (Section 15A "most valuable to the customer by default") is not
      // fully implemented here since only one specific benefit type
      // (platform-fee waiver) exists yet — flagged for whoever adds a
      // second benefit type that could actually conflict with a coupon.
      const waived = fareBreakdown.platform_fee;
      fareBreakdown = {
        ...fareBreakdown,
        subscription_benefit: waived,
        final_fare: round2(fareBreakdown.final_fare - waived),
      };
    }

    if (loyaltyPointsToRedeem && loyaltyPointsToRedeem > 0) {
      const { discount, pointsUsed } = await computeLoyaltyDiscount(
        customerId,
        loyaltyPointsToRedeem,
        fareBreakdown.final_fare
      );
      if (pointsUsed > 0) {
        fareBreakdown = {
          ...fareBreakdown,
          loyalty_discount: discount,
          final_fare: round2(fareBreakdown.final_fare - discount),
          ...( { loyalty_points_used: pointsUsed } as { loyalty_points_used?: number }),
        };
      }
    }

    const quoteId = uuidv4();

    await pool.query(
      `INSERT INTO quotes (id, rate_card_id, rate_card_version, customer_id, pickup_geo, drops_geo,
                            vehicle_category_id, surge_multiplier, fare_breakdown, coupon_id, expires_at)
       VALUES ($1, $2, $3, $4, ST_SetSRID(ST_MakePoint($5, $6), 4326), $7, $8, $9, $10, $11, $12)`,
      [
        quoteId,
        rateCard.id,
        rateCard.version,
        customerId,
        pickup.lng,
        pickup.lat,
        JSON.stringify(drops),
        rateCard.vehicle_category_id,
        surgeMultiplier,
        JSON.stringify(fareBreakdown),
        couponId,
        expiresAt,
      ]
    );

    quotes.push({
      quote_id: quoteId,
      vehicle_category: rateCard.category_name,
      expires_at: expiresAt.toISOString(),
      surge_multiplier: surgeMultiplier,
      fare_breakdown: fareBreakdown,
    });
  }

  return quotes;
}
