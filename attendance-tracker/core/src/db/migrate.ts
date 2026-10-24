import { readdirSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import type { DatabaseSync } from "node:sqlite";

type Database = DatabaseSync;

const __dirname = dirname(fileURLToPath(import.meta.url));
const MIGRATIONS_DIR = join(__dirname, "migrations");

interface MigrationFile {
  version: number;
  name: string;
  sql: string;
}

function loadMigrations(): MigrationFile[] {
  const files = readdirSync(MIGRATIONS_DIR).filter((f) => f.endsWith(".sql"));
  const parsed = files.map((f) => {
    const match = /^(\d+)_(.+)\.sql$/.exec(f);
    if (!match) {
      throw new Error(`Migration filename does not match NNN_name.sql pattern: ${f}`);
    }
    return {
      version: Number(match[1]),
      name: match[2]!,
      sql: readFileSync(join(MIGRATIONS_DIR, f), "utf-8"),
    };
  });
  parsed.sort((a, b) => a.version - b.version);
  // Guard against accidental duplicate version numbers — a silent
  // collision here would mean one migration never runs.
  const seen = new Set<number>();
  for (const m of parsed) {
    if (seen.has(m.version)) {
      throw new Error(`Duplicate migration version ${m.version}`);
    }
    seen.add(m.version);
  }
  return parsed;
}

/**
 * Applies any migrations not yet recorded in schema_migrations, each in
 * its own transaction. Safe to call on every startup — already-applied
 * migrations are skipped, never re-run.
 */
export function runMigrations(db: Database): { applied: number[] } {
  db.exec(`
    CREATE TABLE IF NOT EXISTS schema_migrations (
      version INTEGER PRIMARY KEY,
      applied_at_utc TEXT NOT NULL
    );
  `);

  const appliedVersions = new Set(
    (db.prepare("SELECT version FROM schema_migrations").all() as { version: number }[]).map((r) => r.version),
  );

  const migrations = loadMigrations();
  const applied: number[] = [];

  for (const migration of migrations) {
    if (appliedVersions.has(migration.version)) continue;
    // node:sqlite's DatabaseSync has no built-in `.transaction()` helper
    // (unlike better-sqlite3) — wrap explicitly so a failing migration
    // can't leave the schema half-applied.
    db.exec("BEGIN");
    try {
      db.exec(migration.sql);
      db.prepare("INSERT INTO schema_migrations (version, applied_at_utc) VALUES (?, ?)").run(
        migration.version,
        new Date().toISOString(),
      );
      db.exec("COMMIT");
    } catch (err) {
      db.exec("ROLLBACK");
      throw err;
    }
    applied.push(migration.version);
  }

  return { applied };
}
