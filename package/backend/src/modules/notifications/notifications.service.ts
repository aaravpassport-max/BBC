import { createHash } from 'crypto';
import { pool } from '../../db/pool';
import { Errors } from '../../utils/errors';
import * as fcmProvider from './fcm.provider';
import * as emailProvider from './email.provider';

/**
 * Derives a deterministic, syntactically-valid UUID from a domain event's
 * natural key (e.g. `${bookingId}:driver_assigned`) — REAL FIX for a bug
 * caught by this module's own tests: notification_log.event_id is a genuine
 * UUID column (migration 005), and idempotency dedup is scoped to
 * (event_id, channel) alone (migration 009's own comment confirms this is
 * the intended, correct scope). A composite string like that can't be
 * inserted into a UUID column at all. Hashing the natural key into a
 * deterministic UUID-shaped value means the SAME event always produces the
 * SAME event_id (real idempotency), while different events/bookings never
 * collide (a real cryptographic hash, not a guessed pattern).
 */
export function deriveEventId(naturalKey: string): string {
  const hash = createHash('sha256').update(naturalKey).digest('hex');
  return [hash.slice(0, 8), hash.slice(8, 12), '4' + hash.slice(13, 16), '8' + hash.slice(17, 20), hash.slice(20, 32)].join('-');
}

const NON_TOGGLEABLE_CATEGORIES = ['otp', 'sos'];
const VALID_CATEGORIES = ['trip_updates', 'promotions', 'account_activity', 'product_news'];
const VALID_CHANNELS = ['push', 'sms', 'whatsapp', 'email'];

export async function getPreferences(userId: string) {
  const result = await pool.query(
    `SELECT category, channel, enabled FROM notification_preferences WHERE user_id = $1`,
    [userId]
  );
  const stored = new Map(result.rows.map((r) => [`${r.category}:${r.channel}`, r.enabled]));

  // Every category/channel combination defaults to enabled=true if the user
  // has never explicitly toggled it — this list is generated, not read
  // directly from the DB, so a user with zero rows still sees a complete,
  // correctly-defaulted preference grid (PRD 16A.1 Screen behavior).
  const all = [];
  for (const category of VALID_CATEGORIES) {
    for (const channel of VALID_CHANNELS) {
      const key = `${category}:${channel}`;
      all.push({ category, channel, enabled: stored.has(key) ? stored.get(key) : true });
    }
  }
  return all;
}

/**
 * PRD 16A.1 hard rule: OTP and SOS categories are not settable here — not
 * "settable but ignored," structurally rejected, so a crafted client request
 * can never even attempt it.
 */
export async function setPreference(params: {
  userId: string;
  category: string;
  channel: string;
  enabled: boolean;
}): Promise<void> {
  const { userId, category, channel, enabled } = params;

  if (NON_TOGGLEABLE_CATEGORIES.includes(category)) {
    throw Errors.validation({ category: 'This notification category cannot be disabled.' });
  }
  if (!VALID_CATEGORIES.includes(category)) {
    throw Errors.validation({ category: 'Unknown notification category.' });
  }
  if (!VALID_CHANNELS.includes(channel)) {
    throw Errors.validation({ channel: 'Unknown notification channel.' });
  }

  await pool.query(
    `INSERT INTO notification_preferences (user_id, category, channel, enabled)
     VALUES ($1, $2, $3, $4)
     ON CONFLICT (user_id, category, channel) DO UPDATE SET enabled = $4`,
    [userId, category, channel, enabled]
  );
}

interface SendResult {
  sent: boolean;
  skippedReason?: 'user_opted_out' | 'duplicate_event';
}

/**
 * Sends (simulates, in this reference implementation — see the dev-only
 * console.log pattern established in auth.service) a notification for a
 * domain event. Idempotent on (event_id, channel) per PRD Section 22's
 * at-least-once event delivery assumption — a duplicate call for the same
 * event+channel is a silent no-op, never a duplicate user-facing send (PRD
 * 16A.2 acceptance criteria). OTP/SOS categories bypass the user's
 * preference entirely (PRD Section 16 rule: never suppressible for
 * security-critical or safety-critical sends).
 */
export async function sendNotification(params: {
  eventId: string;
  userId: string;
  category: string;
  channel: string;
  templateId: string;
  isFallback?: boolean;
}): Promise<SendResult> {
  const { eventId, userId, category, channel, templateId } = params;

  if (!NON_TOGGLEABLE_CATEGORIES.includes(category)) {
    const pref = await pool.query(
      `SELECT enabled FROM notification_preferences WHERE user_id = $1 AND category = $2 AND channel = $3`,
      [userId, category, channel]
    );
    const enabled = pref.rowCount && pref.rowCount > 0 ? pref.rows[0].enabled : true; // default-enabled, PRD 16A.1
    if (!enabled) {
      return { sent: false, skippedReason: 'user_opted_out' };
    }
  }

  try {
    await pool.query(
      `INSERT INTO notification_log (user_id, event_id, category, channel, template_id, status)
       VALUES ($1, $2, $3, $4, $5, 'sent')`,
      [userId, eventId, category, channel, templateId]
    );
  } catch (err) {
    const pgErr = err as { code?: string };
    if (pgErr.code === '23505') {
      // uq_notification_event_channel (migration 005) — duplicate event
      // delivery for the same channel is a no-op, not a second send.
      return { sent: false, skippedReason: 'duplicate_event' };
    }
    throw err;
  }

  if (channel === 'push') {
    const tokens = await pool.query(`SELECT token FROM device_tokens WHERE user_id = $1`, [userId]);
    const tokenList = tokens.rows.map((t) => t.token as string);

    if (fcmProvider.isConfigured() && tokenList.length > 0) {
      const result = await fcmProvider.sendPush({ tokens: tokenList, templateId });
      for (const stale of result.invalidTokens) {
        await pool.query(`DELETE FROM device_tokens WHERE user_id = $1 AND token = $2`, [userId, stale]);
      }
    } else {
      console.log(
        `[DEV ONLY] Push to ${tokenList.length} device(s): user=${userId} template=${templateId}`
      );
    }
  } else if (channel === 'email') {
    const user = await pool.query(`SELECT phone, name FROM users WHERE id = $1`, [userId]);
    const email = `${user.rows[0]?.phone}@customers.portmystuff.local`;
    await emailProvider.sendEmail({
      to: email,
      subject: `PORTMYSTUFF — ${templateId}`,
      text: `Notification: ${templateId} (category: ${category})`,
    });
  } else {
    console.log(`[DEV ONLY] Notification sent: user=${userId} category=${category} channel=${channel} template=${templateId}`);
  }

  return { sent: true };
}

export async function registerDeviceToken(params: {
  userId: string;
  platform: string;
  token: string;
}): Promise<void> {
  const { userId, platform, token } = params;
  await pool.query(
    `INSERT INTO device_tokens (user_id, platform, token, updated_at)
     VALUES ($1, $2, $3, now())
     ON CONFLICT (user_id, token) DO UPDATE SET platform = $2, updated_at = now()`,
    [userId, platform, token]
  );
}

export async function unregisterDeviceToken(params: { userId: string; token: string }): Promise<void> {
  await pool.query(`DELETE FROM device_tokens WHERE user_id = $1 AND token = $2`, [params.userId, params.token]);
}

export async function getInbox(userId: string) {
  const result = await pool.query(
    `SELECT id, category, channel, template_id, status, created_at
     FROM notification_log WHERE user_id = $1 ORDER BY created_at DESC LIMIT 50`,
    [userId]
  );
  return result.rows;
}
