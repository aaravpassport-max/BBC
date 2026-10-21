#!/usr/bin/env node
/**
 * End-to-end visual audit — same HTTP flow as the Windows desktop app.
 */
import { createRequire } from 'node:module';
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';

const require = createRequire(import.meta.url);
const ROOT = path.resolve(import.meta.dirname, '..');
const pwModule = process.env.PLAYWRIGHT_MODULE
  || path.join(ROOT, 'node_modules', 'playwright');
const { chromium } = require(fs.existsSync(pwModule) ? pwModule : '/workspace/leadcrm/node_modules/playwright');
const WP_SRC = path.join(ROOT, 'app', 'wordpress');
const AUDIT = process.env.LCRM_AUDIT_DIR || '/tmp/lcrm-wa-audit';
const WP = path.join(AUDIT, 'wordpress');
const OUT = process.env.LCRM_AUDIT_OUT || '/opt/cursor/artifacts/leadcrm-wa-audit';
const PORT = 9780;
const BASE = `http://127.0.0.1:${PORT}`;

function waitPort(p, ms = 60000) {
  return new Promise((resolve, reject) => {
    const t0 = Date.now();
    const tick = () => {
      const s = net.createConnection({ port: p, host: '127.0.0.1' }, () => {
        s.end();
        resolve();
      });
      s.on('error', () => {
        if (Date.now() - t0 > ms) reject(new Error(`port ${p}`));
        else setTimeout(tick, 300);
      });
    };
    tick();
  });
}

function copyDir(src, dest) {
  fs.mkdirSync(dest, { recursive: true });
  for (const e of fs.readdirSync(src, { withFileTypes: true })) {
    const f = path.join(src, e.name);
    const t = path.join(dest, e.name);
    if (e.isDirectory()) copyDir(f, t);
    else fs.copyFileSync(f, t);
  }
}

function ensureDesktopWordPressFiles(wpRoot) {
  const muDir = path.join(wpRoot, 'wp-content', 'mu-plugins');
  fs.mkdirSync(muDir, { recursive: true });
  const muSrc = path.join(ROOT, 'app', 'bin', 'leadcrm-desktop-mu-plugin.php');
  if (!fs.existsSync(muSrc)) {
    throw new Error(`Missing ${muSrc} — run from leadcrm-wa-desktop repo`);
  }
  fs.copyFileSync(muSrc, path.join(muDir, 'leadcrm-desktop.php'));
  fs.copyFileSync(
    path.join(ROOT, 'app', 'bin', 'leadcrm-desktop-bootstrap.php'),
    path.join(wpRoot, 'leadcrm-desktop-bootstrap.php'),
  );
  fs.copyFileSync(
    path.join(ROOT, 'app', 'bin', 'desktop-router.php'),
    path.join(wpRoot, 'router.php'),
  );
}

const results = [];
function pass(name, detail = '') {
  results.push({ name, ok: true, detail });
}
function fail(name, detail = '') {
  results.push({ name, ok: false, detail });
}

async function main() {
  fs.rmSync(AUDIT, { recursive: true, force: true });
  fs.mkdirSync(OUT, { recursive: true });
  for (const f of fs.readdirSync(OUT)) {
    if (f.endsWith('.png')) fs.unlinkSync(path.join(OUT, f));
  }
  if (!fs.existsSync(path.join(WP_SRC, 'wp-includes'))) {
    console.error('Run: node scripts/download-wordpress.js');
    process.exit(1);
  }
  copyDir(WP_SRC, WP);
  copyDir(path.join(ROOT, 'app', 'bin'), path.join(AUDIT, 'bin'));
  ensureDesktopWordPressFiles(WP);

  spawnSync('sudo', ['mysql', '-e', 'DROP DATABASE IF EXISTS leadcrm_wp_audit; CREATE DATABASE leadcrm_wp_audit; GRANT ALL ON leadcrm_wp_audit.* TO \'lcrmuser\'@\'127.0.0.1\'; FLUSH PRIVILEGES;'], { stdio: 'inherit' });

  const wpConfig = `<?php
define('DB_NAME', 'leadcrm_wp_audit');
define('DB_USER', 'lcrmuser');
define('DB_PASSWORD', 'testpass123');
define('DB_HOST', '127.0.0.1:3306');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 'wp_';
define('LCRM_DESKTOP_APP', true);
define('AUTH_KEY','a');define('SECURE_AUTH_KEY','a');define('LOGGED_IN_KEY','a');define('NONCE_KEY','a');
define('AUTH_SALT','a');define('SECURE_AUTH_SALT','a');define('LOGGED_IN_SALT','a');define('NONCE_SALT','a');
define('WP_DEBUG', false);
if (file_exists(__DIR__ . '/.maintenance')) { @unlink(__DIR__ . '/.maintenance'); }
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
`;
  fs.writeFileSync(path.join(WP, 'wp-config.php'), wpConfig);
  fs.copyFileSync(
    path.join(ROOT, 'app', 'bin', 'desktop-router.php'),
    path.join(WP, 'router.php'),
  );

  const install = spawnSync('php', [path.join(AUDIT, 'bin', 'install-wordpress.php')], {
    env: {
      ...process.env,
      LCRM_WP_ROOT: WP,
      LCRM_SITE_URL: BASE,
      LCRM_ADMIN_PASS: 'AuditTest1!',
      LCRM_LOGIN_FILE: path.join(AUDIT, 'LOGIN.txt'),
    },
    encoding: 'utf8',
  });
  if (install.status !== 0) {
    fail('WordPress + plugin install', (install.stderr || install.stdout || '').slice(-500));
  } else {
    pass('WordPress + plugin install');
  }

  const router = path.join(WP, 'router.php');
  const php = spawn('php', ['-S', `${BASE.replace('http://', '')}`, '-t', WP, router], { cwd: WP, stdio: 'ignore' });
  await waitPort(PORT);

  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const context = await browser.newContext({ viewport: { width: 1360, height: 860 } });
  const page = await context.newPage();

  const consoleErrors = [];
  page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()); });

  // 1) Bootstrap (desktop sign-in)
  await page.goto(`${BASE}/leadcrm-desktop-bootstrap.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForURL(/\/crm\/?/, { timeout: 60000 }).catch(() => {});
  await page.waitForTimeout(2000);
  const afterBoot = page.url();
  await page.screenshot({ path: path.join(OUT, '01-after-bootstrap.png'), fullPage: true });
  if (afterBoot.includes('/crm')) pass('Bootstrap redirects to /crm/', afterBoot);
  else fail('Bootstrap redirects to /crm/', afterBoot);

  // 2) CRM without prior cookies (Electron-style cold load)
  await context.clearCookies();
  await page.goto(`${BASE}/crm/`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2000);
  let titleCold = await page.title();
  const coldBody = await page.locator('body').innerText();
  if (/login/i.test(titleCold) || coldBody.includes('Sign in to access')) {
    fail('CRM cold load without cookies', titleCold);
  } else {
    pass('CRM cold load without cookies', titleCold.slice(0, 80));
  }

  // Maintenance mode must not block CRM (stuck .maintenance after interrupted install)
  const upgrading = Math.floor(Date.now() / 1000);
  fs.writeFileSync(path.join(WP, '.maintenance'), `<?php $upgrading = ${upgrading};`);
  await page.goto(`${BASE}/crm/`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(1500);
  const maintBody = await page.locator('body').innerText();
  if (/scheduled maintenance/i.test(maintBody)) {
    fail('WordPress maintenance bypass', 'CRM showed maintenance screen');
  } else if (/login/i.test(await page.title()) || maintBody.includes('Sign in to access')) {
    fail('WordPress maintenance bypass', 'CRM login wall with .maintenance present');
  } else {
    pass('WordPress maintenance bypass', 'CRM loads with .maintenance present');
  }
  try { fs.unlinkSync(path.join(WP, '.maintenance')); } catch {}

  // 3) CRM must not be wp-login
  await page.goto(`${BASE}/crm/`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2000);
  await page.screenshot({ path: path.join(OUT, '02-crm-home.png'), fullPage: true });
  const title = await page.title();
  const bodyText = await page.locator('body').innerText();
  if (
    bodyText.includes('Username or Email Address')
    || /login/i.test(title)
    || bodyText.includes('Sign in to access')
  ) {
    fail('CRM opens without WordPress login', title);
  } else {
    pass('CRM opens without WordPress login', title.slice(0, 80));
  }

  // 3) wp-login should redirect away on localhost
  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: path.join(OUT, '03-wp-login-bypass.png'), fullPage: true });
  if (page.url().includes('wp-login.php')) fail('wp-login bypass', page.url());
  else pass('wp-login bypass', page.url());

  // 4) SPA navigation — sidebar count + key sections (same UI as Windows Electron shell)
  await page.goto(`${BASE}/crm/`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(2000);
  const navItems = page.locator('.lcrm-nav-item[data-section]');
  const navCount = await navItems.count();
  if (navCount > 0) {
    pass('SPA sidebar present', `items=${navCount}`);
  } else {
    await page.screenshot({ path: path.join(OUT, '04-crm-section-missing-nav.png'), fullPage: true });
    fail('SPA sidebar present', 'no .lcrm-nav-item links — check screenshot');
  }

  async function visitSection(sectionId, shotName, label) {
    await page.goto(`${BASE}/crm/#${sectionId}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(2500);
    const active = await page.locator(`.lcrm-nav-item[data-section="${sectionId}"].active`).count();
    await page.screenshot({ path: path.join(OUT, shotName), fullPage: true });
    if (active > 0) pass(`Section: ${label}`, sectionId);
    else fail(`Section: ${label}`, `nav not active for #${sectionId}`);
  }

  if (navCount > 0) {
    await visitSection('leads', '04-all-leads.png', 'All Leads');
    await visitSection('pipeline', '05-pipeline.png', 'Pipeline');
    await visitSection('settings', '06-settings.png', 'Settings');
    await visitSection('add', '07-add-lead-form.png', 'Add Lead');
  }

  // 5) API health — admin-ajax reachable
  const ajax = await page.evaluate(async (url) => {
    const r = await fetch(`${url}/wp-admin/admin-ajax.php`, { method: 'POST', credentials: 'include', body: 'action=heartbeat' });
    return r.status;
  }, BASE);
  if (ajax === 200 || ajax === 400) pass('admin-ajax reachable', String(ajax));
  else fail('admin-ajax reachable', String(ajax));

  const critical = consoleErrors.filter(e => !/heartbeat|400|favicon/i.test(e));
  if (critical.length) fail('Browser console errors', critical.slice(0, 5).join(' | '));
  else pass('Browser console errors', consoleErrors.length ? 'benign only' : 'none');

  await browser.close();
  php.kill();

  const report = {
    auditedAt: new Date().toISOString(),
    baseUrl: BASE,
    results,
    screenshots: fs.readdirSync(OUT).filter(f => f.endsWith('.png')),
  };
  fs.writeFileSync(path.join(OUT, 'audit-report.json'), JSON.stringify(report, null, 2));

  const md = ['# LeadCRM WA Desktop — Visual E2E Audit', '', `Date: ${report.auditedAt}`, '', '## Results', ''];
  for (const r of results) {
    md.push(`- ${r.ok ? 'PASS' : '**FAIL**'} — ${r.name}${r.detail ? `: ${r.detail}` : ''}`);
  }
  md.push('', '## Screenshots', '');
  for (const s of report.screenshots) md.push(`- ${s}`);
  fs.writeFileSync(path.join(OUT, 'AUDIT.md'), md.join('\n'));

  console.log(md.join('\n'));
  if (results.some(r => !r.ok)) process.exit(1);
}

main().catch(e => {
  console.error(e);
  process.exit(1);
});
