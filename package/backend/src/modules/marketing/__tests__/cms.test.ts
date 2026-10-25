import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser } from '../../../test-utils/helpers';
import { createCoupon, getRoleIdByName } from '../../../test-utils/seed';

const app = createApp();

afterAll(async () => {
  await pool.end();
});

async function grantAdmin(userId: string) {
  const roleId = await getRoleIdByName('ops_admin');
  await pool.query(`INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`, [
    userId,
    roleId,
  ]);
}

function futureRange() {
  return {
    start_at: new Date(Date.now() - 1000).toISOString(),
    end_at: new Date(Date.now() + 1000 * 60 * 60 * 24 * 7).toISOString(),
  };
}

describe('CMS: RBAC and creation', () => {
  it('a plain user cannot create a banner', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/cms/banners')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ headline: 'x', image_url: 'https://x.com/a.png', cta_deep_link: '/home', ...futureRange() });
    expect(res.status).toBe(403);
  });

  it('creates a draft banner', async () => {
    const admin = await loginAsNewUser(app);
    await grantAdmin(admin.userId);
    const res = await request(app)
      .post('/v1/cms/banners')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ headline: '20% off your first move', image_url: 'https://x.com/a.png', cta_deep_link: '/home', ...futureRange() });
    expect(res.status).toBe(201);

    const row = await pool.query('SELECT status FROM banners WHERE id = $1', [res.body.id]);
    expect(row.rows[0].status).toBe('draft');
  });
});

describe('CMS: publish validation (PRD 9B.1 hard rule)', () => {
  it('blocks publishing a banner with an unresolvable deep link', async () => {
    const admin = await loginAsNewUser(app);
    await grantAdmin(admin.userId);
    const banner = await request(app)
      .post('/v1/cms/banners')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ headline: 'x', image_url: 'https://x.com/a.png', cta_deep_link: '/not-a-real-route', ...futureRange() });

    const publish = await request(app)
      .post(`/v1/cms/banners/${banner.body.id}/publish`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(publish.status).toBe(400);

    const row = await pool.query('SELECT status FROM banners WHERE id = $1', [banner.body.id]);
    expect(row.rows[0].status).toBe('draft');
  });

  it('publishes successfully with a valid in-app deep link', async () => {
    const admin = await loginAsNewUser(app);
    await grantAdmin(admin.userId);
    const banner = await request(app)
      .post('/v1/cms/banners')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ headline: 'x', image_url: 'https://x.com/a.png', cta_deep_link: '/wallet', ...futureRange() });

    const publish = await request(app)
      .post(`/v1/cms/banners/${banner.body.id}/publish`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(publish.status).toBe(200);

    const row = await pool.query('SELECT status FROM banners WHERE id = $1', [banner.body.id]);
    expect(row.rows[0].status).toBe('live');
  });

  it('blocks publishing a banner linked to a non-active coupon', async () => {
    const admin = await loginAsNewUser(app);
    await grantAdmin(admin.userId);
    const couponId = await createCoupon({ code: `CMSTEST${Date.now()}`, discountValue: 10 });
    await pool.query(`UPDATE coupons SET status = 'paused' WHERE id = $1`, [couponId]);

    const banner = await request(app)
      .post('/v1/cms/banners')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({
        headline: 'x',
        image_url: 'https://x.com/a.png',
        cta_deep_link: '/home',
        linked_coupon_id: couponId,
        ...futureRange(),
      });

    const publish = await request(app)
      .post(`/v1/cms/banners/${banner.body.id}/publish`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(publish.status).toBe(400);
  });
});

describe('CMS: active banner visibility (PRD Screen 2.2.3)', () => {
  it('a customer sees a live, in-window, untargeted banner', async () => {
    const admin = await loginAsNewUser(app);
    await grantAdmin(admin.userId);
    const banner = await request(app)
      .post('/v1/cms/banners')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ headline: 'Visible to everyone', image_url: 'https://x.com/a.png', cta_deep_link: '/home', ...futureRange() });
    await request(app)
      .post(`/v1/cms/banners/${banner.body.id}/publish`)
      .set('Authorization', `Bearer ${admin.accessToken}`);

    const customer = await loginAsNewUser(app);
    const active = await request(app).get('/v1/cms/banners/active').set('Authorization', `Bearer ${customer.accessToken}`);
    expect(active.body.some((b: { id: string }) => b.id === banner.body.id)).toBe(true);
  });

  it('a banner targeted at a different segment is NOT shown to an unmatched user', async () => {
    const admin = await loginAsNewUser(app);
    await grantAdmin(admin.userId);
    const banner = await request(app)
      .post('/v1/cms/banners')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({
        headline: 'VIP only',
        image_url: 'https://x.com/a.png',
        cta_deep_link: '/home',
        target_segment: 'vip_customers',
        ...futureRange(),
      });
    await request(app)
      .post(`/v1/cms/banners/${banner.body.id}/publish`)
      .set('Authorization', `Bearer ${admin.accessToken}`);

    const customer = await loginAsNewUser(app);
    const active = await request(app).get('/v1/cms/banners/active').set('Authorization', `Bearer ${customer.accessToken}`);
    expect(active.body.some((b: { id: string }) => b.id === banner.body.id)).toBe(false);

    const vipActive = await request(app)
      .get('/v1/cms/banners/active?segment=vip_customers')
      .set('Authorization', `Bearer ${customer.accessToken}`);
    expect(vipActive.body.some((b: { id: string }) => b.id === banner.body.id)).toBe(true);
  });

  it('a draft (unpublished) banner is never visible to customers', async () => {
    const admin = await loginAsNewUser(app);
    await grantAdmin(admin.userId);
    const banner = await request(app)
      .post('/v1/cms/banners')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ headline: 'Still a draft', image_url: 'https://x.com/a.png', cta_deep_link: '/home', ...futureRange() });

    const customer = await loginAsNewUser(app);
    const active = await request(app).get('/v1/cms/banners/active').set('Authorization', `Bearer ${customer.accessToken}`);
    expect(active.body.some((b: { id: string }) => b.id === banner.body.id)).toBe(false);
  });
});
