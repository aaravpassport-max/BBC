import { pool } from '../../db/pool';
import { Errors } from '../../utils/errors';

const VALID_DEEP_LINK_ROUTES = ['home', 'wallet', 'referral', 'subscriptions', 'trip-history', 'booking'];

function isValidDeepLink(link: string): boolean {
  if (link.startsWith('http://') || link.startsWith('https://')) return true;
  const route = link.replace(/^\//, '').split('/')[0];
  return VALID_DEEP_LINK_ROUTES.includes(route);
}

export async function createBanner(params: {
  headline: string;
  imageUrl: string;
  ctaText?: string;
  ctaDeepLink: string;
  linkedCouponId?: string;
  targetSegment?: string;
  priority?: number;
  startAt: string;
  endAt: string;
  createdBy: string;
}): Promise<{ id: string }> {
  const result = await pool.query(
    `INSERT INTO banners (headline, image_url, cta_text, cta_deep_link, linked_coupon_id,
                           target_segment, priority, start_at, end_at, created_by)
     VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
     RETURNING id`,
    [
      params.headline,
      params.imageUrl,
      params.ctaText || null,
      params.ctaDeepLink,
      params.linkedCouponId || null,
      params.targetSegment || null,
      params.priority || 0,
      params.startAt,
      params.endAt,
      params.createdBy,
    ]
  );
  return { id: result.rows[0].id };
}

/**
 * Publishes a draft banner — blocked if the deep link doesn't resolve (PRD
 * 9B.1 hard rule) or if the linked coupon (if any) is no longer active,
 * since a banner promoting a dead coupon is exactly the "health-check"
 * failure mode the PRD calls out.
 */
export async function publishBanner(bannerId: string): Promise<void> {
  const banner = await pool.query(`SELECT * FROM banners WHERE id = $1`, [bannerId]);
  if (banner.rowCount === 0) {
    throw Errors.notFound('Banner');
  }
  const row = banner.rows[0];

  if (!isValidDeepLink(row.cta_deep_link)) {
    throw Errors.validation({ cta_deep_link: "This link doesn't resolve to a valid destination." });
  }

  if (row.linked_coupon_id) {
    const coupon = await pool.query(`SELECT status FROM coupons WHERE id = $1`, [row.linked_coupon_id]);
    if (coupon.rowCount === 0 || coupon.rows[0].status !== 'active') {
      throw Errors.validation({
        linked_coupon_id: 'The linked coupon is not currently active — activate it before publishing this banner.',
      });
    }
  }

  const status = new Date(row.start_at) > new Date() ? 'scheduled' : 'live';
  await pool.query(`UPDATE banners SET status = $1 WHERE id = $2`, [status, bannerId]);
}

export async function getActiveBannersForUser(segment: string | null) {
  const result = await pool.query(
    `SELECT id, headline, image_url, cta_text, cta_deep_link
     FROM banners
     WHERE status = 'live'
       AND start_at <= now() AND end_at >= now()
       AND (target_segment IS NULL OR target_segment = $1)
     ORDER BY priority DESC, created_at DESC`,
    [segment]
  );
  return result.rows;
}

export async function listBanners() {
  const result = await pool.query(`SELECT * FROM banners ORDER BY created_at DESC`);
  return result.rows;
}

export async function findUnhealthyBanners() {
  const result = await pool.query(
    `SELECT b.id, b.headline, c.status AS coupon_status
     FROM banners b
     JOIN coupons c ON c.id = b.linked_coupon_id
     WHERE b.status = 'live' AND c.status != 'active'`
  );
  return result.rows;
}
