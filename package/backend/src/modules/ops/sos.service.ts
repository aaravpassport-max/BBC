import { pool } from '../../db/pool';
import { Errors } from '../../utils/errors';
import { broadcastOpsEvent, broadcastBookingEvent } from '../realtime/realtime.hub';

/**
 * Triggers an SOS event from an active booking (PRD 10A.1). Either the
 * customer or the driver on that specific booking can trigger it — anyone
 * else is rejected, since an SOS is tied to a specific trip's two
 * participants, not a general-purpose alert any authenticated user could
 * fire for any booking.
 */
export async function triggerSos(params: {
  bookingId: string;
  triggeredBy: string;
  lat?: number;
  lng?: number;
}): Promise<{ id: string }> {
  const { bookingId, triggeredBy, lat, lng } = params;

  const booking = await pool.query(`SELECT customer_id, driver_id, status FROM bookings WHERE id = $1`, [bookingId]);
  if (booking.rowCount === 0) {
    throw Errors.notFound('Booking');
  }
  const b = booking.rows[0];

  let role: 'customer' | 'driver';
  if (b.customer_id === triggeredBy) role = 'customer';
  else if (b.driver_id === triggeredBy) role = 'driver';
  else throw Errors.forbidden('You are not a participant on this booking.');

  const result = await pool.query(
    `INSERT INTO sos_events (booking_id, triggered_by, triggered_by_role, trigger_lat, trigger_lng)
     VALUES ($1, $2, $3, $4, $5) RETURNING id`,
    [bookingId, triggeredBy, role, lat ?? null, lng ?? null]
  );

  // PRD 10A.1: trip location streams continuously to Control Room for the
  // duration of an active SOS regardless of the rider's normal
  // tracking-sharing preference. This reference implementation doesn't
  // have a live location-streaming/WebSocket layer to hook into (see the
  // backend README's dev-only-substitutes list) — the SOS event itself,
  // and the booking's current pickup/drop geography already on the
  // `bookings` row, are what the Control Room queue surfaces instead.

  const sosId = result.rows[0].id as string;

  broadcastOpsEvent({ event: 'sos.triggered', sos_id: sosId, booking_id: bookingId, role });
  broadcastBookingEvent(bookingId, { event: 'sos.triggered', sos_id: sosId });

  return { id: sosId };
}

/** The live Control Room queue (PRD 10A.1) — every SOS not yet resolved,
 * oldest-unacknowledged first so nothing sits ignored. */
export async function getSosQueue() {
  const result = await pool.query(
    `SELECT se.id, se.booking_id, se.triggered_by_role, se.trigger_lat, se.trigger_lng,
            se.status, se.acknowledged_at, se.created_at,
            se.escalated_at, se.auto_escalated,
            b.status AS booking_status, b.pickup_address_snapshot
     FROM sos_events se
     JOIN bookings b ON b.id = se.booking_id
     WHERE se.status != 'resolved'
     ORDER BY (se.status = 'triggered') DESC, se.created_at ASC`
  );
  return result.rows;
}

/**
 * Acknowledges an SOS (PRD 10A.1: "cannot be dismissed without an explicit
 * acknowledgment"). This is a distinct step from resolving — it marks that
 * an operator has seen and picked up the alert, before the incident is
 * necessarily over.
 */
export async function acknowledgeSos(id: string, operatorId: string): Promise<void> {
  const result = await pool.query(
    `UPDATE sos_events SET status = 'acknowledged', acknowledged_by = $1, acknowledged_at = now()
     WHERE id = $2 AND status = 'triggered' RETURNING id`,
    [operatorId, id]
  );
  if (result.rowCount === 0) {
    throw Errors.validation({ sos: 'This SOS event was not found or is not in a state that can be acknowledged.' });
  }
}

/**
 * Resolves an SOS (PRD 10A.1: "every SOS event has a mandatory resolution
 * note and timestamp before it can be closed"). Can only be resolved after
 * acknowledgment — there is no path that closes an SOS event that no
 * operator ever picked up.
 *
 * SECURITY/QUALITY FIX: the PRD specifies resolution_note must be a
 * genuine account, "min 20 chars (forces a real note, not a one-word
 * dismissal)" — this previously only checked for non-empty, which a lazy
 * one-character note would have satisfied.
 */
export async function resolveSos(params: {
  id: string;
  operatorId: string;
  outcomeTag: string;
  resolutionNote: string;
}): Promise<void> {
  const { id, operatorId, outcomeTag, resolutionNote } = params;
  if (!resolutionNote || resolutionNote.trim().length < 20) {
    throw Errors.validation({ resolution_note: 'Add a detailed resolution note (at least 20 characters).' });
  }

  const result = await pool.query(
    `UPDATE sos_events SET status = 'resolved', resolved_by = $1, resolved_at = now(),
            outcome_tag = $2, resolution_note = $3
     WHERE id = $4 AND status = 'acknowledged' RETURNING id`,
    [operatorId, outcomeTag, resolutionNote, id]
  );
  if (result.rowCount === 0) {
    throw Errors.validation({
      sos: 'This SOS event must be acknowledged before it can be resolved, or was not found.',
    });
  }
}

const AUTO_ESCALATE_THRESHOLD_SECONDS = 30; // PRD 10A.1: "e.g., 30s"

/**
 * Manual "Escalate to Safety Team Lead" action (PRD 10A.1 layout — one of
 * the one-tap actions on the alert). Deliberately gated by a SEPARATE
 * permission (ops.sos_escalate) from acknowledge/resolve (ops.sos_respond)
 * at the route layer — the PRD explicitly lists these as two different
 * permissions ("ops.sos.respond ... broadly granted ...; ops.sos.escalate
 * for secondary-tier actions"), meaning a normal on-duty operator can
 * acknowledge and resolve, but escalating to the safety team lead is a
 * smaller, higher-trust action set. Can be called from ANY non-resolved
 * state — an operator might realize mid-handling that this needs the
 * safety team, not just at the moment of first triage.
 */
export async function escalateSos(id: string, escalatedBy: string): Promise<void> {
  const result = await pool.query(
    `UPDATE sos_events SET escalated_at = now(), escalated_by = $1
     WHERE id = $2 AND status != 'resolved' RETURNING id`,
    [escalatedBy, id]
  );
  if (result.rowCount === 0) {
    throw Errors.validation({ sos: 'This SOS event was not found or is already resolved.' });
  }
}

/**
 * Auto-escalation sweep (PRD 10A.1 edge case: "No operator acknowledges
 * within the hard threshold — auto-escalates to a secondary on-call
 * operator AND triggers a distinct louder/broader alert tier ... never
 * silently waiting indefinitely for the first operator"). Run on a short
 * interval by the job scheduler, the same pattern as dispatch's expired-
 * offer sweep. `escalated_by` stays NULL here specifically to distinguish
 * an automatic escalation from a human's deliberate one in
 * escalateSos above — the queue/detail view can tell them apart.
 */
export async function sweepUnacknowledgedSos(): Promise<number> {
  const result = await pool.query(
    `UPDATE sos_events SET auto_escalated = true, escalated_at = now()
     WHERE status = 'triggered' AND auto_escalated = false
       AND created_at < now() - interval '${AUTO_ESCALATE_THRESHOLD_SECONDS} seconds'
     RETURNING id`
  );
  // A real deployment would fire the "distinct louder/broader alert tier"
  // here (e.g., an SMS to a safety-team distribution list) — this
  // reference backend has no SMS provider (see the README's dev-only-
  // substitutes list); the auto_escalated flag itself is what the Control
  // Room UI surfaces instead.
  return result.rowCount || 0;
}
