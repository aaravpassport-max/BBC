import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser } from '../../../test-utils/helpers';
import { sendNotification } from '../notifications.service';

const app = createApp();

afterAll(async () => {
  await pool.end();
});

describe('Notifications: preferences (PRD 16A.1)', () => {
  it('defaults every category/channel to enabled for a user who has never toggled anything', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app).get('/v1/notifications/preferences').set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body.length).toBeGreaterThan(0);
    expect(res.body.every((p: { enabled: boolean }) => p.enabled === true)).toBe(true);
  });

  it('can disable a toggleable category/channel, and it persists', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const update = await request(app)
      .put('/v1/notifications/preferences')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ category: 'promotions', channel: 'push', enabled: false });
    expect(update.status).toBe(200);

    const prefs = await request(app).get('/v1/notifications/preferences').set('Authorization', `Bearer ${accessToken}`);
    const promoPush = prefs.body.find((p: { category: string; channel: string }) => p.category === 'promotions' && p.channel === 'push');
    expect(promoPush.enabled).toBe(false);
  });

  it('structurally rejects disabling the otp category via a crafted request (PRD 16A.1 hard rule)', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .put('/v1/notifications/preferences')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ category: 'otp', channel: 'sms', enabled: false });
    expect(res.status).toBe(400);
  });

  it('structurally rejects disabling the sos category via a crafted request', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .put('/v1/notifications/preferences')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ category: 'sos', channel: 'push', enabled: false });
    expect(res.status).toBe(400);
  });
});

describe('Notifications: send idempotency and preference enforcement (PRD 16A.2, Section 22)', () => {
  it('does not send to a category/channel the user has disabled', async () => {
    const { accessToken, userId } = await loginAsNewUser(app);
    await request(app)
      .put('/v1/notifications/preferences')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ category: 'promotions', channel: 'push', enabled: false });

    const result = await sendNotification({
      eventId: crypto.randomUUID(),
      userId,
      category: 'promotions',
      channel: 'push',
      templateId: 'promo_banner_1',
    });
    expect(result.sent).toBe(false);
    expect(result.skippedReason).toBe('user_opted_out');
  });

  it('SOS notifications bypass user preference entirely, even if every channel is disabled', async () => {
    const { accessToken, userId } = await loginAsNewUser(app);
    // Disable everything toggleable — irrelevant, since 'sos' isn't in the
    // toggleable set and sendNotification checks category membership, not
    // any stored row for 'sos' (which can never exist per the PUT-side rule).
    for (const category of ['trip_updates', 'promotions', 'account_activity', 'product_news']) {
      await request(app)
        .put('/v1/notifications/preferences')
        .set('Authorization', `Bearer ${accessToken}`)
        .send({ category, channel: 'push', enabled: false });
    }

    const result = await sendNotification({
      eventId: crypto.randomUUID(),
      userId,
      category: 'sos',
      channel: 'push',
      templateId: 'sos_triggered',
    });
    expect(result.sent).toBe(true);
  });

  it('duplicate event delivery for the same event_id+channel does not send twice', async () => {
    const { userId } = await loginAsNewUser(app);
    const eventId = crypto.randomUUID();

    const first = await sendNotification({ eventId, userId, category: 'trip_updates', channel: 'push', templateId: 'driver_arrived' });
    const second = await sendNotification({ eventId, userId, category: 'trip_updates', channel: 'push', templateId: 'driver_arrived' });

    expect(first.sent).toBe(true);
    expect(second.sent).toBe(false);
    expect(second.skippedReason).toBe('duplicate_event');

    const logCount = await pool.query('SELECT count(*) FROM notification_log WHERE event_id = $1', [eventId]);
    expect(parseInt(logCount.rows[0].count, 10)).toBe(1);
  });

  it('the same event_id on a DIFFERENT channel is a separate, valid send (fallback pattern, PRD 16A.2)', async () => {
    const { userId } = await loginAsNewUser(app);
    const eventId = crypto.randomUUID();

    const push = await sendNotification({ eventId, userId, category: 'trip_updates', channel: 'push', templateId: 'driver_arrived' });
    const smsFallback = await sendNotification({ eventId, userId, category: 'trip_updates', channel: 'sms', templateId: 'driver_arrived' });

    expect(push.sent).toBe(true);
    expect(smsFallback.sent).toBe(true);
  });

  it('inbox reflects sent notifications in reverse-chronological order', async () => {
    const { accessToken, userId } = await loginAsNewUser(app);
    await sendNotification({ eventId: crypto.randomUUID(), userId, category: 'trip_updates', channel: 'push', templateId: 'first' });
    await sendNotification({ eventId: crypto.randomUUID(), userId, category: 'trip_updates', channel: 'push', templateId: 'second' });

    const inbox = await request(app).get('/v1/notifications/inbox').set('Authorization', `Bearer ${accessToken}`);
    expect(inbox.body.length).toBe(2);
    expect(inbox.body[0].template_id).toBe('second');
  });
});
