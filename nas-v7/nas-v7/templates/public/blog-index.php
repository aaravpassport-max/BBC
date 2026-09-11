<?php
/**
 * NAS Blog Index — Enterprise v2
 * Sidebar layout, search, category filter, popular posts, newsletter signup
 */
if (!defined('ABSPATH')) exit;

$cfg       = \NAS\Core\Config::instance();
$brand     = $cfg->get('brand_name', get_bloginfo('name'));
$book_url  = nas_get_page_url('nas_page_booking','/book-newspaper-ad/');
$nonce     = wp_create_nonce('nas_action');
$db        = \NAS\Core\Database::instance();

// Get all categories and popular posts for sidebar
$categories_raw = $db->select("SELECT category, COUNT(*) as cnt FROM {$db->t('blog_posts')} WHERE status='published' GROUP BY category ORDER BY cnt DESC");
$popular_posts  = $db->select("SELECT id,title,slug,views,published_at FROM {$db->t('blog_posts')} WHERE status='published' ORDER BY views DESC LIMIT 5");

?>
<style>
.nas-blog-page{max-width:1200px;margin:0 auto;padding:48px 20px 80px;font-family:'Inter','Segoe UI',sans-serif}
.nas-blog-hero{background:linear-gradient(135deg,#1e1b4b,#3730a3);color:#fff;padding:64px 20px;text-align:center;margin-bottom:48px}
.nas-blog-hero h1{font-size:42px;font-weight:800;margin:0 0 12px;line-height:1.2}
.nas-blog-hero p{font-size:17px;opacity:.85;margin:0 0 24px;max-width:520px;margin-left:auto;margin-right:auto}
.nas-blog-search-bar{display:flex;max-width:480px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.2)}
.nas-blog-search-bar input{flex:1;border:none;padding:14px 18px;font-size:15px;outline:none;color:#0f172a}
.nas-blog-search-bar button{background:#6c47ff;border:none;padding:14px 20px;color:#fff;cursor:pointer;font-size:15px}
.nas-blog-layout{display:grid;grid-template-columns:1fr 320px;gap:36px}
@media(max-width:900px){.nas-blog-layout{grid-template-columns:1fr}}
.nas-blog-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:24px}
.nas-blog-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:16px;overflow:hidden;transition:all .2s;cursor:pointer;display:flex;flex-direction:column}
.nas-blog-card:hover{border-color:#6c47ff;box-shadow:0 8px 32px rgba(108,71,255,.12);transform:translateY(-2px)}
.nas-blog-card-img{height:180px;background:linear-gradient(135deg,#f5f3ff,#ede9fe);display:flex;align-items:center;justify-content:center;font-size:48px;overflow:hidden;flex-shrink:0}
.nas-blog-card-img img{width:100%;height:100%;object-fit:cover}
.nas-blog-card-body{padding:20px;flex:1;display:flex;flex-direction:column}
.nas-blog-card-cat{display:inline-block;background:#f5f3ff;color:#6c47ff;font-size:11px;font-weight:700;padding:3px 10px;border-radius:99px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px}
.nas-blog-card-title{font-size:16px;font-weight:700;color:#0f172a;line-height:1.4;margin:0 0 8px;flex:1}
.nas-blog-card-excerpt{font-size:13px;color:#64748b;line-height:1.6;margin:0 0 14px}
.nas-blog-card-footer{display:flex;align-items:center;justify-content:space-between;font-size:12px;color:#94a3b8;border-top:1px solid #f1f5f9;padding-top:12px}
.nas-blog-card-read{color:#6c47ff;font-weight:700;font-size:13px;text-decoration:none}
/* Sidebar */
.nas-blog-sidebar{display:flex;flex-direction:column;gap:20px}
.nas-sidebar-widget{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden}
.nas-sw-head{padding:14px 18px;border-bottom:1px solid #f1f5f9;font-size:13px;font-weight:700;color:#0f172a}
.nas-sw-body{padding:16px 18px}
.nas-cat-item{display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f8fafc;cursor:pointer;font-size:13px;color:#374151;transition:color .15s}
.nas-cat-item:last-child{border-bottom:none}
.nas-cat-item:hover{color:#6c47ff}
.nas-cat-count{background:#f1f5f9;color:#64748b;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px}
.nas-popular-item{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid #f8fafc;cursor:pointer}
.nas-popular-item:last-child{border-bottom:none}
.nas-popular-num{width:24px;height:24px;border-radius:6px;background:#f5f3ff;color:#6c47ff;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.nas-popular-title{font-size:13px;font-weight:600;color:#0f172a;line-height:1.4;flex:1}
.nas-popular-views{font-size:11px;color:#94a3b8;margin-top:2px}
.nas-newsletter-widget{background:linear-gradient(135deg,#6c47ff,#8b5cf6);border-radius:14px;padding:20px;color:#fff}
.nas-newsletter-widget h4{font-size:16px;font-weight:800;margin:0 0 6px}
.nas-newsletter-widget p{font-size:13px;opacity:.85;margin:0 0 14px;line-height:1.5}
.nas-newsletter-form{display:flex;flex-direction:column;gap:8px}
.nas-newsletter-form input{border:none;border-radius:8px;padding:10px 12px;font-size:13px;outline:none;width:100%;box-sizing:border-box}
.nas-newsletter-form button{background:rgba(255,255,255,.2);border:1.5px solid rgba(255,255,255,.5);color:#fff;padding:10px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700;transition:background .15s}
.nas-newsletter-form button:hover{background:rgba(255,255,255,.3)}
/* Filters */
.nas-blog-filters{display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap}
.nas-cat-filter{padding:7px 16px;border-radius:99px;border:1.5px solid #e2e8f0;background:#fff;font-size:13px;font-weight:600;color:#64748b;cursor:pointer;transition:all .15s}
.nas-cat-filter:hover{border-color:#6c47ff;color:#6c47ff}
.nas-cat-filter.active{background:#6c47ff;border-color:#6c47ff;color:#fff}
.nas-blog-load-more{display:block;width:100%;padding:14px;background:#fff;border:2px solid #6c47ff;color:#6c47ff;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;margin-top:24px;transition:all .15s}
.nas-blog-load-more:hover{background:#6c47ff;color:#fff}
.nas-blog-skeleton{animation:nasSkeleton 1.6s ease-in-out infinite;background:linear-gradient(90deg,#f0f4f8 25%,#e2e8f0 50%,#f0f4f8 75%);background-size:200% 100%;border-radius:8px}
</style>

<!-- Hero -->
<div class="nas-blog-hero">
  <div style="display:inline-block;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:99px;padding:5px 14px;font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;margin-bottom:14px">📰 Resources & Insights</div>
  <h1>Newspaper Advertising Blog</h1>
  <p>Tips, guides, and industry insights to help you get the most from newspaper advertising in India.</p>
  <!-- Search bar -->
  <div class="nas-blog-search-bar">
    <input type="text" id="blog-search-input" placeholder="Search articles…" onkeydown="if(event.key==='Enter')blogSearch()">
    <button onclick="blogSearch()"><i class="fa-solid fa-magnifying-glass"></i></button>
  </div>
</div>

<div class="nas-blog-page">
  <div class="nas-blog-layout">
    <!-- Main content -->
    <div>
      <!-- Category filters -->
      <div class="nas-blog-filters" id="blog-cat-filters">
        <button class="nas-cat-filter active" onclick="blogSetCat('',this)">All Articles</button>
        <?php foreach($categories_raw as $cat): ?>
        <button class="nas-cat-filter" onclick="blogSetCat('<?= esc_js($cat['category']) ?>',this)">
          <?= esc_html(ucwords(str_replace('-',' ',$cat['category']))) ?>
          <span style="opacity:.6;font-size:11px">(<?= (int)$cat['cnt'] ?>)</span>
        </button>
        <?php endforeach; ?>
      </div>

      <!-- Posts grid -->
      <div class="nas-blog-grid" id="blog-posts-grid">
        <!-- Skeleton loaders -->
        <?php for($i=0;$i<6;$i++): ?>
        <div class="nas-blog-card" style="pointer-events:none">
          <div class="nas-blog-card-img"><div class="nas-blog-skeleton" style="width:100%;height:100%"></div></div>
          <div class="nas-blog-card-body">
            <div class="nas-blog-skeleton" style="height:12px;width:60px;margin-bottom:10px"></div>
            <div class="nas-blog-skeleton" style="height:16px;width:90%;margin-bottom:6px"></div>
            <div class="nas-blog-skeleton" style="height:16px;width:70%;margin-bottom:12px"></div>
            <div class="nas-blog-skeleton" style="height:12px;width:100%;margin-bottom:4px"></div>
            <div class="nas-blog-skeleton" style="height:12px;width:80%"></div>
          </div>
        </div>
        <?php endfor; ?>
      </div>
      <div id="blog-no-results" style="display:none;text-align:center;padding:60px 20px;color:#94a3b8">
        <div style="font-size:48px;margin-bottom:16px">🔍</div>
        <h3 style="font-size:18px;font-weight:700;color:#374151;margin-bottom:8px">No articles found</h3>
        <p style="font-size:14px">Try a different search term or category.</p>
      </div>
      <button class="nas-blog-load-more" id="blog-load-more" style="display:none" onclick="blogLoadMore()">
        <i class="fa-solid fa-arrow-down"></i> Load More Articles
      </button>
    </div>

    <!-- Sidebar -->
    <aside class="nas-blog-sidebar">
      <!-- Categories -->
      <div class="nas-sidebar-widget">
        <div class="nas-sw-head">📂 Categories</div>
        <div class="nas-sw-body" style="padding:8px 18px">
          <?php foreach($categories_raw as $cat): ?>
          <div class="nas-cat-item" onclick="blogSetCat('<?= esc_js($cat['category']) ?>',null)">
            <?= esc_html(ucwords(str_replace('-',' ',$cat['category']))) ?>
            <span class="nas-cat-count"><?= (int)$cat['cnt'] ?></span>
          </div>
          <?php endforeach; ?>
          <?php if(empty($categories_raw)): ?>
          <div style="color:#94a3b8;font-size:13px;padding:8px 0">No categories yet.</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Popular Posts -->
      <?php if(!empty($popular_posts)): ?>
      <div class="nas-sidebar-widget">
        <div class="nas-sw-head">🔥 Most Popular</div>
        <div class="nas-sw-body" style="padding:8px 18px">
          <?php foreach($popular_posts as $i => $p): ?>
          <div class="nas-popular-item" onclick="window.location.href='<?= esc_js(home_url('/blog/'.($p['slug']??$p['id']))) ?>'">
            <div class="nas-popular-num"><?= $i+1 ?></div>
            <div>
              <div class="nas-popular-title"><?= esc_html($p['title']) ?></div>
              <div class="nas-popular-views"><i class="fa-solid fa-eye" style="font-size:10px"></i> <?= number_format((int)($p['views']??0)) ?> views</div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Newsletter -->
      <div class="nas-newsletter-widget">
        <h4>📬 Get Ad Tips Weekly</h4>
        <p>Join 5,000+ advertisers getting our weekly newspaper advertising insights.</p>
        <div class="nas-newsletter-form">
          <input type="email" id="newsletter-email" placeholder="Your email address">
          <button onclick="blogSubscribe()"><i class="fa-solid fa-paper-plane"></i> Subscribe Free</button>
          <div id="newsletter-msg" style="font-size:12px;opacity:.9;display:none"></div>
        </div>
      </div>

      <!-- Book CTA -->
      <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:20px;text-align:center">
        <div style="font-size:32px;margin-bottom:10px">📰</div>
        <div style="font-size:15px;font-weight:700;color:#0f172a;margin-bottom:6px">Ready to book your ad?</div>
        <div style="font-size:13px;color:#64748b;margin-bottom:14px">Online booking in minutes. Expert support included.</div>
        <a href="<?= esc_url($book_url) ?>" style="display:block;padding:12px;background:#6c47ff;color:#fff;border-radius:8px;text-decoration:none;font-size:14px;font-weight:700">
          <i class="fa-solid fa-pen-nib"></i> Book Now
        </a>
      </div>
    </aside>
  </div>
</div>

<script>
var BLOG_CONFIG = {
  ajaxUrl: '<?= esc_js(admin_url('admin-ajax.php')) ?>',
  nonce:   '<?= esc_js($nonce) ?>',
  blogUrl: '<?= esc_js(home_url('/blog/')) ?>',
};
var blogCurrentCat  = '';
var blogCurrentPage = 1;
var blogSearchQuery = '';
var blogLoading     = false;

var BLOG_ICONS = {
  'news':'📰','tips':'💡','guide':'📚','matrimonial':'💒','property':'🏠',
  'jobs':'💼','announcement':'📢','obituary':'🕯️','education':'🎓','business':'🏢',
  'default':'📝'
};

function getBlogIcon(cat) {
  return BLOG_ICONS[cat] || BLOG_ICONS.default;
}

function fdate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

function blogRenderPosts(posts, append) {
  var grid = document.getElementById('blog-posts-grid');
  var noRes = document.getElementById('blog-no-results');
  var loadMore = document.getElementById('blog-load-more');

  if (!append) grid.innerHTML = '';

  if (!posts || !posts.length) {
    if (!append) noRes.style.display = 'block';
    loadMore.style.display = 'none';
    return;
  }
  noRes.style.display = 'none';
  loadMore.style.display = posts.length >= 9 ? 'block' : 'none';

  var html = posts.map(function(p) {
    var imgHtml = p.featured_image
      ? '<img src="'+esc(p.featured_image)+'" alt="'+esc(p.title)+'" loading="lazy">'
      : '<span>'+getBlogIcon(p.category)+'</span>';
    var excerpt = p.excerpt ? esc(p.excerpt).substring(0,120)+'…' : '';
    return '<div class="nas-blog-card" onclick="window.location.href=\''+BLOG_CONFIG.blogUrl+esc(p.slug)+'\'">'+
      '<div class="nas-blog-card-img">'+imgHtml+'</div>'+
      '<div class="nas-blog-card-body">'+
      '<span class="nas-blog-card-cat">'+esc(p.category||'news')+'</span>'+
      '<h3 class="nas-blog-card-title">'+esc(p.title)+'</h3>'+
      (excerpt?'<p class="nas-blog-card-excerpt">'+excerpt+'</p>':'')+
      '<div class="nas-blog-card-footer">'+
      '<span>'+fdate(p.published_at)+'</span>'+
      '<div style="display:flex;align-items:center;gap:8px">'+
      '<span><i class="fa-solid fa-eye" style="font-size:10px"></i> '+parseInt(p.views||0).toLocaleString()+'</span>'+
      '<a class="nas-blog-card-read" href="'+BLOG_CONFIG.blogUrl+esc(p.slug)+'">Read →</a>'+
      '</div></div></div></div>';
  }).join('');

  grid.insertAdjacentHTML('beforeend', html);
}

function blogLoad(append) {
  if (blogLoading) return;
  blogLoading = true;
  var btn = document.getElementById('blog-load-more');
  if (btn && append) { btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Loading…'; btn.disabled = true; }

  var fd = new FormData();
  fd.append('action','nas_get_blog_posts');
  fd.append('nonce', BLOG_CONFIG.nonce);
  fd.append('page', blogCurrentPage);
  fd.append('category', blogCurrentCat);
  if (blogSearchQuery) fd.append('search', blogSearchQuery);

  fetch(BLOG_CONFIG.ajaxUrl, {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(function(res) {
      blogLoading = false;
      if (btn) { btn.innerHTML = '<i class="fa-solid fa-arrow-down"></i> Load More Articles'; btn.disabled = false; }
      blogRenderPosts(res.data?.posts || [], append);
    })
    .catch(function() {
      blogLoading = false;
      if (btn) { btn.innerHTML = '<i class="fa-solid fa-arrow-down"></i> Load More Articles'; btn.disabled = false; }
    });
}

window.blogSetCat = function(cat, btn) {
  blogCurrentCat  = cat;
  blogCurrentPage = 1;
  blogSearchQuery = '';
  document.getElementById('blog-search-input').value = '';
  document.querySelectorAll('.nas-cat-filter').forEach(function(b){b.classList.remove('active');});
  if (btn) btn.classList.add('active');
  else {
    // Triggered from sidebar — activate matching filter button
    document.querySelectorAll('.nas-cat-filter').forEach(function(b) {
      if (cat && b.textContent.trim().toLowerCase().startsWith(cat.toLowerCase())) b.classList.add('active');
    });
  }
  blogLoad(false);
};

window.blogSearch = function() {
  blogSearchQuery = document.getElementById('blog-search-input').value.trim();
  blogCurrentPage = 1;
  blogCurrentCat  = '';
  document.querySelectorAll('.nas-cat-filter').forEach(function(b){b.classList.remove('active');});
  document.querySelectorAll('.nas-cat-filter')[0]?.classList.add('active');
  blogLoad(false);
};

window.blogLoadMore = function() {
  blogCurrentPage++;
  blogLoad(true);
};

window.blogSubscribe = function() {
  var email = document.getElementById('newsletter-email').value.trim();
  var msg   = document.getElementById('newsletter-msg');
  if (!email || !email.includes('@')) { msg.textContent='Please enter a valid email.'; msg.style.display='block'; return; }
  // Store subscription — simple wp_options based for now
  var fd = new FormData();
  fd.append('action','nas_subscribe_newsletter');
  fd.append('email', email);
  fd.append('nonce', BLOG_CONFIG.nonce);
  fetch(BLOG_CONFIG.ajaxUrl,{method:'POST',body:fd}).then(r=>r.json()).then(function(r){
    msg.textContent = r.success ? '✅ Subscribed! Check your inbox.' : (r.data?.message||'Already subscribed.');
    msg.style.display = 'block';
    document.getElementById('newsletter-email').value = '';
  }).catch(function(){msg.textContent='Please try again.';msg.style.display='block';});
};

// Initial load
document.addEventListener('DOMContentLoaded', function() { blogLoad(false); });
</script>
