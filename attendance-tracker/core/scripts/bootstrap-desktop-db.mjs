#!/usr/bin/env node
/**
 * First-run database bootstrap for the WPF desktop app.
 * Creates an empty DB with migrations, optional demo seed, and the first admin user.
 */
import { existsSync, mkdirSync } from "node:fs";
import { dirname } from "node:path";
import {
  openDatabase,
  createAdminUser,
  upsertEmployee,
  getAllEmployees,
} from "../dist/index.js";

const dbPath = process.argv[2];
const email = process.argv[3];
const password = process.argv[4];
const displayName = process.argv[5] ?? "Administrator";
const seedDemo = process.argv.includes("--seed-demo");

if (!dbPath || !email || !password) {
  console.error("Usage: bootstrap-desktop-db.mjs <dbPath> <email> <password> [displayName] [--seed-demo]");
  process.exit(1);
}

mkdirSync(dirname(dbPath), { recursive: true });
const isNew = !existsSync(dbPath);
const db = openDatabase(dbPath);

try {
  const adminCount = db.prepare("SELECT COUNT(*) AS c FROM admin_users").get().c;
  if (adminCount === 0) {
    createAdminUser(db, {
      adminId: "admin-primary",
      displayName,
      email,
      password,
    });
    console.log("Created administrator account.");
  } else {
    console.log("Administrator already exists; skipped user creation.");
  }

  if (seedDemo && getAllEmployees(db).length === 0) {
    upsertEmployee(db, {
      employeeId: "emp-demo",
      displayName: "Demo Employee",
      department: "General",
      designation: "Associate",
    });
    console.log("Seeded demo employee row (no attendance events).");
  }
} finally {
  db.close();
}

console.log(JSON.stringify({ dbPath, isNew, ok: true }));
