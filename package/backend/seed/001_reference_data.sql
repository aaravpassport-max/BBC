-- Seed data: reference geography/pricing + baseline RBAC roles.
-- Run this once after applying all migrations (001-010) to either the dev
-- or test database. Every ID here is a properly-generated RFC4122 UUID —
-- an earlier draft of this seed used simple placeholder literals like
-- '11111111-1111-...' which are NOT RFC4122-compliant (wrong variant
-- nibble) and were correctly rejected by strict UUID validation on any
-- endpoint using Zod's z.string().uuid(). Lesson learned the hard way
-- during development — kept here as a comment so it isn't repeated.

BEGIN;

-- ---------- Reference city, zone, vehicle category, rate card ----------

INSERT INTO cities (id, name, country, timezone, currency, status)
VALUES ('f7a78914-17a4-4b8f-91c9-79d76c87c9b9', 'Bengaluru', 'IN', 'Asia/Kolkata', 'INR', 'active')
ON CONFLICT (id) DO NOTHING;

INSERT INTO zones (id, city_id, name, zone_type, boundary)
VALUES (
  '802e94e0-2fa7-4359-b9e6-93b8b76085c1',
  'f7a78914-17a4-4b8f-91c9-79d76c87c9b9',
  'Bengaluru Core',
  'service_area',
  ST_GeogFromText('SRID=4326;POLYGON((77.4 12.8, 77.8 12.8, 77.8 13.1, 77.4 13.1, 77.4 12.8))')
) ON CONFLICT (id) DO NOTHING;

INSERT INTO vehicle_categories (id, name, capacity_descriptor, license_class_required, permit_required, status)
VALUES ('cc0530bc-0866-406f-86cd-2244d997ea9f', 'mini_truck', 'Up to 750kg', 'LMV', false, 'active')
ON CONFLICT (id) DO NOTHING;

-- P1 gap-analysis item: the backend's quote endpoint has always supported
-- returning multiple vehicle-category options for one route (vehicle_
-- category is OPTIONAL on the request — see pricing.service.ts), but only
-- one real category ever existed to return. These four, matching Porter's
-- actual published lineup, are what makes vehicle selection a genuine
-- customer-facing choice rather than a UI that could only ever show one
-- option.
INSERT INTO vehicle_categories (id, name, capacity_descriptor, license_class_required, permit_required, status) VALUES
  ('c19526d0-1baf-4e63-8228-f014108dbc31', 'two_wheeler', 'Small parcels, up to 20kg', 'MC', false, 'active'),
  ('ada6b667-eae7-4c13-8841-946be998f936', 'three_wheeler', 'Up to 500kg', 'LMV', false, 'active'),
  ('861c719c-fdc4-4c66-b24c-fb04793d8343', 'pickup_truck', 'Up to 1500kg', 'LMV', true, 'active'),
  ('3c022fa4-ce1c-4769-91f9-42770616dd83', 'large_truck', 'Up to 5000kg', 'HMV', true, 'active')
ON CONFLICT (id) DO NOTHING;

-- A SEPARATE category, deliberately distinct from 'mini_truck' above.
-- Admin rate-card tests (admin.test.ts) repeatedly create+publish new rate
-- cards for whatever (city, category) pair they're given, and "publishing
-- a new card supersedes the previously-published one for the same
-- city+category" is itself a real, intentional acceptance criterion of
-- that endpoint. If admin tests targeted the SAME category as 'mini_truck'
-- pricing/quote tests use, every admin-test run would silently supersede
-- the real seeded rate card (platform_fee=10.00) with a test one that
-- never sets platform_fee (defaulting to 0) — breaking every pricing test
-- that runs afterward in the same database. Keeping these genuinely
-- separate categories is what actually prevents that collision.
-- status='inactive' — this exists purely for admin rate-card CRUD tests to
-- publish cards against without colliding with 'mini_truck' (see above);
-- it must never be a real customer-bookable option. The pricing quote
-- query filters on vehicle_categories.status = 'active' specifically so
-- this can never leak into a real quote regardless of whether it happens
-- to have a published rate card at any given moment.
INSERT INTO vehicle_categories (id, name, capacity_descriptor, license_class_required, permit_required, status)
VALUES ('dad7b98b-b54a-4890-9da7-73a645b9de7a', 'admin_test_category', 'Test-only category for admin rate-card tests', 'LMV', false, 'inactive')
ON CONFLICT (id) DO UPDATE SET status = 'inactive';

INSERT INTO rate_cards (id, city_id, vehicle_category_id, base_fare, per_km_rate, per_min_rate,
                         waiting_free_min, waiting_per_min_rate, night_surcharge_pct,
                         night_window_start, night_window_end, minimum_fare, platform_fee, tax_rate_pct,
                         status, effective_from)
VALUES (
  '5eea6b99-1170-4d46-bfa4-c2d650d4d117',
  'f7a78914-17a4-4b8f-91c9-79d76c87c9b9',
  'cc0530bc-0866-406f-86cd-2244d997ea9f',
  60.00, 12.00, 1.50, 5, 2.00, 15.00, '22:00', '06:00', 80.00, 10.00, 5.00,
  'published', now()
) ON CONFLICT (id) DO NOTHING;

-- Tiered rate cards for the 4 categories above — same real Bengaluru
-- city/zone, real published rate cards, genuinely different economics per
-- tier so the customer app's vehicle-selection screen shows real price
-- differentiation, not four identical numbers with different names.
INSERT INTO rate_cards (id, city_id, vehicle_category_id, base_fare, per_km_rate, per_min_rate,
                         waiting_free_min, waiting_per_min_rate, night_surcharge_pct,
                         night_window_start, night_window_end, minimum_fare, platform_fee, tax_rate_pct,
                         status, effective_from)
VALUES
  -- two_wheeler: cheapest, fastest for small parcels
  ('56aceefc-1c1b-447d-90cd-5ea8c1b630aa', 'f7a78914-17a4-4b8f-91c9-79d76c87c9b9',
   'c19526d0-1baf-4e63-8228-f014108dbc31',
   25.00, 6.00, 1.00, 5, 1.00, 10.00, '22:00', '06:00', 35.00, 5.00, 5.00, 'published', now()),
  -- three_wheeler: auto-rickshaw tier, between two-wheeler and mini_truck
  ('7d13ca34-01f3-46c7-9f5a-5ac7bf47f287', 'f7a78914-17a4-4b8f-91c9-79d76c87c9b9',
   'ada6b667-eae7-4c13-8841-946be998f936',
   40.00, 9.00, 1.20, 5, 1.50, 12.00, '22:00', '06:00', 55.00, 8.00, 5.00, 'published', now()),
  -- pickup_truck: above mini_truck, permit-required heavier tier
  ('caccd15c-8366-4ba5-9aff-22bad1f2ecf4', 'f7a78914-17a4-4b8f-91c9-79d76c87c9b9',
   '861c719c-fdc4-4c66-b24c-fb04793d8343',
   90.00, 16.00, 1.80, 5, 2.50, 18.00, '22:00', '06:00', 120.00, 15.00, 5.00, 'published', now()),
  -- large_truck: top tier, heavy-vehicle license class, highest permit/platform fee
  ('62d3437b-6ff3-4de7-a04f-1f66408ac49e', 'f7a78914-17a4-4b8f-91c9-79d76c87c9b9',
   '3c022fa4-ce1c-4769-91f9-42770616dd83',
   150.00, 22.00, 2.20, 10, 3.00, 20.00, '22:00', '06:00', 200.00, 25.00, 5.00, 'published', now())
ON CONFLICT (id) DO NOTHING;

-- ---------- Baseline permissions ----------

INSERT INTO permissions (resource, action) VALUES
  ('pricing', 'edit'),
  ('driver', 'suspend'),
  ('driver', 'kyc_review'),
  ('fraud', 'review'),
  ('support', 'ticket_manage'),
  ('analytics', 'view'),
  ('rbac', 'role_manage'),
  ('marketing', 'cms_manage'),
  ('ops', 'sos_respond'),
  ('ops', 'sos_escalate'),
  ('ops', 'dispatch_override')
ON CONFLICT (resource, action) DO NOTHING;

-- ---------- Baseline roles ----------

DO $$
DECLARE
  ops_admin_id UUID;
  kyc_reviewer_id UUID;
  support_agent_id UUID;
  control_room_operator_id UUID;
  safety_team_lead_id UUID;
BEGIN
  INSERT INTO roles (name, description) VALUES ('ops_admin', 'Full operations administrator')
  ON CONFLICT (name) DO NOTHING RETURNING id INTO ops_admin_id;
  IF ops_admin_id IS NULL THEN
    SELECT id INTO ops_admin_id FROM roles WHERE name = 'ops_admin';
  END IF;

  INSERT INTO roles (name, description) VALUES ('kyc_reviewer', 'Can review driver KYC submissions')
  ON CONFLICT (name) DO NOTHING RETURNING id INTO kyc_reviewer_id;
  IF kyc_reviewer_id IS NULL THEN
    SELECT id INTO kyc_reviewer_id FROM roles WHERE name = 'kyc_reviewer';
  END IF;

  INSERT INTO roles (name, description) VALUES ('support_agent', 'Can manage support tickets')
  ON CONFLICT (name) DO NOTHING RETURNING id INTO support_agent_id;
  IF support_agent_id IS NULL THEN
    SELECT id INTO support_agent_id FROM roles WHERE name = 'support_agent';
  END IF;

  -- Distinct from ops_admin (PRD 10A.1: "ops.sos.respond broadly granted to
  -- on-duty Control Room staff") — a real deployment staffs the live
  -- Control Room with a much larger, narrower-permissioned team than the
  -- handful of full ops_admin accounts, so this is its own role rather
  -- than reusing ops_admin's broad permission set.
  INSERT INTO roles (name, description) VALUES ('control_room_operator', 'On-duty Ops/Control Room staff: SOS response and dispatch monitoring')
  ON CONFLICT (name) DO NOTHING RETURNING id INTO control_room_operator_id;
  IF control_room_operator_id IS NULL THEN
    SELECT id INTO control_room_operator_id FROM roles WHERE name = 'control_room_operator';
  END IF;

  -- PRD 10A.1: "ops.sos.escalate for secondary-tier actions" — deliberately
  -- a SEPARATE, smaller role from control_room_operator. Every on-duty
  -- operator can acknowledge/resolve an SOS; escalating it to the safety
  -- team lead is a smaller, higher-trust action set, matching the PRD's
  -- explicit two-permission split rather than folding escalate into the
  -- broad on-duty-staff role.
  INSERT INTO roles (name, description) VALUES ('safety_team_lead', 'Can be escalated to on an SOS event, beyond standard on-duty response')
  ON CONFLICT (name) DO NOTHING RETURNING id INTO safety_team_lead_id;
  IF safety_team_lead_id IS NULL THEN
    SELECT id INTO safety_team_lead_id FROM roles WHERE name = 'safety_team_lead';
  END IF;

  INSERT INTO role_permissions (role_id, permission_id)
  SELECT ops_admin_id, id FROM permissions
  WHERE (resource, action) IN (
    ('pricing', 'edit'), ('driver', 'suspend'), ('fraud', 'review'),
    ('analytics', 'view'), ('rbac', 'role_manage'), ('marketing', 'cms_manage'),
    ('support', 'ticket_manage'), ('driver', 'kyc_review'),
    ('ops', 'sos_respond'), ('ops', 'sos_escalate'), ('ops', 'dispatch_override')
  )
  ON CONFLICT DO NOTHING;

  INSERT INTO role_permissions (role_id, permission_id)
  SELECT kyc_reviewer_id, id FROM permissions WHERE (resource, action) = ('driver', 'kyc_review')
  ON CONFLICT DO NOTHING;

  INSERT INTO role_permissions (role_id, permission_id)
  SELECT support_agent_id, id FROM permissions WHERE (resource, action) = ('support', 'ticket_manage')
  ON CONFLICT DO NOTHING;

  INSERT INTO role_permissions (role_id, permission_id)
  SELECT control_room_operator_id, id FROM permissions
  WHERE (resource, action) IN (('ops', 'sos_respond'), ('ops', 'dispatch_override'))
  ON CONFLICT DO NOTHING;

  -- A safety team lead can do everything a normal on-duty operator can,
  -- PLUS escalate — not a narrower role, a superset with one extra
  -- permission, matching how an actual safety escalation contact would
  -- need full context to act, not just the ability to escalate blind.
  INSERT INTO role_permissions (role_id, permission_id)
  SELECT safety_team_lead_id, id FROM permissions
  WHERE (resource, action) IN (('ops', 'sos_respond'), ('ops', 'sos_escalate'))
  ON CONFLICT DO NOTHING;
END $$;

COMMIT;

-- To grant yourself ops_admin after creating an account via the normal OTP
-- login flow, run:
--   INSERT INTO user_roles (user_id, role_id)
--   VALUES ('<your user id>', (SELECT id FROM roles WHERE name = 'ops_admin'));
