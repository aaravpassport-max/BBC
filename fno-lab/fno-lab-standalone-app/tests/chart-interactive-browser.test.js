'use strict';
/**
 * Real browser click audit for chart toolbar (Puppeteer drives the same
 * harness a human would open). Serves static files from app root on 8765.
 */
const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = Number(process.env.FNO_CHART_VISUAL_PORT || 8765);
const ROOT = path.join(__dirname, '..');

function startStaticServer() {
  const server = http.createServer((req, res) => {
    let urlPath = req.url.split('?')[0];
    if (urlPath === '/') urlPath = '/tests/chart-interactive-harness.html';
    const filePath = path.join(ROOT, urlPath.replace(/^\//, ''));
    if (!filePath.startsWith(ROOT)) {
      res.writeHead(403); res.end(); return;
    }
    fs.readFile(filePath, (err, data) => {
      if (err) { res.writeHead(404); res.end('not found'); return; }
      const ext = path.extname(filePath);
      const types = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css' };
      res.writeHead(200, { 'Content-Type': types[ext] || 'application/octet-stream' });
      res.end(data);
    });
  });
  return new Promise((resolve, reject) => {
    server.listen(PORT, '127.0.0.1', () => resolve(server));
    server.on('error', reject);
  });
}

function waitForAudit(page, timeoutMs) {
  return page.waitForFunction(
    () => {
      const r = window.__chartClickAuditResult;
      if (r && typeof r.total === 'number') return r;
      const t = document.getElementById('auditSummary')?.textContent || '';
      const m = t.match(/Audit:\s*(\d+)\/(\d+)\s*passed/);
      if (m) return { pass: Number(m[1]), total: Number(m[2]), ok: m[1] === m[2] };
      if (/Boot failed/.test(t)) return { bootFailed: t };
      return null;
    },
    { timeout: timeoutMs }
  );
}

async function main() {
  let puppeteer;
  try {
    puppeteer = require('puppeteer');
  } catch {
    console.log('SKIP chart-interactive-browser.test.js (puppeteer not installed)');
    process.exit(0);
  }

  const server = await startStaticServer();
  try {
    const browser = await puppeteer.launch({
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 900 });
    await page.goto(`http://127.0.0.1:${PORT}/tests/chart-interactive-harness.html`, {
      waitUntil: 'networkidle0',
      timeout: 120000,
    });

    const resultHandle = await waitForAudit(page, 120000);
    const result = await resultHandle.jsonValue();
    if (result.bootFailed) throw new Error(result.bootFailed);
    if (!result.ok) {
      const lines = await page.$$eval('#auditList li', (els) => els.map((e) => e.textContent));
      const fails = lines.filter((l) => l.startsWith('FAIL'));
      throw new Error(`Chart click audit ${result.pass}/${result.total}: ${fails.join(' | ') || 'unknown failure'}`);
    }

    await page.click('#runAuditBtn');
    const result2Handle = await waitForAudit(page, 60000);
    const result2 = await result2Handle.jsonValue();
    if (!result2.ok) throw new Error(`Re-run audit failed ${result2.pass}/${result2.total}`);

    await browser.close();
    console.log(`chart-interactive-browser: ${result.total} toolbar checks passed (auto + manual re-run)`);
  } finally {
    server.close();
  }
}

main().catch((e) => {
  console.error(e.message || e);
  process.exit(1);
});
