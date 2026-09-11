<?php
/**
 * NAS Super Combo — 11-Step Booking Wizard
 * Fully standalone, no WordPress theme dependency.
 * Steps: Category → Samples → City → Newspaper → Edition →
 *        Ad Type → Build Ad → Size & Pricing → Publish Date →
 *        Client Details → Review & Submit
 */
if ( ! defined( 'ABSPATH' ) ) exit;
$GLOBALS['portal_active_nav'] = 'book-newspaper-ad';

// Suppress WP theme chrome
add_action('wp_head', function() { ?><style id="nas-ts">
#wpadminbar{display:none!important}html{margin-top:0!important}
body.nas-fullpage>header:not(.nas-topnav),body.nas-fullpage>#masthead,
body.nas-fullpage>.site-header,body.nas-fullpage>footer,
body.nas-fullpage>.site-footer,body.nas-fullpage>#colophon{display:none!important}
</style><?php }, 1);

$user  = is_user_logged_in() ? wp_get_current_user() : null;
$steps = [
    ['icon'=>'fa-tag',          'label'=>'Category'],
    ['icon'=>'fa-images',       'label'=>'Samples'],
    ['icon'=>'fa-location-dot', 'label'=>'City'],
    ['icon'=>'fa-newspaper',    'label'=>'Newspaper'],
    ['icon'=>'fa-map-pin',      'label'=>'Edition'],
    ['icon'=>'fa-layer-group',  'label'=>'Ad Type'],
    ['icon'=>'fa-pen-nib',      'label'=>'Build Ad'],
    ['icon'=>'fa-ruler-combined','label'=>'Size & Price'],
    ['icon'=>'fa-calendar-days','label'=>'Publish Date'],
    ['icon'=>'fa-user',         'label'=>'Your Details'],
    ['icon'=>'fa-circle-check', 'label'=>'Review'],
];
?>
<div class="nas-portal-page nas-booking-wizard">
<!-- ══ MASTHEAD — newspaper front-page motif ═════════════════════════════════ -->
<header class="nas-masthead">
  <div class="nas-masthead-inner">
    <div class="nas-masthead-rule nas-masthead-rule-top">
      <span><?php echo esc_html( date_i18n('l, F j, Y') ); ?></span>
      <span class="nas-masthead-edition">National Edition</span>
      <span><?php echo (int) apply_filters('nas_masthead_paper_count', 300); ?>+ Publications</span>
    </div>
    <div class="nas-masthead-title">
      <span class="nas-masthead-kicker">— The Official Booking Desk —</span>
      <h1>Book Your Advertisement<br>in India's Leading Newspapers</h1>
      <p class="nas-masthead-sub">From local dailies to national broadsheets — plan, design and book your ad in minutes, with transparent pricing and a real team behind every booking.</p>
    </div>
    <div class="nas-masthead-rule nas-masthead-rule-bottom"></div>
  </div>

  <!-- Trust strip -->
  <div class="nas-trust-strip" role="list" aria-label="Why book with us">
    <div class="nas-trust-item" role="listitem">
      <i class="fa-solid fa-newspaper"></i>
      <div><strong>300+</strong><span>Newspapers &amp; Editions</span></div>
    </div>
    <div class="nas-trust-item" role="listitem">
      <i class="fa-solid fa-city"></i>
      <div><strong>150+</strong><span>Cities Covered</span></div>
    </div>
    <div class="nas-trust-item" role="listitem">
      <i class="fa-solid fa-shield-halved"></i>
      <div><strong>Secure</strong><span>Payments &amp; Data</span></div>
    </div>
    <div class="nas-trust-item" role="listitem">
      <i class="fa-solid fa-headset"></i>
      <div><strong>Real Team</strong><span>Reviews Every Ad</span></div>
    </div>
    <div class="nas-trust-item" role="listitem">
      <i class="fa-solid fa-star"></i>
      <div><strong>4.8/5</strong><span>Advertiser Rating</span></div>
    </div>
  </div>
</header>

<!-- ══ WIZARD WRAP ══════════════════════════════════════════════════════════ -->
<div class="nas-wrap">
<div class="nas-layout-grid">
<div class="nas-wizard-main">
<div class="nas-booking-wizard" id="nas-booking-wizard" data-total-steps="11">

  <!-- Progress strip — replaces a redundant duplicate hero;
       masthead already carries the headline, this carries live status -->
  <div class="nas-progress-strip">
    <div class="nas-progress-strip-info">
      <span class="nas-progress-strip-step">Step <strong id="nas-progress-current-step">1</strong> of 11</span>
      <span class="nas-progress-strip-divider"></span>
      <span class="nas-progress-strip-eta"><i class="fa-regular fa-clock"></i> About 5 minutes</span>
    </div>
    <div class="nas-progress-strip-track">
      <div class="nas-progress-strip-fill" id="nas-progress-strip-fill" style="width:9%"></div>
    </div>
  </div>

  <!-- Progress Track -->
  <div class="nas-step-track" id="nas-step-track" role="list" aria-label="Booking steps">
    <?php foreach ($steps as $i => $s): ?>
    <div class="nas-step-item <?php echo $i===0?'active':''; ?>" role="listitem" aria-label="Step <?php echo $i+1; ?>: <?php echo $s['label']; ?>" data-step="<?php echo $i+1; ?>">
      <div class="nas-step-icon-wrap">
        <div class="nas-step-num"><i class="fa-solid <?php echo $s['icon']; ?>"></i></div>
        <div class="nas-step-check"><i class="fa-solid fa-check"></i></div>
      </div>
      <div class="nas-step-label"><?php echo $s['label']; ?></div>
    </div>
    <?php if ($i < count($steps)-1): ?><div class="nas-step-connector"></div><?php endif; ?>
    <?php endforeach; ?>
  </div>

  <!-- ═══ STEP 1: CATEGORY ═══════════════════════════════════════════════ -->
  <div class="nas-wizard-step active" id="nas-wizard-step-1">
  <div class="nas-wizard-card">
    <div class="nas-wizard-card-header">
      <div class="nas-step-badge">Step 1 of 11</div>
      <h2><i class="fa-solid fa-tag"></i> Choose Ad Category</h2>
      <p>Select the type of advertisement you want to publish</p>
      <div class="nas-step-tip"><i class="fa-solid fa-lightbulb"></i> Not sure which category fits? Pick the closest match — you can fine-tune wording and format in later steps.</div>
    </div>
    <div class="nas-wizard-card-body">
      <div class="nas-loading-label" id="nas-category-loading-label"><i class="fa-solid fa-newspaper fa-fade"></i> Loading ad categories…</div>
      <div class="nas-category-grid" id="nas-category-grid">
        <?php for ($i=0; $i<8; $i++): ?>
          <div class="nas-skeleton" style="height:100px;border-radius:var(--nas-radius-xl)"></div>
        <?php endfor; ?>
      </div>
    </div>
    <div class="nas-wizard-nav">
      <span class="nas-wizard-nav-info" id="nas-step1-info">Select a category to continue</span>
      <button class="nas-btn nas-btn-primary nas-btn-lg nas-wizard-next" data-step="1" id="nas-btn-next-1" disabled>
        Continue <i class="fa-solid fa-arrow-right"></i>
      </button>
    </div>
  </div>
  </div>

  <!-- ═══ STEP 2: SAMPLE ADS ═══════════════════════════════════════════════ -->
  <div class="nas-wizard-step" id="nas-wizard-step-2">
  <div class="nas-wizard-card">
    <div class="nas-wizard-card-header">
      <div class="nas-step-badge">Step 2 of 11</div>
      <h2><i class="fa-solid fa-images"></i> Browse Sample Ads</h2>
      <p>Get inspired by real ad examples. Click any sample to use as a starting point.</p>
      <div class="nas-step-tip"><i class="fa-solid fa-lightbulb"></i> This step is optional — feel free to skip it and write your ad from scratch in Step 7.</div>
    </div>
    <div class="nas-wizard-card-body">
      <div class="nas-samples-toolbar">
        <span id="nas-samples-category-label" class="nas-tag nas-tag-accent"></span>
        <span class="nas-samples-hint">Click a sample to pre-fill your ad content</span>
      </div>
      <div class="nas-loading-label" id="nas-samples-loading-label"><i class="fa-solid fa-newspaper fa-fade"></i> Fetching sample ads…</div>
      <div class="nas-samples-grid" id="nas-samples-grid">
        <?php for ($i=0; $i<4; $i++): ?>
          <div class="nas-skeleton" style="height:140px;border-radius:var(--nas-radius-xl)"></div>
        <?php endfor; ?>
      </div>
    </div>
    <div class="nas-wizard-nav">
      <button class="nas-btn nas-btn-secondary nas-wizard-prev" data-step="2"><i class="fa-solid fa-arrow-left"></i> Back</button>
      <button class="nas-btn nas-btn-primary nas-btn-lg nas-wizard-next" data-step="2" id="nas-btn-next-2">
        Skip / Continue <i class="fa-solid fa-arrow-right"></i>
      </button>
    </div>
  </div>
  </div>

  <!-- ═══ STEP 3: CITY ═══════════════════════════════════════════════════ -->
  <div class="nas-wizard-step" id="nas-wizard-step-3">
  <div class="nas-wizard-card">
    <div class="nas-wizard-card-header">
      <div class="nas-step-badge">Step 3 of 11</div>
      <h2><i class="fa-solid fa-location-dot"></i> Select Publication City</h2>
      <p>Choose the city where you want to publish your newspaper advertisement</p>
      <div class="nas-step-tip"><i class="fa-solid fa-lightbulb"></i> Booking for more than one city? You can add additional editions once you reach the newspaper selection step.</div>
    </div>
    <div class="nas-wizard-card-body">
      <!-- Popular cities quick-select -->
      <div class="nas-popular-cities-label-row">
        <div class="nas-popular-cities-label"><i class="fa-solid fa-bolt"></i> Popular Cities</div>
        <span class="nas-popular-cities-count"><i class="fa-solid fa-location-dot"></i> 300+ cities covered</span>
      </div>
      <div class="nas-popular-cities" id="nas-popular-cities">
        <?php foreach (['Mumbai','Delhi','Bengaluru','Hyderabad','Chennai','Kolkata','Pune','Ahmedabad'] as $city): ?>
        <button class="nas-popular-city-btn" data-city="<?php echo esc_attr($city); ?>">
          <i class="fa-solid fa-city"></i> <?php echo $city; ?>
        </button>
        <?php endforeach; ?>
      </div>
      <!-- Search -->
      <div class="nas-city-selector">
        <div class="nas-city-search-wrap">
          <i class="fa-solid fa-magnifying-glass nas-city-search-icon"></i>
          <input type="text" id="nas-city-search" aria-label="Search for a city" class="nas-city-search-input"
            placeholder="Search 300+ cities — type city name..." autocomplete="off" role="combobox"
            aria-autocomplete="list" aria-expanded="false" aria-controls="nas-city-dropdown">
          <div class="nas-city-dropdown" id="nas-city-dropdown" role="listbox"></div>
        </div>
      </div>
      <!-- Selected -->
      <div id="nas-city-selected-card" class="nas-selected-card" style="display:none">
        <div class="nas-selected-card-icon"><i class="fa-solid fa-location-dot"></i></div>
        <div class="nas-selected-card-info">
          <span class="nas-selected-card-name" id="nas-city-selected-name"></span>
          <span class="nas-selected-card-sub" id="nas-city-selected-state"></span>
        </div>
        <button class="nas-btn nas-btn-ghost nas-btn-sm" id="nas-city-clear-btn">
          <i class="fa-solid fa-pen"></i> Change
        </button>
      </div>
      <!-- State groups hint -->
      <div class="nas-city-state-groups" id="nas-city-state-groups">
        <div class="nas-state-group-label">Browse by State</div>
        <div class="nas-state-chips" id="nas-state-chips"></div>
      </div>
    </div>
    <div class="nas-wizard-nav">
      <button class="nas-btn nas-btn-secondary nas-wizard-prev" data-step="3"><i class="fa-solid fa-arrow-left"></i> Back</button>
      <button class="nas-btn nas-btn-primary nas-btn-lg nas-wizard-next" data-step="3" id="nas-btn-next-3" disabled>
        Continue <i class="fa-solid fa-arrow-right"></i>
      </button>
    </div>
  </div>
  </div>

  <!-- ═══ STEP 4: NEWSPAPER ════════════════════════════════════════════════ -->
  <div class="nas-wizard-step" id="nas-wizard-step-4">
  <div class="nas-wizard-card">
    <div class="nas-wizard-card-header">
      <div class="nas-step-badge">Step 4 of 11</div>
      <h2><i class="fa-solid fa-newspaper"></i> Select Newspaper</h2>
      <p>Available publications in <strong id="nas-paper-city-label">your city</strong></p>
      <div class="nas-step-tip"><i class="fa-solid fa-lightbulb"></i> Higher circulation means wider reach — sort by "Circulation" to see the most-read papers first, or filter by language.</div>
    </div>
    <div class="nas-wizard-card-body">
      <!-- Filter bar -->
      <div class="nas-paper-filters">
        <div class="nas-paper-search-wrap">
          <i class="fa-solid fa-search"></i>
          <input type="text" id="nas-paper-search" aria-label="Search newspapers by name" class="nas-input" placeholder="Search newspapers...">
        </div>
        <div class="nas-lang-filter-wrap">
          <select id="nas-lang-filter" class="nas-select nas-select-sm">
            <option value="">All Languages</option>
          </select>
        </div>
        <label class="nas-sort-label">Sort:
          <select id="nas-paper-sort" class="nas-select nas-select-sm">
            <option value="circ">Circulation</option>
            <option value="rate_asc">Rate: Low to High</option>
            <option value="rate_desc">Rate: High to Low</option>
            <option value="name">Name A-Z</option>
          </select>
        </label>
      </div>
      <!-- Combo offer banner -->
      <div id="nas-combo-banner" class="nas-combo-banner" style="display:none">
        <i class="fa-solid fa-bolt"></i>
        <div class="nas-combo-banner-text">
          <strong id="nas-combo-name"></strong>
          <span id="nas-combo-desc"></span>
        </div>
        <button class="nas-btn nas-btn-accent nas-btn-sm" id="nas-combo-apply-btn">Apply Combo</button>
      </div>
      <!-- Grid -->
      <div class="nas-loading-label" id="nas-newspaper-loading-label"><i class="fa-solid fa-newspaper fa-fade"></i> Loading newspapers in your city…</div>
      <div class="nas-paper-grid" id="nas-newspaper-grid">
        <?php for ($i=0; $i<6; $i++): ?>
          <div class="nas-skeleton" style="height:80px;border-radius:var(--nas-radius-xl)"></div>
        <?php endfor; ?>
      </div>
      <div id="nas-paper-empty" class="nas-empty-state" style="display:none">
        <i class="fa-solid fa-newspaper" style="font-size:40px;color:var(--nas-border);margin-bottom:12px"></i>
        <p>No newspapers found matching your filters.<br><small>Try a different language filter or search term.</small></p>
        <button class="nas-btn nas-btn-outline nas-btn-sm" id="nas-paper-clear-filters-btn" style="margin-top:14px">
          <i class="fa-solid fa-rotate-left"></i> Clear Filters
        </button>
      </div>
    </div>
    <div class="nas-wizard-nav">
      <button class="nas-btn nas-btn-secondary nas-wizard-prev" data-step="4"><i class="fa-solid fa-arrow-left"></i> Back</button>
      <button class="nas-btn nas-btn-primary nas-btn-lg nas-wizard-next" data-step="4" id="nas-btn-next-4" disabled>
        Continue <i class="fa-solid fa-arrow-right"></i>
      </button>
    </div>
  </div>
  </div>

  <!-- ═══ STEP 5: EDITION ══════════════════════════════════════════════════ -->
  <div class="nas-wizard-step" id="nas-wizard-step-5">
  <div class="nas-wizard-card">
    <div class="nas-wizard-card-header">
      <div class="nas-step-badge">Step 5 of 11</div>
      <h2><i class="fa-solid fa-map-pin"></i> Select Edition</h2>
      <p>Choose the edition for <strong id="nas-edition-paper-label">your selected newspaper(s)</strong></p>
      <div class="nas-step-tip"><i class="fa-solid fa-lightbulb"></i> The edition determines which regional print run your ad appears in — pick the one closest to your target audience.</div>
    </div>
    <div class="nas-wizard-card-body">
      <!-- Multi-newspaper edition container — JS renders per-paper sections here -->
      <div class="nas-loading-label" id="nas-edition-loading-label"><i class="fa-solid fa-newspaper fa-fade"></i> Loading available editions…</div>
      <div id="nas-multi-edition-container" style="display:flex;flex-direction:column;gap:14px;">
        <div class="nas-skeleton" style="height:130px;border-radius:12px"></div>
      </div>
      <div id="nas-edition-all-hint" style="display:none;align-items:center;gap:8px;padding:12px 16px;background:#ecfdf5;border:1px solid #bbf7d0;border-radius:10px;margin-top:8px;font-size:13px;font-weight:600;color:#15803d;">
        <i class="fa-solid fa-circle-check"></i> All newspapers have an edition selected — ready to continue!
      </div>
    </div>
    <div class="nas-wizard-nav">
      <button class="nas-btn nas-btn-secondary nas-wizard-prev" data-step="5"><i class="fa-solid fa-arrow-left"></i> Back</button>
      <button class="nas-btn nas-btn-primary nas-btn-lg nas-wizard-next" data-step="5" id="nas-btn-next-5" disabled>
        Continue <i class="fa-solid fa-arrow-right"></i>
      </button>
    </div>
  </div>
  </div>

  <!-- ═══ STEP 6: AD TYPE ══════════════════════════════════════════════════ -->
  <div class="nas-wizard-step" id="nas-wizard-step-6">
  <div class="nas-wizard-card">
    <div class="nas-wizard-card-header">
      <div class="nas-step-badge">Step 6 of 11</div>
      <h2><i class="fa-solid fa-layer-group"></i> Choose Ad Format</h2>
      <p>Select the type of advertisement that best fits your needs and budget</p>
      <div class="nas-step-tip"><i class="fa-solid fa-lightbulb"></i> On a budget? Classified Text is charged per word. Want visual impact? Display ads let you add logos and images.</div>
    </div>
    <div class="nas-wizard-card-body">
      <div class="nas-adtype-grid" id="nas-adtype-grid">
        <!-- Classified Text -->
        <div class="nas-adtype-card" data-type="classified_text" tabindex="0" role="button" aria-pressed="false">
          <div class="nas-adtype-icon">📰</div>
          <div class="nas-adtype-swatch nas-adtype-swatch-classified" aria-hidden="true">
            <span></span><span></span><span class="nas-swatch-line-short"></span>
          </div>
          <div class="nas-adtype-title">Classified Text</div>
          <div class="nas-adtype-desc">Simple text-only ad. Charged per word. Most economical option. Appears in classified columns.</div>
          <div class="nas-adtype-price" id="nas-adtype-price-classified">₹<span id="nas-adtype-rate-cl">—</span>/word</div>
          <div class="nas-adtype-tag">Most Popular</div>
        </div>
        <!-- Display -->
        <div class="nas-adtype-card" data-type="display" tabindex="0" role="button" aria-pressed="false">
          <div class="nas-adtype-icon">🖼️</div>
          <div class="nas-adtype-swatch nas-adtype-swatch-display" aria-hidden="true">
            <span class="nas-swatch-block"></span>
          </div>
          <div class="nas-adtype-title">Display Ad</div>
          <div class="nas-adtype-desc">Custom-designed ad with images, logos, borders. Charged per square centimetre. Maximum visual impact.</div>
          <div class="nas-adtype-price">₹<span id="nas-adtype-rate-di">—</span>/sq.cm</div>
          <div class="nas-adtype-tag nas-tag-premium">Premium</div>
        </div>
        <!-- Display Classified -->
        <div class="nas-adtype-card" data-type="display_classified" tabindex="0" role="button" aria-pressed="false">
          <div class="nas-adtype-icon">📋</div>
          <div class="nas-adtype-swatch nas-adtype-swatch-dc" aria-hidden="true">
            <span></span><span class="nas-swatch-line-short"></span>
          </div>
          <div class="nas-adtype-title">Display Classified</div>
          <div class="nas-adtype-desc">Enhanced text ad with border, bold text and logo. Best of both worlds — impactful yet affordable.</div>
          <div class="nas-adtype-price">₹<span id="nas-adtype-rate-dc">—</span>/sq.cm</div>
          <div class="nas-adtype-tag nas-tag-value">Best Value</div>
        </div>
      </div>
      <!-- Ad type comparison table -->
      <div class="nas-adtype-compare">
        <table class="nas-compare-table">
          <thead><tr><th>Feature</th><th>Classified</th><th>Display</th><th>Display Classified</th></tr></thead>
          <tbody>
            <tr><td>Pricing Basis</td><td>Per word</td><td>Per sq.cm</td><td>Per sq.cm</td></tr>
            <tr><td>Images/Logo</td><td><i class="fa-solid fa-xmark nas-compare-no"></i></td><td><i class="fa-solid fa-check nas-compare-yes"></i></td><td><i class="fa-solid fa-check nas-compare-yes"></i> Logo</td></tr>
            <tr><td>Custom Design</td><td><i class="fa-solid fa-xmark nas-compare-no"></i></td><td><i class="fa-solid fa-check nas-compare-yes"></i></td><td><i class="fa-solid fa-bolt nas-compare-partial"></i> Partial</td></tr>
            <tr><td>Border/Box</td><td><i class="fa-solid fa-xmark nas-compare-no"></i></td><td><i class="fa-solid fa-check nas-compare-yes"></i></td><td><i class="fa-solid fa-check nas-compare-yes"></i></td></tr>
            <tr><td>Placement</td><td>Classified cols</td><td>Any position</td><td>Classified section</td></tr>
            <tr><td>Best For</td><td>Notices, Jobs</td><td>Brand ads</td><td>Property, Services</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="nas-wizard-nav">
      <button class="nas-btn nas-btn-secondary nas-wizard-prev" data-step="6"><i class="fa-solid fa-arrow-left"></i> Back</button>
      <button class="nas-btn nas-btn-primary nas-btn-lg nas-wizard-next" data-step="6" id="nas-btn-next-6" disabled>
        Continue <i class="fa-solid fa-arrow-right"></i>
      </button>
    </div>
  </div>
  </div>

  <!-- ═══ STEP 7: BUILD AD ════════════════════════════════════════════════ -->
  <div class="nas-wizard-step" id="nas-wizard-step-7">
  <div class="nas-wizard-card nas-wizard-card-wide">
    <div class="nas-wizard-card-header">
      <div class="nas-step-badge">Step 7 of 11</div>
      <h2><i class="fa-solid fa-pen-nib"></i> Compose Your Ad</h2>
      <p>Write or paste your advertisement text. Use templates for a quick start.</p>
      <div class="nas-step-tip"><i class="fa-solid fa-lightbulb"></i> Keep it clear and specific — mention what, where and how to contact you. Use AI Assist to polish tone or trim length instantly.</div>
    </div>
    <div class="nas-wizard-card-body">
      <div class="nas-build-ad-layout">

        <!-- LEFT: Editor -->
        <div class="nas-editor-panel">
          <!-- Template selector -->
          <div class="nas-template-selector">
            <label class="nas-label">Start from a Template</label>
            <div class="nas-template-grid" id="nas-template-grid">
              <button class="nas-template-chip nas-template-chip-blank" data-id="0">
                <i class="fa-solid fa-plus"></i> Blank
              </button>
              <!-- Loaded dynamically -->
            </div>
          </div>
          <!-- Ad title (for display ads) -->
          <div class="nas-field-group" id="nas-ad-title-group" style="display:none">
            <label class="nas-label" for="nas-ad-title">Ad Headline / Title</label>
            <input type="text" id="nas-ad-title" class="nas-input" placeholder="e.g. 3 BHK Flat For Sale in Dwarka" maxlength="120">
          </div>
          <!-- Textarea -->
          <div class="nas-field-group">
            <label class="nas-label" for="nas-ad-content">
              Ad Content <span class="nas-required">*</span>
              <span class="nas-label-hint" id="nas-content-type-hint">(Plain text, no HTML)</span>
            </label>
            <div class="nas-textarea-wrap">
              <textarea id="nas-ad-content" class="nas-textarea" rows="8"
                placeholder="Type your ad content here... Use [Name], [Phone], [City] as placeholders."
                aria-label="Ad content" spellcheck="true"></textarea>
              <!-- Word/char counter bar -->
              <div class="nas-word-bar">
                <div class="nas-word-bar-track">
                  <div class="nas-word-bar-fill" id="nas-word-bar-fill" style="width:0%"></div>
                </div>
                <span class="nas-word-count-label" id="nas-word-count-label">0 words</span>
              </div>
            </div>
            <div class="nas-content-meta">
              <span id="nas-char-count" class="nas-char-count">0 characters</span>
              <span id="nas-word-warning" class="nas-word-warning" style="display:none"></span>
            </div>
          </div>
          <!-- AI assist toolbar -->
          <div class="nas-ai-toolbar">
            <span class="nas-ai-toolbar-label"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Assist</span>
            <button class="nas-btn nas-btn-ai nas-btn-xs" id="nas-ai-improve-btn"><i class="fa-solid fa-wand-magic-sparkles"></i> Improve</button>
            <button class="nas-btn nas-btn-ai nas-btn-xs" id="nas-ai-shorten-btn"><i class="fa-solid fa-scissors"></i> Shorten</button>
            <button class="nas-btn nas-btn-ai nas-btn-xs" id="nas-ai-formal-btn"><i class="fa-solid fa-file-signature"></i> Formalise</button>
            <div class="nas-ai-spinner" id="nas-ai-spinner" style="display:none"><i class="fa-solid fa-spinner fa-spin"></i></div>
          </div>
        </div>

        <!-- RIGHT: Live Preview -->
        <div class="nas-preview-panel">
          <div class="nas-preview-panel-header">
            <i class="fa-solid fa-eye"></i> Live Preview
          </div>
          <div class="nas-newspaper-mock" id="nas-newspaper-mock">
            <div class="nas-mock-header" id="nas-mock-paper-name">Select a newspaper</div>
            <div class="nas-mock-date" id="nas-mock-date"></div>
            <div class="nas-mock-columns">
              <div class="nas-mock-column nas-mock-col-left">
                <div class="nas-mock-classified-heading">CLASSIFIED ADVERTISEMENTS</div>
                <div class="nas-mock-category-label" id="nas-mock-cat"></div>
                <div class="nas-mock-ad-box" id="nas-mock-ad-box">
                  <div class="nas-mock-ad-title" id="nas-mock-ad-title" style="display:none"></div>
                  <div class="nas-mock-ad-content" id="nas-mock-ad-content"><em>Your ad will appear here...</em></div>
                  <div class="nas-mock-contact" id="nas-mock-contact"></div>
                </div>
              </div>
              <div class="nas-mock-column nas-mock-col-right">
                <div class="nas-mock-filler"></div>
                <div class="nas-mock-filler nas-mock-filler-sm"></div>
                <div class="nas-mock-filler"></div>
              </div>
            </div>
          </div>
          <!-- Estimated cost card -->
          <div class="nas-preview-cost" id="nas-preview-cost">
            <div class="nas-preview-cost-row">
              <span>Estimated Cost</span>
              <strong id="nas-preview-est-cost">₹—</strong>
            </div>
            <div class="nas-preview-cost-note" id="nas-preview-cost-note"></div>
          </div>
        </div>

      </div><!-- /.nas-build-ad-layout -->
    </div>
    <div class="nas-wizard-nav">
      <button class="nas-btn nas-btn-secondary nas-wizard-prev" data-step="7"><i class="fa-solid fa-arrow-left"></i> Back</button>
      <button class="nas-btn nas-btn-primary nas-btn-lg nas-wizard-next" data-step="7" id="nas-btn-next-7" disabled>
        Continue <i class="fa-solid fa-arrow-right"></i>
      </button>
    </div>
  </div>
  </div>

  <!-- ═══ STEP 8: SIZE & PRICING ══════════════════════════════════════════ -->
  <div class="nas-wizard-step" id="nas-wizard-step-8">
  <div class="nas-wizard-card">
    <div class="nas-wizard-card-header">
      <div class="nas-step-badge">Step 8 of 11</div>
      <h2><i class="fa-solid fa-ruler-combined"></i> Size & Pricing</h2>
      <p>Review the ad dimensions, pricing breakdown, and available combo discounts</p>
      <div class="nas-step-tip"><i class="fa-solid fa-lightbulb"></i> Booking multiple newspapers together often unlocks combo discounts — check the offers panel below before you continue.</div>
    </div>
    <div class="nas-wizard-card-body">

      <!-- Classified pricing panel -->
      <div id="nas-pricing-classified-panel" style="display:none">
        <div class="nas-pricing-section-title">Classified Ad Pricing</div>
        <div class="nas-pricing-breakdown">
          <div class="nas-pricing-row">
            <span>Word Count</span>
            <strong id="nas-price-word-count">0 words</strong>
          </div>
          <div class="nas-pricing-row">
            <span>Rate per Word</span>
            <strong id="nas-price-per-word">₹—</strong>
          </div>
          <div class="nas-pricing-row nas-pricing-row-sub">
            <span>Base Amount</span>
            <strong id="nas-price-base">₹—</strong>
          </div>
          <div class="nas-pricing-row nas-pricing-row-sub" id="nas-pricing-min-row" style="display:none">
            <span>Minimum Charge Applied</span>
            <strong id="nas-price-min-applied">₹—</strong>
          </div>
        </div>
      </div>

      <!-- Display pricing panel -->
      <div id="nas-pricing-display-panel" style="display:none">
        <div class="nas-pricing-section-title">Display Ad Size</div>
        <!-- Size presets -->
        <div class="nas-size-presets" id="nas-size-presets">
          <div class="nas-size-preset-label">Quick Select Size</div>
          <div class="nas-size-preset-grid" id="nas-size-preset-grid"></div>
        </div>
        <!-- Custom size -->
        <div class="nas-custom-size">
          <div class="nas-size-inputs">
            <div class="nas-field-group-inline">
              <label class="nas-label">Width (cm)</label>
              <input type="number" id="nas-width-cm" aria-label="Ad width in centimetres" class="nas-input nas-input-sm" min="2" max="50" step="0.5" value="7.5">
            </div>
            <span class="nas-size-times">×</span>
            <div class="nas-field-group-inline">
              <label class="nas-label">Height (cm)</label>
              <input type="number" id="nas-height-cm" aria-label="Ad height in centimetres" class="nas-input nas-input-sm" min="2" max="60" step="0.5" value="5">
            </div>
            <div class="nas-field-group-inline">
              <label class="nas-label">Area (sq.cm)</label>
              <input type="text" id="nas-area-sqcm" aria-label="Ad area in square centimetres" class="nas-input nas-input-sm" readonly>
            </div>
          </div>
          <div class="nas-size-visual" id="nas-size-visual">
            <div class="nas-size-visual-box" id="nas-size-box">
              <span id="nas-size-box-label">7.5 × 5 cm</span>
            </div>
          </div>
        </div>
        <div class="nas-pricing-breakdown">
          <div class="nas-pricing-row">
            <span>Rate per sq.cm</span>
            <strong id="nas-price-per-sqcm">₹—</strong>
          </div>
          <div class="nas-pricing-row nas-pricing-row-sub">
            <span>Base Amount</span>
            <strong id="nas-price-display-base">₹—</strong>
          </div>
        </div>
      </div>

      <!-- Combo offer discount -->
      <div id="nas-combo-discount-row" class="nas-pricing-row nas-pricing-row-discount" style="display:none">
        <span><i class="fa-solid fa-bolt"></i> Combo Discount</span>
        <strong id="nas-combo-discount-amount" style="color:var(--nas-success)">-₹—</strong>
      </div>

      <!-- GST row -->
      <div class="nas-pricing-row nas-pricing-row-sub">
        <span>GST (18%)</span>
        <strong id="nas-price-gst">₹—</strong>
      </div>

      <!-- Total -->
      <div class="nas-pricing-total">
        <span>Total Payable</span>
        <strong id="nas-price-total">₹—</strong>
      </div>

      <!-- Available combos -->
      <div id="nas-available-combos" class="nas-available-combos" style="display:none">
        <div class="nas-combos-title"><i class="fa-solid fa-tags"></i> Available Combo Discounts</div>
        <div id="nas-combo-list" class="nas-combo-list"></div>
      </div>

      <!-- Coupon -->
      <div class="nas-coupon-row">
        <label class="nas-label">Have a Coupon Code?</label>
        <div class="nas-coupon-input-wrap">
          <input type="text" id="nas-coupon-code" aria-label="Enter coupon code" class="nas-input" placeholder="Enter coupon code" style="text-transform:uppercase">
          <button class="nas-btn nas-btn-secondary" id="nas-coupon-apply-btn">Apply</button>
        </div>
        <div id="nas-coupon-msg" class="nas-coupon-msg" style="display:none"></div>
      </div>
    </div>
    <div class="nas-wizard-nav">
      <button class="nas-btn nas-btn-secondary nas-wizard-prev" data-step="8"><i class="fa-solid fa-arrow-left"></i> Back</button>
      <button class="nas-btn nas-btn-primary nas-btn-lg nas-wizard-next" data-step="8" id="nas-btn-next-8">
        Continue <i class="fa-solid fa-arrow-right"></i>
      </button>
    </div>
  </div>
  </div>

  <!-- ═══ STEP 9: PUBLISH DATE ════════════════════════════════════════════ -->
  <div class="nas-wizard-step" id="nas-wizard-step-9">
  <div class="nas-wizard-card">
    <div class="nas-wizard-card-header">
      <div class="nas-step-badge">Step 9 of 11</div>
      <h2><i class="fa-solid fa-calendar-days"></i> Select Publication Date</h2>
      <p>Choose when you want your ad to be published. Minimum <strong id="nas-min-days-label">2 working days</strong> advance booking required.</p>
      <div class="nas-step-tip"><i class="fa-solid fa-lightbulb"></i> Planning around a festival, launch or anniversary? Special/high-demand dates may need extra lead time — book early to secure your slot.</div>
    </div>
    <div class="nas-wizard-card-body">
      <div class="nas-date-picker-wrap">
        <label class="nas-label" for="nas-publish-date-input">
          Publication Date <span class="nas-required">*</span>
        </label>
        <input type="text" id="nas-publish-date-input" class="nas-input nas-date-input"
          placeholder="Click to pick a date" readonly aria-label="Select publication date">
        <div id="nas-publish-date-calendar" class="nas-calendar-inline"></div>
      </div>
      <!-- Selected date card -->
      <div id="nas-date-selected-card" class="nas-selected-card" style="display:none">
        <div class="nas-selected-card-icon"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="nas-selected-card-info">
          <span class="nas-selected-card-name" id="nas-date-display-label"></span>
          <span class="nas-selected-card-sub" id="nas-date-days-label"></span>
        </div>
        <button class="nas-btn nas-btn-ghost nas-btn-sm" id="nas-date-clear-btn">
          <i class="fa-solid fa-pen"></i> Change
        </button>
      </div>
      <!-- Booking deadline notice -->
      <div class="nas-booking-deadline-notice">
        <i class="fa-solid fa-clock"></i>
        <div>
          <strong>Booking Deadline:</strong> Ads must be submitted at least <strong id="nas-deadline-days">2 business days</strong>
          before the selected publication date. Deadlines vary by newspaper.
        </div>
      </div>
      <!-- Special days notice -->
      <div class="nas-special-days-note" id="nas-special-days-note" style="display:none">
        <i class="fa-solid fa-star"></i>
        <span id="nas-special-days-text"></span>
      </div>
    </div>
    <div class="nas-wizard-nav">
      <button class="nas-btn nas-btn-secondary nas-wizard-prev" data-step="9"><i class="fa-solid fa-arrow-left"></i> Back</button>
      <button class="nas-btn nas-btn-primary nas-btn-lg nas-wizard-next" data-step="9" id="nas-btn-next-9" disabled>
        Continue <i class="fa-solid fa-arrow-right"></i>
      </button>
    </div>
  </div>
  </div>

  <!-- ═══ STEP 10: CLIENT DETAILS ══════════════════════════════════════════ -->
  <div class="nas-wizard-step" id="nas-wizard-step-10">
  <div class="nas-wizard-card">
    <div class="nas-wizard-card-header">
      <div class="nas-step-badge">Step 10 of 11</div>
      <h2><i class="fa-solid fa-user-circle"></i> Your Contact Details</h2>
      <p>We'll use these details to confirm your booking and send updates</p>
      <div class="nas-step-tip"><i class="fa-solid fa-lightbulb"></i> We'll only use these details to confirm your booking and send status updates — never for marketing without your consent.</div>
    </div>
    <div class="nas-wizard-card-body">
      <?php if ($user): ?>
      <div class="nas-logged-in-notice">
        <i class="fa-solid fa-circle-check" style="color:var(--nas-success)"></i>
        Logged in as <strong><?php echo esc_html($user->display_name); ?></strong> — details pre-filled below
      </div>
      <?php endif; ?>
      <div class="nas-form-grid">
        <div class="nas-field-group">
          <label class="nas-label" for="nas-client-name">Full Name <span class="nas-required">*</span></label>
          <input type="text" id="nas-client-name" class="nas-input" placeholder="Your full name"
            value="<?php echo $user ? esc_attr($user->display_name) : ''; ?>" autocomplete="name" required>
          <div class="nas-field-error" id="nas-err-name"></div>
        </div>
        <div class="nas-field-group">
          <label class="nas-label" for="nas-client-phone">Mobile Number <span class="nas-required">*</span></label>
          <div class="nas-phone-wrap">
            <span class="nas-phone-prefix">+91</span>
            <input type="tel" id="nas-client-phone" class="nas-input nas-input-phone" placeholder="10-digit mobile number"
              autocomplete="tel" maxlength="10" pattern="[6-9][0-9]{9}" required>
          </div>
          <div class="nas-field-error" id="nas-err-phone"></div>
        </div>
        <div class="nas-field-group">
          <label class="nas-label" for="nas-client-email">Email Address <span class="nas-required">*</span></label>
          <input type="email" id="nas-client-email" class="nas-input" placeholder="your@email.com"
            value="<?php echo $user ? esc_attr($user->user_email) : ''; ?>" autocomplete="email" required>
          <div class="nas-field-error" id="nas-err-email"></div>
        </div>
        <div class="nas-field-group">
          <label class="nas-label" for="nas-client-city">City</label>
          <input type="text" id="nas-client-city" class="nas-input" placeholder="Your city" autocomplete="address-level2">
        </div>
        <div class="nas-field-group">
          <label class="nas-label" for="nas-client-company">Company / Organisation <span class="nas-label-optional">(optional)</span></label>
          <input type="text" id="nas-client-company" class="nas-input" placeholder="Company name if applicable" autocomplete="organization">
        </div>
        <div class="nas-field-group">
          <label class="nas-label" for="nas-client-gst">GST Number <span class="nas-label-optional">(optional — for invoice)</span></label>
          <input type="text" id="nas-client-gst" class="nas-input" placeholder="15-digit GSTIN" maxlength="15" style="text-transform:uppercase">
        </div>
        <div class="nas-field-group nas-field-group-full">
          <label class="nas-label" for="nas-client-notes">Special Instructions <span class="nas-label-optional">(optional)</span></label>
          <textarea id="nas-client-notes" class="nas-textarea" rows="3" placeholder="Any specific instructions for the newspaper, formatting preferences, etc."></textarea>
        </div>
      </div>
      <!-- WhatsApp opt-in -->
      <label class="nas-checkbox-label">
        <input type="checkbox" id="nas-whatsapp-optin" checked class="nas-checkbox">
        <span>Send booking updates on WhatsApp</span>
      </label>
    </div>
    <div class="nas-wizard-nav">
      <button class="nas-btn nas-btn-secondary nas-wizard-prev" data-step="10"><i class="fa-solid fa-arrow-left"></i> Back</button>
      <button class="nas-btn nas-btn-primary nas-btn-lg nas-wizard-next" data-step="10" id="nas-btn-next-10">
        Review My Booking <i class="fa-solid fa-arrow-right"></i>
      </button>
    </div>
  </div>
  </div>

  <!-- ═══ STEP 11: REVIEW & SUBMIT ════════════════════════════════════════ -->
  <div class="nas-wizard-step" id="nas-wizard-step-11">
  <div class="nas-wizard-card">
    <div class="nas-wizard-card-header">
      <div class="nas-step-badge">Step 11 of 11</div>
      <h2><i class="fa-solid fa-circle-check"></i> Review & Submit</h2>
      <p>Please review your booking details carefully before submitting</p>
      <div class="nas-step-tip"><i class="fa-solid fa-lightbulb"></i> Once submitted, our team reviews every ad before it goes to print — you'll get a confirmation call or message if anything needs adjusting.</div>
    </div>
    <div class="nas-wizard-card-body">

      <!-- Summary sections -->
      <div class="nas-review-sections">
        <div class="nas-review-section">
          <div class="nas-review-section-header">
            <i class="fa-solid fa-clipboard-list"></i> Booking Summary
            <button class="nas-review-edit-btn" data-goto="1">Edit</button>
          </div>
          <div class="nas-review-grid">
            <div class="nas-review-item"><span>Category</span><strong id="rv-category">—</strong></div>
            <div class="nas-review-item"><span>City</span><strong id="rv-city">—</strong></div>
            <div class="nas-review-item"><span>Newspaper</span><strong id="rv-newspaper">—</strong></div>
            <div class="nas-review-item"><span>Edition</span><strong id="rv-edition">—</strong></div>
            <div class="nas-review-item"><span>Ad Type</span><strong id="rv-adtype">—</strong></div>
            <div class="nas-review-item"><span>Publish Date</span><strong id="rv-date">—</strong></div>
          </div>
        </div>

        <div class="nas-review-section">
          <div class="nas-review-section-header">
            <i class="fa-solid fa-pen-nib"></i> Ad Content
            <button class="nas-review-edit-btn" data-goto="7">Edit</button>
          </div>
          <div class="nas-review-ad-content" id="rv-ad-content-box">
            <div class="nas-review-ad-title" id="rv-ad-title" style="display:none"></div>
            <div class="nas-review-ad-text" id="rv-ad-text"></div>
          </div>
        </div>

        <div class="nas-review-section">
          <div class="nas-review-section-header">
            <i class="fa-solid fa-user"></i> Contact Details
            <button class="nas-review-edit-btn" data-goto="10">Edit</button>
          </div>
          <div class="nas-review-grid">
            <div class="nas-review-item"><span>Name</span><strong id="rv-name">—</strong></div>
            <div class="nas-review-item"><span>Phone</span><strong id="rv-phone">—</strong></div>
            <div class="nas-review-item"><span>Email</span><strong id="rv-email">—</strong></div>
          </div>
        </div>

        <div class="nas-review-section nas-review-section-pricing">
          <div class="nas-review-section-header">
            <i class="fa-solid fa-receipt"></i> Price Estimate
            <button class="nas-review-edit-btn" data-goto="8">Edit</button>
          </div>
          <div class="nas-pricing-summary">
            <div class="nas-pricing-row"><span>Base Amount</span><span id="rv-base">₹—</span></div>
            <div class="nas-pricing-row" id="rv-discount-row" style="display:none">
              <span>Discount</span><span id="rv-discount" style="color:var(--nas-success)">-₹—</span>
            </div>
            <div class="nas-pricing-row"><span>GST (18%)</span><span id="rv-gst">₹—</span></div>
            <div class="nas-pricing-row nas-pricing-total-row">
              <span>Total Payable</span><strong id="rv-total">₹—</strong>
            </div>
          </div>
          <p class="nas-review-price-note"><i class="fa-solid fa-circle-info"></i> Final pricing may vary slightly. Our team will share an exact quotation after reviewing your booking.</p>
        </div>
      </div>

      <!-- Terms -->
      <label class="nas-checkbox-label nas-terms-label">
        <input type="checkbox" id="nas-terms-check" class="nas-checkbox">
        <span>I have reviewed all details and agree to the <a href="#" target="_blank">Terms &amp; Conditions</a>. I understand that final pricing will be confirmed by the team.</span>
      </label>

      <!-- Submit error -->
      <div id="nas-submit-error" class="nas-alert nas-alert-error" style="display:none" role="alert" aria-live="assertive"></div>

      <!-- Trust badges — right at the point of commitment -->
      <div class="nas-submit-trust-row">
        <span><i class="fa-solid fa-lock"></i> Secure Submission</span>
        <span><i class="fa-solid fa-user-check"></i> Human-Reviewed Ad</span>
        <span><i class="fa-solid fa-headset"></i> Support on WhatsApp</span>
      </div>
    </div>
    <div class="nas-wizard-nav">
      <button class="nas-btn nas-btn-secondary nas-wizard-prev" data-step="11"><i class="fa-solid fa-arrow-left"></i> Back</button>
      <button class="nas-btn nas-btn-success nas-btn-xl" id="nas-submit-btn" disabled>
        <i class="fa-solid fa-paper-plane"></i> Submit Booking
        <div class="nas-btn-spinner" id="nas-submit-spinner" style="display:none"><i class="fa-solid fa-spinner fa-spin"></i></div>
      </button>
    </div>
  </div>
  </div>

</div><!-- /#nas-booking-wizard -->
</div><!-- /.nas-wizard-main -->

<!-- ══ SIDEBAR — live summary, trust & help ═══════════════════════════════ -->
<aside class="nas-wizard-sidebar" id="nas-wizard-sidebar" aria-label="Booking summary and help">

  <!-- Live booking summary -->
  <div class="nas-side-card nas-side-summary">
    <div class="nas-side-card-title"><i class="fa-solid fa-receipt"></i> Your Booking So Far</div>
    <div class="nas-side-progress">
      <div class="nas-side-progress-bar"><div class="nas-side-progress-fill" id="nas-side-progress-fill" style="width:9%"></div></div>
      <span id="nas-side-progress-label">Step 1 of 11 · Choose Category</span>
    </div>
    <ul class="nas-side-summary-list">
      <li><span><i class="fa-solid fa-tag"></i> Category</span><strong id="side-category">—</strong></li>
      <li><span><i class="fa-solid fa-location-dot"></i> City</span><strong id="side-city">—</strong></li>
      <li><span><i class="fa-solid fa-newspaper"></i> Newspaper</span><strong id="side-newspaper">—</strong></li>
      <li><span><i class="fa-solid fa-layer-group"></i> Ad Type</span><strong id="side-adtype">—</strong></li>
      <li><span><i class="fa-solid fa-calendar-days"></i> Publish Date</span><strong id="side-date">—</strong></li>
    </ul>
    <div class="nas-side-summary-total" id="nas-side-total-wrap" style="display:none">
      <span>Estimated Total</span>
      <strong id="side-total">₹—</strong>
    </div>
    <p class="nas-side-summary-hint" id="nas-side-hint">Your selections will appear here as you go — nothing is booked until you submit.</p>
  </div>

  <!-- Why book with us -->
  <div class="nas-side-card">
    <div class="nas-side-card-title"><i class="fa-solid fa-circle-check"></i> Why Advertisers Trust Us</div>
    <ul class="nas-side-points">
      <li><i class="fa-solid fa-check"></i> Verified circulation &amp; rate cards for every publication</li>
      <li><i class="fa-solid fa-check"></i> Human review of every ad before it goes to print</li>
      <li><i class="fa-solid fa-check"></i> Transparent GST-inclusive pricing, no hidden charges</li>
      <li><i class="fa-solid fa-check"></i> Booking updates by email &amp; WhatsApp at every stage</li>
    </ul>
  </div>

  <!-- Testimonial -->
  <div class="nas-side-card nas-side-quote">
    <i class="fa-solid fa-quote-left nas-side-quote-mark"></i>
    <p>“Booked a full-page ad for our store launch in under 10 minutes. The team called to confirm details the same day.”</p>
    <div class="nas-side-quote-author">— Verified Advertiser, Retail</div>
  </div>

  <!-- Help card -->
  <div class="nas-side-card nas-side-help">
    <div class="nas-side-card-title"><i class="fa-solid fa-headset"></i> Need Help Booking?</div>
    <p>Our ad specialists can help you pick the right newspaper, size and date.</p>
    <a href="<?php echo esc_url( nas_get_page_url('nas_page_contact','/contact/') ); ?>" class="nas-btn nas-btn-outline nas-btn-sm nas-side-help-btn">
      <i class="fa-solid fa-comment-dots"></i> Talk to Our Team
    </a>
  </div>

</aside>
</div><!-- /.nas-layout-grid -->
</div><!-- /.nas-wrap -->
</div><!-- /.nas-portal-page -->

<?php nas_portal_bottom_nav(); ?>

<!-- Hidden data for JS -->
<script id="nas-wizard-config" type="application/json">
{
  "ajaxUrl": "<?php echo esc_js(admin_url('admin-ajax.php')); ?>",
  "nonce": "<?php echo wp_create_nonce('nas_wizard_nonce'); ?>",
  "userId": <?php echo $user ? (int)$user->ID : 0; ?>,
  "userName": "<?php echo $user ? esc_js($user->display_name) : ''; ?>",
  "userEmail": "<?php echo $user ? esc_js($user->user_email) : ''; ?>",
  "gstRate": 18,
  "minAdvanceDays": 2,
  "currency": "₹",
  "confirmationUrl": "<?php echo esc_url( nas_get_page_url('nas_page_confirmation','/booking-confirmation/') ); ?>"
}
</script>

