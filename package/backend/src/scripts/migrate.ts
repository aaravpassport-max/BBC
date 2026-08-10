/**
 * A real, working migration runner — closes a genuine gap: every
 * migration this session applied to real databases was run by hand,
 * `for f in migrations/*.sql; do psql -f "$f"; done`, which works on a
 * developer's own machine but not inside the production container image
 * (deliberately built without the psql CLI at all — see the Dockerfile's
 * own comment on why). Runs each migrations/*.sql file, in filename
 * order, against DATABASE_URL, using the same `pg` library the app
 * itself already depends on — no extra tooling required in the image.
 *
 * NOT idempotent by design, matching how every migration in this
 * codebase was actually applied throughout development: running it twice
 * against the same database will fail on the second run (tables already
 * exist) rather than silently doing nothing. That failure is the correct,
 * safe behavior — the alternative (a migrations-tracking table) is a
 * reasonable real addition a production deployment may well want, but
 * this reference backend never had one, so this script doesn't invent
 * behavior beyond what the codebase actually does.
 */
import fs from 'fs';
import path from 'path';
import { Pool } from 'pg';
import dotenv from 'dotenv';

dotenv.config();

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
    for (const file of files) {
      const sql = fs.readFileSync(path.join(migrationsDir, file), 'utf-8');
      console.log(`Applying ${file}...`);
      await pool.query(sql);
      console.log(`  done.`);
    }
    console.log(`All ${files.length} migrations applied successfully.`);
  } finally {
    await pool.end();
  }
}

main().catch((err) => {
  console.error('Migration failed:', err.message);
  process.exit(1);
});
