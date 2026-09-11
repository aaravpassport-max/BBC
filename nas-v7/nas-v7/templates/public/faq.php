<?php // faq.php
if ( ! defined( 'ABSPATH' ) ) exit;
$nonce = wp_create_nonce('nas_action');
$cfg   = \NAS\Core\Config::instance();
$color = $cfg->get('brand_primary_color','#1A3A5C');
$_nas_nav_links = [
    ['label' => 'Home',       'url' => home_url('/')],
    ['label' => 'Book an Ad', 'url' => nas_get_page_url('nas_page_booking','/book-newspaper-ad/')],
    ['label' => 'My Bookings','url' => nas_get_page_url('nas_page_client_dashboard','/client-dashboard/')],
];
$nav_links = $_nas_nav_links;
include NAS_PLUGIN_DIR . 'templates/partials/top-nav.php';
?>
<style>
.nas-faq-wrap{max-width:820px;margin:40px auto;padding:0 16px;font-family:'Inter','Segoe UI',sans-serif}
.nas-faq-wrap h2{color:<?php echo esc_js($color); ?>;font-size:26px;margin:0 0 6px}
.nas-faq-wrap>p{color:#64748b;margin:0 0 24px}
.nas-faq-search{position:relative;margin-bottom:20px}
.nas-faq-search input{width:100%;padding:12px 16px 12px 42px;border:1.5px solid #d0d7de;border-radius:10px;font-size:14px;box-sizing:border-box;transition:border .2s}
.nas-faq-search input:focus{outline:none;border-color:<?php echo esc_js($color); ?>}
.nas-faq-search svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8}
.nas-faq-cats{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
.nas-faq-cat{padding:6px 16px;border-radius:20px;border:1.5px solid #d0d7de;background:#fff;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;color:#374151}
.nas-faq-cat.active,.nas-faq-cat:hover{border-color:<?php echo esc_js($color); ?>;background:<?php echo esc_js($color); ?>;color:#fff}
.nas-accordion{border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}
.nas-acc-item{border-bottom:1px solid #e2e8f0}
.nas-acc-item:last-child{border-bottom:none}
.nas-acc-q{padding:16px 20px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:15px;color:#1e293b;user-select:none}
.nas-acc-q:hover{background:#f8fafc}
.nas-acc-q .arrow{transition:transform .25s;font-size:18px;color:<?php echo esc_js($color); ?>}
.nas-acc-q.open .arrow{transform:rotate(180deg)}
.nas-acc-a{display:none;padding:0 20px 16px;font-size:14px;color:#475569;line-height:1.7}
.nas-faq-cta{margin-top:24px;text-align:center;padding:20px;background:#f8fafc;border-radius:12px;font-size:14px;color:#64748b}
.nas-faq-cta a{color:<?php echo esc_js($color); ?>;font-weight:700;text-decoration:none}
</style>

<div class="nas-faq-wrap">
  <h2>Frequently Asked Questions</h2>
  <p>Find answers to common questions about our newspaper ad booking service.</p>

  <div class="nas-faq-search">
    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
    <input type="text" id="nas-faq-search" placeholder="Search questions…" oninput="nasFilterFaqs()">
  </div>

  <div class="nas-faq-cats" id="nas-faq-cats">
    <button class="nas-faq-cat active" onclick="nasSetCat(this,'')">All</button>
  </div>

  <div id="nas-accordion" class="nas-accordion">
    <div style="padding:20px;color:#94a3b8;font-size:14px">Loading questions…</div>
  </div>

  <div class="nas-faq-cta">Still have a question? <a href="<?php echo esc_url(get_permalink(get_option('nas_page_contact'))); ?>">Contact our support team →</a></div>
</div>

<script>
var NAS_FAQS_ALL = [];
var NAS_FAQ_CAT = '';

jQuery.post('<?php echo get_permalink() ?: home_url('/'); ?>', {action:'nas_get_faqs',nas_action:'1',nonce:'<?php echo $nonce; ?>'}, function(r) {
    if (!r.success) return;
    NAS_FAQS_ALL = r.data.faqs;
    // Build category list
    var cats = [...new Set(NAS_FAQS_ALL.map(f=>f.category))];
    var catEl = document.getElementById('nas-faq-cats');
    cats.forEach(function(c) {
        catEl.innerHTML += '<button class="nas-faq-cat" onclick="nasSetCat(this,\''+c+'\')">' + c + '</button>';
    });
    nasRenderFaqs(NAS_FAQS_ALL);
});

function nasSetCat(el, cat) {
    document.querySelectorAll('.nas-faq-cat').forEach(e=>e.classList.remove('active'));
    el.classList.add('active');
    NAS_FAQ_CAT = cat;
    nasFilterFaqs();
}

function nasFilterFaqs() {
    var q = document.getElementById('nas-faq-search').value.toLowerCase();
    var filtered = NAS_FAQS_ALL.filter(function(f) {
        var matchCat = !NAS_FAQ_CAT || f.category === NAS_FAQ_CAT;
        var matchQ   = !q || f.question.toLowerCase().includes(q) || f.answer.toLowerCase().includes(q);
        return matchCat && matchQ;
    });
    nasRenderFaqs(filtered);
}

function nasRenderFaqs(faqs) {
    var acc = document.getElementById('nas-accordion');
    if (!faqs.length) { acc.innerHTML = '<div style="padding:20px;color:#94a3b8;font-size:14px">No questions found.</div>'; return; }
    acc.innerHTML = faqs.map(function(f, i) {
        return '<div class="nas-acc-item"><div class="nas-acc-q" onclick="nasToggleAcc(this)">'+escHtml(f.question)+'<span class="arrow">▾</span></div><div class="nas-acc-a">'+f.answer+'</div></div>';
    }).join('');
}

function nasToggleAcc(el) {
    var isOpen = el.classList.contains('open');
    document.querySelectorAll('.nas-acc-q.open').forEach(function(e) {
        e.classList.remove('open');
        e.nextElementSibling.style.display='none';
    });
    if (!isOpen) { el.classList.add('open'); el.nextElementSibling.style.display='block'; }
}
function escHtml(t){return (t||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;')}
</script>
