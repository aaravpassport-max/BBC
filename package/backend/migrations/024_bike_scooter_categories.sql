-- Bike and scooter as distinct customer-facing vehicle types (Porter parity).
-- Rate cards for these categories live in seed/001_reference_data.sql because
-- cities are seeded there (migrations must not reference seed-only FK rows).

BEGIN;

INSERT INTO vehicle_categories (id, name, capacity_descriptor, license_class_required, permit_required, status) VALUES
  ('a1b2c3d4-e5f6-4789-a012-3456789abcde', 'bike', 'Documents & small parcels, up to 10kg', 'MC', false, 'active'),
  ('b2c3d4e5-f6a7-4890-b123-456789abcdef', 'scooter', 'Parcels up to 20kg', 'MC', false, 'active')
ON CONFLICT (id) DO NOTHING;

COMMIT;
