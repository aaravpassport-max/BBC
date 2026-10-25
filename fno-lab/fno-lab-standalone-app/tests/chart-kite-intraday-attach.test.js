// Static lock: Kite intraday chart attachment helpers (PHP) — all chart paths.
const fs = require('fs');
const path = require('path');
const php = fs.readFileSync(path.join(__dirname, '../fno-lab.php'), 'utf8');
let failed = 0;
function check(cond, label) {
  if (cond) console.log('  PASS  ' + label);
  else { failed++; console.log('  FAIL  ' + label); }
}
check(php.includes('function fno_kite_fetch_index_intraday_chart'), 'fno_kite_fetch_index_intraday_chart exists');
check(php.includes('function fno_chart_attach_kite_intraday_series'), 'fno_chart_attach_kite_intraday_series exists');
check(php.includes("fno_chart_attach_kite_intraday_series(\$symbol, \$data)"), 'NSE success path attaches intraday when needed');
check(php.includes("'5minute', 5,"), 'Kite intraday fetch falls back to 5minute');
check(php.includes("'15minute', 15,"), 'Kite intraday fetch falls back to 15minute');
if (failed) process.exit(1);
console.log('chart-kite-intraday-attach static checks passed');
