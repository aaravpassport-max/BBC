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
<div class="nas-portal-page">
  <div class="nas-blog-hero">
    <span class="nas-blog-hero__eyebrow"><i class="fa-solid fa-newspaper"></i> Resources &amp; Insights</span>
    <h1>Newspaper Advertising Blog</h1>
    <p>Tips, guides, and industry insights to help you get the most from newspaper advertising in India.</p>
    <div class="nas-blog-search-bar">
      <input type="text" id="blog-search-input" placeholder="Search articles…" onkeydown="if(event.key==='Enter')blogSearch()">
      <button type="button" onclick="blogSearch()" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
    </div>
  </div>

  <?php nas_portal_block_trust_ribbon(); ?>

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

      <div class="nas-blog-cta-card">
        <div style="font-size:2rem;margin-bottom:10px"><i class="fa-solid fa-pen-nib" style="color:var(--nas-teal,#0D9488)"></i></div>
        <div style="font-size:0.9375rem;font-weight:700;color:var(--nas-text);margin-bottom:6px">Ready to book your ad?</div>
        <div style="font-size:0.8125rem;color:var(--nas-text-muted);margin-bottom:14px">Online booking in minutes. Expert support included.</div>
        <a href="<?php echo esc_url( $book_url ); ?>" class="nhp-btn nhp-btn--primary">Book Now <i class="fa-solid fa-arrow-right"></i></a>
      </div>
    </aside>
  </div>
</div>

<?php
nas_portal_block_accent_band( 'Ready to Advertise?', 'Stop reading about it — book your newspaper ad online in minutes with transparent pricing and expert support.' );
nas_portal_block_quick_links();
?>
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
