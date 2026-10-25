'use strict';
/**
 * Headless browser proof that the ledger harness updates visually.
 * Requires: ledger-visual-server.js running on 8767 (starts automatically).
 */
const { spawn } = require('child_process');
const http = require('http');
const path = require('path');

const PORT = 8767;
const ROOT = path.join(__dirname, '..');

function waitForPort(ms) {
  const deadline = Date.now() + ms;
  return new Promise((resolve, reject) => {
    const tick = () => {
      http.get(`http://127.0.0.1:${PORT}/`, (res) => {
        res.resume();
        if (res.statusCode === 200) resolve();
        else if (Date.now() > deadline) reject(new Error('bad status'));
        else setTimeout(tick, 200);
      }).on('error', () => {
        if (Date.now() > deadline) reject(new Error('port timeout'));
        else setTimeout(tick, 200);
      });
    };
    tick();
  });
}

async function main() {
  let puppeteer;
  try {
    puppeteer = require('puppeteer');
  } catch {
    console.log('SKIP ledger-visual-browser.test.js (puppeteer not installed)');
    process.exit(0);
  }

  const server = spawn('node', ['tests/ledger-visual-harness-page.js'], {
    cwd: ROOT,
    env: { ...process.env, FNO_LEDGER_VISUAL_PORT: String(PORT) },
    stdio: 'ignore',
  });

  try {
    await waitForPort(15000);
    const browser = await puppeteer.launch({
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1200, height: 900 });
    await page.goto(`http://127.0.0.1:${PORT}/`, { waitUntil: 'networkidle0', timeout: 60000 });

    await page.waitForFunction(
      () => document.getElementById('harnessStatus')?.textContent?.includes('Core loaded'),
      { timeout: 120000 }
    );

    const summaryBefore = await page.$eval('#tradeLedgerSummary', (el) => el.textContent);
    if (/Loading\.\.\./.test(summaryBefore)) {
      throw new Error('ledger summary still Loading after boot');
    }

    await page.click('#btnSimOpen');
    await page.waitForTimeout(500);
    const openBody = await page.$eval('#tradeLedgerBody', (el) => el.textContent);
    if (!/Open|Live position/i.test(openBody)) {
      throw new Error('expected open row after simulate entry: ' + openBody.slice(0, 120));
    }

    await page.click('#btnSimClose');
    await page.waitForTimeout(500);
    const summaryAfter = await page.$eval('#tradeLedgerSummary', (el) => el.textContent);
    if (!/Total closed: 1 · Open: 0/.test(summaryAfter)) {
      throw new Error('expected Total Trades 1 after close: ' + summaryAfter.slice(0, 200));
    }

    await browser.close();
    console.log('ledger-visual-browser.test.js OK (open row + closed stats verified)');
  } finally {
    server.kill('SIGTERM');
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
