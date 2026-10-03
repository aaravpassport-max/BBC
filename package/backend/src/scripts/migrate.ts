/**
 * Idempotent migration runner — tracks applied files in schema_migrations so
 * deploy scripts can safely re-run `npm run migrate` (Docker entrypoint, CI, K8s jobs).
 */
import fs from 'fs';
import path from 'path';
import { Pool } from 'pg';
import dotenv from 'dotenv';

dotenv.config();

async function ensureMigrationsTable(pool: Pool): Promise<void> {
  await pool.query(`
    CREATE TABLE IF NOT EXISTS schema_migrations (
      filename VARCHAR(255) PRIMARY KEY,
      applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
    )
  `);
}

async function main() {
  const migrationsDir = path.resolve(__dirname, '..', '..', 'migrations');
  const files = fs
    .readdirSync(migrationsDir)
    .filter((f) => f.endsWith('.sql'))
    .sort();

  if (files.length === 0) {
    console.error(`No .sql files found in ${migrationsDir}`);
    process.exit(1);
  }

  const pool = new Pool({ connectionString: process.env.DATABASE_URL });

  try {
    await ensureMigrationsTable(pool);

    const appliedCount = await pool.query(`SELECT count(*)::int AS n FROM schema_migrations`);
    const legacyDb = await pool.query(`SELECT to_regclass('public.users') AS users_table`);
    if (appliedCount.rows[0].n === 0 && legacyDb.rows[0].users_table) {
      console.log('Baseline: existing database detected — marking all migrations as applied.');
      for (const file of files) {
        await pool.query(`INSERT INTO schema_migrations (filename) VALUES ($1) ON CONFLICT DO NOTHING`, [file]);
      }
    }

    let applied = 0;
    let skipped = 0;

    for (const file of files) {
      const existing = await pool.query(`SELECT 1 FROM schema_migrations WHERE filename = $1`, [file]);
      if ((existing.rowCount ?? 0) > 0) {
        console.log(`Skipping ${file} (already applied).`);
        skipped++;
        continue;
      }

      const sql = fs.readFileSync(path.join(migrationsDir, file), 'utf-8');
      console.log(`Applying ${file}...`);
      await pool.query('BEGIN');
      try {
        await pool.query(sql);
        await pool.query(`INSERT INTO schema_migrations (filename) VALUES ($1)`, [file]);
        await pool.query('COMMIT');
        console.log('  done.');
        applied++;
      } catch (err) {
        await pool.query('ROLLBACK');
        throw err;
      }
    }

    console.log(`Migrations complete: ${applied} applied, ${skipped} skipped (${files.length} total).`);
  } finally {
    await pool.end();
  }
}

main().catch((err) => {
  console.error('Migration failed:', err.message);
  process.exit(1);
});
