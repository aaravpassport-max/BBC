-- Demo users for physical-device testing with fixed OTP codes.
-- Requires ALLOW_TEST_OTP=true on the backend and running this seed after 001.
-- OTPs are never stored here — they are fixed in auth otp-test.ts / OTP_TEST_PHONES.

BEGIN;

-- Customer demo account (Customer app: phone 9000000001, OTP 111111)
INSERT INTO users (id, phone, country_code, account_type, name)
VALUES (
  'e9c8b7a6-0001-4000-8000-000000000001',
  '9000000001',
  '+91',
  'customer',
  'Demo Customer'
)
ON CONFLICT (country_code, phone) WHERE deleted_at IS NULL DO NOTHING;

-- Driver demo account (Driver app: phone 9000000002, OTP 222222)
-- Fully onboarded so testers skip KYC/training/vehicle registration.
INSERT INTO users (id, phone, country_code, account_type, name)
VALUES (
  'e9c8b7a6-0002-4000-8000-000000000002',
  '9000000002',
  '+91',
  'driver',
  'Demo Driver'
)
ON CONFLICT (country_code, phone) WHERE deleted_at IS NULL DO NOTHING;

INSERT INTO driver_profiles (
  user_id,
  kyc_status,
  training_status,
  online_status,
  current_lat,
  current_lng,
  last_ping_at
)
VALUES (
  'e9c8b7a6-0002-4000-8000-000000000002',
  'approved',
  'passed',
  false,
  12.952,
  77.602,
  now()
)
ON CONFLICT (user_id) DO UPDATE SET
  kyc_status = 'approved',
  training_status = 'passed',
  current_lat = EXCLUDED.current_lat,
  current_lng = EXCLUDED.current_lng;

INSERT INTO vehicles (id, owner_type, owner_id, category, plate_number, status)
VALUES (
  'e9c8b7a6-0003-4000-8000-000000000003',
  'driver',
  'e9c8b7a6-0002-4000-8000-000000000002',
  'mini_truck',
  'DEMO-KA01',
  'active'
)
ON CONFLICT (id) DO NOTHING;

INSERT INTO driver_vehicle_assignment (driver_id, vehicle_id, is_active, effective_from)
VALUES (
  'e9c8b7a6-0002-4000-8000-000000000002',
  'e9c8b7a6-0003-4000-8000-000000000003',
  true,
  '2026-01-01T00:00:00Z'
)
ON CONFLICT (driver_id, vehicle_id, effective_from) DO UPDATE SET is_active = true;

COMMIT;
