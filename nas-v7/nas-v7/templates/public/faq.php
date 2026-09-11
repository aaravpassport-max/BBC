<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$nonce = wp_create_nonce( 'nas_action' );
$cfg   = \NAS\Core\Config::instance();
$contact_url = get_permalink( get_option( 'nas_page_contact' ) ) ?: home_url( '/contact-us/' );
$walink = $cfg->get( 'brand_whatsapp', '' )
    ? 'https://wa.me/' . preg_replace( '/[^0-9]/', '', $cfg->get( 'brand_whatsapp', '' ) )
    : '';
?>

<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">Help Center</span>
      <h1>Frequently Asked <span>Questions</span></h1>
      <p>Find answers about booking newspaper ads, payments, publication timelines, material requirements, and order tracking.</p>
    </div>
  </div>

  <?php nas_portal_block_trust_ribbon(); ?>

  <section class="nas-portal-section nas-portal-section--after-ribbon">
    <div class="nhp-container">
      <div class="nas-faq-layout">
      <aside class="nas-faq-sidebar">
        <div class="nas-faq-help-card">
          <div class="nas-faq-help-card__icon"><i class="fa-solid fa-headset"></i></div>
          <h3>Need personal help?</h3>
          <p>Our support team can assist with newspaper selection, ad formatting, and rate quotes.</p>
          <a href="<?php echo esc_url( $contact_url ); ?>" class="nas-btn nas-btn-primary">Contact Support</a>
          <?php if ( $walink ) : ?>
          <a href="<?php echo esc_url( $walink ); ?>" class="nas-btn nas-btn-secondary" style="margin-top:10px;display:flex;justify-content:center" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
          <?php endif; ?>
        </div>
        <div class="nas-faq-search-wrap">
          <i class="fa-solid fa-search"></i>
          <input type="search" id="nas-faq-search" placeholder="Search questions…" oninput="nasFilterFaqs()">
        </div>
        <div class="nas-faq-cats" id="nas-faq-cats">
          <button type="button" class="nas-faq-cat active" onclick="nasSetCat(this,'')">All</button>
        </div>
      </aside>

      <div>
        <div id="nas-accordion" class="nas-faq-list">
          <div style="padding:24px;color:#94a3b8;text-align:center">Loading questions…</div>
        </div>
        <div class="nas-faq-cta-bar">Can't find your answer? <a href="<?php echo esc_url( $contact_url ); ?>">Talk to our support team →</a></div>
      </div>
    </div>
    </div>
  </section>

  <?php nas_portal_block_quick_links(); ?>

  <?php nas_portal_block_accent_band(
      'Still Have Questions?',
      'Our support team can help with newspaper selection, ad formatting, and rate quotes.',
      $contact_url,
      'Contact Support'
  ); ?>
</div>

<script>
var NAS_FAQS_ALL = [];
var NAS_FAQ_CAT = '';

jQuery.post('<?php echo esc_url( get_permalink() ?: home_url( '/' ) ); ?>', {action:'nas_get_faqs',nas_action:'1',nonce:'<?php echo esc_js( $nonce ); ?>'}, function(r) {
    if (!r.success) return;
    NAS_FAQS_ALL = r.data.faqs;
    var cats = [...new Set(NAS_FAQS_ALL.map(function(f){ return f.category; }).filter(Boolean))];
    var catEl = document.getElementById('nas-faq-cats');
    cats.forEach(function(c) {
        catEl.innerHTML += '<button type="button" class="nas-faq-cat" onclick="nasSetCat(this,\''+c.replace(/'/g,"\\'")+'\')">' + c + '</button>';
    });
    nasRenderFaqs(NAS_FAQS_ALL);
});

function nasSetCat(el, cat) {
    document.querySelectorAll('.nas-faq-cat').forEach(function(e){ e.classList.remove('active'); });
    el.classList.add('active');
    NAS_FAQ_CAT = cat;
    nasFilterFaqs();
}

function nasFilterFaqs() {
    var q = (document.getElementById('nas-faq-search').value || '').toLowerCase();
    var filtered = NAS_FAQS_ALL.filter(function(f) {
        var matchCat = !NAS_FAQ_CAT || f.category === NAS_FAQ_CAT;
        var matchQ   = !q || f.question.toLowerCase().indexOf(q) !== -1 || f.answer.toLowerCase().indexOf(q) !== -1;
        return matchCat && matchQ;
    });
    nasRenderFaqs(filtered);
}

function nasRenderFaqs(faqs) {
    var acc = document.getElementById('nas-accordion');
    if (!faqs.length) {
        acc.innerHTML = '<div style="padding:32px;text-align:center;color:#94a3b8">No questions match your search.</div>';
        return;
    }
    acc.innerHTML = faqs.map(function(f, i) {
        var num = String(i + 1).padStart(2, '0');
        var ci = i % 6;
        return '<div class="nas-faq-item nas-faq-item--c'+ci+'">' +
            '<button type="button" class="nas-faq-q" onclick="nasToggleAcc(this)">' +
            '<span class="nas-faq-q__num">'+num+'</span>' +
            '<span class="nas-faq-q__text">'+escHtml(f.question)+'</span>' +
            '<i class="fa-solid fa-chevron-down nas-faq-q__chevron"></i>' +
            '</button>' +
            '<div class="nas-faq-a">'+f.answer+'</div></div>';
    }).join('');
}

function nasToggleAcc(el) {
    var item = el.closest('.nas-faq-item');
    var wasOpen = item.classList.contains('is-open');
    document.querySelectorAll('.nas-faq-item.is-open').forEach(function(e) { e.classList.remove('is-open'); });
    if (!wasOpen) item.classList.add('is-open');
}

function escHtml(t){ return (t||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;'); }
</script>
