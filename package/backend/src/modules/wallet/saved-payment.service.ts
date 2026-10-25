import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';

export async function listSavedPaymentMethods(userId: string) {
  const result = await pool.query(
    `SELECT id, provider, method_type, display_label, is_default, created_at
     FROM saved_payment_methods WHERE user_id = $1 ORDER BY is_default DESC, created_at DESC`,
    [userId]
  );
  return result.rows;
}

export async function savePaymentMethod(params: {
  userId: string;
  methodType: 'card' | 'upi';
  displayLabel: string;
  tokenRef: string;
  setDefault?: boolean;
}): Promise<{ id: string }> {
  const { userId, methodType, displayLabel, tokenRef, setDefault } = params;

  return withTransaction(async (client) => {
    if (setDefault) {
      await client.query(`UPDATE saved_payment_methods SET is_default = false WHERE user_id = $1`, [userId]);
    }

    const existingDefault = await client.query(
      `SELECT count(*) FROM saved_payment_methods WHERE user_id = $1 AND is_default = true`,
      [userId]
    );
    const makeDefault = setDefault || parseInt(existingDefault.rows[0].count, 10) === 0;

    const result = await client.query(
      `INSERT INTO saved_payment_methods (user_id, method_type, display_label, token_ref, is_default)
       VALUES ($1, $2, $3, $4, $5) RETURNING id`,
      [userId, methodType, displayLabel, tokenRef, makeDefault]
    );
    return { id: result.rows[0].id };
  });
}

export async function deletePaymentMethod(userId: string, methodId: string): Promise<void> {
  const result = await pool.query(
    `DELETE FROM saved_payment_methods WHERE id = $1 AND user_id = $2 RETURNING id`,
    [methodId, userId]
  );
  if (result.rowCount === 0) {
    throw Errors.notFound('Payment method');
  }
}

export async function setDefaultPaymentMethod(userId: string, methodId: string): Promise<void> {
  await withTransaction(async (client) => {
    const owned = await client.query(`SELECT id FROM saved_payment_methods WHERE id = $1 AND user_id = $2`, [
      methodId,
      userId,
    ]);
    if (owned.rowCount === 0) {
      throw Errors.notFound('Payment method');
    }
    await client.query(`UPDATE saved_payment_methods SET is_default = false WHERE user_id = $1`, [userId]);
    await client.query(`UPDATE saved_payment_methods SET is_default = true WHERE id = $1`, [methodId]);
  });
}
