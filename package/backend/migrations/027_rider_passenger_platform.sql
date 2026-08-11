-- Rider/Passenger booking platform — extends parcel infrastructure with booking_type

BEGIN;

-- ---------- Booking type on core tables ----------

ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS booking_type VARCHAR(20) NOT NULL DEFAULT 'parcel',
  ADD COLUMN IF NOT EXISTS passenger_count INT NOT NULL DEFAULT 1;

ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_booking_type_check;
ALTER TABLE bookings ADD CONSTRAINT bookings_booking_type_check
  CHECK (booking_type IN ('parcel', 'ride'));

ALTER TABLE quotes
  ADD COLUMN IF NOT EXISTS booking_type VARCHAR(20) NOT NULL DEFAULT 'parcel';

-- Extended lifecycle statuses for passenger rides
ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_status_check;
ALTER TABLE bookings ADD CONSTRAINT bookings_status_check
  CHECK (status IN (
    'searching', 'scheduled', 'no_drivers_found', 'driver_assigned',
    'driver_arriving', 'driver_arrived', 'in_progress', 'completed', 'cancelled'
  ));

-- Ride stops do not require delivery OTP
ALTER TABLE booking_stops DROP CONSTRAINT IF EXISTS booking_stops_delivery_preference_check;
ALTER TABLE booking_stops ADD CONSTRAINT booking_stops_delivery_preference_check
  CHECK (delivery_preference IN ('otp', 'photo_proof', 'none'));

-- ---------- Vehicle categories: parcel vs ride ----------

ALTER TABLE vehicle_categories
  ADD COLUMN IF NOT EXISTS booking_types TEXT[] NOT NULL DEFAULT ARRAY['parcel']::TEXT[];

UPDATE vehicle_categories
SET booking_types = ARRAY['parcel']::TEXT[]
WHERE booking_types = ARRAY['parcel']::TEXT[] OR booking_types IS NULL;

UPDATE vehicle_categories
SET booking_types = ARRAY['parcel', 'ride']::TEXT[]
WHERE name IN ('bike', 'scooter');

-- Passenger ride categories (Uber/Rapido-style)
INSERT INTO vehicle_categories (id, name, capacity_descriptor, license_class_required, permit_required, status, booking_types)
VALUES
  ('f1a2b3c4-d5e6-4789-a012-3456789abcde', 'auto', '3-passenger auto rickshaw', 'LMV', false, 'active', ARRAY['ride']::TEXT[]),
  ('a2b3c4d5-e6f7-4890-b123-456789abcdef', 'hatchback', 'Compact car — up to 4 passengers', 'LMV', false, 'active', ARRAY['ride']::TEXT[]),
  ('b3c4d5e6-f7a8-4901-c234-56789abcdef0', 'sedan', 'Sedan — up to 4 passengers', 'LMV', false, 'active', ARRAY['ride']::TEXT[]),
  ('c4d5e6f7-a8b9-4012-d345-6789abcdef01', 'suv', 'SUV — up to 6 passengers', 'LMV', false, 'active', ARRAY['ride']::TEXT[])
ON CONFLICT (id) DO UPDATE SET
  booking_types = EXCLUDED.booking_types,
  capacity_descriptor = EXCLUDED.capacity_descriptor,
  status = 'active';

-- Published rate cards for ride categories (Bengaluru — same city as seed data)
INSERT INTO rate_cards (id, city_id, vehicle_category_id, base_fare, per_km_rate, per_min_rate,
                         waiting_free_min, waiting_per_min_rate, night_surcharge_pct,
                         night_window_start, night_window_end, minimum_fare, platform_fee, tax_rate_pct,
                         status, effective_from)
VALUES
  ('e5f6a7b8-c9d0-4012-e345-6789abcdef02', 'f7a78914-17a4-4b8f-91c9-79d76c87c9b9',
   'f1a2b3c4-d5e6-4789-a012-3456789abcde',
   30.00, 8.00, 1.00, 5, 1.00, 10.00, '22:00', '06:00', 40.00, 5.00, 5.00, 'published', now()),
  ('f6a7b8c9-d0e1-4123-f456-789abcdef012', 'f7a78914-17a4-4b8f-91c9-79d76c87c9b9',
   'a2b3c4d5-e6f7-4890-b123-456789abcdef',
   50.00, 12.00, 1.50, 5, 1.50, 12.00, '22:00', '06:00', 60.00, 8.00, 5.00, 'published', now()),
  ('a7b8c9d0-e1f2-4234-a567-89abcdef0123', 'f7a78914-17a4-4b8f-91c9-79d76c87c9b9',
   'b3c4d5e6-f7a8-4901-c234-56789abcdef0',
   60.00, 14.00, 1.80, 5, 2.00, 12.00, '22:00', '06:00', 70.00, 10.00, 5.00, 'published', now()),
  ('b8c9d0e1-f2a3-4345-b678-9abcdef01234', 'f7a78914-17a4-4b8f-91c9-79d76c87c9b9',
   'c4d5e6f7-a8b9-4012-d345-6789abcdef01',
   80.00, 18.00, 2.00, 5, 2.50, 15.00, '22:00', '06:00', 100.00, 12.00, 5.00, 'published', now())
ON CONFLICT (id) DO NOTHING;

COMMIT;
