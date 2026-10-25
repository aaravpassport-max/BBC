import { Pool, PoolClient } from 'pg';
import dotenv from 'dotenv';

dotenv.config();

export const pool = new Pool({
  connectionString: process.env.DATABASE_URL,
  max: 20,
  idleTimeoutMillis: 30000,
  connectionTimeoutMillis: 5000,
});

pool.on('error', (err) => {
  // A pool-level error means a client crashed while idle — log it, but never let
  // it crash the whole process (PRD Section 27 / Section 16C "runtime stability" principle).
  console.error('Unexpected error on idle PG client', err);
});

/**
 * Run a callback inside a single transaction. Commits on success, rolls back
 * on any thrown error — used for every multi-statement financial or booking
 * mutation in this codebase (PRD Section 6/24: financial mutations are never
 * partially applied).
 */
export async function withTransaction<T>(
  fn: (client: PoolClient) => Promise<T>
): Promise<T> {
  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    const result = await fn(client);
    await client.query('COMMIT');
    return result;
  } catch (err) {
    await client.query('ROLLBACK');
    throw err;
  } finally {
    client.release();
  }
}
