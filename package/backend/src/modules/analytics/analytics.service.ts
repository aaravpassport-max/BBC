import { pool } from '../../db/pool';

function round2(n: number): number {
  return Math.round(n * 100) / 100;
}

/**
 * Revenue dashboard (PRD Section 20 / A.10) — gross bookings, net revenue,
 * take-rate, by date range. Reads directly from the bookings/wallet ledger
 * (never a separately-maintained shadow number, per the PRD's cross-
 * dashboard-consistency rule) so this and any future Finance settlement
 * dashboard built on the same underlying tables can never silently diverge.
 */
export async function getRevenueDashboard(params: { from?: string; to?: string }) {
  const fromClause = params.from ? `AND b.created_at >= $1` : '';
  const toClause = params.to ? `AND b.created_at <= $${params.from ? 2 : 1}` : '';
  const args = [params.from, params.to].filter((v): v is string => v !== undefined);

  const result = await pool.query(
    `SELECT
       count(*) FILTER (WHERE b.status = 'completed') AS completed_bookings,
       count(*) FILTER (WHERE b.status = 'cancelled') AS cancelled_bookings,
       count(*) AS total_bookings,
       COALESCE(SUM((b.fare_breakdown->>'final_fare')::numeric) FILTER (WHERE b.status = 'completed'), 0) AS gross_revenue,
       COALESCE(SUM((b.fare_breakdown->>'platform_fee')::numeric) FILTER (WHERE b.status = 'completed'), 0) AS platform_fee_revenue,
       COALESCE(SUM((b.fare_breakdown->>'coupon_discount')::numeric) FILTER (WHERE b.status = 'completed'), 0) AS coupon_discount_liability,
       COALESCE(SUM((b.fare_breakdown->>'subscription_benefit')::numeric) FILTER (WHERE b.status = 'completed'), 0) AS subscription_benefit_liability
     FROM bookings b
     WHERE 1=1 ${fromClause} ${toClause}`,
    args
  );

  const row = result.rows[0];
  return {
    completed_bookings: parseInt(row.completed_bookings, 10),
    cancelled_bookings: parseInt(row.cancelled_bookings, 10),
    total_bookings: parseInt(row.total_bookings, 10),
    gross_revenue: parseFloat(row.gross_revenue),
    platform_fee_revenue: parseFloat(row.platform_fee_revenue),
    coupon_discount_liability: parseFloat(row.coupon_discount_liability),
    subscription_benefit_liability: parseFloat(row.subscription_benefit_liability),
    // take_rate: platform's share of gross revenue — the metric definition
    // is stated explicitly here (PRD Section 20 acceptance criteria: every
    // metric has a documented, versioned definition), not left implicit.
    take_rate_pct:
      parseFloat(row.gross_revenue) > 0
        ? round2((parseFloat(row.platform_fee_revenue) / parseFloat(row.gross_revenue)) * 100)
        : 0,
  };
}

/**
 * Booking funnel (PRD 20A.1) — Confirmed -> Assigned -> Completed. This
 * reference implementation only has server-side events to work from
 * (quote-shown/home-viewed are client-only telemetry not captured by this
 * backend), so the funnel starts at "booking confirmed" rather than the
 * full quote-to-completion funnel the PRD describes — a real deployment
 * would ingest client analytics events into the same OLAP store to extend
 * this further back, flagged here rather than silently faked.
 */
export async function getBookingFunnel(params: { from?: string; to?: string }) {
  const fromClause = params.from ? `AND created_at >= $1` : '';
  const toClause = params.to ? `AND created_at <= $${params.from ? 2 : 1}` : '';
  const args = [params.from, params.to].filter((v): v is string => v !== undefined);

  const result = await pool.query(
    `SELECT
       count(*) AS confirmed,
       count(*) FILTER (WHERE status IN ('driver_assigned', 'in_progress', 'completed')) AS assigned,
       count(*) FILTER (WHERE status = 'completed') AS completed,
       count(*) FILTER (WHERE status = 'no_drivers_found') AS no_drivers_found,
       count(*) FILTER (WHERE status = 'cancelled') AS cancelled
     FROM bookings WHERE 1=1 ${fromClause} ${toClause}`,
    args
  );

  const row = result.rows[0];
  const confirmed = parseInt(row.confirmed, 10);
  const assigned = parseInt(row.assigned, 10);
  const completed = parseInt(row.completed, 10);

  return {
    stages: [
      { stage: 'confirmed', count: confirmed, conversion_from_previous_pct: 100 },
      {
        stage: 'driver_assigned',
        count: assigned,
        conversion_from_previous_pct: confirmed > 0 ? round2((assigned / confirmed) * 100) : 0,
      },
      {
        stage: 'completed',
        count: completed,
        conversion_from_previous_pct: assigned > 0 ? round2((completed / assigned) * 100) : 0,
      },
    ],
    no_drivers_found: parseInt(row.no_drivers_found, 10),
    cancelled: parseInt(row.cancelled, 10),
  };
}

/**
 * Cancellation-rate breakdown (PRD 20A.10) — every reason traces to an
 * actual reason_code from the fixed taxonomy used at cancellation time
 * (booking.service's cancelBooking), never a free-text bucket that can't be
 * reliably aggregated (PRD acceptance criteria, stated explicitly).
 */
export async function getCancellationBreakdown(params: { from?: string; to?: string }) {
  const fromClause = params.from ? `AND created_at >= $1` : '';
  const toClause = params.to ? `AND created_at <= $${params.from ? 2 : 1}` : '';
  const args = [params.from, params.to].filter((v): v is string => v !== undefined);

  const totalResult = await pool.query(`SELECT count(*) FROM bookings WHERE 1=1 ${fromClause} ${toClause}`, args);
  const total = parseInt(totalResult.rows[0].count, 10);

  const byReason = await pool.query(
    `SELECT cancellation_reason_code AS reason_code, count(*) AS count
     FROM bookings WHERE status = 'cancelled' ${fromClause} ${toClause}
     GROUP BY cancellation_reason_code ORDER BY count DESC`,
    args
  );

  return {
    total_bookings: total,
    cancellation_rate_pct:
      total > 0
        ? round2((byReason.rows.reduce((s, r) => s + parseInt(r.count, 10), 0) / total) * 100)
        : 0,
    by_reason: byReason.rows.map((r) => ({ reason_code: r.reason_code, count: parseInt(r.count, 10) })),
  };
}

/**
 * Driver utilization (PRD 20A.10) — online-hours vs trip-hours ratio, per
 * driver. Computed from the SAME authoritative state (driver_profiles'
 * online_status transitions aren't individually logged in this reference
 * schema, so this uses trip-hours from bookings.started_at/updated_at as a
 * proxy metric) — flagged explicitly rather than presenting a fabricated
 * precision the underlying data doesn't support. A production deployment
 * would log every online/offline toggle as its own event for a true
 * online-hours denominator.
 */
export async function getDriverUtilization(params: { from?: string; to?: string }) {
  const fromClause = params.from ? `AND b.started_at >= $1` : '';
  const toClause = params.to ? `AND b.started_at <= $${params.from ? 2 : 1}` : '';
  const args = [params.from, params.to].filter((v): v is string => v !== undefined);

  const result = await pool.query(
    `SELECT b.driver_id,
            count(*) AS completed_trips,
            COALESCE(SUM(EXTRACT(EPOCH FROM (b.updated_at - b.started_at)) / 3600), 0) AS trip_hours
     FROM bookings b
     WHERE b.status = 'completed' AND b.started_at IS NOT NULL ${fromClause} ${toClause}
     GROUP BY b.driver_id
     ORDER BY trip_hours DESC
     LIMIT 100`,
    args
  );

  return result.rows.map((r) => ({
    driver_id: r.driver_id,
    completed_trips: parseInt(r.completed_trips, 10),
    trip_hours: round2(parseFloat(r.trip_hours)),
  }));
}
