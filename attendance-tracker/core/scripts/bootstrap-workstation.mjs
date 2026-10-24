#!/usr/bin/env node
/**
 * Prepares the local database on an employee PC: migrations, employee row,
 * device enrollment token for monitoring ingest, optional first admin later.
 */
import { existsSync, mkdirSync } from "node:fs";
import { dirname } from "node:path";
import { openDatabase, upsertEmployee, upsertDevice, enrollDevice } from "../dist/index.js";

const dbPath = process.argv[2];
const employeeId = process.argv[3];
const deviceId = process.argv[4];
const displayName = process.argv[5] ?? employeeId;

if (!dbPath || !employeeId || !deviceId) {
  console.error("Usage: bootstrap-workstation.mjs <dbPath> <employeeId> <deviceId> [displayName]");
  process.exit(1);
}

mkdirSync(dirname(dbPath), { recursive: true });
const isNew = !existsSync(dbPath);
const db = openDatabase(dbPath);

try {
  upsertEmployee(db, {
    employeeId,
    displayName,
    department: "General",
    designation: "Associate",
  });
  upsertDevice(db, { deviceId });
  const deviceToken = enrollDevice(db, employeeId, deviceId);
  console.log(JSON.stringify({ dbPath, isNew, employeeId, deviceId, deviceToken, ok: true }));
} finally {
  db.close();
}
