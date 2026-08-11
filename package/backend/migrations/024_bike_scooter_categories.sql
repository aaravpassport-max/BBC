-- Bike and scooter as distinct customer-facing vehicle types (Porter parity)

BEGIN;

INSERT INTO vehicle_categories (id, name, capacity_descriptor, license_class_required, permit_required, status) VALUES
  ('a1b2c3d4-e5f6-4789-a012-3456789abcde', 'bike', 'Documents & small parcels, up to 10kg', 'MC', false, 'active'),
  ('b2c3d4e5-f6a7-4890-b123-456789abcdef', 'scooter', 'Parcels up to 20kg', 'MC', false, 'active')
ON CONFLICT (id) DO NOTHING;

INSERT INTO rate_cards (id, city_id, vehicle_category_id, base_fare, per_km_rate, per_min_rate,
                         waiting_free_min, waiting_per_min_rate, night_surcharge_pct,
                         night_window_start, night_window_end, minimum_fare, platform_fee, tax_rate_pct,
                         status, effective_from)
VALUES
  ('c3d4e5f6-a7b8-4901-c234-56789abcdef0', 'f7a78914-17a4-4b8f-91c9-79d76c87c9b9',
   'a1b2c3d4-e5f6-4789-a012-3456789abcde',
   20.00, 5.00, 0.80, 5, 1.00, 10.00, '22:00', '06:00', 30.00, 4.00, 5.00, 'published', now()),
  ('d4e5f6a7-b8c9-4012-d345-6789abcdef01', 'f7a78914-17a4-4b8f-91c9-79d76c87c9b9',
   'b2c3d4e5-f6a7-4890-b123-456789abcdef',
   25.00, 6.00, 1.00, 5, 1.00, 10.00, '22:00', '06:00', 35.00, 5.00, 5.00, 'published', now())
ON CONFLICT (id) DO NOTHING;

COMMIT;
