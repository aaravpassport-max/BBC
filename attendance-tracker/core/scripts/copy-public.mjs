// Same reasoning as copy-migrations.mjs: tsc compiles .ts -> .js but
// does not copy static assets, and server.ts's getLocalDashboardHtml()
// resolves public/local-dashboard.html relative to its own compiled
// location (dist/api/../public), so that directory must actually exist
// after a build for GET /local-dashboard to serve anything at all.
import { cpSync, mkdirSync, readdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const src = join(__dirname, "..", "public");
const dest = join(__dirname, "..", "dist", "public");

mkdirSync(dest, { recursive: true });
for (const file of readdirSync(src)) {
  cpSync(join(src, file), join(dest, file));
}
console.log(`Copied ${readdirSync(dest).length} public asset(s) to ${dest}`);
