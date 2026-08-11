import { pool } from '../../db/pool';
import { Errors } from '../../utils/errors';

export async function listRecentAddresses(userId: string, limit = 8) {
  const result = await pool.query(
    `SELECT id, label, address_line, lat, lng, landmark, contact_name, contact_phone,
            is_default, is_favourite, last_used_at, usage_count, created_at
     FROM saved_addresses
     WHERE user_id = $1 AND last_used_at IS NOT NULL
     ORDER BY last_used_at DESC
     LIMIT $2`,
    [userId, limit]
  );
  return result.rows;
}

export async function listFavouriteAddresses(userId: string) {
  const result = await pool.query(
    `SELECT id, label, address_line, lat, lng, landmark, contact_name, contact_phone,
            is_default, is_favourite, last_used_at, usage_count, created_at
     FROM saved_addresses
     WHERE user_id = $1 AND is_favourite = true
     ORDER BY label ASC`,
    [userId]
  );
  return result.rows;
}

export async function setAddressFavourite(userId: string, addressId: string, isFavourite: boolean) {
  const result = await pool.query(
    `UPDATE saved_addresses SET is_favourite = $1, updated_at = now()
     WHERE id = $2 AND user_id = $3
     RETURNING id, label, address_line, lat, lng, landmark, contact_name, contact_phone,
               is_default, is_favourite, last_used_at, usage_count, created_at`,
    [isFavourite, addressId, userId]
  );
  if (result.rowCount === 0) {
    throw Errors.notFound('Address');
  }
  return result.rows[0];
}

export async function recordAddressUsage(
  userId: string,
  params: {
    label: string;
    address_line: string;
    lat: number;
    lng: number;
    landmark?: string | null;
    contact_name?: string | null;
    contact_phone?: string | null;
  }
) {
  const existing = await pool.query(
    `SELECT id FROM saved_addresses
     WHERE user_id = $1 AND abs(lat - $2) < 0.0001 AND abs(lng - $3) < 0.0001
     ORDER BY created_at DESC LIMIT 1`,
    [userId, params.lat, params.lng]
  );

  if (existing.rowCount && existing.rowCount > 0) {
    const id = existing.rows[0].id as string;
    await pool.query(
      `UPDATE saved_addresses
       SET usage_count = usage_count + 1, last_used_at = now(), updated_at = now(),
           label = COALESCE($2, label),
           address_line = COALESCE($3, address_line),
           landmark = COALESCE($4, landmark),
           contact_name = COALESCE($5, contact_name),
           contact_phone = COALESCE($6, contact_phone)
       WHERE id = $1`,
      [
        id,
        params.label,
        params.address_line,
        params.landmark ?? null,
        params.contact_name ?? null,
        params.contact_phone ?? null,
      ]
    );
    return id;
  }

  const inserted = await pool.query(
    `INSERT INTO saved_addresses
       (user_id, label, address_line, lat, lng, landmark, contact_name, contact_phone, last_used_at, usage_count)
     VALUES ($1, $2, $3, $4, $5, $6, $7, $8, now(), 1)
     RETURNING id`,
    [
      userId,
      params.label,
      params.address_line,
      params.lat,
      params.lng,
      params.landmark ?? null,
      params.contact_name ?? null,
      params.contact_phone ?? null,
    ]
  );
  return inserted.rows[0].id as string;
}

export async function listBookingTemplates(userId: string) {
  const result = await pool.query(
    `SELECT id, name, snapshot, is_favourite, last_used_at, usage_count, created_at, updated_at
     FROM booking_templates WHERE user_id = $1
     ORDER BY is_favourite DESC, last_used_at DESC NULLS LAST, created_at DESC`,
    [userId]
  );
  return result.rows;
}

export async function createBookingTemplate(userId: string, name: string, snapshot: Record<string, unknown>) {
  const result = await pool.query(
    `INSERT INTO booking_templates (user_id, name, snapshot, is_favourite, last_used_at, usage_count)
     VALUES ($1, $2, $3, true, now(), 1)
     RETURNING id, name, snapshot, is_favourite, last_used_at, usage_count, created_at, updated_at`,
    [userId, name, JSON.stringify(snapshot)]
  );
  return result.rows[0];
}

export async function deleteBookingTemplate(userId: string, templateId: string) {
  const result = await pool.query(
    `DELETE FROM booking_templates WHERE id = $1 AND user_id = $2 RETURNING id`,
    [templateId, userId]
  );
  if (result.rowCount === 0) {
    throw Errors.notFound('Template');
  }
}

export async function touchBookingTemplate(userId: string, templateId: string) {
  await pool.query(
    `UPDATE booking_templates SET usage_count = usage_count + 1, last_used_at = now(), updated_at = now()
     WHERE id = $1 AND user_id = $2`,
    [templateId, userId]
  );
}
