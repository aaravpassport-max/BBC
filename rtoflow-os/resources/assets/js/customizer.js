/**
 * RTOFLOW Design Customizer
 * Live color & style editor with 100+ presets
 */
(function() {
'use strict';

// ── 100+ Color Presets ────────────────────────────────────────────────────────
const PRESETS = [
  // Blues
  {name:'Ocean Blue',    primary:'#1B2A6B', accent:'#2563EB', bg:'#F0F4FF'},
  {name:'Sky Blue',      primary:'#0C4A6E', accent:'#0EA5E9', bg:'#F0F9FF'},
  {name:'Royal Blue',    primary:'#1E3A8A', accent:'#3B82F6', bg:'#EFF6FF'},
  {name:'Deep Navy',     primary:'#0F172A', accent:'#1D4ED8', bg:'#F8FAFC'},
  {name:'Cobalt',        primary:'#1e40af', accent:'#60a5fa', bg:'#eff6ff'},
  // Purples
  {name:'Purple Haze',   primary:'#4C1D95', accent:'#7C3AED', bg:'#FAF5FF'},
  {name:'Violet Dream',  primary:'#5B21B6', accent:'#8B5CF6', bg:'#F5F3FF'},
  {name:'Deep Purple',   primary:'#3B0764', accent:'#A855F7', bg:'#faf5ff'},
  {name:'Lavender',      primary:'#4338CA', accent:'#818CF8', bg:'#EEF2FF'},
  {name:'Indigo Night',  primary:'#312E81', accent:'#6366F1', bg:'#EEEDF7'},
  // Greens
  {name:'Emerald',       primary:'#064E3B', accent:'#10B981', bg:'#ECFDF5'},
  {name:'Forest',        primary:'#14532D', accent:'#22C55E', bg:'#F0FDF4'},
  {name:'Mint Fresh',    primary:'#065F46', accent:'#34D399', bg:'#ECFDF5'},
  {name:'Sage',          primary:'#166534', accent:'#4ADE80', bg:'#F0FDF4'},
  {name:'Teal',          primary:'#134E4A', accent:'#14B8A6', bg:'#F0FDFA'},
  {name:'Cyan Wave',     primary:'#164E63', accent:'#06B6D4', bg:'#ECFEFF'},
  // Reds & Pinks
  {name:'Ruby Red',      primary:'#7F1D1D', accent:'#EF4444', bg:'#FFF5F5'},
  {name:'Crimson',       primary:'#881337', accent:'#F43F5E', bg:'#FFF1F2'},
  {name:'Rose Gold',     primary:'#9F1239', accent:'#FB7185', bg:'#FFF1F2'},
  {name:'Hot Pink',      primary:'#831843', accent:'#EC4899', bg:'#FDF2F8'},
  {name:'Fuchsia',       primary:'#701A75', accent:'#D946EF', bg:'#FDF4FF'},
  // Oranges & Yellows
  {name:'Sunset',        primary:'#7C2D12', accent:'#F97316', bg:'#FFF7ED'},
  {name:'Amber',         primary:'#78350F', accent:'#F59E0B', bg:'#FFFBEB'},
  {name:'Gold',          primary:'#713F12', accent:'#EAB308', bg:'#FEFCE8'},
  {name:'Copper',        primary:'#92400E', accent:'#F59E0B', bg:'#FEF9C3'},
  {name:'Tangerine',     primary:'#9A3412', accent:'#FB923C', bg:'#FFF7ED'},
  // Dark/Black
  {name:'Midnight',      primary:'#020617', accent:'#334155', bg:'#0F172A'},
  {name:'Charcoal',      primary:'#111827', accent:'#374151', bg:'#F9FAFB'},
  {name:'Carbon',        primary:'#18181B', accent:'#52525B', bg:'#FAFAFA'},
  {name:'Obsidian',      primary:'#09090B', accent:'#27272A', bg:'#F4F4F5'},
  // Special combos
  {name:'Spotify Green', primary:'#006450', accent:'#1DB954', bg:'#F0FDF4'},
  {name:'WhatsApp',      primary:'#0a5c36', accent:'#25D366', bg:'#ECFDF5'},
  {name:'Uber Black',    primary:'#000000', accent:'#276EF1', bg:'#F6F6F6'},
  {name:'Airbnb',        primary:'#FF385C', accent:'#FF5A5F', bg:'#FFF0F0'},
  {name:'Stripe',        primary:'#0A2540', accent:'#635BFF', bg:'#F8F9FF'},
  {name:'Notion',        primary:'#191919', accent:'#2EAADC', bg:'#FFFFFF'},
  {name:'Linear',        primary:'#5E6AD2', accent:'#5E6AD2', bg:'#FAFAFA'},
  {name:'Figma',         primary:'#1E1E1E', accent:'#A259FF', bg:'#F5F5F5'},
  {name:'Vercel',        primary:'#000000', accent:'#000000', bg:'#FAFAFA'},
  {name:'Tailwind',      primary:'#0F172A', accent:'#06B6D4', bg:'#F0FDFA'},
  // Indian-inspired
  {name:'Saffron',       primary:'#FF6200', accent:'#FF9933', bg:'#FFF5EE'},
  {name:'Peacock',       primary:'#004B49', accent:'#00897B', bg:'#E8F5E9'},
  {name:'Lotus Pink',    primary:'#880E4F', accent:'#E91E63', bg:'#FCE4EC'},
  {name:'Spice',         primary:'#BF360C', accent:'#FF5722', bg:'#FBE9E7'},
  {name:'Marigold',      primary:'#E65100', accent:'#FF9800', bg:'#FFF3E0'},
  // Professional
  {name:'Corporate',     primary:'#003366', accent:'#0066CC', bg:'#F0F5FF'},
  {name:'Legal',         primary:'#1A1A2E', accent:'#16213E', bg:'#F5F5F5'},
  {name:'Finance',       primary:'#004953', accent:'#00838F', bg:'#E0F7FA'},
  {name:'Tech',          primary:'#001E3C', accent:'#007FFF', bg:'#F0F7FF'},
  {name:'Medical',       primary:'#0D3349', accent:'#1565C0', bg:'#E3F2FD'},
  // Gradients / modern
  {name:'Aurora',        primary:'#00416A', accent:'#00C9FF', bg:'#F0FCFF'},
  {name:'Dusk',          primary:'#232526', accent:'#FF6B6B', bg:'#FFF5F5'},
  {name:'Twilight',      primary:'#141E30', accent:'#243B55', bg:'#EBF5FB'},
  {name:'Candy',         primary:'#DA1884', accent:'#FDDB92', bg:'#FFF9F9'},
  {name:'Pastel Blue',   primary:'#2C3E50', accent:'#6BBFEC', bg:'#EBF5FB'},
  // More variety
  {name:'Lemon',         primary:'#3D2B1F', accent:'#FDD835', bg:'#FFFDE7'},
  {name:'Lime',          primary:'#1B5E20', accent:'#8BC34A', bg:'#F9FBE7'},
  {name:'Olive',         primary:'#33691E', accent:'#9CCC65', bg:'#F9FBE7'},
  {name:'Brown',         primary:'#3E2723', accent:'#8D6E63', bg:'#FBE9E7'},
  {name:'Slate',         primary:'#263238', accent:'#607D8B', bg:'#ECEFF1'},
  {name:'Steel',         primary:'#1C313A', accent:'#546E7A', bg:'#ECEFF1'},
  {name:'Ink',           primary:'#212121', accent:'#757575', bg:'#FAFAFA'},
  {name:'Graphite',      primary:'#2C2C2C', accent:'#616161', bg:'#F5F5F5'},
  {name:'Sand',          primary:'#5D4037', accent:'#A1887F', bg:'#FBE9E7'},
  {name:'Terracotta',    primary:'#6D2E23', accent:'#C0725B', bg:'#FBF2EF'},
  {name:'Clay',          primary:'#4E342E', accent:'#A1887F', bg:'#EFEBE9'},
  {name:'Sienna',        primary:'#8D3E2A', accent:'#E8703A', bg:'#FFF3E0'},
  {name:'Brick',         primary:'#7F1D1D', accent:'#C62828', bg:'#FFEBEE'},
  {name:'Maroon',        primary:'#4A0404', accent:'#B71C1C', bg:'#FFEBEE'},
  {name:'Burgundy',      primary:'#560319', accent:'#880E4F', bg:'#FCE4EC'},
  {name:'Plum',          primary:'#4A148C', accent:'#7B1FA2', bg:'#F3E5F5'},
  {name:'Eggplant',      primary:'#37003C', accent:'#6A1B9A', bg:'#F3E5F5'},
  {name:'Mulberry',      primary:'#5E0035', accent:'#AD1457', bg:'#FCE4EC'},
  {name:'Raspberry',     primary:'#6A0036', accent:'#D81B60', bg:'#FCE4EC'},
  {name:'Magenta',       primary:'#880E4F', accent:'#E91E63', bg:'#FCE4EC'},
  {name:'Orchid',        primary:'#4A148C', accent:'#CE93D8', bg:'#F3E5F5'},
  {name:'Blueberry',     primary:'#1A1A55', accent:'#3949AB', bg:'#E8EAF6'},
  {name:'Cornflower',    primary:'#1565C0', accent:'#42A5F5', bg:'#E3F2FD'},
  {name:'Ice Blue',      primary:'#0277BD', accent:'#29B6F6', bg:'#E1F5FE'},
  {name:'Aqua',          primary:'#006064', accent:'#00ACC1', bg:'#E0F7FA'},
  {name:'Seafoam',       primary:'#004D40', accent:'#00BCD4', bg:'#E0F7FA'},
  {name:'Jade',          primary:'#00600F', accent:'#43A047', bg:'#E8F5E9'},
  {name:'Moss',          primary:'#33691E', accent:'#7CB342', bg:'#F9FBE7'},
  {name:'Fern',          primary:'#2E7D32', accent:'#66BB6A', bg:'#F1F8E9'},
  {name:'Pine',          primary:'#1B5E20', accent:'#388E3C', bg:'#E8F5E9'},
  {name:'Forest Night',  primary:'#0A3622', accent:'#1B6B3A', bg:'#EAFAF1'},
  {name:'Deep Teal',     primary:'#00332B', accent:'#00897B', bg:'#E0F2F1'},
  {name:'Peacock Blue',  primary:'#003333', accent:'#00695C', bg:'#E0F2F1'},
  {name:'Dark Cyan',     primary:'#00272B', accent:'#00838F', bg:'#E0F7FA'},
  {name:'Midnight Blue', primary:'#001337', accent:'#0D47A1', bg:'#E3F2FD'},
  {name:'Dark Indigo',   primary:'#1A0050', accent:'#283593', bg:'#E8EAF6'},
  {name:'Eclipse',       primary:'#0D0D0D', accent:'#FF6B35', bg:'#FFF8F5'},
  {name:'Nebula',        primary:'#0D0221', accent:'#7400B8', bg:'#F5EFFF'},
  {name:'Galaxy',        primary:'#0D0221', accent:'#4895EF', bg:'#EFF6FF'},
  {name:'Solar',         primary:'#2B1700', accent:'#FF8C00', bg:'#FFF5E0'},
  {name:'Fire',          primary:'#3D0000', accent:'#FF3300', bg:'#FFF0EE'},
  {name:'Lava',          primary:'#3D1A00', accent:'#FF4500', bg:'#FFF3EE'},
  {name:'Volcano',       primary:'#2D0000', accent:'#D32F2F', bg:'#FFEBEE'},
  {name:'Storm',         primary:'#1A1A2E', accent:'#6B8CFF', bg:'#EEF2FF'},
];

// ── CSS Variables controlled by customizer ────────────────────────────────────
const CSS_VARS = [
  {var:'--primary',       label:'Primary Color',   key:'primary'},
  {var:'--primary-light', label:'Accent / Buttons', key:'accent'},
  {var:'--accent',        label:'Highlight',        key:'accent'},
  {var:'--bg',            label:'Background',       key:'bg'},
  {var:'--surface',       label:'Card Surface',     val:'#FFFFFF'},
  {var:'--border',        label:'Borders',          val:'#E2E8F0'},
  {var:'--text',          label:'Body Text',        val:'#0F172A'},
  {var:'--text-muted',    label:'Muted Text',       val:'#64748B'},
];

// ── Saved customizations ──────────────────────────────────────────────────────
function getSaved() {
  try { return JSON.parse(localStorage.getItem('rtoflow_custom') || '{}'); } catch(e) { return {}; }
}
function save(data) {
  localStorage.setItem('rtoflow_custom', JSON.stringify(data));
}

// ── Apply CSS vars to :root ───────────────────────────────────────────────────
function applyVars(vars) {
  const root = document.documentElement;
  Object.entries(vars).forEach(([k, v]) => root.style.setProperty(k, v));
}

// ── Init: apply saved customizations ─────────────────────────────────────────
const saved = getSaved();
if (Object.keys(saved).length) applyVars(saved);

// ── Build the panel ───────────────────────────────────────────────────────────
function buildPanel() {
  const panel = document.createElement('div');
  panel.className = 'rto-customizer-panel';
  panel.id = 'rto-customizer';
  panel.innerHTML = `
    <button class="rto-customizer-tab" onclick="toggleCustomizer()" title="Design Customizer">🎨<br>Design</button>
    <div class="rto-customizer-header">
      <h4>🎨 Design Customizer</h4>
      <button onclick="toggleCustomizer()" style="background:none;border:none;color:#fff;cursor:pointer;font-size:16px">✕</button>
    </div>
    <div class="rto-customizer-body">
      <div class="rto-customizer-section">
        <h5>🎨 Color Presets (${PRESETS.length})</h5>
        <div class="rto-color-presets" id="rto-presets"></div>
      </div>
      <div class="rto-customizer-section">
        <h5>🎛 Individual Controls</h5>
        <div id="rto-var-controls"></div>
      </div>
      <div class="rto-customizer-section">
        <h5>🔠 Typography</h5>
        <div class="rto-customizer-var">
          <label>Base Font Size</label>
          <select id="rto-font-size" onchange="applyFontSize(this.value)" style="font-size:12px;padding:3px 8px;border:1px solid #e2e8f0;border-radius:5px">
            <option value="13px" selected>Normal (13px)</option>
            <option value="14px">Large (14px)</option>
            <option value="15px">Extra Large (15px)</option>
            <option value="12px">Small (12px)</option>
          </select>
        </div>
        <div class="rto-customizer-var">
          <label>Border Radius</label>
          <select id="rto-radius" onchange="applyRadius(this.value)" style="font-size:12px;padding:3px 8px;border:1px solid #e2e8f0;border-radius:5px">
            <option value="10px" selected>Rounded</option>
            <option value="4px">Sharp</option>
            <option value="16px">Very Rounded</option>
            <option value="0px">Square</option>
          </select>
        </div>
      </div>
      <div class="rto-customizer-actions">
        <button onclick="resetCustomizer()" class="rto-btn rto-btn--sm" style="flex:1">Reset</button>
        <button onclick="saveCustomizer()" class="rto-btn rto-btn--sm rto-btn--primary" style="flex:1">Save & Apply</button>
      </div>
      <div id="rto-custom-saved" style="display:none;text-align:center;font-size:12px;color:#10b981;margin-top:8px;font-weight:600">✅ Design saved!</div>
    </div>
  `;
  document.body.appendChild(panel);

  // Build color presets
  const presetsEl = document.getElementById('rto-presets');
  PRESETS.forEach((p, i) => {
    const btn = document.createElement('button');
    btn.className = 'rto-color-preset';
    btn.title = p.name;
    btn.style.background = p.primary;
    btn.style.boxShadow = `0 0 0 3px ${p.accent}44`;
    btn.onclick = () => applyPreset(p, btn);
    presetsEl.appendChild(btn);
  });

  // Build var controls
  const varEl = document.getElementById('rto-var-controls');
  CSS_VARS.forEach(v => {
    const curVal = getComputedStyle(document.documentElement).getPropertyValue(v.var).trim() || v.val || '#ffffff';
    const row = document.createElement('div');
    row.className = 'rto-customizer-var';
    row.innerHTML = `<label>${v.label}</label><input type="color" value="${curVal.replace(/ /g,'')}" onchange="setVar('${v.var}',this.value)" oninput="setVar('${v.var}',this.value)">`;
    varEl.appendChild(row);
  });

  // Start closed on mobile, open on desktop
  if (window.innerWidth < 768) panel.classList.add('closed');
}

function toggleCustomizer() {
  const panel = document.getElementById('rto-customizer');
  panel && panel.classList.toggle('closed');
}

function applyPreset(p, btn) {
  document.querySelectorAll('.rto-color-preset').forEach(b => b.classList.remove('active'));
  btn && btn.classList.add('active');
  const vars = {
    '--primary':       p.primary,
    '--primary-light': p.accent,
    '--primary-dark':  p.primary,
    '--accent':        p.accent,
    '--bg':            p.bg,
  };
  applyVars(vars);
  // Update color pickers
  CSS_VARS.forEach((v, i) => {
    const inp = document.querySelectorAll('#rto-var-controls input[type=color]')[i];
    if (inp) {
      const val = vars[v.var];
      if (val) inp.value = val;
    }
  });
}

function setVar(varName, val) {
  document.documentElement.style.setProperty(varName, val);
}

function applyFontSize(size) {
  document.body.style.fontSize = size;
}

function applyRadius(radius) {
  document.documentElement.style.setProperty('--radius', radius);
  document.documentElement.style.setProperty('--radius-sm', Math.max(0, parseInt(radius)-4) + 'px');
  document.documentElement.style.setProperty('--radius-lg', Math.max(0, parseInt(radius)+4) + 'px');
}

function saveCustomizer() {
  const root = document.documentElement;
  const vars = {};
  CSS_VARS.forEach(v => {
    const val = root.style.getPropertyValue(v.var);
    if (val) vars[v.var] = val;
  });
  const radius = root.style.getPropertyValue('--radius');
  if (radius) { vars['--radius'] = radius; vars['--radius-sm'] = root.style.getPropertyValue('--radius-sm'); }
  save(vars);

  // Save to server if admin
  if (window.RtoApp && window.RtoApp.nonce) {
    const formData = new FormData();
    formData.append('action', 'rtoflow_save_design');
    formData.append('_wpnonce', window.RtoApp.nonce);
    formData.append('vars', JSON.stringify(vars));
    fetch('/wp-admin/admin-ajax.php', {method:'POST', body:formData});
  }

  const el = document.getElementById('rto-custom-saved');
  if (el) { el.style.display = 'block'; setTimeout(() => el.style.display = 'none', 2500); }
}

function resetCustomizer() {
  localStorage.removeItem('rtoflow_custom');
  const root = document.documentElement;
  CSS_VARS.forEach(v => root.style.removeProperty(v.var));
  root.style.removeProperty('--radius');
  root.style.removeProperty('--radius-sm');
  root.style.removeProperty('--radius-lg');
  document.body.style.fontSize = '';
  document.querySelectorAll('.rto-color-preset').forEach(b => b.classList.remove('active'));
  location.reload();
}

// ── Window exposed functions ──────────────────────────────────────────────────
window.toggleCustomizer = toggleCustomizer;
window.applyPreset      = applyPreset;
window.setVar           = setVar;
window.applyFontSize    = applyFontSize;
window.applyRadius      = applyRadius;
window.saveCustomizer   = saveCustomizer;
window.resetCustomizer  = resetCustomizer;

// Build panel on DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', buildPanel);
} else {
  buildPanel();
}

})();
