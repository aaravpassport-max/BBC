import { pool } from '../db/pool';

// REAL FIX: these four constants previously pointed at placeholder IDs
// ('11111111...' / '22222222...' / '33333333...' / '44444444...') that the
// actual shipped seed script (backend/seed/001_reference_data.sql) never
// creates — it inserts the city/zone/category/rate-card below with
// different, real IDs. This was masked for a long time by a manually
// inserted row left over from early debugging that happened to sit in the
// long-lived dev/test database — assertReferenceSeedPresent kept finding
// that stray row and passing, even though a genuinely fresh database
// seeded ONLY from the shipped SQL (exactly what a new developer following
// the README would do) would fail here. Corrected to the values the seed
// script actually produces.
export const SEED_CITY_ID = 'f7a78914-17a4-4b8f-91c9-79d76c87c9b9';
export const SEED_ZONE_ID = '802e94e0-2fa7-4359-b9e6-93b8b76085c1';
export const SEED_CATEGORY_ID = 'cc0530bc-0866-406f-86cd-2244d997ea9f';
export const SEED_RATE_CARD_ID = '5eea6b99-1170-4d46-bfa4-c2d650d4d117';

// A second, RFC4122-compliant-named alias for the seeded reference data.
// ADMIN_SEED_CITY_ID shares the same city as SEED_CITY_ID (the city itself
// isn't the collision risk), but ADMIN_SEED_CATEGORY_ID deliberately
// points at a SEPARATE vehicle_categories row from SEED_CATEGORY_ID's
// 'mini_truck' — see the seed script's own comment on this: admin.test.ts
// repeatedly publishes new rate cards for whatever (city, category) pair
// it's given, which supersedes the previous published card for that same
// pair. If admin tests targeted 'mini_truck' directly, every admin-test
// run would silently overwrite the rate card every pricing/quote test
// depends on with one that never sets platform_fee — an actual bug this
// exact collapse caused earlier in this file's history, caught by the
// subscription-benefit pricing tests failing with platform_fee=0.
export const ADMIN_SEED_CITY_ID = 'f7a78914-17a4-4b8f-91c9-79d76c87c9b9';
export const ADMIN_SEED_CATEGORY_ID = 'dad7b98b-b54a-4890-9da7-73a645b9de7a';

/** Confirms the fixed reference seed (city/zone/category/rate-card) that the
 * test DB's own SQL seed script inserts once is present — tests don't
 * recreate it per-run since it's stable, immutable reference data shared
 * across the whole suite (mirrors how the dev DB is seeded once, not
 * per-request). Fails loudly if a fresh test DB was never seeded, rather
 * than every test silently failing on an unrelated foreign-key error. */
export async function assertReferenceSeedPresent(): Promise<void> {
  const result = await pool.query('SELECT id FROM rate_cards WHERE id = $1', [SEED_RATE_CARD_ID]);
  if (result.rowCount === 0) {
    throw new Error(
      'Test database reference seed (city/zone/category/rate-card) is missing. ' +
        'Run the seed SQL against logistics_superapp_test before running tests — see README.'
    );
  }
}

/**
 * Looks up a seeded role's ACTUAL id by name (REAL FIX, same root cause as
 * the SEED_* constants above: 9 test files across the suite hardcoded
 * literal role UUIDs like '99999999-3333-...' for 'ops_admin', which the
 * real seed script's `INSERT INTO roles (...) VALUES (...) RETURNING id`
 * never produces — it's a fresh gen_random_uuid() every time the DO block
 * runs. Those hardcoded values only ever worked against a stray manually-
 * inserted row from early debugging that happened to persist in the
 * long-lived dev/test database. Looking the role up by its stable NAME,
 * which the seed script's `ON CONFLICT (name) DO NOTHING` makes the real
 * durable identity, is the actual fix — not another hardcoded guess.
 */
export async function getRoleIdByName(name: string): Promise<string> {
  const result = await pool.query('SELECT id FROM roles WHERE name = $1', [name]);
  if (result.rowCount === 0) {
    throw new Error(`Role '${name}' not found — is the reference seed script applied to this database?`);
  }
  return result.rows[0].id;
}

const PICKUP = { lat: 12.952, lng: 77.602 };
const DROP = { lat: 12.97, lng: 77.62 };

export async function createCoupon(params: {
  code: string;
  discountValue: number;
  globalLimit?: number | null;
  perUserLimit?: number | null;
}): Promise<string> {
  const id = crypto.randomUUID();
  await pool.query(
    `INSERT INTO coupons (id, code, discount_type, discount_value, min_order_value, per_user_limit, global_limit, valid_from, valid_to, status)
     VALUES ($1, $2, 'flat', $3, 0, $4, $5, now() - interval '1 day', now() + interval '30 days', 'active')`,
    [id, params.code, params.discountValue, params.perUserLimit ?? null, params.globalLimit ?? null]
  );
  return id;
}

export async function createCorporateAccount(params: { name: string; creditLimit: number }): Promise<string> {
  const id = crypto.randomUUID();
  await pool.query(
    `INSERT INTO corporate_accounts (id, name, credit_limit, committed_spend, reserved_spend, status)
     VALUES ($1, $2, $3, 0, 0, 'active')`,
    [id, params.name, params.creditLimit]
  );
  return id;
}

export async function addCorporateEmployee(params: { accountId: string; userId: string; email: string }): Promise<void> {
  await pool.query(
    `INSERT INTO corporate_employees (corporate_account_id, user_id, email, status)
     VALUES ($1, $2, $3, 'active')`,
    [params.accountId, params.userId, params.email]
  );
}

export async function createOnlineEligibleDriver(params: { phone: string }): Promise<string> {
  const driverId = crypto.randomUUID();
  await pool.query(
    `INSERT INTO users (id, phone, country_code, account_type) VALUES ($1, $2, '+91', 'driver')`,
    [driverId, params.phone]
  );
  await pool.query(
    `INSERT INTO driver_profiles (user_id, kyc_status, training_status, online_status, current_lat, current_lng, last_ping_at)
     VALUES ($1, 'approved', 'passed', true, $2, $3, now())`,
    [driverId, PICKUP.lat, PICKUP.lng]
  );
  const vehicleId = crypto.randomUUID();
  await pool.query(
    `INSERT INTO vehicles (id, owner_type, owner_id, category, plate_number, status)
     VALUES ($1, 'driver', $2, 'mini_truck', $3, 'active')`,
    [vehicleId, driverId, `TEST-${driverId.slice(0, 8)}`]
  );
  await pool.query(
    `INSERT INTO driver_vehicle_assignment (driver_id, vehicle_id, is_active) VALUES ($1, $2, true)`,
    [driverId, vehicleId]
  );
  return driverId;
}

/** A driver + vehicle owned by a specific fleet (not an independent
 * owner-driver like createOnlineEligibleDriver above) — for Fleet module
 * tests (PRD Section 13). */
export async function createFleetDriverAndVehicle(params: {
  fleetOwnerId: string;
  phone: string;
}): Promise<{ driverId: string; vehicleId: string }> {
  const driverId = crypto.randomUUID();
  await pool.query(
    `INSERT INTO users (id, phone, country_code, account_type) VALUES ($1, $2, '+91', 'driver')`,
    [driverId, params.phone]
  );
  await pool.query(
    `INSERT INTO driver_profiles (user_id, kyc_status, training_status, online_status, current_lat, current_lng, last_ping_at, fleet_owner_id)
     VALUES ($1, 'approved', 'passed', true, $2, $3, now(), $4)`,
    [driverId, PICKUP.lat, PICKUP.lng, params.fleetOwnerId]
  );
  const vehicleId = crypto.randomUUID();
  await pool.query(
    `INSERT INTO vehicles (id, owner_type, owner_id, category, plate_number, status)
     VALUES ($1, 'fleet', $2, 'mini_truck', $3, 'active')`,
    [vehicleId, params.fleetOwnerId, `FLEET-${driverId.slice(0, 8)}`]
  );
  await pool.query(
    `INSERT INTO driver_vehicle_assignment (driver_id, vehicle_id, is_active) VALUES ($1, $2, true)`,
    [driverId, vehicleId]
  );
  return { driverId, vehicleId };
}

export function samplePickupDrop() {
  return { pickup: PICKUP, drops: [DROP] };
}
