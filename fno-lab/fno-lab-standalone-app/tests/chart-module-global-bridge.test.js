/**
 * Production loads fno-lab-core.js as type=module; chart engines load as classic scripts on globalThis.
 * This lock ensures the bridge + sync exist so indicators are not permanently dead in the browser.
 */
const fs = require('fs');
const path = require('path');
const core = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
let failed = 0;
function check(c, m) { if (c) console.log('  PASS  ' + m); else { failed++; console.log('  FAIL  ' + m); } }
check(core.includes('let FNO_CHART_INDICATORS = typeof globalThis'), 'module-level FNO_CHART_INDICATORS binds from globalThis');
check(core.includes('function fnoChartSyncGlobalEngines'), 'fnoChartSyncGlobalEngines defined');
check(core.includes('fnoChartSyncGlobalEngines();') && core.includes('function renderPriceChart'), 'renderPriceChart syncs engines');
check(core.includes('indicatorUiWired = true') && core.includes('function fnoChartWireIndicatorManager'), 'indicatorUiWired set only after manager wiring');
check(core.includes('fnoChartRenderIndicatorListImpl = renderList'), 'indicator list impl wired for legacy toggles');
check(core.includes('function fnoChartNotifyIndicatorAdded') && !core.includes('function fnoChartCommitAddedIndicator'), 'add flow keeps instances (no commit rollback)');
check(core.includes('function fnoChartPublishHostApi') && core.includes('globalThis.renderPriceChart = renderPriceChart'), 'ES module publishes chart API on globalThis');
check(core.includes('plotWOpt') && core.includes('recentTicks'), 'Option LTP uses tick-index plotting');
if (failed) process.exit(1);
console.log('chart-module-global-bridge static checks passed');
