import { pool } from '../../db/pool';
import { Errors } from '../../utils/errors';

export async function getProfile(userId: string) {
  const result = await pool.query(
    `SELECT id, phone, country_code, email, name, locale, gstin, billing_address, business_name
     FROM users WHERE id = $1 AND deleted_at IS NULL`,
    [userId]
  );
  if (result.rowCount === 0) {
    throw Errors.notFound('User');
  }
  const row = result.rows[0];
  return {
    id: row.id,
    phone: row.phone,
    country_code: row.country_code,
    email: row.email,
    name: row.name,
    locale: row.locale,
    gstin: row.gstin,
    billing_address: row.billing_address,
    business_name: row.business_name,
  };
}

export async function updateProfile(
  userId: string,
  updates: {
    name?: string;
    email?: string | null;
    locale?: string;
    gstin?: string | null;
    billing_address?: string | null;
    business_name?: string | null;
  }
) {
  const fields: string[] = [];
  const values: unknown[] = [];
  let idx = 1;

  for (const [key, value] of Object.entries(updates)) {
    if (value !== undefined) {
      fields.push(`${key} = $${idx++}`);
      values.push(value);
    }
  }
  if (fields.length === 0) {
    return getProfile(userId);
  }
  fields.push('updated_at = now()');
  values.push(userId);

  await pool.query(`UPDATE users SET ${fields.join(', ')} WHERE id = $${idx} AND deleted_at IS NULL`, values);
  return getProfile(userId);
}
