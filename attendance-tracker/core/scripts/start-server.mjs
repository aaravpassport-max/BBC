#!/usr/bin/env node
/**
 * Headless API server entry for the native Windows desktop shell.
 * Writes the bound port to ATTENDANCE_PORT_FILE then stays alive.
 */
import { writeFileSync } from "node:fs";
import { startServer } from "../dist/api/server.js";

const dbPath = process.env.ATTENDANCE_DB_PATH;
if (!dbPath) {
  console.error("ATTENDANCE_DB_PATH is required.");
  process.exit(1);
}

const portFile = process.env.ATTENDANCE_PORT_FILE;
const preferredPort = Number(process.env.ATTENDANCE_PORT || 0);

const handle = await startServer(dbPath, Number.isFinite(preferredPort) ? preferredPort : 0);
if (portFile) {
  writeFileSync(portFile, JSON.stringify({ port: handle.port, dbPath }), "utf-8");
}
console.log(`Attendance core API listening on 127.0.0.1:${handle.port}`);

async function shutdown() {
  await handle.close();
  process.exit(0);
}

process.on("SIGINT", shutdown);
process.on("SIGTERM", shutdown);
