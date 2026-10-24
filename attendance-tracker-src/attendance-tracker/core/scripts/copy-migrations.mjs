// tsc compiles .ts -> .js but does not copy non-TS assets. migrate.ts
// resolves its migrations directory relative to its own compiled
// location (dist/db/migrations), so that directory must actually exist
// there after a build — this script is what makes that true. Found by
// actually RUNNING the compiled dist/index.js and hitting ENOENT, not by
// reading the build config and assuming it was fine.
import { cpSync, mkdirSync, readdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const src = join(__dirname, "..", "src", "db", "migrations");
const dest = join(__dirname, "..", "dist", "db", "migrations");

mkdirSync(dest, { recursive: true });
for (const file of readdirSync(src)) {
  if (!file.endsWith(".sql")) continue;
  cpSync(join(src, file), join(dest, file));
}
console.log(`Copied ${readdirSync(dest).length} migration file(s) to ${dest}`);
