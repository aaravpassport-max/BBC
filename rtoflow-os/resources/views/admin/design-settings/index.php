<?php if (!defined('ABSPATH')) exit;
/** @var array $settings @var array $colors */
$pageTitle = 'Design & Typography Settings';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';

$elements = [
    'h1' => 'H1', 'h2' => 'H2 (section headings — .hp-tt)', 'h3' => 'H3',
    'heading' => 'Headings (H4–H6)', 'body' => 'Body Text', 'label' => 'Labels (.hp-ey)',
    'button' => 'Button Text', 'nav' => 'Navigation',
];
$colorLabels = [
    'primary' => 'Primary', 'secondary' => 'Secondary', 'accent' => 'Accent',
    'heading' => 'Heading Text', 'body' => 'Body Text', 'link' => 'Link',
    'button_bg' => 'Button Background', 'button_text' => 'Button Text',
    'background' => 'Page Background', 'border' => 'Border', 'muted' => 'Muted Text',
    'bg_dark' => 'Header/Footer Dark Background',
];
$fontLabels = [
    'system' => 'System Default', 'georgia' => 'Georgia (serif)', 'arial' => 'Arial',
    'verdana' => 'Verdana', 'trebuchet' => 'Trebuchet MS', 'courier' => 'Courier New (monospace)',
];
$transformLabels = ['none' => 'None', 'uppercase' => 'UPPERCASE', 'lowercase' => 'lowercase', 'capitalize' => 'Capitalize'];
$weightValues = [300, 400, 500, 600, 700, 800, 900];
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Design & Typography Settings</h1>
  </div>
  <p style="color:var(--gray-600,#64748b);font-size:13.5px;max-width:820px;margin:-8px 0 20px">
    Controls the visual style of the Home page (more pages coming in a later phase). Changes are previewed live below
    before you save — nothing goes to the live site until you click Save on that tab. Every field can be reset
    individually with the ↺ button beside it, or a whole tab/everything at once with the buttons at the bottom.
  </p>

  <div id="dsGlobalMsg" class="rto-msg" style="display:none" aria-live="polite" role="status"></div>

  <!-- ===================== LIVE PREVIEW (sticky) ===================== -->
  <div class="rto-card" style="position:sticky;top:8px;z-index:5">
    <div class="rto-card-header"><h3>Live Preview</h3></div>
    <div class="rto-card-body" id="dsPreview" style="background:#fff;border-radius:8px;padding:24px">
      <div id="dsPrevLabel">Eyebrow Label</div>
      <h2 id="dsPrevH2" style="margin:6px 0 8px">Section Heading (H2)</h2>
      <h3 id="dsPrevH3" style="margin:0 0 8px">Sub Heading (H3)</h3>
      <p id="dsPrevBody" style="margin:0 0 16px">This is body text, exactly as it will render across the site — the quick brown fox jumps over the lazy dog.</p>
      <a href="#" id="dsPrevBtn" style="display:inline-flex;align-items:center;text-decoration:none">Primary Button</a>
      <span id="dsPrevNav" style="margin-left:18px">Navigation Link</span>
      <div id="dsPrevCard" style="margin-top:18px;max-width:260px">Sample Card</div>
    </div>
  </div>

  <!-- ===================== TABS ===================== -->
  <div class="rto-tabs rto-mb-4" style="margin-top:16px" role="tablist">
    <button type="button" class="rto-tab active" data-tab="colors">Colors</button>
    <button type="button" class="rto-tab" data-tab="typography">Typography</button>
    <button type="button" class="rto-tab" data-tab="spacing">Spacing</button>
    <button type="button" class="rto-tab" data-tab="buttons">Buttons</button>
    <button type="button" class="rto-tab" data-tab="cards">Cards & Components</button>
    <button type="button" class="rto-tab" data-tab="sections">Sections</button>
    <button type="button" class="rto-tab" data-tab="effects">Effects</button>
    <button type="button" class="rto-tab" data-tab="header">Header & Navigation</button>
    <button type="button" class="rto-tab" data-tab="footer">Footer</button>
  </div>

  <!-- ===================== COLORS ===================== -->
  <div class="rto-card ds-tab-panel" data-panel="colors">
    <div class="rto-card-header"><h3>Colors</h3></div>
    <div class="rto-card-body">
      <p style="margin:0 0 14px;color:var(--gray-600,#64748b);font-size:12.5px;max-width:640px">
        Primary also applies to every other page's headings, navigation, and hover states (About, Contact,
        Pricing, How It Works, Terms, Privacy, Login, All Cities, City Page, Service) — not just the Home page.
        The rest of the colors below apply to the Home page only.
      </p>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px">
        <?php foreach ($colorLabels as $key => $label): ?>
        <div class="rto-form-group">
          <label class="rto-label"><?= esc_html($label) ?>
            <button type="button" class="ds-reset-field" data-section="colors" data-field="<?= esc_attr($key) ?>" title="Reset to default">↺</button>
          </label>
          <div style="display:flex;gap:6px;align-items:center">
            <input type="color" class="ds-color-input" id="ds_color_<?= esc_attr($key) ?>" data-key="<?= esc_attr($key) ?>" value="<?= esc_attr($colors[$key]) ?>" style="width:44px;height:36px;border:1px solid var(--gray-200,#e5e7eb);border-radius:6px;flex-shrink:0">
            <input type="text" class="rto-input ds-color-text" data-key="<?= esc_attr($key) ?>" value="<?= esc_attr($colors[$key]) ?>" maxlength="7" style="font-family:monospace">
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:18px;display:flex;gap:10px">
        <button type="button" class="rto-btn rto-btn-primary" id="dsSaveColors">Save Colors</button>
        <button type="button" class="rto-btn rto-btn-outline ds-reset-section" data-section="colors">Reset All Colors</button>
      </div>
    </div>
  </div>

  <!-- ===================== TYPOGRAPHY ===================== -->
  <div class="rto-card ds-tab-panel" data-panel="typography" style="display:none">
    <div class="rto-card-header"><h3>Typography</h3></div>
    <div class="rto-card-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:22px;max-width:700px">
        <div class="rto-form-group">
          <label class="rto-label">Body Font Family</label>
          <select class="rto-select" id="ds_body_font_family">
            <?php foreach ($fontLabels as $v => $l): ?>
            <option value="<?= esc_attr($v) ?>" <?= $settings['typography']['body_font_family'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Heading Font Family</label>
          <select class="rto-select" id="ds_heading_font_family">
            <?php foreach ($fontLabels as $v => $l): ?>
            <option value="<?= esc_attr($v) ?>" <?= $settings['typography']['heading_font_family'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <?php foreach ($elements as $elKey => $elLabel): $el = $settings['typography']['elements'][$elKey]; ?>
      <details class="rto-card" style="margin-bottom:12px" <?= $elKey === 'h2' ? 'open' : '' ?>>
        <summary style="cursor:pointer;padding:12px 16px;background:#F8FAFC;border-bottom:1px solid var(--gray-200,#e5e7eb);font-weight:700;font-size:13.5px;color:var(--navy,#1B2A6B)"><?= esc_html($elLabel) ?></summary>
        <div class="rto-card-body ds-el" data-el="<?= esc_attr($elKey) ?>">
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:12px">
            <div class="rto-form-group">
              <label class="rto-label">Size — Desktop (px)
                <button type="button" class="ds-reset-field" data-section="typography" data-element="<?= esc_attr($elKey) ?>" data-field="size_desktop" title="Reset">↺</button>
              </label>
              <input type="number" class="rto-input ds-el-input" data-field="size_desktop" min="8" max="160" step="0.5" value="<?= esc_attr($el['size_desktop']) ?>">
            </div>
            <div class="rto-form-group">
              <label class="rto-label">Size — Tablet (px)</label>
              <input type="number" class="rto-input ds-el-input" data-field="size_tablet" min="8" max="160" step="0.5" value="<?= esc_attr($el['size_tablet']) ?>">
            </div>
            <div class="rto-form-group">
              <label class="rto-label">Size — Mobile (px)</label>
              <input type="number" class="rto-input ds-el-input" data-field="size_mobile" min="8" max="160" step="0.5" value="<?= esc_attr($el['size_mobile']) ?>">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
            <div class="rto-form-group">
              <label class="rto-label">Weight
                <button type="button" class="ds-reset-field" data-section="typography" data-element="<?= esc_attr($elKey) ?>" data-field="weight" title="Reset">↺</button>
              </label>
              <select class="rto-select ds-el-input" data-field="weight">
                <?php foreach ($weightValues as $w): ?><option value="<?= $w ?>" <?= (int)$el['weight'] === $w ? 'selected' : '' ?>><?= $w ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="rto-form-group">
              <label class="rto-label">Line Height</label>
              <input type="number" class="rto-input ds-el-input" data-field="line_height" min="0.8" max="3" step="0.05" value="<?= esc_attr($el['line_height']) ?>">
            </div>
            <div class="rto-form-group">
              <label class="rto-label">Letter Spacing (em)</label>
              <input type="number" class="rto-input ds-el-input" data-field="letter_spacing" min="-0.1" max="0.5" step="0.005" value="<?= esc_attr($el['letter_spacing']) ?>">
            </div>
            <div class="rto-form-group">
              <label class="rto-label">Text Transform</label>
              <select class="rto-select ds-el-input" data-field="transform">
                <?php foreach ($transformLabels as $v => $l): ?><option value="<?= esc_attr($v) ?>" <?= $el['transform'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <?php if ($elKey !== 'button'): ?>
          <div class="rto-form-group" style="max-width:240px;margin-top:12px">
            <label class="rto-label">Color</label>
            <select class="rto-select ds-el-input" data-field="color_slot">
              <?php foreach ($colorLabels as $ck => $cl): ?><option value="<?= esc_attr($ck) ?>" <?= $el['color_slot'] === $ck ? 'selected' : '' ?>><?= esc_html($cl) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
        </div>
      </details>
      <?php endforeach; ?>

      <div style="margin-top:10px;display:flex;gap:10px">
        <button type="button" class="rto-btn rto-btn-primary" id="dsSaveTypography">Save Typography</button>
        <button type="button" class="rto-btn rto-btn-outline ds-reset-section" data-section="typography">Reset All Typography</button>
      </div>
    </div>
  </div>

  <!-- ===================== SPACING ===================== -->
  <div class="rto-card ds-tab-panel" data-panel="spacing" style="display:none">
    <div class="rto-card-header"><h3>Spacing</h3></div>
    <div class="rto-card-body">
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;max-width:900px">
        <div class="rto-form-group">
          <label class="rto-label">Section Padding — Desktop (px)
            <button type="button" class="ds-reset-field" data-section="spacing" data-field="section_padding_desktop" title="Reset">↺</button>
          </label>
          <input type="number" class="rto-input" id="ds_section_padding_desktop" min="0" max="300" value="<?= (int)$settings['spacing']['section_padding_desktop'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Section Padding — Tablet (px)</label>
          <input type="number" class="rto-input" id="ds_section_padding_tablet" min="0" max="300" value="<?= (int)$settings['spacing']['section_padding_tablet'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Section Padding — Mobile (px)</label>
          <input type="number" class="rto-input" id="ds_section_padding_mobile" min="0" max="300" value="<?= (int)$settings['spacing']['section_padding_mobile'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Extra Section Gap (px)
            <button type="button" class="ds-reset-field" data-section="spacing" data-field="section_gap" title="Reset">↺</button>
          </label>
          <input type="number" class="rto-input" id="ds_section_gap" min="0" max="200" value="<?= (int)$settings['spacing']['section_gap'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Card Spacing (px)
            <button type="button" class="ds-reset-field" data-section="spacing" data-field="card_spacing" title="Reset">↺</button>
          </label>
          <input type="number" class="rto-input" id="ds_card_spacing" min="0" max="100" value="<?= (int)$settings['spacing']['card_spacing'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Container Width (px)
            <button type="button" class="ds-reset-field" data-section="spacing" data-field="container_width" title="Reset">↺</button>
          </label>
          <input type="number" class="rto-input" id="ds_container_width" min="640" max="1920" value="<?= (int)$settings['spacing']['container_width'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Content Width (px) <small>(reserved for a later phase)</small>
            <button type="button" class="ds-reset-field" data-section="spacing" data-field="content_width" title="Reset">↺</button>
          </label>
          <input type="number" class="rto-input" id="ds_content_width" min="320" max="1200" value="<?= (int)$settings['spacing']['content_width'] ?>">
        </div>
      </div>
      <div style="margin-top:18px;display:flex;gap:10px">
        <button type="button" class="rto-btn rto-btn-primary" id="dsSaveSpacing">Save Spacing</button>
        <button type="button" class="rto-btn rto-btn-outline ds-reset-section" data-section="spacing">Reset All Spacing</button>
      </div>
    </div>
  </div>

  <!-- ===================== BUTTONS ===================== -->
  <div class="rto-card ds-tab-panel" data-panel="buttons" style="display:none">
    <div class="rto-card-header"><h3>Buttons</h3></div>
    <div class="rto-card-body">
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;max-width:900px">
        <div class="rto-form-group">
          <label class="rto-label">Border Radius (px, 999 = full pill)
            <button type="button" class="ds-reset-field" data-section="buttons" data-field="radius" title="Reset">↺</button>
          </label>
          <input type="number" class="rto-input" id="ds_btn_radius" min="0" max="999" value="<?= (int)$settings['buttons']['radius'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Padding Y (px)</label>
          <input type="number" class="rto-input" id="ds_btn_padding_y" min="0" max="60" value="<?= (int)$settings['buttons']['padding_y'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Padding X (px)</label>
          <input type="number" class="rto-input" id="ds_btn_padding_x" min="0" max="80" value="<?= (int)$settings['buttons']['padding_x'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Font Weight</label>
          <select class="rto-select" id="ds_btn_font_weight">
            <?php foreach ($weightValues as $w): ?><option value="<?= $w ?>" <?= (int)$settings['buttons']['font_weight'] === $w ? 'selected' : '' ?>><?= $w ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Border Width (px)</label>
          <input type="number" class="rto-input" id="ds_btn_border_width" min="0" max="10" value="<?= (int)$settings['buttons']['border_width'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Border Color</label>
          <input type="color" id="ds_btn_border_color" value="<?= esc_attr($settings['buttons']['border_color']) ?>" style="width:100%;height:36px;border:1px solid var(--gray-200,#e5e7eb);border-radius:6px">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Shadow</label>
          <select class="rto-select" id="ds_btn_shadow">
            <?php foreach (['none'=>'None','sm'=>'Small','md'=>'Medium','lg'=>'Large'] as $v=>$l): ?>
            <option value="<?= esc_attr($v) ?>" <?= $settings['buttons']['shadow'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Hover Effect</label>
          <select class="rto-select" id="ds_btn_hover_effect">
            <?php foreach (['none'=>'None','lift'=>'Lift','glow'=>'Glow'] as $v=>$l): ?>
            <option value="<?= esc_attr($v) ?>" <?= $settings['buttons']['hover_effect'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Transition Speed (ms)
            <button type="button" class="ds-reset-field" data-section="buttons" data-field="transition_speed" title="Reset">↺</button>
          </label>
          <input type="number" class="rto-input" id="ds_btn_transition_speed" min="0" max="2000" step="10" value="<?= (int)$settings['buttons']['transition_speed'] ?>">
        </div>
      </div>
      <div style="margin-top:18px;display:flex;gap:10px">
        <button type="button" class="rto-btn rto-btn-primary" id="dsSaveButtons">Save Buttons</button>
        <button type="button" class="rto-btn rto-btn-outline ds-reset-section" data-section="buttons">Reset All Buttons</button>
      </div>
    </div>
  </div>

  <!-- ===================== CARDS & COMPONENTS ===================== -->
  <div class="rto-card ds-tab-panel" data-panel="cards" style="display:none">
    <div class="rto-card-header"><h3>Cards & Components</h3></div>
    <div class="rto-card-body">
      <p style="margin:0 0 14px;color:var(--gray-600,#64748b);font-size:12.5px;max-width:640px">
        Applies to the "Why Choose Us" and Testimonial cards site-wide, plus the shared corner radius used by the
        service-icon cards and video card. The service/video cards and FAQ items keep their own distinct
        backgrounds and accent borders by design, so those are not overridden here.
      </p>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;max-width:900px">
        <div class="rto-form-group">
          <label class="rto-label">Corner Radius (px)
            <button type="button" class="ds-reset-field" data-section="cards" data-field="radius" title="Reset">↺</button>
          </label>
          <input type="number" class="rto-input" id="ds_cd_radius" min="0" max="60" value="<?= (int)$settings['cards']['radius'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Shadow Strength
            <button type="button" class="ds-reset-field" data-section="cards" data-field="shadow" title="Reset">↺</button>
          </label>
          <select class="rto-select" id="ds_cd_shadow">
            <?php foreach (['flat'=>'Flat (none)','subtle'=>'Subtle (default)','bold'=>'Bold'] as $v=>$l): ?>
            <option value="<?= esc_attr($v) ?>" <?= $settings['cards']['shadow'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Border Width (px)</label>
          <input type="number" class="rto-input" id="ds_cd_border_width" min="0" max="6" value="<?= (int)$settings['cards']['border_width'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Border Color</label>
          <input type="color" id="ds_cd_border_color" value="<?= esc_attr($settings['cards']['border_color']) ?>" style="width:100%;height:36px;border:1px solid var(--gray-200,#e5e7eb);border-radius:6px">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Background Color</label>
          <input type="color" id="ds_cd_background" value="<?= esc_attr($settings['cards']['background']) ?>" style="width:100%;height:36px;border:1px solid var(--gray-200,#e5e7eb);border-radius:6px">
        </div>
      </div>
      <div style="margin-top:18px;display:flex;gap:10px">
        <button type="button" class="rto-btn rto-btn-primary" id="dsSaveCards">Save Cards & Components</button>
        <button type="button" class="rto-btn rto-btn-outline ds-reset-section" data-section="cards">Reset All Cards</button>
      </div>
    </div>
  </div>

  <!-- ===================== SECTIONS ===================== -->
  <div class="rto-card ds-tab-panel" data-panel="sections" style="display:none">
    <div class="rto-card-header"><h3>Sections</h3></div>
    <div class="rto-card-body">
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;max-width:900px">
        <div class="rto-form-group">
          <label class="rto-label">Minimum Section Height (px, 0 = auto)
            <button type="button" class="ds-reset-field" data-section="sections" data-field="min_height" title="Reset">↺</button>
          </label>
          <input type="number" class="rto-input" id="ds_se_min_height" min="0" max="1200" value="<?= (int)$settings['sections']['min_height'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Alternate Section Background Pattern
            <button type="button" class="ds-reset-field" data-section="sections" data-field="alt_pattern" title="Reset">↺</button>
          </label>
          <select class="rto-select" id="ds_se_alt_pattern">
            <option value="on" <?= $settings['sections']['alt_pattern'] === 'on' ? 'selected' : '' ?>>On (default)</option>
            <option value="off" <?= $settings['sections']['alt_pattern'] === 'off' ? 'selected' : '' ?>>Off</option>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Section Header Alignment
            <button type="button" class="ds-reset-field" data-section="sections" data-field="header_align" title="Reset">↺</button>
          </label>
          <select class="rto-select" id="ds_se_header_align">
            <option value="left" <?= $settings['sections']['header_align'] === 'left' ? 'selected' : '' ?>>Left (default)</option>
            <option value="center" <?= $settings['sections']['header_align'] === 'center' ? 'selected' : '' ?>>Center</option>
          </select>
        </div>
      </div>
      <div style="margin-top:18px;display:flex;gap:10px">
        <button type="button" class="rto-btn rto-btn-primary" id="dsSaveSections">Save Sections</button>
        <button type="button" class="rto-btn rto-btn-outline ds-reset-section" data-section="sections">Reset All Sections</button>
      </div>
    </div>
  </div>

  <!-- ===================== EFFECTS ===================== -->
  <div class="rto-card ds-tab-panel" data-panel="effects" style="display:none">
    <div class="rto-card-header"><h3>Effects</h3></div>
    <div class="rto-card-body">
      <p style="margin:0 0 14px;color:var(--gray-600,#64748b);font-size:12.5px;max-width:640px">
        Controls the hover motion and transition speed of the "Why Choose Us", service, and testimonial cards.
      </p>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;max-width:900px">
        <div class="rto-form-group">
          <label class="rto-label">Card Hover Effect
            <button type="button" class="ds-reset-field" data-section="effects" data-field="card_hover_effect" title="Reset">↺</button>
          </label>
          <select class="rto-select" id="ds_ef_card_hover_effect">
            <?php foreach (['none'=>'None','lift'=>'Lift (default)','glow'=>'Glow'] as $v=>$l): ?>
            <option value="<?= esc_attr($v) ?>" <?= $settings['effects']['card_hover_effect'] === $v ? 'selected' : '' ?>><?= esc_html($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Hover Lift Distance (px)</label>
          <input type="number" class="rto-input" id="ds_ef_card_hover_lift" min="0" max="30" value="<?= (int)$settings['effects']['card_hover_lift'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Transition Speed (ms)
            <button type="button" class="ds-reset-field" data-section="effects" data-field="card_transition_ms" title="Reset">↺</button>
          </label>
          <input type="number" class="rto-input" id="ds_ef_card_transition_ms" min="0" max="2000" step="10" value="<?= (int)$settings['effects']['card_transition_ms'] ?>">
        </div>
      </div>
      <div style="margin-top:18px;display:flex;gap:10px">
        <button type="button" class="rto-btn rto-btn-primary" id="dsSaveEffects">Save Effects</button>
        <button type="button" class="rto-btn rto-btn-outline ds-reset-section" data-section="effects">Reset All Effects</button>
      </div>
    </div>
  </div>

  <!-- ===================== HEADER & NAVIGATION ===================== -->
  <div class="rto-card ds-tab-panel" data-panel="header" style="display:none">
    <div class="rto-card-header"><h3>Header & Navigation</h3></div>
    <div class="rto-card-body">
      <p style="margin:0 0 14px;color:var(--gray-600,#64748b);font-size:12.5px;max-width:640px">
        Navigation link typography and color are controlled from the Typography tab ("Navigation") and the Colors
        tab (Primary / Link) — not duplicated here. Header Background, Sticky Header and Top Info Bar apply to
        the Home page AND to every other page (About, Contact, Pricing, How It Works, Terms, Privacy, Login, All
        Cities, City Page, Service), since they share one header. Header Height and Border Color are Home page
        only — the other pages' header uses a different height and has no border to color.
      </p>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;max-width:900px">
        <div class="rto-form-group">
          <label class="rto-label">Header Background</label>
          <input type="color" id="ds_hd_background" value="<?= esc_attr($settings['header']['background']) ?>" style="width:100%;height:36px;border:1px solid var(--gray-200,#e5e7eb);border-radius:6px">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Header Height (px) <small>(Home page only)</small>
            <button type="button" class="ds-reset-field" data-section="header" data-field="height" title="Reset">↺</button>
          </label>
          <input type="number" class="rto-input" id="ds_hd_height" min="48" max="140" value="<?= (int)$settings['header']['height'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Border Color <small>(Home page only)</small></label>
          <input type="color" id="ds_hd_border_color" value="<?= esc_attr($settings['header']['border_color']) ?>" style="width:100%;height:36px;border:1px solid var(--gray-200,#e5e7eb);border-radius:6px">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Sticky Header
            <button type="button" class="ds-reset-field" data-section="header" data-field="sticky" title="Reset">↺</button>
          </label>
          <select class="rto-select" id="ds_hd_sticky">
            <option value="on" <?= $settings['header']['sticky'] === 'on' ? 'selected' : '' ?>>On — stays visible while scrolling (default)</option>
            <option value="off" <?= $settings['header']['sticky'] === 'off' ? 'selected' : '' ?>>Off — scrolls away with the page</option>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Top Info Bar
            <button type="button" class="ds-reset-field" data-section="header" data-field="topbar_visible" title="Reset">↺</button>
          </label>
          <select class="rto-select" id="ds_hd_topbar_visible">
            <option value="on" <?= $settings['header']['topbar_visible'] === 'on' ? 'selected' : '' ?>>Visible (default)</option>
            <option value="off" <?= $settings['header']['topbar_visible'] === 'off' ? 'selected' : '' ?>>Hidden</option>
          </select>
        </div>
      </div>
      <div style="margin-top:18px;display:flex;gap:10px">
        <button type="button" class="rto-btn rto-btn-primary" id="dsSaveHeader">Save Header & Navigation</button>
        <button type="button" class="rto-btn rto-btn-outline ds-reset-section" data-section="header">Reset All Header Settings</button>
      </div>
    </div>
  </div>

  <!-- ===================== FOOTER ===================== -->
  <div class="rto-card ds-tab-panel" data-panel="footer" style="display:none">
    <div class="rto-card-header"><h3>Footer</h3></div>
    <div class="rto-card-body">
      <p style="margin:0 0 14px;color:var(--gray-600,#64748b);font-size:12.5px;max-width:640px">
        Column Heading Color and Link Color apply to the Home page footer AND to the footer shared by every other
        page (About, Contact, Pricing, How It Works, Terms, Privacy, Login, All Cities, City Page, Service).
        Padding applies to the Home page footer only. Home page footer background is the "Header/Footer Dark
        Background" color on the Colors tab (shared with the top info bar there); the other pages' footer uses
        its own background field below, since it currently renders a different dark shade.
      </p>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;max-width:900px">
        <div class="rto-form-group">
          <label class="rto-label">Column Heading Color</label>
          <input type="color" id="ds_ft_heading_color" value="<?= esc_attr($settings['footer']['heading_color']) ?>" style="width:100%;height:36px;border:1px solid var(--gray-200,#e5e7eb);border-radius:6px">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Link Color</label>
          <input type="color" id="ds_ft_link_color" value="<?= esc_attr($settings['footer']['link_color']) ?>" style="width:100%;height:36px;border:1px solid var(--gray-200,#e5e7eb);border-radius:6px">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Other Pages' Footer Background</label>
          <input type="color" id="ds_ft_site_bg" value="<?= esc_attr($settings['footer']['site_bg']) ?>" style="width:100%;height:36px;border:1px solid var(--gray-200,#e5e7eb);border-radius:6px">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Top Padding (px) <small>(Home page only)</small>
            <button type="button" class="ds-reset-field" data-section="footer" data-field="padding_top" title="Reset">↺</button>
          </label>
          <input type="number" class="rto-input" id="ds_ft_padding_top" min="0" max="160" value="<?= (int)$settings['footer']['padding_top'] ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label">Bottom Padding (px) <small>(Home page only)</small>
            <button type="button" class="ds-reset-field" data-section="footer" data-field="padding_bottom" title="Reset">↺</button>
          </label>
          <input type="number" class="rto-input" id="ds_ft_padding_bottom" min="0" max="160" value="<?= (int)$settings['footer']['padding_bottom'] ?>">
        </div>
      </div>
      <div style="margin-top:18px;display:flex;gap:10px">
        <button type="button" class="rto-btn rto-btn-primary" id="dsSaveFooter">Save Footer</button>
        <button type="button" class="rto-btn rto-btn-outline ds-reset-section" data-section="footer">Reset All Footer Settings</button>
      </div>
    </div>
  </div>

  <div class="rto-card rto-card-danger" style="margin-top:20px">
    <div class="rto-card-body" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
      <div>
        <strong>Reset entire design system</strong>
        <p style="margin:4px 0 0;color:var(--gray-600,#64748b);font-size:12.5px">Resets Colors, Typography, Spacing, Buttons, Cards, Sections, Effects, Header and Footer all back to their defaults. This cannot be undone.</p>
      </div>
      <button type="button" class="rto-btn rto-btn-danger" id="dsResetAll">Reset Everything</button>
    </div>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var nonce = rtoflowAdmin.nonce; var ajaxUrl = rtoflowAdmin.ajax_url;

function dsShowMsg(text, ok) {
  var box = document.getElementById('dsGlobalMsg');
  box.style.display = 'block';
  box.className = 'rto-msg ' + (ok ? 'rto-msg-success' : 'rto-msg-error');
  box.textContent = text;
  window.scrollTo({top: 0, behavior: 'smooth'});
}

// ── Tabs ─────────────────────────────────────────────────────────────
document.querySelectorAll('.rto-tab').forEach(function (tab) {
  tab.addEventListener('click', function () {
    document.querySelectorAll('.rto-tab').forEach(function (t) { t.classList.remove('active'); });
    document.querySelectorAll('.ds-tab-panel').forEach(function (p) { p.style.display = 'none'; });
    tab.classList.add('active');
    document.querySelector('.ds-tab-panel[data-panel="' + tab.dataset.tab + '"]').style.display = '';
  });
});

// ── Live preview ─────────────────────────────────────────────────────
var FONT_STACKS = {
  system: "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif",
  georgia: "Georgia,'Times New Roman',serif",
  arial: "Arial,Helvetica,sans-serif",
  verdana: "Verdana,Geneva,sans-serif",
  trebuchet: "'Trebuchet MS',sans-serif",
  courier: "'Courier New',Courier,monospace"
};

function dsCurrentColors() {
  var c = {};
  document.querySelectorAll('.ds-color-input').forEach(function (el) { c[el.dataset.key] = el.value; });
  return c;
}

function dsElValues(elKey) {
  var wrap = document.querySelector('.ds-el[data-el="' + elKey + '"]');
  if (!wrap) return null;
  var v = {};
  wrap.querySelectorAll('.ds-el-input').forEach(function (el) { v[el.dataset.field] = el.value; });
  return v;
}

function dsApplyPreview() {
  var colors = dsCurrentColors();
  var bodyFont = FONT_STACKS[document.getElementById('ds_body_font_family').value] || FONT_STACKS.system;
  var headFont = FONT_STACKS[document.getElementById('ds_heading_font_family').value] || FONT_STACKS.system;

  var map = [
    ['dsPrevLabel', 'label', bodyFont],
    ['dsPrevH2', 'h2', headFont],
    ['dsPrevH3', 'h3', headFont],
    ['dsPrevBody', 'body', bodyFont],
    ['dsPrevNav', 'nav', bodyFont],
  ];
  map.forEach(function (row) {
    var el = document.getElementById(row[0]);
    var v = dsElValues(row[1]);
    if (!el || !v) return;
    el.style.fontFamily = row[2];
    el.style.fontSize = v.size_desktop + 'px';
    el.style.fontWeight = v.weight;
    el.style.lineHeight = v.line_height;
    el.style.letterSpacing = v.letter_spacing + 'em';
    el.style.textTransform = v.transform;
    el.style.color = colors[v.color_slot] || colors.body;
  });

  var btnV = dsElValues('button');
  var btn = document.getElementById('dsPrevBtn');
  if (btn && !btn.dataset.dsBound) {
    btn.dataset.dsBound = '1';
    btn.addEventListener('click', function(e) { e.preventDefault(); });
  }
  if (btnV && btn) {
    btn.style.fontFamily = bodyFont;
    btn.style.fontSize = btnV.size_desktop + 'px';
    btn.style.fontWeight = btnV.weight;
    btn.style.letterSpacing = btnV.letter_spacing + 'em';
    btn.style.textTransform = btnV.transform;
  }
  btn.style.color = colors.button_text;
  btn.style.background = colors.button_bg;
  btn.style.borderRadius = document.getElementById('ds_btn_radius').value + 'px';
  btn.style.padding = document.getElementById('ds_btn_padding_y').value + 'px ' + document.getElementById('ds_btn_padding_x').value + 'px';
  btn.style.borderWidth = document.getElementById('ds_btn_border_width').value + 'px';
  btn.style.borderStyle = 'solid';
  btn.style.borderColor = document.getElementById('ds_btn_border_color').value;
  var shadowMap = {none:'none', sm:'0 2px 8px rgba(0,0,0,.10)', md:'0 4px 14px rgba(0,0,0,.18)', lg:'0 10px 28px rgba(0,0,0,.24)'};
  btn.style.boxShadow = shadowMap[document.getElementById('ds_btn_shadow').value] || 'none';
  btn.style.transition = 'all ' + document.getElementById('ds_btn_transition_speed').value + 'ms ease';

  document.getElementById('dsPreview').style.background = colors.background;

  // ── Cards & Components + Effects preview ──
  var card = document.getElementById('dsPrevCard');
  var cardShadowMap = {
    flat:   'none',
    subtle: '0 1px 2px rgba(16,24,53,.05),0 1px 1px rgba(16,24,53,.04)',
    bold:   '0 2px 6px rgba(16,24,53,.10),0 1px 2px rgba(16,24,53,.08)'
  };
  card.style.borderRadius = document.getElementById('ds_cd_radius').value + 'px';
  card.style.boxShadow = cardShadowMap[document.getElementById('ds_cd_shadow').value] || 'none';
  card.style.borderWidth = document.getElementById('ds_cd_border_width').value + 'px';
  card.style.borderStyle = 'solid';
  card.style.borderColor = document.getElementById('ds_cd_border_color').value;
  card.style.background = document.getElementById('ds_cd_background').value;
  card.style.padding = '18px 16px';
  card.style.fontFamily = bodyFont;
  card.style.fontSize = '13.5px';
  card.style.color = colors.body;
  card.style.transition = 'all ' + document.getElementById('ds_ef_card_transition_ms').value + 'ms ease';
  if (!card.dataset.dsHoverBound) {
    card.dataset.dsHoverBound = '1';
    card.addEventListener('mouseenter', function () {
      var effect = document.getElementById('ds_ef_card_hover_effect').value;
      var lift = document.getElementById('ds_ef_card_hover_lift').value;
      if (effect === 'lift') { card.style.transform = 'translateY(-' + lift + 'px)'; card.style.boxShadow = cardShadowMap.bold; }
      else if (effect === 'glow') { card.style.boxShadow = '0 0 0 4px ' + (colors.primary || '#1B2A6B') + '2e'; }
    });
    card.addEventListener('mouseleave', function () { dsApplyPreview(); });
  }
}

document.querySelectorAll('.ds-color-input, .ds-color-text').forEach(function (el) {
  el.addEventListener('input', function () {
    if (el.classList.contains('ds-color-text')) {
      var pair = document.getElementById('ds_color_' + el.dataset.key);
      if (pair && /^#[0-9a-fA-F]{6}$/.test(el.value)) pair.value = el.value;
    } else {
      var textPair = document.querySelector('.ds-color-text[data-key="' + el.dataset.key + '"]');
      if (textPair) textPair.value = el.value;
    }
    dsApplyPreview();
  });
});
document.querySelectorAll('.ds-el-input, #ds_body_font_family, #ds_heading_font_family, #ds_btn_radius, #ds_btn_padding_y, #ds_btn_padding_x, #ds_btn_border_width, #ds_btn_border_color, #ds_btn_shadow, #ds_btn_transition_speed, #ds_cd_radius, #ds_cd_shadow, #ds_cd_border_width, #ds_cd_border_color, #ds_cd_background, #ds_ef_card_hover_effect, #ds_ef_card_hover_lift, #ds_ef_card_transition_ms').forEach(function (el) {
  el.addEventListener('input', dsApplyPreview);
  el.addEventListener('change', dsApplyPreview);
});
dsApplyPreview();

// ── Save: Colors ─────────────────────────────────────────────────────
document.getElementById('dsSaveColors').addEventListener('click', function () {
  var btn = this;
  var fd = new FormData();
  fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'design_colors_save');
  fd.append('rto_nonce', nonce);
  var colors = dsCurrentColors();
  Object.keys(colors).forEach(function (k) { fd.append(k, colors[k]); });
  btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Saving…';
  fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
    .then(function (r) { return r.json(); })
    .then(function (r) { btn.disabled = false; btn.textContent = orig; dsShowMsg(r.message || (r.success ? 'Saved.' : 'Save failed.'), r.success); })
    .catch(function () { btn.disabled = false; btn.textContent = orig; dsShowMsg('Connection error. Please try again.', false); });
});

// ── Save: Typography ─────────────────────────────────────────────────
document.getElementById('dsSaveTypography').addEventListener('click', function () {
  var btn = this;
  var fd = new FormData();
  fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'design_typography_save');
  fd.append('rto_nonce', nonce);
  fd.append('body_font_family', document.getElementById('ds_body_font_family').value);
  fd.append('heading_font_family', document.getElementById('ds_heading_font_family').value);
  document.querySelectorAll('.ds-el').forEach(function (wrap) {
    var elKey = wrap.dataset.el;
    wrap.querySelectorAll('.ds-el-input').forEach(function (input) {
      fd.append(elKey + '_' + input.dataset.field, input.value);
    });
  });
  btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Saving…';
  fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
    .then(function (r) { return r.json(); })
    .then(function (r) { btn.disabled = false; btn.textContent = orig; dsShowMsg(r.message || (r.success ? 'Saved.' : 'Save failed.'), r.success); })
    .catch(function () { btn.disabled = false; btn.textContent = orig; dsShowMsg('Connection error. Please try again.', false); });
});

// ── Save: Spacing ─────────────────────────────────────────────────────
document.getElementById('dsSaveSpacing').addEventListener('click', function () {
  var btn = this;
  var fd = new FormData();
  fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'design_spacing_save');
  fd.append('rto_nonce', nonce);
  ['section_padding_desktop','section_padding_tablet','section_padding_mobile','section_gap','card_spacing','container_width','content_width'].forEach(function (f) {
    fd.append(f, document.getElementById('ds_' + f).value);
  });
  btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Saving…';
  fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
    .then(function (r) { return r.json(); })
    .then(function (r) { btn.disabled = false; btn.textContent = orig; dsShowMsg(r.message || (r.success ? 'Saved.' : 'Save failed.'), r.success); })
    .catch(function () { btn.disabled = false; btn.textContent = orig; dsShowMsg('Connection error. Please try again.', false); });
});

// ── Save: Buttons ─────────────────────────────────────────────────────
document.getElementById('dsSaveButtons').addEventListener('click', function () {
  var btn = this;
  var fd = new FormData();
  fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'design_buttons_save');
  fd.append('rto_nonce', nonce);
  fd.append('radius', document.getElementById('ds_btn_radius').value);
  fd.append('padding_y', document.getElementById('ds_btn_padding_y').value);
  fd.append('padding_x', document.getElementById('ds_btn_padding_x').value);
  fd.append('font_weight', document.getElementById('ds_btn_font_weight').value);
  fd.append('border_width', document.getElementById('ds_btn_border_width').value);
  fd.append('border_color', document.getElementById('ds_btn_border_color').value);
  fd.append('shadow', document.getElementById('ds_btn_shadow').value);
  fd.append('hover_effect', document.getElementById('ds_btn_hover_effect').value);
  fd.append('transition_speed', document.getElementById('ds_btn_transition_speed').value);
  btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Saving…';
  fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
    .then(function (r) { return r.json(); })
    .then(function (r) { btn.disabled = false; btn.textContent = orig; dsShowMsg(r.message || (r.success ? 'Saved.' : 'Save failed.'), r.success); })
    .catch(function () { btn.disabled = false; btn.textContent = orig; dsShowMsg('Connection error. Please try again.', false); });
});

// ── Save: Cards & Components ─────────────────────────────────────────
document.getElementById('dsSaveCards').addEventListener('click', function () {
  var btn = this;
  var fd = new FormData();
  fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'design_cards_save');
  fd.append('rto_nonce', nonce);
  fd.append('radius', document.getElementById('ds_cd_radius').value);
  fd.append('shadow', document.getElementById('ds_cd_shadow').value);
  fd.append('border_width', document.getElementById('ds_cd_border_width').value);
  fd.append('border_color', document.getElementById('ds_cd_border_color').value);
  fd.append('background', document.getElementById('ds_cd_background').value);
  btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Saving…';
  fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
    .then(function (r) { return r.json(); })
    .then(function (r) { btn.disabled = false; btn.textContent = orig; dsShowMsg(r.message || (r.success ? 'Saved.' : 'Save failed.'), r.success); })
    .catch(function () { btn.disabled = false; btn.textContent = orig; dsShowMsg('Connection error. Please try again.', false); });
});

// ── Save: Sections ───────────────────────────────────────────────────
document.getElementById('dsSaveSections').addEventListener('click', function () {
  var btn = this;
  var fd = new FormData();
  fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'design_sections_save');
  fd.append('rto_nonce', nonce);
  fd.append('min_height', document.getElementById('ds_se_min_height').value);
  fd.append('alt_pattern', document.getElementById('ds_se_alt_pattern').value);
  fd.append('header_align', document.getElementById('ds_se_header_align').value);
  btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Saving…';
  fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
    .then(function (r) { return r.json(); })
    .then(function (r) { btn.disabled = false; btn.textContent = orig; dsShowMsg(r.message || (r.success ? 'Saved.' : 'Save failed.'), r.success); })
    .catch(function () { btn.disabled = false; btn.textContent = orig; dsShowMsg('Connection error. Please try again.', false); });
});

// ── Save: Effects ────────────────────────────────────────────────────
document.getElementById('dsSaveEffects').addEventListener('click', function () {
  var btn = this;
  var fd = new FormData();
  fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'design_effects_save');
  fd.append('rto_nonce', nonce);
  fd.append('card_hover_effect', document.getElementById('ds_ef_card_hover_effect').value);
  fd.append('card_hover_lift', document.getElementById('ds_ef_card_hover_lift').value);
  fd.append('card_transition_ms', document.getElementById('ds_ef_card_transition_ms').value);
  btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Saving…';
  fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
    .then(function (r) { return r.json(); })
    .then(function (r) { btn.disabled = false; btn.textContent = orig; dsShowMsg(r.message || (r.success ? 'Saved.' : 'Save failed.'), r.success); })
    .catch(function () { btn.disabled = false; btn.textContent = orig; dsShowMsg('Connection error. Please try again.', false); });
});

// ── Save: Header & Navigation ────────────────────────────────────────
document.getElementById('dsSaveHeader').addEventListener('click', function () {
  var btn = this;
  var fd = new FormData();
  fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'design_header_save');
  fd.append('rto_nonce', nonce);
  fd.append('background', document.getElementById('ds_hd_background').value);
  fd.append('height', document.getElementById('ds_hd_height').value);
  fd.append('sticky', document.getElementById('ds_hd_sticky').value);
  fd.append('border_color', document.getElementById('ds_hd_border_color').value);
  fd.append('topbar_visible', document.getElementById('ds_hd_topbar_visible').value);
  btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Saving…';
  fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
    .then(function (r) { return r.json(); })
    .then(function (r) { btn.disabled = false; btn.textContent = orig; dsShowMsg(r.message || (r.success ? 'Saved.' : 'Save failed.'), r.success); })
    .catch(function () { btn.disabled = false; btn.textContent = orig; dsShowMsg('Connection error. Please try again.', false); });
});

// ── Save: Footer ─────────────────────────────────────────────────────
document.getElementById('dsSaveFooter').addEventListener('click', function () {
  var btn = this;
  var fd = new FormData();
  fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'design_footer_save');
  fd.append('rto_nonce', nonce);
  fd.append('heading_color', document.getElementById('ds_ft_heading_color').value);
  fd.append('link_color', document.getElementById('ds_ft_link_color').value);
  fd.append('site_bg', document.getElementById('ds_ft_site_bg').value);
  fd.append('padding_top', document.getElementById('ds_ft_padding_top').value);
  fd.append('padding_bottom', document.getElementById('ds_ft_padding_bottom').value);
  btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Saving…';
  fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
    .then(function (r) { return r.json(); })
    .then(function (r) { btn.disabled = false; btn.textContent = orig; dsShowMsg(r.message || (r.success ? 'Saved.' : 'Save failed.'), r.success); })
    .catch(function () { btn.disabled = false; btn.textContent = orig; dsShowMsg('Connection error. Please try again.', false); });
});

// ── Reset (field / section / all) ────────────────────────────────────
function dsDoReset(scope, section, element, field, confirmMsg) {
  if (confirmMsg && !confirm(confirmMsg)) return;
  var fd = new FormData();
  fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin'); fd.append('rto_action', 'design_reset');
  fd.append('rto_nonce', nonce);
  fd.append('scope', scope);
  if (section) fd.append('section', section);
  if (element) fd.append('element', element);
  if (field) fd.append('field', field);
  fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
    .then(function (r) { return r.json(); })
    .then(function (r) {
      dsShowMsg(r.message || (r.success ? 'Reset.' : 'Reset failed.'), r.success);
      if (r.success) setTimeout(function () { location.reload(); }, 500);
    })
    .catch(function () { dsShowMsg('Connection error. Please try again.', false); });
}

document.querySelectorAll('.ds-reset-field').forEach(function (btn) {
  btn.addEventListener('click', function (ev) {
    ev.preventDefault();
    dsDoReset('field', btn.dataset.section, btn.dataset.element || null, btn.dataset.field, null);
  });
});
document.querySelectorAll('.ds-reset-section').forEach(function (btn) {
  btn.addEventListener('click', function () {
    dsDoReset('section', btn.dataset.section, null, null, 'Reset every field in this section to its default? This cannot be undone.');
  });
});
document.getElementById('dsResetAll').addEventListener('click', function () {
  dsDoReset('all', null, null, null, 'Reset the ENTIRE design system (Colors, Typography, Spacing, Buttons, Cards, Sections, Effects, Header, Footer) to defaults? This cannot be undone.');
});
</script>

<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
