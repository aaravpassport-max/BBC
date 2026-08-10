import { pool } from '../../db/pool';
import { Errors } from '../../utils/errors';

export async function listAddresses(userId: string) {
  const result = await pool.query(
    `SELECT id, label, address_line, lat, lng, landmark, contact_name, contact_phone, is_default, created_at
     FROM saved_addresses WHERE user_id = $1 ORDER BY is_default DESC, created_at DESC`,
    [userId]
  );
  return result.rows;
}

export async function createAddress(
  userId: string,
  params: {
    label: string;
    address_line: string;
    lat: number;
    lng: number;
    landmark?: string;
    contact_name?: string;
    contact_phone?: string;
    is_default?: boolean;
  }
) {
  if (params.is_default) {
    await pool.query(`UPDATE saved_addresses SET is_default = false WHERE user_id = $1`, [userId]);
  }
  const result = await pool.query(
    `INSERT INTO saved_addresses (user_id, label, address_line, lat, lng, landmark, contact_name, contact_phone, is_default)
     VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)
     RETURNING id, label, address_line, lat, lng, landmark, contact_name, contact_phone, is_default, created_at`,
    [
      userId,
      params.label,
      params.address_line,
      params.lat,
      params.lng,
      params.landmark || null,
      params.contact_name || null,
      params.contact_phone || null,
      params.is_default ?? false,
    ]
  );
  return result.rows[0];
}

export async function updateAddress(
  userId: string,
  addressId: string,
  params: Partial<{
    label: string;
    address_line: string;
    lat: number;
    lng: number;
    landmark: string;
    contact_name: string;
    contact_phone: string;
    is_default: boolean;
  }>
) {
  const existing = await pool.query(`SELECT id FROM saved_addresses WHERE id = $1 AND user_id = $2`, [
    addressId,
    userId,
  ]);
  if (existing.rowCount === 0) {
    throw Errors.notFound('Address');
  }
  if (params.is_default) {
    await pool.query(`UPDATE saved_addresses SET is_default = false WHERE user_id = $1`, [userId]);
  }
  const fields: string[] = [];
  const values: unknown[] = [];
  let idx = 1;
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined) {
      fields.push(`${key} = $${idx++}`);
      values.push(value);
    }
  }
  if (fields.length === 0) {
    const row = await pool.query(`SELECT * FROM saved_addresses WHERE id = $1`, [addressId]);
    return row.rows[0];
  }
  fields.push('updated_at = now()');
  values.push(addressId, userId);
  const result = await pool.query(
    `UPDATE saved_addresses SET ${fields.join(', ')} WHERE id = $${idx} AND user_id = $${idx + 1} RETURNING *`,
    values
  );
  return result.rows[0];
}

export async function deleteAddress(userId: string, addressId: string) {
  const result = await pool.query(`DELETE FROM saved_addresses WHERE id = $1 AND user_id = $2 RETURNING id`, [
    addressId,
    userId,
  ]);
  if (result.rowCount === 0) {
    throw Errors.notFound('Address');
  }
}
