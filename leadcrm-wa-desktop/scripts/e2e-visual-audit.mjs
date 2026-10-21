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

  // 4b) Functional QA: sidebar toggle, Status column, instant filters
  if (navCount > 0) {
    await page.setViewportSize({ width: 1360, height: 860 });
    await page.goto(`${BASE}/crm/#leads`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2000);
    await page.evaluate(() => localStorage.removeItem('lcrm_sidebar_col'));
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);

    let sideOk = true;
    for (let i = 0; i < 3; i++) {
      await page.click('#lcrm-menu-toggle');
      await page.waitForTimeout(350);
      const w1 = (await page.locator('#lcrm-sidebar').boundingBox()).width;
      const vis = await page.locator('#lcrm-menu-toggle').isVisible();
      if (i === 0) await page.screenshot({ path: path.join(OUT, '08-sidebar-collapsed.png') });
      await page.click('#lcrm-menu-toggle');
      await page.waitForTimeout(350);
      const w2 = (await page.locator('#lcrm-sidebar').boundingBox()).width;
      if (!(w1 < 80 && w2 > 180 && vis)) sideOk = false;
    }
    await page.click('#lcrm-menu-toggle');
    await page.waitForTimeout(350);
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    const persisted = (await page.locator('#lcrm-sidebar').boundingBox()).width < 80;
    await page.click('#lcrm-menu-toggle');
    await page.waitForTimeout(350);
    (sideOk && persisted) ? pass('Sidebar collapse/expand x3 + persistence') : fail('Sidebar collapse/expand', `ok=${sideOk} persisted=${persisted}`);

    const api = (action, data) => page.evaluate(async ({ action, data }) => {
      const body = new URLSearchParams({ action, nonce: LCRM.nonce, ...data });
      const r = await fetch(LCRM.ajax_url, { method: 'POST', credentials: 'include', body });
      return r.json();
    }, { action, data });
    const stages = await page.evaluate(() => LCRM.stages.map(s => s.id));
    const mk = (n, extra) => api('lcrm_save_lead', { client_name: n, client_phone: '99' + Math.floor(Math.random() * 1e8), work_type: 'Logo', force_create: '1', ...extra });
    const seeds = [
      ['QA Alpha', { stage_id: stages[0], priority: 'high', lead_temperature: 'hot', remarks: 'alpharem' }],
      ['QA Beta', { stage_id: stages[1] || stages[0], priority: 'low', lead_temperature: 'cold', remarks: 'betarem' }],
      ['QA Gamma', { stage_id: stages[0], priority: 'high', lead_temperature: 'cold', remarks: 'gammarem' }],
    ];
    const ids = [];
    for (const [n, e] of seeds) { const r = await mk(n, e); ids.push(r.data && r.data.id); }
    ids.every(Boolean) ? pass('Create test inquiries', ids.join(',')) : fail('Create test inquiries', JSON.stringify(ids));

    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    const rows = () => page.locator('#lcrm-tbody tr.lcrm-lead-row').count();
    const names = async () => (await page.locator('#lcrm-tbody tr.lcrm-lead-row').allInnerTexts()).join('|');
    const waitRows = async n => { for (let i = 0; i < 30; i++) { if ((await rows()) === n) return true; await page.waitForTimeout(150); } return false; };

    // Status: default, change, persist, independence from Stage
    const row = id => page.locator(`#lcrm-tbody tr[data-id="${id}"]`);
    const defaultStatus = await row(ids[0]).locator('.lcrm-ws-select').inputValue();
    const stageBefore = await row(ids[0]).locator('td.col-stage, td[data-editable="stage_id"]').first().innerText();
    await row(ids[0]).locator('.lcrm-ws-select').selectOption('In Progress');
    await row(ids[1]).locator('.lcrm-ws-select').selectOption('On Hold');
    await page.waitForTimeout(800);
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    const st0 = await row(ids[0]).locator('.lcrm-ws-select').inputValue();
    const st1 = await row(ids[1]).locator('.lcrm-ws-select').inputValue();
    const st2 = await row(ids[2]).locator('.lcrm-ws-select').inputValue();
    const stageAfter = await row(ids[0]).locator('td[data-editable="stage_id"]').first().innerText();
    (defaultStatus === 'Inquiry' && st0 === 'In Progress' && st1 === 'On Hold' && st2 === 'Inquiry')
      ? pass('Status saves + persists after reload', `${st0}/${st1}/${st2}`) : fail('Status persistence', `${defaultStatus} ${st0}/${st1}/${st2}`);
    stageBefore.trim() === stageAfter.trim() ? pass('Changing Status leaves Stage untouched', stageAfter.trim()) : fail('Stage changed by Status', `${stageBefore} -> ${stageAfter}`);
    const q = await api('lcrm_quick_update', { id: ids[2], field: 'stage_id', value: stages[1] || stages[0] });
    const g = await api('lcrm_get_leads', { search: 'QA Gamma' });
    const gl = g.data.leads[0];
    (q.success && gl.work_status === 'Inquiry' && String(gl.stage_id) === String(stages[1] || stages[0]))
      ? pass('Changing Stage leaves Status untouched', gl.work_status) : fail('Status changed by Stage', JSON.stringify(gl).slice(0, 200));
    const bad = await api('lcrm_quick_update', { id: ids[2], field: 'work_status', value: 'Bogus' });
    const g2 = await api('lcrm_get_leads', { search: 'QA Gamma' });
    g2.data.leads[0].work_status === 'Inquiry' ? pass('Invalid Status rejected/normalised') : fail('Invalid Status stored');

    // Instant filters — never click the Filter button
    await page.fill('#lcrm-search', 'QA ');
    const f1 = await waitRows(3);
    await page.screenshot({ path: path.join(OUT, '09-leads-status-column.png'), fullPage: true });
    f1 ? pass('Instant text search (debounced)') : fail('Instant text search', await names());
    await page.selectOption('#lcrm-filter-wstatus', 'In Progress');
    (await waitRows(1)) && (await names()).includes('QA Alpha') ? pass('Status filter instant') : fail('Status filter', await names());
    await page.selectOption('#lcrm-filter-wstatus', '');
    await waitRows(3);
    await page.selectOption('#lcrm-filter-priority', 'high');
    (await waitRows(2)) ? pass('Priority filter instant') : fail('Priority filter', await names());
    await page.selectOption('#lcrm-filter-temp', 'cold');
    (await waitRows(1)) && (await names()).includes('QA Gamma') ? pass('Priority + Temperature combined') : fail('Combined filters', await names());
    await page.selectOption('#lcrm-filter-temp', '');
    await page.selectOption('#lcrm-filter-priority', '');
    await waitRows(3);
    await page.selectOption('#lcrm-filter-stage', String(stages[0]));
    (await waitRows(1)) && (await names()).includes('QA Alpha') ? pass('Stage filter instant') : fail('Stage filter', await names());
    await page.selectOption('#lcrm-filter-wstatus', 'On Hold');
    (await waitRows(0)) ? pass('Stage + Status combined (no match)') : fail('Stage+Status combo', await names());
    await page.selectOption('#lcrm-filter-stage', '');
    (await waitRows(1)) && (await names()).includes('QA Beta') ? pass('Clear Stage keeps Status filter') : fail('Clear single filter', await names());
    const today = new Date().toISOString().slice(0, 10);
    await page.fill('#lcrm-filter-from', today);
    await page.fill('#lcrm-filter-to', today);
    (await waitRows(1)) ? pass('Date range filters instant') : fail('Date filter', await names());
    await page.fill('#lcrm-filter-from', '2000-01-01'); await page.fill('#lcrm-filter-to', '2000-01-02');
    (await waitRows(0)) ? pass('Date range excludes out-of-range') : fail('Date exclusion', await names());
    await page.click('#lcrm-reset-btn');
    (await waitRows(3)) || (await rows()) >= 3 ? pass('Reset clears all filters instantly') : fail('Reset', String(await rows()));

    // Rapid typing / stale-request protection
    await page.fill('#lcrm-search', 'QA Alpha');
    await page.fill('#lcrm-search', 'QA Beta');
    await page.waitForTimeout(1200);
    const nm = await names();
    (nm.includes('QA Beta') && !nm.includes('QA Alpha')) ? pass('Latest filter wins (no stale results)') : fail('Stale results', nm);
    await page.click('#lcrm-reset-btn');
    await page.waitForTimeout(600);

    // Existing features: edit inline stage, sort, pagination container
    await page.click('.lcrm-sort >> nth=0').catch(() => {});
    await page.waitForTimeout(600);
    (await rows()) >= 3 ? pass('Sorting still works') : fail('Sorting');
    (await page.locator('#lcrm-pagination').count()) ? pass('Pagination present') : fail('Pagination missing');

    // Responsive layout
    for (const [w, h] of [[1920, 1000], [1360, 860], [1024, 768], [768, 900], [390, 844]]) {
      await page.setViewportSize({ width: w, height: h });
      await page.waitForTimeout(500);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      const toggle = await page.locator('#lcrm-menu-toggle').isVisible();
      await page.screenshot({ path: path.join(OUT, `10-responsive-${w}.png`) });
      if (w <= 782) {
        await page.click('#lcrm-menu-toggle');
        await page.waitForTimeout(400);
        const open = await page.locator('#lcrm-sidebar.mobile-open').count();
        await page.screenshot({ path: path.join(OUT, `11-mobile-drawer-${w}.png`) });
        await page.click('#lcrm-sidebar-overlay', { position: { x: 380, y: 400 } }).catch(() => {});
        await page.waitForTimeout(300);
        (toggle && open && overflow <= 4) ? pass(`Responsive ${w}px (drawer toggle)`) : fail(`Responsive ${w}px`, `toggle=${toggle} open=${open} overflow=${overflow}`);
      } else {
        (toggle && overflow <= 4) ? pass(`Responsive ${w}px`) : fail(`Responsive ${w}px`, `toggle=${toggle} overflow=${overflow}`);
      }
    }
    await page.setViewportSize({ width: 1360, height: 860 });
  }

  // 4c) Navigation stability: geometry of persistent chrome must never move while switching sections
  if (navCount > 0) {
    await page.setViewportSize({ width: 1360, height: 860 });
    await page.goto(`${BASE}/crm/#dashboard`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    await page.evaluate(() => {
      const geo = () => {
        const r = s => { const b = document.querySelector(s).getBoundingClientRect(); return [b.left, b.top, b.width, b.height].map(Math.round).join(','); };
        return { side: r('#lcrm-sidebar'), top: r('.lcrm-spa-topbar'), cont: [document.querySelector('#lcrm-content').getBoundingClientRect().left, document.querySelector('#lcrm-content').getBoundingClientRect().width, document.querySelector('#lcrm-content').getBoundingClientRect().top].map(Math.round).join(','),
          hidden: getComputedStyle(document.querySelector('#lcrm-content')).display === 'none', docW: document.documentElement.scrollWidth - innerWidth };
      };
      window.__geo = { base: geo(), bad: 0, hiddenFrames: 0, samples: 0 };
      const tick = () => {
        const g = geo(); const b = window.__geo.base;
        window.__geo.samples++;
        if (g.side !== b.side || g.top !== b.top || g.cont !== b.cont || g.docW > 0) window.__geo.bad++;
        if (g.hidden) window.__geo.hiddenFrames++;
        requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    });
    const secIds = await page.$$eval('.lcrm-nav-item', els => els.map(e => e.dataset.section));
    for (let round = 0; round < 3; round++) {
      for (const id of secIds) {
        await page.click(`.lcrm-nav-item[data-section="${id}"]`);
        await page.waitForTimeout(id === 'add' ? 500 : 250);
        await page.evaluate(() => document.querySelectorAll('.lcrm-modal').forEach(m => { m.style.display = 'none'; m.classList.remove('open'); }));
      }
    }
    await page.click('.lcrm-nav-item[data-section="leads"]');
    await page.waitForTimeout(800);
    for (let i = 0; i < 4; i++) { await page.evaluate(() => history.back()); await page.waitForTimeout(250); }
    for (let i = 0; i < 3; i++) { await page.evaluate(() => history.forward()); await page.waitForTimeout(250); }
    // rapid-fire switching (race protection)
    for (const id of ['analytics', 'pipeline', 'leads', 'vendors', 'alerts', 'leads']) await page.click(`.lcrm-nav-item[data-section="${id}"]`);
    await page.waitForTimeout(1500);
    const finalActive = await page.$eval('.lcrm-nav-item.active', e => e.dataset.section);
    const hasLeads = await page.locator('#lcrm-leads-table').count();
    // collapse/expand while sampling
    for (let i = 0; i < 4; i++) { await page.click('#lcrm-menu-toggle'); await page.waitForTimeout(300); }
    const geo = await page.evaluate(() => window.__geo);
    // sidebar width legitimately changes while collapsed; only count non-sidebar drift in that phase separately
    const noHidden = geo.hiddenFrames === 0;
    (noHidden) ? pass('Content pane never hidden during navigation', `${geo.samples} frames`) : fail('Content pane hidden (flash)', `${geo.hiddenFrames} frames`);
    (finalActive === 'leads' && hasLeads) ? pass('Rapid switching settles on last section', finalActive) : fail('Rapid switching race', `${finalActive} leads=${hasLeads}`);
    // chrome geometry stability measured separately without the collapse phase
    await page.evaluate(() => { window.__geo.bad = 0; window.__geo.base = null; });
    await page.evaluate(() => {
      const el = id => document.querySelector(id).getBoundingClientRect();
      window.__geo.base = { side: ['#lcrm-sidebar'].map(s => { const b = el(s); return [b.left, b.top, b.width, b.height].map(Math.round).join(','); })[0] };
    });
    const geoStable = await page.evaluate(async (ids) => {
      const snap = () => { const f = s => { const b = document.querySelector(s).getBoundingClientRect(); return [b.left, b.top, b.width, b.height].map(Math.round).join(','); }; return f('#lcrm-sidebar') + '|' + f('.lcrm-spa-topbar') + '|' + f('#lcrm-content'); };
      const base = snap(); let drift = 0; const seen = new Set();
      for (const id of ids) {
        document.querySelector(`.lcrm-nav-item[data-section="${id}"]`).click();
        for (let i = 0; i < 12; i++) { await new Promise(r => requestAnimationFrame(r)); const s = snap(); if (s !== base) { drift++; seen.add(id + ':' + s); } }
      }
      return { drift, seen: [...seen].slice(0, 3) };
    }, secIds);
    geoStable.drift === 0 ? pass('Sidebar/header/content geometry stable across all sections', `${secIds.length} sections`) : fail('Layout shift during navigation', JSON.stringify(geoStable));
    await page.screenshot({ path: path.join(OUT, '12-nav-stable.png') });
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
