/**
 * Runs seed/001_reference_data.sql against DATABASE_URL — the same real
 * reference data (city zones, vehicle categories, published rate cards,
 * platform roles) every test and every manual verification this session
 * relied on. Same reasoning as migrate.ts: this exists because the
 * production container has no psql CLI to run it by hand.
 */
import fs from 'fs';
import path from 'path';
import { Pool } from 'pg';
import dotenv from 'dotenv';

dotenv.config();

async function main() {
  const seedDir = path.resolve(__dirname, '..', '..', 'seed');
  const seedFiles = fs
    .readdirSync(seedDir)
    .filter((name) => name.endsWith('.sql'))
    .sort();

  if (seedFiles.length === 0) {
    console.error(`No seed files found in: ${seedDir}`);
    process.exit(1);
  }

  const pool = new Pool({ connectionString: process.env.DATABASE_URL });
  try {
    for (const file of seedFiles) {
      const seedFile = path.join(seedDir, file);
      const sql = fs.readFileSync(seedFile, 'utf-8');
      console.log(`Applying ${file}...`);
      await pool.query(sql);
    }
    console.log('Seed data applied successfully.');
  } finally {
    await pool.end();
  }
}

main().catch((err) => {
  console.error('Seeding failed:', err.message);
  process.exit(1);
});
