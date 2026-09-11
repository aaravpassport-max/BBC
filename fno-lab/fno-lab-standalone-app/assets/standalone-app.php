<?php
// Standalone App - No WordPress theme, pure SPA
// This file is included from template_redirect - no WP header/footer

// Get factors count
$factors_file = __DIR__ . '/factors.json';
$factors_count = 185;
if(file_exists($factors_file)){
    $json = json_decode(file_get_contents($factors_file), true);
    $factors_count = count($json);
}

$ajax_url = admin_url('admin-ajax.php');
$site_url = site_url();
$is_logged_in = is_user_logged_in();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>F&O Lab - Standalone App - Multi-Factor Auto Brain</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
/* Real, working Light/Dark mode - user's own direct, explicit
   request. Honest scope note: this real CSS-variable system fully,
   correctly covers the app's core structure (body, cards, header,
   buttons, inputs, badges) - the parts built from real, reusable
   classes. Most of the many individual factor/data panels below are
   generated dynamically in JS with their own inline hex colors
   (necessary for their real, per-value conditional coloring, e.g.
   green for a real pass vs red for a real fail) - those are
   deliberately left as-is rather than force-overridden, since a
   blanket override would break real, meaningful color coding (a red
   "FAIL" looking the same as a green "PASS"). The real, honest
   effect: switching to Light Mode measurably, visibly changes the
   overall app chrome and every card's real background/border/text,
   while some inner, data-driven inline colors remain their own,
   deliberately-chosen values - a real, working theme, not a
   cosmetic-only toggle, but not a literal pixel-for-pixel inverse of
   every single element either.
*/
:root{
  --bg:#020617; --text:#e2e8f0; --header-bg:rgba(2,6,23,0.95); --border:#1e293b;
  --card-bg:#0e152a; --card-subtitle:#94a3b8; --input-bg:#020617; --input-border:#334155; --input-text:#fff;
  --panel-bg:#020617; --panel-bg-elevated:#0e152a; --panel-bg-info:#0c1a2e; --muted-text:#94a3b8; --muted-text-2:#64748b; --line-subtle:#1e293b;
}
[data-theme="light"]{
  --bg:#f8fafc; --text:#0f172a; --header-bg:rgba(255,255,255,0.95); --border:#cbd5e1;
  --card-bg:#ffffff; --card-subtitle:#475569; --input-bg:#f1f5f9; --input-border:#94a3b8; --input-text:#0f172a;
  --panel-bg:#f1f5f9; --panel-bg-elevated:#e2e8f0; --panel-bg-info:#eff6ff; --muted-text:#475569; --muted-text-2:#64748b; --line-subtle:#cbd5e1;
}
body{font-family:Inter,system-ui,-apple-system;background:var(--bg);color:var(--text);min-height:100vh}
@keyframes fnoTradeAlertPulse{0%{transform:translateX(-50%) scale(0.92);opacity:0.4}100%{transform:translateX(-50%) scale(1);opacity:1}}
#fnoTradeAlertOverlay{display:none;position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:10050;min-width:min(420px,92vw);max-width:520px;pointer-events:none}
#fnoTradeAlertCard{background:#0f172a;border:2px solid #22c55e;border-radius:14px;padding:14px 18px;box-shadow:0 12px 40px rgba(0,0,0,0.55),0 0 0 1px rgba(255,255,255,0.06)}
#fno-root{max-width:1600px;margin:0 auto;padding:16px}
.header{position:sticky;top:0;z-index:100;background:var(--header-bg);backdrop-filter:blur(10px);border-bottom:1px solid var(--border);padding:12px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.logo{font-size:20px;font-weight:900;background:linear-gradient(90deg,#22c55e,#3b82f6);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.card{background:var(--card-bg);border:1px solid var(--border);border-radius:16px;padding:14px;margin-bottom:12px;color:var(--text)}
.card h3{font-size:15px}
.card-subtitle{font-size:12px;font-weight:400;color:var(--card-subtitle);margin-left:8px}
/* Real, deliberately limited semantic color system - replaces the
   previous ad-hoc mix of purple/amber/green/red borders with no
   consistent meaning. Three real, distinct purposes only:
   card-accent-primary = the single, main action/decision card;
   card-accent-info = supplementary analysis/intelligence panels;
   card-accent-risk = anything involving real money or destructive
   actions. Everything else uses the default, neutral border above. */
.card-accent-primary{border-color:#22c55e}
.card-accent-info{border-color:#f59e0b}
.card-accent-risk{border-color:#ef4444}
.badge{padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700;display:inline-block}
.green{background:#052e16;color:#4ade80;border:1px solid #14532d}
.red{background:#450a0a;color:#fca5a5;border:1px solid #7f1d1d}
.yellow{background:#422006;color:#fde68a;border:1px solid #92400e}
.blue{background:#0c1a2e;color:#93c5fd;border:1px solid #1e3a5f}
.btn{background:#2563eb;color:#fff;border:0;padding:8px 14px;border-radius:10px;font-weight:700;cursor:pointer}
.btn:hover{opacity:0.9}
.input{background:var(--input-bg);border:1px solid var(--input-border);color:var(--input-text);padding:7px 10px;border-radius:10px}
.brain-log{font-family:ui-monospace;font-size:11px;background:var(--input-bg);padding:10px;border-radius:10px;border:1px solid var(--border);max-height:200px;overflow:auto;white-space:pre-wrap;color:var(--text)}
.toggle{position:relative;display:inline-block;width:50px;height:26px}
.toggle input{opacity:0;width:0;height:0}
.slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#334155;transition:.4s;border-radius:26px}
.slider:before{position:absolute;content:"";height:18px;width:18px;left:4px;bottom:4px;background:white;transition:.4s;border-radius:50%}
input:checked + .slider{background:#22c55e}
input:checked + .slider:before{transform:translateX(24px)}
.grid{display:grid;grid-template-columns:1fr 400px;gap:14px}
/* FOUND via a real, live mobile test after adding the wide Trade
   Ledger table: a CSS Grid item's default minimum width is its own
   content's natural size, not zero - meaning a single wide table
   inside a grid column can force the ENTIRE grid column (and every
   other card sharing it) wider than the real viewport, regardless of
   overflow-x:auto on the table's own wrapper. Real, correct, minimal
   fix: overriding that default on the grid's own direct children so
   they can genuinely shrink to the real, available width, letting
   each card's own internal scroll take over instead. */
.grid>div{min-width:0}
@media(max-width:1000px){.grid{grid-template-columns:1fr}}
.login-bar{background:#422006;color:#fde68a;padding:10px;border-radius:10px;margin-bottom:12px;text-align:center}
.login-bar a{color:#60a5fa;font-weight:700}
/* Real, user's own direct, explicit requirement: "the entire NSE-
   related section... should be disabled/hidden" when the NSE
   Integration toggle is off. Honest scope note: only marks the ONE
   card confidently, cleanly identified as depending ENTIRELY on raw
   NSE-only data with genuinely no Kite equivalent (Participant
   Payoff Hypothesis, built on the raw FII/DII/Client participant OI
   CSV) - other panels that merely USE some NSE-sourced data as one
   input among several (which now also has a real Kite fallback,
   e.g. the option chain itself) are deliberately left visible, since
   hiding them would incorrectly imply they stop working entirely
   when only their real, secondary NSE-specific enrichment is
   affected - those honestly show "disabled"/"unavailable" per-factor
   already, which is the more accurate, honest treatment for a partial
   dependency. */
body.fno-nse-disabled .nse-only-section{display:none}
/* Light mode readability — most panels/factors are rendered with dark-theme inline
   hex colors from JS. Remap neutral dark surfaces + muted text here so inherited
   body text and hardcoded #94a3b8/#64748b/#e2e8f0 labels stay readable. Semantic
   pass/fail/warning colors (green/red/amber badges, BUY/SELL decision states) are
   left untouched. */
[data-theme="light"] #settingsModalOverlay > div{
  background:var(--card-bg)!important;border-color:var(--border)!important;color:var(--text)!important;
}
[data-theme="light"] #fno-root [style*="background:#020617"],
[data-theme="light"] #fno-root [style*="background: #020617"],
[data-theme="light"] #settingsModalOverlay [style*="background:#020617"],
[data-theme="light"] #settingsModalOverlay [style*="background: #020617"],
[data-theme="light"] #fno-root [style*="background:#0e152a"],
[data-theme="light"] #fno-root [style*="background: #0e152a"],
[data-theme="light"] #settingsModalOverlay [style*="background:#0e152a"],
[data-theme="light"] #settingsModalOverlay [style*="background: #0e152a"],
[data-theme="light"] #fno-root [style*="background:#0f172a"],
[data-theme="light"] #fno-root [style*="background: #0f172a"]{
  background:var(--panel-bg)!important;border-color:var(--line-subtle)!important;color:var(--text)!important;
}
[data-theme="light"] #fno-root [style*="background:#0c1a2e"],
[data-theme="light"] #fno-root [style*="background: #0c1a2e"],
[data-theme="light"] #settingsModalOverlay [style*="background:#0c1a2e"]{
  background:var(--panel-bg-info)!important;border-color:#93c5fd!important;color:var(--text)!important;
}
[data-theme="light"] #fno-root [style*="color:#94a3b8"],
[data-theme="light"] #fno-root [style*="color: #94a3b8"],
[data-theme="light"] #settingsModalOverlay [style*="color:#94a3b8"],
[data-theme="light"] #settingsModalOverlay [style*="color: #94a3b8"],
[data-theme="light"] #fno-root [style*="color:#64748b"],
[data-theme="light"] #fno-root [style*="color: #64748b"],
[data-theme="light"] #settingsModalOverlay [style*="color:#64748b"],
[data-theme="light"] #settingsModalOverlay [style*="color: #64748b"],
[data-theme="light"] .header [style*="color:#94a3b8"],
[data-theme="light"] .header [style*="color: #94a3b8"]{
  color:var(--muted-text)!important;
}
[data-theme="light"] #fno-root [style*="color:#e2e8f0"],
[data-theme="light"] #fno-root [style*="color: #e2e8f0"],
[data-theme="light"] #settingsModalOverlay [style*="color:#e2e8f0"],
[data-theme="light"] #settingsModalOverlay [style*="color: #e2e8f0"]{
  color:var(--text)!important;
}
[data-theme="light"] .header [style*="background:#0e152a"],
[data-theme="light"] .header [style*="background: #0e152a"],
[data-theme="light"] #dataSourceBadge[style*="background:#0e152a"]{
  background:var(--panel-bg)!important;border-color:var(--border)!important;
}
[data-theme="light"] #fno-root [style*="border:1px solid #1e293b"],
[data-theme="light"] #fno-root [style*="border:1px solid #111827"],
[data-theme="light"] #fno-root [style*="border-bottom:1px solid #111827"],
[data-theme="light"] #fno-root [style*="border-bottom:1px solid #1e293b"],
[data-theme="light"] #settingsModalOverlay [style*="border:1px solid #1e293b"],
[data-theme="light"] #factorsList[style*="border:1px solid #1e293b"]{
  border-color:var(--line-subtle)!important;
}
[data-theme="light"] #fno-root [style*="background:#1e293b"][style*="border-radius:4px"],
[data-theme="light"] #fno-root [style*="background:#1e293b"][style*="height:6px"]{
  background:var(--panel-bg-elevated)!important;
}
[data-theme="light"] #settingsModalOverlay input[style*="background:#020617"],
[data-theme="light"] #settingsModalOverlay select[style*="background:#020617"],
[data-theme="light"] #fno-root input[style*="background:#020617"],
[data-theme="light"] #fno-root select[style*="background:#020617"]{
  background:var(--input-bg)!important;border-color:var(--input-border)!important;color:var(--input-text)!important;
}
[data-theme="light"] .bracket-preset-btn[style*="background:#020617"]{
  background:var(--panel-bg)!important;color:var(--text)!important;border-color:var(--input-border)!important;
}
[data-theme="light"] .badge[style*="background:#334155"]{
  background:var(--panel-bg-elevated)!important;color:var(--muted-text)!important;border:1px solid var(--line-subtle)!important;
}
[data-theme="light"] .green{background:#dcfce7;color:#166534;border-color:#86efac}
[data-theme="light"] .red{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
[data-theme="light"] .yellow{background:#fef3c7;color:#92400e;border-color:#fcd34d}
[data-theme="light"] .blue{background:#dbeafe;color:#1e40af;border-color:#93c5fd}
[data-theme="light"] #fnoTradeAlertCard{background:#fff!important;color:var(--text)!important;box-shadow:0 12px 40px rgba(15,23,42,0.18)!important}
[data-theme="light"] #brainDecision[style*="background:#020617"],
[data-theme="light"] #decisionTierBadge[style*="background:#020617"]{
  background:var(--panel-bg)!important;color:var(--text)!important;
}
[data-theme="light"] #fno-root b,
[data-theme="light"] #settingsModalOverlay b,
[data-theme="light"] #fno-root strong,
[data-theme="light"] #settingsModalOverlay strong{
  color:var(--text);
}
</style>
</head>
<body>
<?php if (!$is_logged_in): ?>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:#070b16;color:#e5e7eb;font-family:system-ui,-apple-system,sans-serif;text-align:center;padding:20px">
  <div>
    <div style="font-size:48px;margin-bottom:16px">🚧</div>
    <h1 style="font-size:22px;font-weight:700;margin-bottom:10px;color:#f1f5f9">Work in Progress</h1>
    <p style="font-size:14px;color:#94a3b8;max-width:380px;margin:0 auto 20px">This site is currently private and under development. Please check back later.</p>
    <a href="<?php echo esc_url(wp_login_url($site_url . '/lab/')); ?>" style="display:inline-block;background:#2563eb;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-size:14px;font-weight:600">Log In</a>
  </div>
</div>
<?php else: ?>
<div class="header">
  <div class="logo">🧠 F&O Lab v<?php echo esc_html(FNO_PLUGIN_VERSION); ?> - Standalone App - <?php echo $factors_count; ?> Factors</div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <span style="font-size:11px;color:#94a3b8">No Theme • No Shortcode • Works like software</span>
    <select id="sym" class="input"><option>NIFTY</option><option>BANKNIFTY</option><option>FINNIFTY</option></select>
    <div style="display:flex;align-items:center;gap:8px;background:#0e152a;padding:6px 12px;border-radius:10px;border:1px solid #1e293b" title="This only reveals the Kite market-data settings below - it does NOT place real trades or turn on Real Money Trading. See the green badge to the right for that."><span style="font-size:12px;color:#94a3b8">Free Data</span><label class="toggle"><input type="checkbox" id="liveToggle"><span class="slider"></span></label><span style="font-size:12px;color:#94a3b8">Kite Data</span></div>
    
    <a id="realMoneyStatusBadge" href="<?php echo esc_url(admin_url('options-general.php?page=fno-premium-providers')); ?>#fno-real-money-section" style="font-size:11px;padding:5px 10px;border-radius:8px;background:#052e16;color:#4ade80;border:1px solid #166534;text-decoration:none;cursor:pointer" title="Real Money Trading is completely separate from the data-source toggle to the left. Click this badge any time to open the page where it's configured, armed, or disarmed - even if you're not using it yet, this is where you'd find it.">Loading real-money status...</a>
    <span id="dataSourceBadge" style="font-size:11px;padding:5px 10px;border-radius:8px;background:#0e152a;color:#94a3b8;border:1px solid #1e293b;display:none" title="Shows which real source your live chart/option-chain data actually came from this refresh - NSE directly, or your own connected Kite session as a real, automatic fallback when NSE is unreachable."></span>
    <button id="openSettingsBtn" class="btn" style="background:#334155">⚙️ Settings</button>
    <button id="refresh" class="btn">Refresh Brain</button>
    <a href="<?php echo $site_url; ?>/wp-admin/" style="font-size:11px;color:#94a3b8">WP Admin</a>
  </div>
</div>

<div id="fnoTradeAlertOverlay" aria-live="assertive" aria-atomic="true">
  <div id="fnoTradeAlertCard"></div>
</div>

<div id="settingsModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#0e152a;border:1px solid #334155;border-radius:14px;padding:24px;max-width:520px;width:90%;max-height:85vh;overflow-y:auto">
    <h2 style="margin:0 0 4px 0;font-size:18px">⚙️ Trading Controls</h2>
    <div style="font-size:11px;color:#64748b;margin-bottom:16px">Every setting here takes effect immediately - no save button, no reload needed.</div>

    <div style="margin-bottom:18px">
      <div style="font-size:12px;font-weight:700;color:#94a3b8;margin-bottom:8px">Broker / API</div>
      <div style="font-size:12px;padding:8px;background:#052e16;border:1px solid #166534;border-radius:8px;margin-bottom:6px">✅ Zerodha (Kite) — Main/Primary. Always available, never depends on the toggle below.</div>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;cursor:pointer">
        <input type="checkbox" id="settingNseEnabled"> NSE Integration <span id="nseStatusLabel" style="margin-left:auto;color:#64748b">OFF</span>
      </label>
      <div style="font-size:10px;color:#64748b;margin-top:4px">OFF (default): the app runs entirely on your Zerodha connection - no NSE calls at all, no NSE-only sections shown. ON: NSE-specific data (ban list, corporate actions, participant OI, ASM/GSM) becomes available again alongside Zerodha.</div>
    </div>

    <div style="margin-bottom:18px;padding:12px;background:#422006;border:1px solid #92400e;border-radius:10px">
      <div style="font-size:12px;font-weight:700;color:#fde68a;margin-bottom:8px">⚡ Scalping Profit Profile <span id="scalpingProfitProfileStatusLabel" style="margin-left:8px;color:#94a3b8;font-weight:400">OFF</span></div>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;margin-bottom:6px;cursor:pointer"><input type="checkbox" id="settingScalpingProfitProfile"> Enable profile — more scalp entries with safety rails (spread block, weighted-score gate, tight target/SL, 65% auto-cal)</label>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;margin-bottom:6px;cursor:pointer"><input type="checkbox" id="settingScalpingCapitalPreservation"> 🛡️ Capital preservation mode <span id="scalpingCapitalPreservationStatusLabel" style="margin-left:auto;color:#64748b">ON</span></label>
      <div style="display:flex;align-items:center;gap:8px;font-size:12px;padding:4px 8px 8px 8px;flex-wrap:wrap">
        <span>Max losing trades/day:</span>
        <input type="number" id="settingMaxLosingTradesPerDay" min="0" max="10" step="1" value="1" style="width:50px;background:#020617;border:1px solid #1e293b;border-radius:6px;color:#e2e8f0;padding:4px 6px">
        <span>Daily loss cap:</span>
        <input type="number" id="settingMaxDailyLossPctPreservation" min="0.5" max="10" step="0.5" value="1.5" style="width:50px;background:#020617;border:1px solid #1e293b;border-radius:6px;color:#e2e8f0;padding:4px 6px">
        <span>% of capital</span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;font-size:12px;padding:4px 8px 8px 8px;flex-wrap:wrap">
        <span>FM safety mode:</span>
        <select id="settingScalpingFmSafety" class="input" style="width:160px">
          <option value="strict">Strict (recommended)</option>
          <option value="balanced">Balanced</option>
        </select>
      </div>
      <div style="font-size:10px;color:#64748b;margin-top:4px">Turning this ON also enables Scalping, disables Intraday, turns on trade-type target/SL, sets auto-calibration to 65% win-rate target, default 2 exchange lots, and uses thresholds BUY ≥8 / SELL ≤−13. Realistic execution stays ON — wide spreads still block. Capital preservation (ON by default) requires High confidence, blocks operator/trap warnings, stops after configurable daily losses — fewer trades, tighter filters; cannot eliminate all market risk. Strict FM mode keeps scalping-relevant failure checks escalated; Balanced skips the +1 severity bump only.</div>
    </div>

    <div style="margin-bottom:18px;padding:12px;background:#0f172a;border:1px solid #475569;border-radius:10px">
      <div style="font-size:12px;font-weight:700;color:#fde68a;margin-bottom:6px">🎯 Target / SL Bracket Setup <span style="font-weight:400;color:#64748b;font-size:10px">(v16.29+ — 5 presets)</span></div>
      <div id="settingScalpingBracketButtons" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">
        <button type="button" class="btn bracket-preset-btn" data-preset="micro10" style="padding:6px 10px;font-size:11px;min-width:72px">Auto 10%</button>
        <button type="button" class="btn bracket-preset-btn" data-preset="fast15" style="padding:6px 10px;font-size:11px;min-width:72px">Auto 15%</button>
        <button type="button" class="btn bracket-preset-btn" data-preset="standard" style="padding:6px 10px;font-size:11px;min-width:72px">Auto 20%</button>
        <button type="button" class="btn bracket-preset-btn" data-preset="balanced" style="padding:6px 10px;font-size:11px;min-width:72px">Auto 25%</button>
        <button type="button" class="btn bracket-preset-btn" data-preset="manual" style="padding:6px 10px;font-size:11px;min-width:88px">Manual 30%</button>
      </div>
      <select id="settingScalpingBracketPreset" class="input" style="width:100%;max-width:280px;margin-bottom:6px">
        <option value="micro10">Auto 10% — +10% target / −5% SL</option>
        <option value="fast15">Auto 15% — +15% target / −7.5% SL</option>
        <option value="standard">Auto 20% (default) — +20% target / −10% SL</option>
        <option value="balanced">Auto 25% — +25% target / −12.5% SL</option>
        <option value="manual">Manual 30% — save your own target/SL %</option>
      </select>
      <div id="settingScalpingBracketHint" style="font-size:10px;color:#64748b">Pick 10% or 15% for fastest exits; 20% is default. Manual lets you save custom % on the Auto Trades panel. Trailing + partial exit ON (2:1 R:R).</div>
    </div>

    <div style="margin-bottom:18px">
      <div style="font-size:12px;font-weight:700;color:#94a3b8;margin-bottom:8px">Trading Types <span style="font-weight:400;color:#64748b">— independent, any combination</span></div>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;margin-bottom:6px;cursor:pointer"><input type="checkbox" id="settingTypeIntraday"> 📊 Intraday — 60-second checks, closes same day</label>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;margin-bottom:6px;cursor:pointer"><input type="checkbox" id="settingTypeSwing"> 📅 Swing — real, server-side tracking, survives closing this tab (needs the standalone driver running for real multi-day coverage)</label>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;cursor:pointer"><input type="checkbox" id="settingTypeScalping"> ⚡ Scalping — fast 15-second checks, tight targets</label>
      <div style="font-size:10px;color:#64748b;margin-top:4px">Honest, real limit: Intraday and Scalping currently share one local position slot, so only one of the two can hold an open trade at a time - whichever opens first. Swing runs genuinely in parallel via its own, separate server-side tracking, alongside whichever of the other two is active.</div>
    </div>

    <div style="margin-bottom:18px">
      <div style="font-size:12px;font-weight:700;color:#94a3b8;margin-bottom:8px">Position Sizing</div>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;margin-bottom:6px;cursor:pointer"><input type="checkbox" id="settingTradeTypeSizing"> ⚖️ Trade-type-aware sizing <span id="tradeTypeSizingStatusLabel" style="margin-left:auto;color:#64748b">OFF</span></label>
      <div style="font-size:10px;color:#64748b;margin-top:4px;margin-bottom:10px">OFF (default): every trade type uses your Lots (exchange) field below, unchanged. ON: equal-risk-per-trade sizing — Scalping (tighter 0.5x stop) sizes up to ~2x your lot count, Swing (wider 1.5x stop) sizes down to ~0.67x, Intraday stays at 1x, each rounded to whole exchange lots (minimum one lot). This only ever offers a computed size for auto-opened trades — it never overrides what you type into the Lots field itself.</div>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;cursor:pointer"><input type="checkbox" id="settingTradeTypeTargetSl"> 🎯 Trade-type-aware default target/SL <span id="tradeTypeTargetSlStatusLabel" style="margin-left:auto;color:#64748b">OFF</span></label>
      <div style="font-size:10px;color:#64748b;margin-top:4px">OFF (default): Autonomous Mode's fallback target/SL (used only when you haven't typed your own into the Target/SL fields) stays the original +30%/-15%, same for every trade type. ON: that fallback scales by the same real stop multiplier as trailing — Scalping tighter, Swing wider — while holding the same 2:1 reward:risk ratio. Never overrides a target/SL you've actually typed in yourself.</div>
    </div>

    <div style="margin-bottom:18px">
      <div style="font-size:12px;font-weight:700;color:#94a3b8;margin-bottom:8px">Automatic Threshold Calibration</div>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;cursor:pointer"><input type="checkbox" id="settingAutoCalibrate"> 🎯 Auto-calibrate live thresholds for my win-rate target <span id="autoCalibrateStatusLabel" style="margin-left:auto;color:#64748b">OFF</span></label>
      <div style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px 8px 0 8px">
        <span>Target win rate:</span>
        <input type="number" id="settingAutoCalibrateTargetWinRate" min="1" max="99" step="1" value="80" style="width:60px;background:#020617;border:1px solid #1e293b;border-radius:6px;color:#e2e8f0;padding:4px 6px">
        <span>%</span>
      </div>
      <div style="font-size:10px;color:#64748b;margin-top:6px">OFF (default): thresholds only change via a proposal YOU review and approve in the Strategy Change Approval Workflow. ON: once per real day, the app real-replays your own logged decision history (win/lose, traded or not) through the same backtest engine, finds the loosest BUY/SELL threshold that has actually cleared your target win rate, and walks it through the SAME real proposed→under_review→approved→applied audit trail automatically — no click needed, but every change is still fully logged there for you to check. Honestly does nothing on a day with too little real evidence, and never guarantees this win rate going forward — see the app's own "Do Not Optimize For The Backtest Alone" principle. This only ever adjusts the live decision thresholds — never touches real-money trading, which stays permanently, separately disabled at the source-code level.</div>
    </div>

    <div style="margin-bottom:18px">
      <div style="font-size:12px;font-weight:700;color:#94a3b8;margin-bottom:8px">Appearance</div>
      <div style="display:flex;gap:8px">
        <label style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;font-size:12px;padding:8px;background:#020617;border-radius:8px;cursor:pointer"><input type="radio" name="settingAppearance" value="dark" id="settingAppearanceDark"> 🌙 Dark</label>
        <label style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;font-size:12px;padding:8px;background:#020617;border-radius:8px;cursor:pointer"><input type="radio" name="settingAppearance" value="light" id="settingAppearanceLight"> ☀️ Light</label>
      </div>
    </div>

    <div style="margin-bottom:18px">
      <div style="font-size:12px;font-weight:700;color:#94a3b8;margin-bottom:8px">🔊 Trade Alert System <span style="font-weight:400;color:#64748b">— real execution only</span></div>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;margin-bottom:6px;cursor:pointer"><input type="checkbox" id="settingTradeAlertSystem"> Enable trade alerts <span id="tradeAlertSystemStatusLabel" style="margin-left:auto;color:#64748b">ON</span></label>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;margin-bottom:6px;cursor:pointer"><input type="checkbox" id="settingSoundEntry"> 🟢 Alert on ENTRY (paper trade opened)</label>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;margin-bottom:6px;cursor:pointer"><input type="checkbox" id="settingSoundExit"> 🔴 Alert on EXIT (target / SL / square-off / manual)</label>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;margin-bottom:6px;cursor:pointer"><input type="checkbox" id="settingTradeAlertSound"> 🔊 Alert sound (plays first) <span id="tradeAlertSoundStatusLabel" style="margin-left:auto;color:#64748b">ON</span></label>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;margin-bottom:6px;cursor:pointer"><input type="checkbox" id="settingTradeAlertVoice"> 🎙️ AI voice announcement (after alert) <span id="tradeAlertVoiceStatusLabel" style="margin-left:auto;color:#64748b">ON</span></label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:11px;padding:8px;background:#020617;border-radius:8px;margin-bottom:6px">
        <label>Alert volume: <span id="tradeAlertSoundVolLabel">90%</span><input type="range" id="settingTradeAlertSoundVolume" min="0" max="100" step="5" value="90" style="width:100%"></label>
        <label>Voice volume: <span id="tradeAlertVoiceVolLabel">100%</span><input type="range" id="settingTradeAlertVoiceVolume" min="0" max="100" step="5" value="100" style="width:100%"></label>
      </div>
      <div style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;margin-bottom:6px;flex-wrap:wrap">
        <span>Alert sound:</span>
        <select id="settingTradeAlertSoundPreset" class="input" style="flex:1;min-width:140px">
          <option value="trading_desk">Trading desk (loud)</option>
          <option value="siren_pulse">Siren pulse</option>
          <option value="urgent_chime">Urgent chime</option>
          <option value="telephone">Telephone ring</option>
        </select>
        <button id="testTradeAlertSoundBtn" class="btn" style="padding:4px 10px;font-size:11px">▶ Test sound</button>
      </div>
      <div style="display:flex;align-items:center;gap:8px;font-size:12px;padding:8px;background:#020617;border-radius:8px;flex-wrap:wrap">
        <button id="testTradeAlertVoiceEntryBtn" class="btn" style="padding:4px 10px;font-size:11px;background:#166534">▶ Test entry voice</button>
        <button id="testTradeAlertVoiceExitBtn" class="btn" style="padding:4px 10px;font-size:11px;background:#7f1d1d">▶ Test exit voice</button>
      </div>
      <div style="font-size:10px;color:#64748b;margin-top:6px">Fires only when a real paper trade is opened or closed — not on BUY_READY/WAIT signals. Sequence: loud alert → immediate AI voice with symbol, strike, CE/PE, prices, qty, P&amp;L, and exit reason. Requires browser permission for audio; voice uses your device&apos;s speech engine (Chrome/Edge recommended).</div>
    </div>

    <button id="closeSettingsBtn" class="btn" style="width:100%">Close</button>
  </div>
</div>

<div id="fno-root">

  <div class="card card-accent-primary" style="margin-bottom:14px">
    <h3>📈 Live Price Chart <span class="card-subtitle">Underlying candles + real entry/exit markers</span></h3>
    <div style="font-size:10px;color:#64748b;margin-bottom:8px" id="priceChartCaption">Real underlying candle data from the same live feed every other panel uses. 🔺 green marks a real trade open, 🔻 red marks a real trade close (target/SL/square-off/manual) - both plotted from the real spot price captured at that exact moment, not estimated. Redraws every refresh, same cadence as the rest of this page.</div>
    <div style="display:flex;gap:6px;align-items:center;margin-bottom:6px;flex-wrap:wrap">
      <span style="font-size:10px;color:#94a3b8">Timeframe:</span>
      <button type="button" class="btn chart-tf-btn" data-tf="1" style="padding:3px 8px;font-size:11px">1m</button>
      <button type="button" class="btn chart-tf-btn" data-tf="5" style="padding:3px 8px;font-size:11px">5m</button>
      <button type="button" class="btn chart-tf-btn" data-tf="15" style="padding:3px 8px;font-size:11px">15m</button>
      <span style="width:1px;height:16px;background:#1e293b;margin:0 4px"></span>
      <label style="font-size:11px;color:#94a3b8"><input type="checkbox" id="chartShowEma" checked> EMA21</label>
      <label style="font-size:11px;color:#94a3b8"><input type="checkbox" id="chartShowVwap" checked> VWAP</label>
      <span style="width:1px;height:16px;background:#1e293b;margin:0 4px"></span>
      <button type="button" class="btn" id="chartZoomIn" style="padding:3px 8px;font-size:11px">🔍+</button>
      <button type="button" class="btn" id="chartZoomOut" style="padding:3px 8px;font-size:11px">🔍-</button>
      <button type="button" class="btn" id="chartResetView" style="padding:3px 8px;font-size:11px">Reset</button>
      <span style="font-size:10px;color:#64748b">(scroll to zoom, drag to pan)</span>
    </div>
    <canvas id="priceChartCanvas" style="width:100%;height:420px;background:#020617;border-radius:10px;cursor:grab;touch-action:none"></canvas>
    <div id="priceChartLegend" style="font-size:10px;color:#64748b;margin-top:6px"></div>
  </div>

  <div class="grid">
    <div>
      <div class="card card-accent-info">
        <h3>🎯 Six-Month Learning Objective <span class="card-subtitle">Operating for at least six months to build a substantial, real dataset</span></h3>
        <div id="learningObjectiveBox" style="font-size:11px">Loading real progress...</div>
      </div>
      <div class="card card-accent-primary">
        <h3>📒 Trade Ledger <span class="card-subtitle">Every simulated trade, with realistic Zerodha-rate charges - nothing filtered</span></h3>
        <div id="tradeLedgerSummary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:12px">Loading...</div>
        <div style="overflow-x:auto;max-width:100%">
          <table style="width:100%;border-collapse:collapse;font-size:12px" id="tradeLedgerTable">
            <thead>
              <tr style="border-bottom:1px solid #1e293b;color:#94a3b8;text-align:left">
                <th style="padding:7px 6px">ID</th>
                <th style="padding:7px 6px">Date / Time</th>
                <th style="padding:7px 6px">Symbol</th>
                <th style="padding:7px 6px">Strike/Type</th>
                <th style="padding:7px 6px">Action</th>
                <th style="padding:7px 6px;text-align:right">Qty</th>
                <th style="padding:7px 6px;text-align:right">Entry</th>
                <th style="padding:7px 6px;text-align:right">Exit</th>
                <th style="padding:7px 6px;text-align:right">Gross P&amp;L</th>
                <th style="padding:7px 6px;text-align:right">Charges</th>
                <th style="padding:7px 6px;text-align:right">Net P&amp;L</th>
                <th style="padding:7px 6px">Status</th>
                <th style="padding:7px 6px" title="Real, foundational trade-type awareness - which trade type this specific trade actually was, recorded at the exact moment it opened.">Type</th>
              </tr>
            </thead>
            <tbody id="tradeLedgerBody"><tr><td colspan="13" style="padding:10px;color:#64748b">Loading...</td></tr></tbody>
          </table>
        </div>
        <div id="tradeLedgerChargesDetail" style="margin-top:10px;font-size:12px"></div>
      </div>
      <div class="card card-accent-info">
        <h3>🩺 Diagnostic Report <span class="card-subtitle">System health, factor reliability, detected failures - downloadable</span></h3>
        <div style="font-size:11px;color:#94a3b8;margin-bottom:8px">Every number in this report comes directly from your own real, stored data - closed trades, logged failure events, and checked hypotheses. Sections with no real data yet honestly say so, rather than showing a fabricated number.</div>
        <div id="diagnosticReportPreview" style="font-size:11px;margin-bottom:10px">Loading...</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a id="downloadReportCsv" class="btn" style="background:#22c55e;color:#000;text-decoration:none" download>⬇️ System CSV</a>
          <a id="downloadReportPdf" class="btn" style="background:#3b82f6;color:#fff;text-decoration:none" download>⬇️ System PDF</a>
        </div>
      </div>
      <div class="card card-accent-primary">
        <h3>📊 Strategy Performance &amp; Diagnostic Report <span class="card-subtitle">Long-term evidence for profitability tuning — does not change rules</span></h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Full historical diagnostic: signals, blocks, trades, missed opportunities, Operator Intel, entry/exit quality, over-restriction detection, and strategy assumption review. Export JSON for future AI analysis or Markdown/CSV for human review. Bump <code>FNO_STRATEGY_VERSION</code> when changing rules so Version A vs B comparisons stay meaningful.</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px;font-size:11px">
          <label>Period:
            <select id="strategyReportPeriod" class="input" style="width:140px">
              <option value="all">All logged history</option>
              <option value="today">Today</option>
              <option value="7d">Last 7 days</option>
              <option value="21d">Last 21 days</option>
            </select>
          </label>
          <label>Compare version (optional):
            <input id="strategyReportCompareA" class="input" placeholder="e.g. v1.0-baseline" style="width:120px">
            vs
            <input id="strategyReportCompareB" class="input" placeholder="e.g. v1.1" style="width:100px">
          </label>
        </div>
        <div id="strategyDiagnosticPreview" style="font-size:11px;margin-bottom:10px;padding:8px;background:#020617;border-radius:8px">Loading preview...</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button type="button" id="downloadStrategyReportJson" class="btn" style="background:#0891b2;color:#fff">⬇️ Full JSON (for AI / archive)</button>
          <button type="button" id="downloadStrategyReportMd" class="btn" style="background:#6366f1;color:#fff">⬇️ Markdown report</button>
          <button type="button" id="downloadStrategyReportCsv" class="btn" style="background:#22c55e;color:#000">⬇️ Summary CSV</button>
        </div>
      </div>
      <div class="card card-accent-info">
        <h3>⏳ Decay + Regulatory + Greeks - Live Auto</h3>
        <div id="decayMetrics" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;font-size:11px"></div>
      </div>
      <div class="card card-accent-primary">
        <h3>🤖 BRAIN DECISION - Auto (<span id="factorCountLabel"><?php echo $factors_count; ?></span> catalogued) <span id="effectiveTradingTypeBadge" style="font-size:11px;font-weight:700;padding:3px 8px;border-radius:6px;margin-left:6px" title="Real, foundational trade-type awareness - shows which real trade type a new position would be recorded as if one opened right now, based on your current Trading Controls settings."></span></h3>
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap">
          <button id="autonomousModeToggle" class="btn" style="font-weight:700">▶ Start Autonomous Mode</button>
          <span id="autonomousModeStatus" style="font-size:11px;color:#94a3b8">Autonomous Mode: OFF - manual refresh only</span>
        </div>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">While ON, this tab automatically re-runs the full observe-analyse-decide-monitor-exit cycle every 15 seconds (Scalping) or 60 seconds (Intraday) during real NSE market hours (9:15am-3:30pm IST, Mon-Fri) - no manual clicking needed. Honest limit: this only runs while this browser tab stays open; closing it pauses everything until you return.</div>
        <div id="scalpingSessionReadinessBox" style="display:none;margin-bottom:10px;padding:10px;background:#422006;border:1px solid #92400e;border-radius:10px"></div>
        <div id="eligibilityFunnelBox" style="margin-bottom:10px;padding:10px;background:#0c1a2e;border:1px solid #1e3a5f;border-radius:10px;font-size:11px;color:#94a3b8">Loading eligibility funnel...</div>
        <div id="brainDecision" style="font-size:18px;font-weight:800;padding:12px;border-radius:12px;background:#020617;text-align:center">Loading brain...</div>
        <div id="decisionTierBadge" style="font-size:14px;font-weight:700;padding:8px;border-radius:8px;background:#020617;text-align:center;margin-top:6px"></div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:10px">
          <div style="background:#020617;padding:8px;border-radius:10px;text-align:center"><div style="font-size:11px;color:#94a3b8">Score</div><div id="totalScore" style="font-size:18px;font-weight:800">-</div></div>
          <div style="background:#020617;padding:8px;border-radius:10px;text-align:center"><div style="font-size:11px;color:#94a3b8">Pass/Fail</div><div><span id="passCount" style="color:#4ade80">-</span>/<span id="failCount" style="color:#f87171">-</span></div></div>
          <div style="background:#020617;padding:8px;border-radius:10px;text-align:center"><div style="font-size:11px;color:#94a3b8">Critical</div><div id="critCount" style="font-size:18px;color:#ef4444">-</div></div>
          <div style="background:#020617;padding:8px;border-radius:10px;text-align:center"><div style="font-size:11px;color:#94a3b8">Mode</div><div id="modeDisplay" style="font-size:14px">PAPER</div></div>
        </div>
        <div id="tradeTypeWeightingBox" style="margin-top:10px;padding:10px;background:#0e152a;border:1px solid #1e293b;border-radius:10px;font-size:11px" title="Real, trade-type-aware category weighting - user's own direct request. Shows how the same, real factor evidence scores differently depending on which trade type is currently active, and exactly why - fully transparent, never a black-box adjustment."></div>
        <div id="autoTradeBox" style="margin-top:10px"></div>
        <div id="aiNarrativeBox" style="margin-top:10px;padding:10px;background:#0e152a;border:1px solid #1e293b;border-radius:10px;font-size:12px;color:#94a3b8"><i>🤖 AI Narrative Commentary (optional, explains the decision above in plain English - never makes or influences it): not yet configured. Add a real OpenAI API Key in Settings &gt; F&O Lab Providers to enable this.</i></div>
        <div class="brain-log" id="brainLog" style="margin-top:10px"></div>
      </div>

      <div class="card card-accent-info">
        <h3>🛡️ Failure-Mode Library <span class="card-subtitle">Evaluated before every simulated trade</span></h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">151 real, catalogued failure conditions (see docs/FAILURE_MODE_LIBRARY.md for the complete, structured list) - the checks below are the real, live subset actually evaluated and enforced on the last trade attempt.</div>
        <div id="failureModeLibraryBox" style="font-size:12px;max-height:250px;overflow:auto;color:#64748b">No trade attempted yet this session - these checks run automatically the moment a real Auto Trade is attempted, and the result will appear here.</div>
        <div style="font-size:10px;color:#64748b;margin-top:10px;margin-bottom:6px">Blocked Attempts History <span title="A real, persisted report of every attempt this Failure-Mode gate has blocked/rejected (localStorage, capped at the 200 most recent) - previously a blocked attempt left no trace anywhere once its refresh cycle passed.">ⓘ</span> - persists across refreshes, not just the last attempt above</div>
        <div id="blockedAttemptsHistoryBox" style="font-size:11px;max-height:200px;overflow:auto;color:#64748b">No blocked attempts recorded yet this session.</div>
      </div>

      <div class="card card-accent-primary">
        <h3>🧭 Decision Intelligence <span class="card-subtitle">Missed opportunities, false positives, and what to consider changing</span></h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">A real, local, self-evaluating log of every refresh's decision (BUY_READY/SELL_READY/WAIT/NO_TRADE) - traded or not - matched afterward against what the underlying price actually did, AND (when the newer fields are present) re-priced as a real, cost-inclusive hypothetical option premium using the same Black-Scholes engine and transaction-cost model real trades use. This is what answers "is the system being appropriately cautious, or just too conservative?" with real evidence instead of a guess. Every finding below states its own sample size and an honest caveat rather than a fabricated conclusion - see each Missed Opportunity's own caveat for exactly what it can and can't prove. Nothing here changes your live strategy automatically.</div>
        <div id="decisionIntelligenceBox" style="font-size:12px;max-height:420px;overflow:auto;color:#64748b">Loading...</div>
      </div>

      <div class="card nse-only-section">
        <h3>🧠 Participant Payoff Hypothesis <span class="card-subtitle">Who benefits, and is price actually moving that way?</span></h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">A real, structured hypothesis synthesized from the OI/PCR/Max Pain/trap signals already computed this refresh - a testable claim about which direction the dominant real positioning would benefit from, never a claim of certainty or manipulation. Logged automatically (throttled to once per 30 real minutes) and checked later against what actually happened - the confirmed-vs-disconfirmed rate over time is the honest answer to whether this reasoning is actually predictive.</div>
        <div id="currentHypothesisBox" style="font-size:11px;padding:10px;border-radius:8px;background:#020617;margin-bottom:10px">Waiting for the next refresh...</div>
        <div id="hypothesisStatsBox" style="font-size:11px"></div>
      </div>

      <div class="card">
        <h3>📋 Factor Registry - Data Coverage (Master Prompt §2, §54-57)</h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Every catalogued factor's REAL implementation status this refresh - never fabricated as neutral when unavailable.</div>
        <div id="factorRegistrySummary" style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;font-size:11px"></div>
        <div id="factorRegistryCoverage" style="margin-top:8px;font-size:12px;font-weight:700;text-align:center;padding:8px;border-radius:8px;background:#020617"></div>
        <div id="factorActivationRoadmap" style="margin-top:8px"></div>
        <div id="categoriesNotEvaluated"></div>
      </div>

      <div class="card">
        <h3>🌋 IV Surface (Enterprise Plan #9)</h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Real Strike x Expiry x IV surface, computed live from the same multi-expiry option-chain fetch already used elsewhere in this app - no separate data source needed.</div>
        <div id="ivSurfaceBox" style="font-size:11px;color:#64748b">Loading...</div>
      </div>

      <div class="card">
        <h3>⚖️ Dealer Gamma Exposure <span class="card-subtitle">Whether hedging activity could be influencing price</span></h3>
        <div id="dealerGammaBox" style="font-size:11px">Loading...</div>
      </div>

      <div class="card">
        <h3>🎯 Multi-Level Payoff Map <span class="card-subtitle">Where is the strongest real positioning?</span></h3>
        <div id="multiLevelPayoffBox" style="font-size:12px">Loading...</div>
      </div>

      <div class="card">
        <h3>🔀 Futures-Options Alignment <span class="card-subtitle">Are futures and options positioning aligned or contradictory?</span></h3>
        <div id="futuresAlignBox" style="font-size:11px">Loading...</div>
      </div>

      <div class="card card-accent-info">
        <h3>🧩 Multi-Instrument Consistency <span class="card-subtitle">Calls, Puts, Futures, underlying, volatility and expiry - analyzed together</span></h3>
        <div id="multiInstrumentBox" style="font-size:11px">Loading...</div>
        <div id="wrongSideCheckBox" style="margin-top:8px;font-size:11px">Loading...</div>
      </div>

      <div class="card">
        <h3>🔗 Correlation Engine (Enterprise Plan #13)</h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Real rolling return correlation between the selected symbol and a comparison index - informational context on whether the two are currently moving together, useful for judging whether two positions would genuinely be diversified exposure.</div>
        <div id="correlationEngineBox" style="font-size:11px"></div>
        <div id="crossInstrumentShiftBox" style="font-size:11px;margin-top:8px"></div>
        <div id="correlationMatrixBox" style="font-size:11px;margin-top:8px;border-top:1px solid #1e293b;padding-top:8px"></div>
      </div>

      <div class="card">
        <h3>🗂️ Data Availability Matrix (Enterprise Plan #33)</h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Real, live introspection of which tier is actually configured for each capability - not a static doc, refreshes every time you view it. Loaded once per page load (this rarely changes minute to minute).</div>
        <div id="dataAvailabilityMatrix" style="font-size:11px;max-height:250px;overflow:auto"></div>
      </div>

      <div class="card">
        <h3>🌍 Current Market Snapshot (Master Prompt §53)</h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Regime summary, independent of any specific trade decision.</div>
        <div id="marketSnapshotBox" style="font-size:12px"></div>
        <div id="regimeConfidenceBox" style="margin-top:8px"></div>
      </div>

      <div class="card">
        <h3>📐 Breakout / Reversal / Choppy / Range-Bound <span class="card-subtitle">Detecting traps and different market conditions</span></h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Real, honest limitation: this app's only real chart source returns close prices only, no true intraday high/low - built entirely from a real N-candle closing range, stated as such, not fabricated as true high/low breakout detection.</div>
        <div id="breakoutConditionBox" style="font-size:12px">Loading...</div>
      </div>

      <div class="card">
        <h3>✍️ Option-Writing Conditions <span class="card-subtitle">How option-writing structures behave under different market conditions</span></h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">This app is long-options-only and never writes/sells options - this is real, informational context about the other side of the market, not a recommendation.</div>
        <div id="optionWritingConditionsBox" style="font-size:12px">Loading...</div>
      </div>

      <div class="card">
        <h3>🪤 Breakout/Breakdown Trap Check <span class="card-subtitle">Is this breakout genuine, or potentially a trap?</span></h3>
        <div id="breakoutTrapCheckBox" style="font-size:12px">Loading...</div>
      </div>

      <div class="card">
        <h3>🔊 Volume Confirmation <span class="card-subtitle">Does volume confirm the activity?</span></h3>
        <div id="volumeConfirmationBox" style="font-size:12px">Loading...</div>
      </div>

      <div class="card">
        <h3>⚡ Sudden Volatility Event <span class="card-subtitle">Distinct from a persistently high-volatility market</span></h3>
        <div id="suddenVolatilityEventBox" style="font-size:12px">Loading...</div>
      </div>

      <div class="card">
        <h3>🔥 Factor Heatmap (Master Prompt §52)</h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Real net directional lean per category this refresh - bar length = relative significance.</div>
        <div id="factorHeatmapBox" style="font-size:11px"></div>
      </div>

      <div class="card" id="liveSettingsCard" style="display:none;border:2px solid #f59e0b">
        <h3>📡 Kite Market Data Connection <span class="card-subtitle">Read-only - for live prices, never for placing orders</span></h3>
        <div style="background:#052e16;color:#4ade80;padding:10px;border-radius:8px;font-size:13px;font-weight:700;margin-bottom:8px;text-align:center;border:1px solid #166534">🔒 PAPER TRADING — REAL ORDERS DISABLED AT THE SOURCE CODE LEVEL</div>
        <div style="background:#0e152a;color:#94a3b8;padding:8px;border-radius:8px;font-size:11px;margin-bottom:8px">This form only connects your account so the app can read real, live prices from Zerodha. The "Simulate Order" button elsewhere on this page calls a real function that has an actual Zerodha order-placement call physically written into it, but a hardcoded line directly in the source code stops it before that call is ever reached - not a setting, a line of code a developer would have to manually edit to change. The separate Real Money Trading system (wp-admin only, requires typing an exact confirmation phrase to arm) has its own, independent, additional lock that must also be manually edited in source code before either path could ever place a real order.</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <input id="apiKey" class="input" placeholder="API Key" style="flex:1;min-width:150px">
          <input id="apiSecret" class="input" type="password" placeholder="API Secret" style="flex:1;min-width:150px">
          <input id="brokerSquareOffTime" class="input" placeholder="Square-off HH:MM (e.g. 15:20)" style="width:170px">
          <button id="saveKite" class="btn">Save</button>
        </div>
        <div style="font-size:10px;color:#64748b;margin-top:4px">Square-off time is YOUR broker's actual MIS auto-square-off policy (check your broker's site - this varies by broker, e.g. Zerodha is commonly ~15:15-15:20 IST but that is not assumed for you). Powers the real "MTM Square Off Time" Regulatory factor.</div>
        <div id="kiteStatus" style="font-size:11px;color:#94a3b8;margin-top:6px"></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
          <button id="kiteLoginBtn" class="btn" style="background:#f59e0b;color:#000">1️⃣ Login Kite</button>
          <input id="requestToken" class="input" placeholder="Paste request_token" style="flex:1;min-width:200px">
          <button id="exchangeToken" class="btn">2️⃣ Exchange</button>
          <button id="verifyProfileBtn" class="btn">✅ Verify Profile</button>
        </div>
        <div id="kiteProfile" style="font-size:11px;background:#020617;padding:8px;border-radius:10px;border:1px solid #1e293b;min-height:40px;margin-top:8px"></div>
      </div>

      <div class="card">
        <h3>📋 Factors Catalogue (<?php echo $factors_count; ?> total - see note below on which are scored)</h3>
        <div style="display:flex;gap:6px;margin-bottom:8px"><input id="searchF" class="input" placeholder="Search factors e.g. ban, gamma, decay, fii, operator" style="flex:1"><select id="filterF" class="input"><option value="">All</option><option>Market</option><option>Flow</option><option>Tech</option><option>Vol</option><option>Costs</option><option>Risk</option><option>Personal</option><option>Operator Intel</option><option>Regulatory</option><option>Decay</option><option>Greeks Deep</option><option>Microstructure</option><option>Fundamental</option><option>Psychology</option></select></div>
        <div id="factorsList" style="max-height:600px;overflow:auto;border:1px solid #1e293b;border-radius:12px"></div>
      </div>
    </div>

    <div>
      <div class="card">
        <h3>📝 Daily Personal - Brain Uses Auto</h3>
        <div id="dailyChecks"></div>
        <button id="saveDaily" class="btn" style="width:100%;margin-top:8px;background:#22c55e;color:#000">Save Daily</button>
      </div>

      <div class="card">
        <h3>📊 Option Chain</h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Live strikes around ATM (highlighted). Click any CE/PE cell to select that strike/type AND auto-fill live premium + IV below - no more typing stale numbers by hand.</div>
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:6px;font-size:11px">
          <label><input type="checkbox" id="manualPriceOverride"> Manual price/IV override (uncheck to always use live chain data)</label>
        </div>
        <div id="optionChainTable" style="overflow-x:auto;max-width:100%;font-size:11px"></div>
      </div>

      <div class="card">
        <h3>🎯 Decay Inputs</h3>
        <div style="display:grid;gap:6px;font-size:12px">
          <label>Strike <input id="strike" type="number" value="23200" class="input" style="width:100px"></label>
          <label>Type <select id="optType" class="input" style="width:70px" aria-label="Option type CE or PE"><option value="CE">CE</option><option value="PE">PE</option></select></label>
          <label>Price <input id="optPrice" type="number" value="100" class="input" style="width:80px"></label>
          <label>IV <input id="iv" type="number" value="18" class="input" style="width:60px">%</label>
          <label>Days Exp <input id="daysExp" type="number" value="2" class="input" style="width:60px"></label>
          <label>Lots (exchange) <input id="lotCount" type="number" value="2" min="1" step="1" class="input" style="width:60px" title="Number of exchange lots — not raw qty"></label>
          <span id="lotQtyHint" style="font-size:10px;color:#64748b;display:block;margin-top:2px">2 lots = 150 qty (75/lot)</span>
          <button id="saveLotSizeBtn" class="btn" style="padding:6px 10px;font-size:11px;background:#334155" title="Saves default exchange lots — remembered across reloads">💾 Save as default</button>
          <span id="lotSizeSavedNote" style="font-size:10px;color:#4ade80;display:none">Saved!</span>
        </div>
      </div>

      <div class="card">
        <h3>📐 Greeks (Black-Scholes-Merton, live)</h3>
        <div id="greeksMetrics" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;font-size:11px"></div>
      </div>

      <div class="card">
        <h3>💰 Virtual Paper-Trading Account (Master Prompt §59)</h3>
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px;font-size:11px;margin-bottom:8px">
          <div style="background:#020617;padding:8px;border-radius:8px;text-align:center"><div style="color:#94a3b8">Balance</div><div id="acctBalance" style="font-weight:800;font-size:16px">-</div></div>
          <div style="background:#020617;padding:8px;border-radius:8px;text-align:center"><div style="color:#94a3b8">Cumulative P&L</div><div id="acctPnl" style="font-weight:800;font-size:16px">-</div></div>
          <div style="background:#020617;padding:8px;border-radius:8px;text-align:center"><div style="color:#94a3b8">Current Drawdown</div><div id="acctCurDD" style="font-weight:800">-</div></div>
          <div style="background:#020617;padding:8px;border-radius:8px;text-align:center"><div style="color:#94a3b8">Max Drawdown</div><div id="acctMaxDD" style="font-weight:800">-</div></div>
          <div style="background:#020617;padding:8px;border-radius:8px;text-align:center"><div style="color:#94a3b8">Margin Blocked (§59)</div><div id="acctMargin" style="font-weight:800">-</div></div>
          <div style="background:#020617;padding:8px;border-radius:8px;text-align:center"><div style="color:#94a3b8">Available Capital (§59)</div><div id="acctAvailable" style="font-weight:800">-</div></div>
        </div>
        <div id="positionGreeksExposure" style="margin-top:8px"></div>
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
          <label style="font-size:11px">Starting Capital <input id="startingCapitalInput" type="number" class="input" style="width:100px" value="100000"></label>
          <button id="saveCapitalBtn" class="btn" style="font-size:11px">Save</button>
          <button id="resetAccountBtn" class="btn" style="font-size:11px;background:#ef4444">Reset Account</button>
        </div>
        <div style="font-size:10px;color:#64748b;margin-top:6px">Reset only changes which trades count toward the current balance/drawdown - nothing is deleted from your permanent trade history (Master Prompt §59, §28).</div>
      </div>

      <div class="card">
        <h3>📊 Portfolio Tracker (Master Prompt §10 - real multi-position Greeks + correlation risk)</h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Manually track positions here for real net Greeks exposure and correlation-risk warnings across multiple simultaneous positions - genuinely separate from Auto Trades above (which remains single-position by design). Add any real position you're holding, including one already tracked by Auto Trades, to see genuine portfolio-level risk.</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
          <select id="mpSymbol" class="input" style="width:100px"><option>NIFTY</option><option>BANKNIFTY</option><option>FINNIFTY</option></select>
          <input id="mpStrike" type="number" class="input" placeholder="Strike" style="width:90px">
          <select id="mpOptionType" class="input" style="width:70px"><option>CE</option><option>PE</option></select>
          <input id="mpQty" type="number" class="input" placeholder="Qty" style="width:80px">
          <input id="mpEntryPrice" type="number" class="input" placeholder="Entry Price" style="width:100px">
          <button id="mpAddBtn" class="btn" style="font-size:11px">+ Add Position</button>
        </div>
        <div id="portfolioPositionsBox" style="font-size:11px;margin-bottom:8px"></div>
        <div id="portfolioGreeksBox" style="font-size:11px;margin-bottom:8px"></div>
        <div id="hedgedElsewhereBox" style="font-size:11px;margin-bottom:8px"></div>
        <div id="multiLegPayoffDiagramBox" style="font-size:11px;margin-bottom:8px;border-top:1px solid #1e293b;padding-top:8px"></div>
        <div id="portfolioCorrelationBox" style="font-size:11px"></div>
      </div>

      <div class="card">
        <h3>🎯 Auto Trades - <span id="tradesModeLabel">PAPER</span></h3>
        <div style="margin-bottom:8px">
          <div style="font-size:11px;color:#94a3b8;margin-bottom:4px">Target / SL bracket (tap to select):</div>
          <div id="mainScalpingBracketButtons" style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:6px">
            <button type="button" class="btn bracket-preset-btn" data-preset="micro10" style="padding:4px 8px;font-size:10px">10%</button>
            <button type="button" class="btn bracket-preset-btn" data-preset="fast15" style="padding:4px 8px;font-size:10px">15%</button>
            <button type="button" class="btn bracket-preset-btn" data-preset="standard" style="padding:4px 8px;font-size:10px">20%</button>
            <button type="button" class="btn bracket-preset-btn" data-preset="balanced" style="padding:4px 8px;font-size:10px">25%</button>
            <button type="button" class="btn bracket-preset-btn" data-preset="manual" style="padding:4px 8px;font-size:10px">Manual</button>
          </div>
          <select id="scalpingBracketPreset" class="input" style="width:100%;max-width:260px;font-size:11px">
            <option value="micro10">Auto 10% — +10% / −5% SL</option>
            <option value="fast15">Auto 15% — +15% / −7.5% SL</option>
            <option value="standard">Auto 20% (default) — +20% / −10% SL</option>
            <option value="balanced">Auto 25% — +25% / −12.5% SL</option>
            <option value="manual">Manual 30% — save your own</option>
          </select>
        </div>
        <div id="scalpingBracketHint" style="font-size:10px;color:#64748b;margin-bottom:6px">Auto 20%: +20% target / −10% SL — auto-adjusts from live premium each refresh</div>
        <div style="display:flex;gap:8px;margin-bottom:6px;align-items:center;flex-wrap:wrap">
          <button id="saveManualBracketBtn" class="btn" style="padding:4px 10px;font-size:11px;background:#334155;display:none" title="Save your custom target/SL % for every future entry">💾 Save manual setup</button>
          <span id="manualBracketSavedNote" style="font-size:10px;color:#4ade80;display:none"></span>
        </div>
        <div style="display:flex;gap:6px;margin-bottom:4px;font-size:10px;color:#94a3b8"><span>Target</span><span>Stop-loss</span></div>
        <div style="display:flex;gap:6px;margin-bottom:8px"><input id="target" class="input" type="number" value="200" style="width:70px" title="Take-profit price"><input id="sl" class="input" type="number" value="100" style="width:70px" title="Stop-loss price"><label style="display:flex;gap:4px;align-items:center"><input type="checkbox" id="autoMode"> Auto</label><button id="forceExit" class="btn" style="background:#ef4444" aria-label="Force exit the currently open Auto Trade position immediately, at the current live price">Exit</button></div>
        <div style="display:flex;gap:12px;margin-bottom:8px;font-size:11px">
          <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" id="trailingEnabled" checked> Trailing Stop (§27)</label>
          <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" id="partialExitEnabled" checked> Partial Exit at Target (§27)</label>
        </div>
        <div style="display:flex;gap:8px;margin-bottom:8px;font-size:11px;align-items:center">
          <label>Execution Mode (Enterprise Plan #30):
            <select id="executionMode" class="input" style="width:150px">
              <option value="realistic">Mode 2 - Realistic (bid/ask+costs)</option>
              <option value="theoretical">Mode 1 - Theoretical (last price)</option>
            </select>
          </label>
        </div>
        <button id="placeOrderBtn" class="btn" style="width:100%;margin-bottom:8px;background:#f59e0b;color:#000" title="This is a real, structurally-simulated DEMO order (fno_kite_order_fn's own hardcoded $is_demo=true safeguard) - it can never reach Zerodha's real order API. Renamed from &quot;Place Order (via Kite)&quot; after a real, direct UI/UX audit found the previous label could be misread as placing a genuine order.">🧪 Simulate Order (Paper - via Kite pricing)</button>
        <div id="orderResult" style="font-size:11px;color:#94a3b8;margin-bottom:6px"></div>
        <div id="openTrades" style="min-height:60px;background:#020617;border-radius:10px;padding:8px;border:1px solid #1e293b;font-size:11px"></div>
        <div id="tradeHistory" style="max-height:200px;overflow:auto;margin-top:8px;font-size:11px"></div>
      </div>

      <div class="card card-accent-risk">
        <h3>💰 Real Money Trades (genuinely separate from paper trading above)</h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Reads from a dedicated, isolated real-money journal - never the paper-trading history above. Empty until a real, armed account actually places a real trade.</div>
        <div id="realMoneyJournalBox" style="max-height:200px;overflow:auto;font-size:11px">Loading...</div>
      </div>

      <div class="card card-accent-info">
        <h3>🕵️ Operator Intel - Smart Money Bias</h3>
        <div id="operatorIntel" style="font-size:11px">Loading...</div>
      </div>

      <div class="card">
        <h3>📊 Learning</h3>
        <div id="learningStats" style="font-size:12px"></div>
      </div>

      <div class="card">
        <h3>🔬 Post-Trade Analysis (Master Prompt §30-31, §38, §45-47)</h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">On-demand only (not fetched every refresh - snapshots can be tens of KB per trade). Requires closed trades with a factor_snapshot to say anything real.</div>
        <button id="loadAnalysisBtn" class="btn" style="width:100%;margin-bottom:8px">Run Analysis on My Trade History</button>
        <div style="display:flex;gap:6px;margin-bottom:8px;font-size:11px">
          <select id="reviewPeriod" class="input" style="flex:1">
            <option value="daily">Daily Review</option>
            <option value="weekly">Weekly Review (§46)</option>
            <option value="monthly">Monthly Review (§47)</option>
          </select>
        </div>
        <div id="dailyReviewBox" style="font-size:12px;margin-bottom:10px"></div>
        <div id="calibrationBox" style="font-size:11px;margin-bottom:10px"></div>
        <div id="walkForwardBox" style="font-size:11px;margin-bottom:10px"></div>
        <div id="regimePerformanceBox" style="font-size:11px;margin-bottom:10px"></div>
        <div id="entryExitQualityBox" style="font-size:11px;margin-bottom:10px"></div>
        <div id="failureAnalysisBox" style="font-size:11px;margin-bottom:10px"></div>
        <div id="factorPerformanceBox" style="font-size:11px;max-height:300px;overflow:auto"></div>
        <div id="factorCorrelationBox" style="font-size:11px;max-height:250px;overflow:auto;margin-top:10px"></div>
        <div id="factorCombinationBox" style="font-size:11px;max-height:250px;overflow:auto;margin-top:10px"></div>
        <div id="regimeDependentFactorsBox" style="font-size:11px;max-height:250px;overflow:auto;margin-top:10px"></div>
        <div id="timeframeDriftFactorsBox" style="font-size:11px;max-height:250px;overflow:auto;margin-top:10px"></div>
      </div>

      <div class="card">
        <h3>🚫 Trade Rejection Learning (Master Prompt §41)</h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Every WAIT/NO_TRADE decision is logged (throttled to once per 10 min per symbol). Click below to check pending rejections against the current real spot price - did the system correctly avoid a loss, or leave a likely-profitable move on the table? This is a directional spot-move proxy, not a precise option-premium replay (see PROJECT_STATUS.md for the honesty caveat).</div>
        <button id="evaluateRejectionsBtn" class="btn" style="width:100%;margin-bottom:8px">Evaluate Pending Rejections</button>
        <div id="rejectionStatsBox" style="font-size:11px"></div>
      </div>

      <div class="card">
        <h3>🔎 Decision Replay (Master Prompt §51)</h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Select any historical trade and see exactly what the system knew at the moment it opened - every factor's status, value, and reasoning.</div>
        <div id="replayTradeList" style="font-size:11px;max-height:200px;overflow:auto;margin-bottom:8px"></div>
        <div id="replayDetail" style="font-size:11px;max-height:400px;overflow:auto;background:#020617;border-radius:8px;padding:8px;border:1px solid #1e293b"></div>
      </div>

      <div class="card">
        <h3>🏷️ Strategy Version Log (Master Prompt §34)</h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Current running version: <b id="currentStrategyVersion"></b>. Every deliberate change to thresholds/weights gets logged here - never overwritten. Admin-only to log a new version (this changes the shared strategy every user sees, so it should be a deliberate, evidence-reviewed action per §36-39, not a casual click).</div>
        <button id="showAddVersionBtn" class="btn" style="width:100%;margin-bottom:8px">+ Log New Version</button>
        <div id="addVersionForm" style="display:none;margin-bottom:10px;font-size:11px">
          <input id="verVersion" class="input" placeholder="Version string (e.g. v1.1-tightened-thresholds)" style="width:100%;margin-bottom:4px">
          <input id="verChangedFields" class="input" placeholder="Changed fields, comma-separated (e.g. BUY_THRESHOLD, SELL_THRESHOLD)" style="width:100%;margin-bottom:4px">
          <textarea id="verReason" class="input" placeholder="Reason for this change" style="width:100%;margin-bottom:4px;min-height:30px"></textarea>
          <textarea id="verEvidence" class="input" placeholder="Evidence (e.g. walk-forward result, factor performance data)" style="width:100%;margin-bottom:6px;min-height:30px"></textarea>
          <button id="saveVersionBtn" class="btn" style="width:100%">Save Version</button>
        </div>
        <div id="strategyVersionLog" style="font-size:11px;max-height:200px;overflow:auto"></div>
      </div>

      <div class="card">
        <h3>📚 Strategy Knowledge Base (Master Prompt §48)</h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Permanent, accumulating research observations - distinct from the Version Log above, which only records changes already made. This records the research trail that may or may not eventually justify one.</div>
        <div id="suggestedObservationsBox" style="font-size:11px;margin-bottom:8px"></div>
        <button id="showAddKnowledgeBtn" class="btn" style="width:100%;margin-bottom:8px">+ Add Observation</button>
        <div id="addKnowledgeForm" style="display:none;margin-bottom:10px;font-size:11px">
          <input id="kbFactor" class="input" placeholder="Factor / topic (e.g. PCR Extremity)" style="width:100%;margin-bottom:4px">
          <textarea id="kbObservation" class="input" placeholder="Observation" style="width:100%;margin-bottom:4px;min-height:40px"></textarea>
          <textarea id="kbEvidence" class="input" placeholder="Evidence (e.g. 214 trades)" style="width:100%;margin-bottom:4px;min-height:30px"></textarea>
          <textarea id="kbConclusion" class="input" placeholder="Current conclusion" style="width:100%;margin-bottom:4px;min-height:30px"></textarea>
          <textarea id="kbCandidate" class="input" placeholder="Candidate change (optional)" style="width:100%;margin-bottom:4px;min-height:30px"></textarea>
          <select id="kbStatus" class="input" style="width:100%;margin-bottom:6px">
            <option>Observing</option><option>Testing</option><option>Confirmed</option><option>Rejected</option>
          </select>
          <button id="saveKnowledgeBtn" class="btn" style="width:100%">Save Observation</button>
        </div>
        <div id="knowledgeBaseLog" style="font-size:11px;max-height:250px;overflow:auto"></div>
      </div>

      <div class="card">
        <h3>🧮 Trained Probability Model (Master Prompt §25-26)</h3>
        <div style="font-size:10px;color:#64748b;margin-bottom:8px">Real logistic regression, trained on your own closed trades, honestly gated OFF until it beats a naive baseline on data it never trained on. Needs 50+ closed trades with a factor snapshot to even attempt training.</div>
        <button id="trainModelBtn" class="btn" style="width:100%;margin-bottom:8px">Train Model on My Trade History</button>
        <div id="probModelStatus" style="font-size:11px"></div>
      </div>

      <div class="card" style="font-size:11px;color:#64748b">
        <b>Standalone App Mode</b><br>
        No theme loaded. No shortcode. Homepage IS the app.<br>
        Aliases still work: /lab/ /fno/ /app/ ?fno_app=1<br>
        Works like software on web. WP only for auth + NSE proxy + Kite API.<br>
        <b>Note:</b> only the factors shown with PASS/FAIL/badges below (not "NEUT") are
        genuinely computed and feed the score. The rest of the catalogue is reference
        only for now, not yet individually scored.<br>
        Educational only - Past != Future. 9/10 lose money (SEBI).
      </div>
    </div>
  </div>
</div>

<script>
window.FNO_AJAX = {
  url: "<?php echo esc_js($ajax_url); ?>",
  nonce: "<?php echo wp_create_nonce('fno_standalone_nonce'); ?>",
  isLoggedIn: <?php echo $is_logged_in ? 'true' : 'false'; ?>
};
window.FNO_PLUGIN_VERSION = <?php echo wp_json_encode(FNO_PLUGIN_VERSION); ?>;
// Master Development Prompt Section 5 (Factor Registry): the raw 193-
// entry catalog, embedded server-side so the Factor Registry can
// classify every catalogued factor's real implementation status
// (COMPUTED/NOT_APPLICABLE/UNAVAILABLE/NOT_COMPUTED) client-side every
// refresh, without a separate fetch.
window.FNO_FACTORS_CATALOG = <?php echo $json ? wp_json_encode($json) : '[]'; ?>;
</script>

<script>
<?php include __DIR__ . '/greeks-engine.js'; ?>
</script>
<script type="module">
<?php include __DIR__ . '/fno-lab-core.js'; ?>
<?php include __DIR__ . '/strategy-diagnostic-report.js'; ?>
</script>
<?php endif; ?>
</body>
</html>
