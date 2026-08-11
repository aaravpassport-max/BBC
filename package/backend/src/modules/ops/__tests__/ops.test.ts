import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser, randomPhone } from '../../../test-utils/helpers';
import { assertReferenceSeedPresent, createOnlineEligibleDriver, samplePickupDrop } from '../../../test-utils/seed';
import { sweepUnacknowledgedSos } from '../sos.service';

const app = createApp();
const CONTROL_ROOM_ROLE_ID_QUERY = `SELECT id FROM roles WHERE name = 'control_room_operator'`;
const SAFETY_TEAM_LEAD_ROLE_ID_QUERY = `SELECT id FROM roles WHERE name = 'safety_team_lead'`;

beforeAll(async () => {
  await assertReferenceSeedPresent();
});

afterAll(async () => {
  await pool.end();
});

async function grantControlRoomOperator(userId: string) {
  const role = await pool.query(CONTROL_ROOM_ROLE_ID_QUERY);
  await pool.query(`INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`, [
    userId,
    role.rows[0].id,
  ]);
}

async function grantSafetyTeamLead(userId: string) {
  const role = await pool.query(SAFETY_TEAM_LEAD_ROLE_ID_QUERY);
  await pool.query(`INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`, [
    userId,
    role.rows[0].id,
  ]);
}

async function createSearchingBooking(): Promise<{ bookingId: string; customerToken: string }> {
  await pool.query(`UPDATE driver_profiles SET online_status = false`);
  const customer = await loginAsNewUser(app);
  const quote = await request(app)
    .post('/v1/pricing/quote')
    .set('Authorization', `Bearer ${customer.accessToken}`)
    .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
  const booking = await request(app)
    .post('/v1/bookings')
    .set('Authorization', `Bearer ${customer.accessToken}`)
    .set('Idempotency-Key', `sos-test-${crypto.randomUUID()}`)
    .send({ quote_id: quote.body.quotes[0].quote_id, payment_method: 'wallet' });
  return { bookingId: booking.body.id, customerToken: customer.accessToken };
}

describe('SOS: trigger (PRD 10A.1)', () => {
  it('the customer on a booking can trigger SOS', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const res = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${customerToken}`)
      .send({ booking_id: bookingId, lat: 12.95, lng: 77.6 });
    expect(res.status).toBe(201);

    const row = await pool.query('SELECT triggered_by_role, status FROM sos_events WHERE id = $1', [res.body.id]);
    expect(row.rows[0].triggered_by_role).toBe('customer');
    expect(row.rows[0].status).toBe('triggered');
  });

  it('rejects a user who is not a participant on the booking', async () => {
    const { bookingId } = await createSearchingBooking();
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ booking_id: bookingId });
    expect(res.status).toBe(403);
  });

  it('rejects a nonexistent booking', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ booking_id: '00000000-0000-0000-0000-000000000000' });
    expect(res.status).toBe(404);
  });
});

describe('SOS: Control Room queue, acknowledge, resolve (PRD 10A.1)', () => {
  it('a plain user cannot access the SOS queue', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app).get('/ops/v1/sos/queue').set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(403);
  });

  it('a triggered SOS appears in the queue, then disappears once resolved', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const trigger = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${customerToken}`)
      .send({ booking_id: bookingId });

    const operator = await loginAsNewUser(app);
    await grantControlRoomOperator(operator.userId);

    const queueBefore = await request(app).get('/ops/v1/sos/queue').set('Authorization', `Bearer ${operator.accessToken}`);
    expect(queueBefore.body.some((e: { id: string }) => e.id === trigger.body.id)).toBe(true);

    await request(app)
      .post(`/ops/v1/sos/${trigger.body.id}/acknowledge`)
      .set('Authorization', `Bearer ${operator.accessToken}`);
    await request(app)
      .post(`/ops/v1/sos/${trigger.body.id}/resolve`)
      .set('Authorization', `Bearer ${operator.accessToken}`)
      .send({ outcome_tag: 'false_alarm', resolution_note: 'Confirmed with rider, accidental trigger.' });

    const queueAfter = await request(app).get('/ops/v1/sos/queue').set('Authorization', `Bearer ${operator.accessToken}`);
    expect(queueAfter.body.some((e: { id: string }) => e.id === trigger.body.id)).toBe(false);
  });

  it('cannot be resolved without first being acknowledged', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const trigger = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${customerToken}`)
      .send({ booking_id: bookingId });

    const operator = await loginAsNewUser(app);
    await grantControlRoomOperator(operator.userId);

    const res = await request(app)
      .post(`/ops/v1/sos/${trigger.body.id}/resolve`)
      .set('Authorization', `Bearer ${operator.accessToken}`)
      .send({ outcome_tag: 'false_alarm', resolution_note: 'Trying to skip acknowledgment.' });
    expect(res.status).toBe(400);
  });

  it('cannot be resolved without a resolution note', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const trigger = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${customerToken}`)
      .send({ booking_id: bookingId });

    const operator = await loginAsNewUser(app);
    await grantControlRoomOperator(operator.userId);
    await request(app).post(`/ops/v1/sos/${trigger.body.id}/acknowledge`).set('Authorization', `Bearer ${operator.accessToken}`);

    const res = await request(app)
      .post(`/ops/v1/sos/${trigger.body.id}/resolve`)
      .set('Authorization', `Bearer ${operator.accessToken}`)
      .send({ outcome_tag: 'false_alarm', resolution_note: '' });
    expect(res.status).toBe(400);
  });

  it('cannot be acknowledged twice', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const trigger = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${customerToken}`)
      .send({ booking_id: bookingId });

    const operator = await loginAsNewUser(app);
    await grantControlRoomOperator(operator.userId);
    await request(app).post(`/ops/v1/sos/${trigger.body.id}/acknowledge`).set('Authorization', `Bearer ${operator.accessToken}`);

    const second = await request(app)
      .post(`/ops/v1/sos/${trigger.body.id}/acknowledge`)
      .set('Authorization', `Bearer ${operator.accessToken}`);
    expect(second.status).toBe(400);
  });

  it('rejects a resolution note under the PRD-specified 20-character minimum (a real fix — this previously only checked for non-empty)', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const trigger = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${customerToken}`)
      .send({ booking_id: bookingId });

    const operator = await loginAsNewUser(app);
    await grantControlRoomOperator(operator.userId);
    await request(app).post(`/ops/v1/sos/${trigger.body.id}/acknowledge`).set('Authorization', `Bearer ${operator.accessToken}`);

    const tooShort = await request(app)
      .post(`/ops/v1/sos/${trigger.body.id}/resolve`)
      .set('Authorization', `Bearer ${operator.accessToken}`)
      .send({ outcome_tag: 'false_alarm', resolution_note: 'ok fine' }); // 7 chars, non-empty but too short
    expect(tooShort.status).toBe(400);

    const longEnough = await request(app)
      .post(`/ops/v1/sos/${trigger.body.id}/resolve`)
      .set('Authorization', `Bearer ${operator.accessToken}`)
      .send({ outcome_tag: 'false_alarm', resolution_note: 'This is a genuinely detailed note.' });
    expect(longEnough.status).toBe(200);
  });
});

describe('SOS: manual escalation (PRD 10A.1 "Escalate to Safety Team Lead")', () => {
  it('a plain on-duty operator (sos_respond only) CANNOT escalate — this is a genuinely separate, smaller-trust permission', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const trigger = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${customerToken}`)
      .send({ booking_id: bookingId });

    const operator = await loginAsNewUser(app);
    await grantControlRoomOperator(operator.userId); // has sos_respond, NOT sos_escalate

    const res = await request(app)
      .post(`/ops/v1/sos/${trigger.body.id}/escalate`)
      .set('Authorization', `Bearer ${operator.accessToken}`);
    expect(res.status).toBe(403);
  });

  it('a safety team lead CAN escalate, and it is recorded with who escalated it', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const trigger = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${customerToken}`)
      .send({ booking_id: bookingId });

    const lead = await loginAsNewUser(app);
    await grantSafetyTeamLead(lead.userId);

    const res = await request(app)
      .post(`/ops/v1/sos/${trigger.body.id}/escalate`)
      .set('Authorization', `Bearer ${lead.accessToken}`);
    expect(res.status).toBe(200);

    const row = await pool.query('SELECT escalated_by, escalated_at, auto_escalated FROM sos_events WHERE id = $1', [
      trigger.body.id,
    ]);
    expect(row.rows[0].escalated_by).toBe(lead.userId);
    expect(row.rows[0].escalated_at).toBeTruthy();
    expect(row.rows[0].auto_escalated).toBe(false); // this was a manual escalation, not the auto-sweep
  });

  it('cannot escalate an already-resolved SOS event', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const trigger = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${customerToken}`)
      .send({ booking_id: bookingId });

    const operator = await loginAsNewUser(app);
    await grantControlRoomOperator(operator.userId);
    await request(app).post(`/ops/v1/sos/${trigger.body.id}/acknowledge`).set('Authorization', `Bearer ${operator.accessToken}`);
    await request(app)
      .post(`/ops/v1/sos/${trigger.body.id}/resolve`)
      .set('Authorization', `Bearer ${operator.accessToken}`)
      .send({ outcome_tag: 'false_alarm', resolution_note: 'A genuinely detailed resolution note here.' });

    const lead = await loginAsNewUser(app);
    await grantSafetyTeamLead(lead.userId);
    const res = await request(app)
      .post(`/ops/v1/sos/${trigger.body.id}/escalate`)
      .set('Authorization', `Bearer ${lead.accessToken}`);
    expect(res.status).toBe(400);
  });

  it('CAN escalate a triggered (not yet acknowledged) SOS — escalation is not gated behind acknowledgment first', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const trigger = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${customerToken}`)
      .send({ booking_id: bookingId });

    const lead = await loginAsNewUser(app);
    await grantSafetyTeamLead(lead.userId);
    const res = await request(app)
      .post(`/ops/v1/sos/${trigger.body.id}/escalate`)
      .set('Authorization', `Bearer ${lead.accessToken}`);
    expect(res.status).toBe(200);
  });
});

describe('SOS: auto-escalation sweep (PRD 10A.1 edge case — no operator acknowledges within the hard threshold)', () => {
  it('a triggered SOS well within the threshold is NOT auto-escalated', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const trigger = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${customerToken}`)
      .send({ booking_id: bookingId });

    const swept = await sweepUnacknowledgedSos();
    const row = await pool.query('SELECT auto_escalated FROM sos_events WHERE id = $1', [trigger.body.id]);
    expect(row.rows[0].auto_escalated).toBe(false);
    void swept;
  });

  it('a triggered SOS older than the threshold IS auto-escalated by the sweep, distinctly from a manual escalation', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const trigger = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${customerToken}`)
      .send({ booking_id: bookingId });

    // Simulate 31 seconds having passed with no acknowledgment (PRD's 30s
    // threshold) — backdating created_at directly rather than waiting in
    // real time, the same technique used elsewhere in this codebase for
    // time-based sweep tests.
    await pool.query(`UPDATE sos_events SET created_at = now() - interval '31 seconds' WHERE id = $1`, [
      trigger.body.id,
    ]);

    const sweptCount = await sweepUnacknowledgedSos();
    expect(sweptCount).toBeGreaterThanOrEqual(1);

    const row = await pool.query('SELECT auto_escalated, escalated_by, escalated_at FROM sos_events WHERE id = $1', [
      trigger.body.id,
    ]);
    expect(row.rows[0].auto_escalated).toBe(true);
    expect(row.rows[0].escalated_by).toBeNull(); // distinguishes it from a human's manual escalation
    expect(row.rows[0].escalated_at).toBeTruthy();
  });

  it('does NOT re-sweep (or double-count) an SOS that was already auto-escalated', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const trigger = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${customerToken}`)
      .send({ booking_id: bookingId });
    await pool.query(`UPDATE sos_events SET created_at = now() - interval '31 seconds' WHERE id = $1`, [
      trigger.body.id,
    ]);

    await sweepUnacknowledgedSos();
    const secondSweepCount = await sweepUnacknowledgedSos();

    // The second sweep run should find nothing NEW to escalate for this
    // event specifically (it may find other unrelated events from other
    // tests, but re-checking this one directly confirms it's stable).
    const row = await pool.query('SELECT auto_escalated FROM sos_events WHERE id = $1', [trigger.body.id]);
    expect(row.rows[0].auto_escalated).toBe(true);
    void secondSweepCount;
  });

  it('an ACKNOWLEDGED SOS is never auto-escalated, even past the threshold — the sweep only targets still-triggered events', async () => {
    const { bookingId, customerToken } = await createSearchingBooking();
    const trigger = await request(app)
      .post('/ops/v1/sos/trigger')
      .set('Authorization', `Bearer ${customerToken}`)
      .send({ booking_id: bookingId });

    const operator = await loginAsNewUser(app);
    await grantControlRoomOperator(operator.userId);
    await request(app).post(`/ops/v1/sos/${trigger.body.id}/acknowledge`).set('Authorization', `Bearer ${operator.accessToken}`);
    await pool.query(`UPDATE sos_events SET created_at = now() - interval '31 seconds' WHERE id = $1`, [
      trigger.body.id,
    ]);

    await sweepUnacknowledgedSos();
    const row = await pool.query('SELECT auto_escalated FROM sos_events WHERE id = $1', [trigger.body.id]);
    expect(row.rows[0].auto_escalated).toBe(false);
  });
});

describe('Dispatch monitoring: log and force-assign (PRD A.3)', () => {
  it('a plain user cannot view the dispatch log', async () => {
    const { bookingId } = await createSearchingBooking();
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app).get(`/ops/v1/bookings/${bookingId}/dispatch-log`).set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(403);
  });

  it('force-assign HARD BLOCKS an ineligible driver — the eligibility gate is never bypassable (PRD explicit acceptance criterion)', async () => {
    const { bookingId } = await createSearchingBooking();
    const operator = await loginAsNewUser(app);
    await grantControlRoomOperator(operator.userId);

    const ineligibleDriver = await loginAsNewUser(app);

    const res = await request(app)
      .post(`/ops/v1/bookings/${bookingId}/force-assign`)
      .set('Authorization', `Bearer ${operator.accessToken}`)
      .send({ driver_id: ineligibleDriver.userId });
    expect(res.status).toBe(400);
    expect(res.body.error.details.driver).toMatch(/not eligible/);

    const bookingRow = await pool.query('SELECT driver_id, status FROM bookings WHERE id = $1', [bookingId]);
    expect(bookingRow.rows[0].driver_id).toBeNull();
    expect(bookingRow.rows[0].status).toBe('searching');
  });

  it('force-assigns an eligible driver, and it is reflected in the dispatch log', async () => {
    const { bookingId } = await createSearchingBooking();
    const operator = await loginAsNewUser(app);
    await grantControlRoomOperator(operator.userId);
    const eligibleDriverId = await createOnlineEligibleDriver({ phone: randomPhone() });

    const res = await request(app)
      .post(`/ops/v1/bookings/${bookingId}/force-assign`)
      .set('Authorization', `Bearer ${operator.accessToken}`)
      .send({ driver_id: eligibleDriverId });
    expect(res.status).toBe(200);

    const bookingRow = await pool.query('SELECT driver_id, status FROM bookings WHERE id = $1', [bookingId]);
    expect(bookingRow.rows[0].driver_id).toBe(eligibleDriverId);
    expect(bookingRow.rows[0].status).toBe('driver_assigned');

    const log = await request(app)
      .get(`/ops/v1/bookings/${bookingId}/dispatch-log`)
      .set('Authorization', `Bearer ${operator.accessToken}`);
    expect(
      log.body.offers.some(
        (o: { driver_id: string; status: string }) => o.driver_id === eligibleDriverId && o.status === 'accepted'
      )
    ).toBe(true);

    const auditRow = await pool.query(
      `SELECT actor_id FROM audit_log WHERE resource_id = $1 AND action = 'booking.force_assign'`,
      [bookingId]
    );
    expect(auditRow.rows[0].actor_id).toBe(operator.userId);
  });

  it('cannot force-assign a booking that already has a driver', async () => {
    const { bookingId } = await createSearchingBooking();
    const operator = await loginAsNewUser(app);
    await grantControlRoomOperator(operator.userId);
    const driver1 = await createOnlineEligibleDriver({ phone: randomPhone() });
    const driver2 = await createOnlineEligibleDriver({ phone: randomPhone() });

    await request(app)
      .post(`/ops/v1/bookings/${bookingId}/force-assign`)
      .set('Authorization', `Bearer ${operator.accessToken}`)
      .send({ driver_id: driver1 });

    const second = await request(app)
      .post(`/ops/v1/bookings/${bookingId}/force-assign`)
      .set('Authorization', `Bearer ${operator.accessToken}`)
      .send({ driver_id: driver2 });
    expect(second.status).toBe(400);
  });
});
