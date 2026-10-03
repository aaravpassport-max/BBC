import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser } from '../../../test-utils/helpers';
import { assertReferenceSeedPresent, samplePickupDrop } from '../../../test-utils/seed';

const app = createApp();

beforeAll(async () => {
  await assertReferenceSeedPresent();
});

afterAll(async () => {
  await pool.end();
});

describe('Pricing: multi-category quotes (P1 gap-analysis item — vehicle selection)', () => {
  it('omitting vehicle_category returns quotes for every published category on this route, not just one', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${accessToken}`)
      .send(samplePickupDrop()); // no vehicle_category — the whole point of this test
    expect(res.status).toBe(200);
    expect(res.body.quotes.length).toBe(5);

    const categories = res.body.quotes.map((q: { vehicle_category: string }) => q.vehicle_category).sort();
    expect(categories).toEqual(['large_truck', 'mini_truck', 'pickup_truck', 'three_wheeler', 'two_wheeler']);
  });

  it('the five categories are genuinely, distinctly priced — not the same fare with different labels', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${accessToken}`)
      .send(samplePickupDrop());

    const fares = res.body.quotes.map((q: { vehicle_category: string; fare_breakdown: { final_fare: number } }) => ({
      category: q.vehicle_category,
      fare: q.fare_breakdown.final_fare,
    }));
    const uniqueFares = new Set(fares.map((f: { fare: number }) => f.fare));
    expect(uniqueFares.size).toBe(5); // no two categories coincidentally landed on the same fare

    // Real tier ordering, matching the seeded rate cards: two_wheeler
    // cheapest through large_truck most expensive, for the identical
    // route — proves the category actually drives the price, not just the
    // label.
    const byCategory = Object.fromEntries(fares.map((f: { category: string; fare: number }) => [f.category, f.fare]));
    expect(byCategory.two_wheeler).toBeLessThan(byCategory.three_wheeler);
    expect(byCategory.three_wheeler).toBeLessThan(byCategory.mini_truck);
    expect(byCategory.mini_truck).toBeLessThan(byCategory.pickup_truck);
    expect(byCategory.pickup_truck).toBeLessThan(byCategory.large_truck);
  });

  it('specifying a single vehicle_category still returns exactly that one, unchanged behavior', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ ...samplePickupDrop(), vehicle_category: 'two_wheeler' });
    expect(res.status).toBe(200);
    expect(res.body.quotes.length).toBe(1);
    expect(res.body.quotes[0].vehicle_category).toBe('two_wheeler');
  });

  it('each quote in a multi-category response is independently bookable', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const quote = await request(app)
      .post('/v1/pricing/quote')
      .set('Authorization', `Bearer ${accessToken}`)
      .send(samplePickupDrop());

    const chosenQuote = quote.body.quotes.find((q: { vehicle_category: string }) => q.vehicle_category === 'three_wheeler');
    const booking = await request(app)
      .post('/v1/bookings')
      .set('Authorization', `Bearer ${accessToken}`)
      .set('Idempotency-Key', `multicat-${crypto.randomUUID()}`)
      .send({ quote_id: chosenQuote.quote_id, payment_method: 'wallet' });
    expect(booking.status).toBe(201);

    const category = await pool.query(
      `SELECT vc.name FROM bookings b JOIN vehicle_categories vc ON vc.id = b.vehicle_category_id WHERE b.id = $1`,
      [booking.body.id]
    );
    expect(category.rows[0].name).toBe('three_wheeler');
  });
});
