import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser } from '../../../test-utils/helpers';
import { samplePickupDrop } from '../../../test-utils/seed';
import { sweepScheduledBookings } from '../../driver/dispatch.service';

const app = createApp();

afterAll(async () => {
  await pool.end();
});

async function getQuoteId(accessToken: string) {
  const quote = await request(app)
    .post('/v1/pricing/quote')
    .set('Authorization', `Bearer ${accessToken}`)
    .send({ ...samplePickupDrop(), vehicle_category: 'mini_truck' });
  return quote.body.quotes[0].quote_id;
}

function futureIso(minutesFromNow: number): string {
  return new Date(Date.now() + minutesFromNow * 60 * 1000).toISOString();
}

describe('Scheduled bookings: creation (P1 gap-analysis item)', () => {
  it('a booking with no scheduled_for still enters SEARCHING immediately — unchanged instant-booking behavior', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const quoteId = await getQuoteId(accessToken);
    const res = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', `sched-instant-${crypto.randomUUID()}`)
      .send({ quote_id: quoteId, payment_method: 'wallet' });
    expect(res.status).toBe(201);
    expect(res.body.status).toBe('searching');
  });

  it('a booking with a valid future scheduled_for enters SCHEDULED, not SEARCHING', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const quoteId = await getQuoteId(accessToken);
    const res = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', `sched-future-${crypto.randomUUID()}`)
      .send({ quote_id: quoteId, payment_method: 'wallet', scheduled_for: futureIso(120) });
    expect(res.status).toBe(201);
    expect(res.body.status).toBe('scheduled');

    const row = await pool.query('SELECT scheduled_at FROM bookings WHERE id = $1', [res.body.id]);
    expect(row.rows[0].scheduled_at).not.toBeNull();
  });

  it('rejects a scheduled_for less than the minimum lead time away', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const quoteId = await getQuoteId(accessToken);
    const res = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', `sched-tooclose-${crypto.randomUUID()}`)
      .send({ quote_id: quoteId, payment_method: 'wallet', scheduled_for: futureIso(5) });
    expect(res.status).toBe(400);
  });

  it('rejects a scheduled_for in the past', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const quoteId = await getQuoteId(accessToken);
    const res = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', `sched-past-${crypto.randomUUID()}`)
      .send({ quote_id: quoteId, payment_method: 'wallet', scheduled_for: futureIso(-60) });
    expect(res.status).toBe(400);
  });

  it('rejects a scheduled_for too far in the future', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const quoteId = await getQuoteId(accessToken);
    const res = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', `sched-toofar-${crypto.randomUUID()}`)
      .send({ quote_id: quoteId, payment_method: 'wallet', scheduled_for: futureIso(60 * 24 * 30) });
    expect(res.status).toBe(400);
  });

  it('a scheduled booking can be cancelled with no fee, same as an un-dispatched instant booking', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const quoteId = await getQuoteId(accessToken);
    const created = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', `sched-cancel-${crypto.randomUUID()}`)
      .send({ quote_id: quoteId, payment_method: 'wallet', scheduled_for: futureIso(120) });

    const cancel = await request(app)
      .post(`/v1/bookings/${created.body.id}/cancel`)
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ reason_code: 'BOOKED_BY_MISTAKE' });
    expect(cancel.status).toBe(200);
    expect(cancel.body.fee_charged).toBe(false);
  });
});

describe('Scheduled bookings: the real dispatch trigger job (previously — before this pass — nothing ever transitioned a scheduled booking out of that status)', () => {
  it('does nothing to a booking whose scheduled time is still far away', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const quoteId = await getQuoteId(accessToken);
    const created = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', `sched-far-${crypto.randomUUID()}`)
      .send({ quote_id: quoteId, payment_method: 'wallet', scheduled_for: futureIso(60 * 24) });

    await sweepScheduledBookings();

    const row = await pool.query('SELECT status FROM bookings WHERE id = $1', [created.body.id]);
    expect(row.rows[0].status).toBe('scheduled');
  });

  it('transitions a booking into real dispatch once it enters the lead-time window', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const quoteId = await getQuoteId(accessToken);
    const created = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', `sched-due-${crypto.randomUUID()}`)
      .send({ quote_id: quoteId, payment_method: 'wallet', scheduled_for: futureIso(35) });

    await pool.query(`UPDATE bookings SET scheduled_at = now() + interval '10 minutes' WHERE id = $1`, [
      created.body.id,
    ]);

    const rowsAffected = await sweepScheduledBookings();
    expect(rowsAffected).toBeGreaterThanOrEqual(1);

    const row = await pool.query('SELECT status FROM bookings WHERE id = $1', [created.body.id]);
    expect(row.rows[0].status).not.toBe('scheduled');
  });

  it('is idempotent — running it twice never dispatches the same booking twice', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const quoteId = await getQuoteId(accessToken);
    const created = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', `sched-idem-${crypto.randomUUID()}`)
      .send({ quote_id: quoteId, payment_method: 'wallet', scheduled_for: futureIso(35) });
    await pool.query(`UPDATE bookings SET scheduled_at = now() WHERE id = $1`, [created.body.id]);

    await sweepScheduledBookings();
    const statusAfterFirst = await pool.query('SELECT status FROM bookings WHERE id = $1', [created.body.id]);

    await sweepScheduledBookings();
    const statusAfterSecond = await pool.query('SELECT status FROM bookings WHERE id = $1', [created.body.id]);

    expect(statusAfterFirst.rows[0].status).toBe(statusAfterSecond.rows[0].status);
  });
});
