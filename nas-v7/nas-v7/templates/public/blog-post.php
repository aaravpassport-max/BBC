<?php
/**
 * NAS Blog Post — Enterprise v2
 * Author bio, social share, related posts, reading time, breadcrumbs
 */
if (!defined('ABSPATH')) exit;

$db    = \NAS\Core\Database::instance();
$cfg   = \NAS\Core\Config::instance();
$brand = $cfg->get('brand_name', get_bloginfo('name'));
$slug  = get_query_var('nas_blog_slug') ?: sanitize_text_field($_GET['slug'] ?? '');
$nonce = wp_create_nonce('nas_action');

if (!$slug) { wp_safe_redirect(home_url('/blog/')); exit; }

// Load post
$post = $db->row("SELECT * FROM {$db->t('blog_posts')} WHERE slug=%s AND status='published'", $slug);
if (!$post) { wp_safe_redirect(home_url('/blog/')); exit; }

// Increment views
$db->update($db->t('blog_posts'), ['views' => (int)($post['views']??0)+1], ['id' => $post['id']]);

// Related posts
$related = $db->select(
    "SELECT id,title,slug,excerpt,featured_image,category,published_at FROM {$db->t('blog_posts')}
     WHERE status='published' AND category=%s AND id!=%d ORDER BY published_at DESC LIMIT 3",
    $post['category']??'news', $post['id']
) ?: $db->select("SELECT id,title,slug,excerpt,featured_image,category,published_at FROM {$db->t('blog_posts')} WHERE status='published' AND id!=%d ORDER BY views DESC LIMIT 3", $post['id']) ?: [];

// Reading time estimate
$word_count   = str_word_count(strip_tags($post['content']??''));
$reading_time = max(1, ceil($word_count / 200));

// Author info
$author_id   = (int)($post['author_id']??0);
$author_name = $author_id ? get_userdata($author_id)?->display_name ?? $brand : $brand;
$author_bio  = $author_id ? get_user_meta($author_id,'description',true) : 'Expert columnist at '.$brand;

$book_url    = nas_get_page_url('nas_page_booking','/book-newspaper-ad/');
$blog_url    = home_url('/blog/');
$post_url    = home_url('/blog/'.$post['slug']);
$wa_share    = 'https://wa.me/?text='.rawurlencode($post['title'].' '.$post_url);
$tw_share    = 'https://twitter.com/intent/tweet?text='.rawurlencode($post['title']).'&url='.rawurlencode($post_url);
$li_share    = 'https://www.linkedin.com/sharing/share-offsite/?url='.rawurlencode($post_url);
$fb_share    = 'https://www.facebook.com/sharer/sharer.php?u='.rawurlencode($post_url);

$pub_date    = $post['published_at'] ? date('d F Y', strtotime($post['published_at'])) : '';

$nav_links = [
    ['label'=>'Home', 'url'=>home_url('/')],
    ['label'=>'Blog', 'url'=>$blog_url],
    ['label'=>esc_html(substr($post['title'],0,30)).'…', 'url'=>'#','active'=>true],
];
include NAS_PLUGIN_DIR . 'templates/partials/top-nav.php';
?>
<link rel="stylesheet" href="<?= NAS_ASSETS ?>css/nas-enterprise.css">
<!-- SEO meta for this post -->
<?php if(!empty($post['seo_title'])): ?>
<title><?= esc_html($post['seo_title']) ?> — <?= esc_html($brand) ?></title>
<?php endif; ?>
<meta property="og:title"       content="<?= esc_attr($post['seo_title']??$post['title']) ?>">
<meta property="og:description" content="<?= esc_attr($post['seo_desc']??$post['excerpt']??'') ?>">
<meta property="og:url"         content="<?= esc_url($post_url) ?>">
<?php if(!empty($post['featured_image'])): ?>
<meta property="og:image" content="<?= esc_url($post['featured_image']) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<style>
.nas-post-wrap{max-width:780px;margin:0 auto;padding:40px 20px 80px;font-family:'Inter','Segoe UI',sans-serif}
.nas-post-breadcrumb{display:flex;align-items:center;gap:6px;font-size:12px;color:#94a3b8;margin-bottom:20px;flex-wrap:wrap}
.nas-post-breadcrumb a{color:#6c47ff;text-decoration:none}
.nas-post-breadcrumb i{font-size:10px}
.nas-post-header{margin-bottom:32px}
.nas-post-cat-badge{display:inline-block;background:#f5f3ff;color:#6c47ff;font-size:11px;font-weight:700;padding:4px 12px;border-radius:99px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px}
.nas-post-title{font-size:36px;font-weight:800;color:#0f172a;line-height:1.25;margin:0 0 16px}
.nas-post-meta{display:flex;align-items:center;gap:16px;flex-wrap:wrap;font-size:13px;color:#64748b;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid #e2e8f0}
.nas-post-meta-item{display:flex;align-items:center;gap:5px}
.nas-post-featured-img{width:100%;max-height:440px;object-fit:cover;border-radius:14px;margin-bottom:28px}
.nas-post-content{font-size:16px;line-height:1.8;color:#374151}
.nas-post-content h2{font-size:24px;font-weight:800;color:#0f172a;margin:36px 0 14px}
.nas-post-content h3{font-size:19px;font-weight:700;color:#0f172a;margin:28px 0 10px}
.nas-post-content p{margin:0 0 20px}
.nas-post-content ul,.nas-post-content ol{margin:0 0 20px;padding-left:24px}
.nas-post-content li{margin-bottom:8px}
.nas-post-content blockquote{border-left:4px solid #6c47ff;margin:24px 0;padding:16px 20px;background:#f5f3ff;border-radius:0 10px 10px 0;font-style:italic;color:#5b21b6}
.nas-post-content a{color:#6c47ff;text-decoration:underline}
.nas-post-content img{max-width:100%;border-radius:10px;margin:12px 0}
.nas-post-content table{width:100%;border-collapse:collapse;margin:20px 0;font-size:14px}
.nas-post-content th{background:#f5f3ff;padding:10px 14px;text-align:left;font-weight:700;border:1px solid #e2e8f0}
.nas-post-content td{padding:10px 14px;border:1px solid #e2e8f0}
/* Share bar */
.nas-share-bar{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:14px;padding:18px 24px;margin:36px 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.nas-share-label{font-size:13px;font-weight:700;color:#374151}
.nas-share-btns{display:flex;gap:8px;flex-wrap:wrap}
.nas-share-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;transition:opacity .15s}
.nas-share-btn:hover{opacity:.85}
.nas-share-wa{background:#25D366;color:#fff}
.nas-share-tw{background:#000;color:#fff}
.nas-share-li{background:#0077B5;color:#fff}
.nas-share-fb{background:#1877F2;color:#fff}
.nas-share-copy{background:#f1f5f9;color:#374151;cursor:pointer;border:none;font-family:inherit;font-size:12px;font-weight:700}
/* Author bio */
.nas-author-bio{display:flex;align-items:flex-start;gap:16px;background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:20px;margin:36px 0}
.nas-author-avatar{width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#6c47ff,#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;color:#fff;flex-shrink:0}
.nas-author-name{font-size:15px;font-weight:700;color:#0f172a;margin-bottom:4px}
.nas-author-role{font-size:12px;color:#6c47ff;font-weight:600;margin-bottom:8px}
.nas-author-desc{font-size:13px;color:#64748b;line-height:1.6}
/* Tags */
.nas-post-tags{display:flex;align-items:center;gap:8px;margin:20px 0;flex-wrap:wrap}
.nas-post-tag{display:inline-block;background:#f1f5f9;color:#475569;font-size:12px;padding:4px 10px;border-radius:6px;cursor:pointer;transition:all .15s}
.nas-post-tag:hover{background:#f5f3ff;color:#6c47ff}
/* Related posts */
.nas-related-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-top:20px}
.nas-related-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;overflow:hidden;cursor:pointer;transition:all .2s}
.nas-related-card:hover{border-color:#6c47ff;box-shadow:0 4px 16px rgba(108,71,255,.1);transform:translateY(-1px)}
.nas-related-img{height:120px;background:linear-gradient(135deg,#f5f3ff,#ede9fe);display:flex;align-items:center;justify-content:center;font-size:32px;overflow:hidden}
.nas-related-img img{width:100%;height:100%;object-fit:cover}
.nas-related-body{padding:14px}
.nas-related-title{font-size:14px;font-weight:700;color:#0f172a;line-height:1.4;margin-bottom:6px}
.nas-related-date{font-size:11px;color:#94a3b8}
/* CTA in post */
.nas-post-cta-box{background:linear-gradient(135deg,#1e1b4b,#3730a3);color:#fff;border-radius:14px;padding:28px;margin:36px 0;text-align:center}
.nas-post-cta-box h3{font-size:20px;font-weight:800;margin:0 0 8px}
.nas-post-cta-box p{font-size:14px;opacity:.85;margin:0 0 18px;line-height:1.6}
@media(max-width:600px){.nas-post-title{font-size:26px!important}.nas-share-bar{flex-direction:column}}
</style>

<div class="nas-post-wrap">
  <!-- Breadcrumb -->
  <div class="nas-post-breadcrumb">
    <a href="<?= esc_url(home_url('/')) ?>">Home</a>
    <i class="fa-solid fa-chevron-right"></i>
    <a href="<?= esc_url($blog_url) ?>">Blog</a>
    <i class="fa-solid fa-chevron-right"></i>
    <span><?= esc_html(substr($post['title'],0,40)).'…' ?></span>
  </div>

  <!-- Post header -->
  <header class="nas-post-header">
    <span class="nas-post-cat-badge"><?= esc_html(ucwords($post['category']??'news')) ?></span>
    <h1 class="nas-post-title"><?= esc_html($post['title']) ?></h1>
    <div class="nas-post-meta">
      <span class="nas-post-meta-item"><i class="fa-solid fa-user"></i> <?= esc_html($author_name) ?></span>
      <?php if($pub_date): ?>
      <span class="nas-post-meta-item"><i class="fa-regular fa-calendar"></i> <?= esc_html($pub_date) ?></span>
      <?php endif; ?>
      <span class="nas-post-meta-item"><i class="fa-regular fa-clock"></i> <?= $reading_time ?> min read</span>
      <span class="nas-post-meta-item"><i class="fa-solid fa-eye"></i> <?= number_format((int)($post['views']??0)) ?> views</span>
    </div>
  </header>

  <!-- Featured image -->
  <?php if(!empty($post['featured_image'])): ?>
  <img src="<?= esc_url($post['featured_image']) ?>" alt="<?= esc_attr($post['title']) ?>" class="nas-post-featured-img">
  <?php endif; ?>

  <!-- Post content -->
  <div class="nas-post-content" id="post-content">
    <?php echo wp_kses_post($post['content']??''); ?>
  </div>

  <!-- Tags -->
  <?php if(!empty($post['tags'])): ?>
  <div class="nas-post-tags">
    <span style="font-size:12px;font-weight:700;color:#94a3b8">Tags:</span>
    <?php foreach(explode(',', $post['tags']) as $tag): $tag=trim($tag); if(!$tag) continue; ?>
    <span class="nas-post-tag" onclick="window.location.href='<?= esc_url($blog_url) ?>?search=<?= urlencode($tag) ?>'">
      #<?= esc_html($tag) ?>
    </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Mid-post CTA -->
  <div class="nas-post-cta-box">
    <h3>Ready to book a newspaper ad?</h3>
    <p>Stop reading about it — our platform makes newspaper advertising in India fast, easy, and affordable.</p>
    <a href="<?= esc_url($book_url) ?>" style="display:inline-flex;align-items:center;gap:8px;padding:12px 28px;background:#fff;color:#6c47ff;border-radius:8px;text-decoration:none;font-size:14px;font-weight:800">
      <i class="fa-solid fa-pen-nib"></i> Book Your Ad Now
    </a>
  </div>

  <!-- Share bar -->
  <div class="nas-share-bar">
    <span class="nas-share-label">📤 Share this article</span>
    <div class="nas-share-btns">
      <a href="<?= esc_url($wa_share) ?>" target="_blank" rel="noopener" class="nas-share-btn nas-share-wa"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
      <a href="<?= esc_url($tw_share) ?>" target="_blank" rel="noopener" class="nas-share-btn nas-share-tw"><i class="fa-brands fa-twitter"></i> X/Twitter</a>
      <a href="<?= esc_url($li_share) ?>" target="_blank" rel="noopener" class="nas-share-btn nas-share-li"><i class="fa-brands fa-linkedin"></i> LinkedIn</a>
      <a href="<?= esc_url($fb_share) ?>" target="_blank" rel="noopener" class="nas-share-btn nas-share-fb"><i class="fa-brands fa-facebook"></i> Facebook</a>
      <button class="nas-share-btn nas-share-copy" onclick="postCopyLink(this)" aria-label="Copy link">
        <i class="fa-solid fa-link"></i> Copy Link
      </button>
    </div>
  </div>

  <!-- Author bio -->
  <div class="nas-author-bio">
    <div class="nas-author-avatar"><?= strtoupper(substr($author_name,0,1)) ?></div>
    <div>
      <div class="nas-author-name"><?= esc_html($author_name) ?></div>
      <div class="nas-author-role">Newspaper Advertising Expert — <?= esc_html($brand) ?></div>
      <div class="nas-author-desc"><?= esc_html($author_bio ?: 'Expert in newspaper advertising across India. Helping businesses reach their audience through print media since '.($cfg->get('founded_year','2018')).'.') ?></div>
    </div>
  </div>

  <!-- Related posts -->
  <?php if(!empty($related)): ?>
  <div style="margin-top:48px">
    <h2 style="font-size:20px;font-weight:800;color:#0f172a;margin:0 0 4px">Related Articles</h2>
    <p style="font-size:14px;color:#64748b;margin:0 0 20px">You might also find these useful</p>
    <div class="nas-related-grid">
      <?php foreach($related as $r):
        $cat_icons = ['news'=>'📰','tips'=>'💡','guide'=>'📚','matrimonial'=>'💒','property'=>'🏠','jobs'=>'💼','default'=>'📝'];
        $icon = $cat_icons[$r['category']] ?? $cat_icons['default'];
      ?>
      <div class="nas-related-card" onclick="window.location.href='<?= esc_js(home_url('/blog/'.$r['slug'])) ?>'">
        <div class="nas-related-img">
          <?php if(!empty($r['featured_image'])): ?>
          <img src="<?= esc_url($r['featured_image']) ?>" alt="<?= esc_attr($r['title']) ?>" loading="lazy">
          <?php else: ?>
          <span><?= $icon ?></span>
          <?php endif; ?>
        </div>
        <div class="nas-related-body">
          <div class="nas-related-title"><?= esc_html($r['title']) ?></div>
          <div class="nas-related-date"><?= $r['published_at'] ? date('d M Y', strtotime($r['published_at'])) : '' ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Back to blog -->
  <div style="margin-top:40px;text-align:center">
    <a href="<?= esc_url($blog_url) ?>" style="display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border:2px solid #6c47ff;color:#6c47ff;border-radius:10px;text-decoration:none;font-size:14px;font-weight:700;transition:all .15s">
      <i class="fa-solid fa-arrow-left"></i> Back to All Articles
    </a>
  </div>
</div>

<script>
function postCopyLink(btn) {
  navigator.clipboard?.writeText('<?= esc_js($post_url) ?>').then(function() {
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
    btn.style.background = '#d1fae5';
    btn.style.color = '#065f46';
    setTimeout(function(){btn.innerHTML='<i class="fa-solid fa-link"></i> Copy Link';btn.style.background='';btn.style.color='';}, 2000);
  });
}
// Add smooth scroll to anchor links in content
document.querySelectorAll('#post-content a[href^="#"]').forEach(function(a) {
  a.addEventListener('click', function(e) {
    var target = document.querySelector(a.getAttribute('href'));
    if (target) { e.preventDefault(); target.scrollIntoView({behavior:'smooth',block:'start'}); }
  });
});
// Auto-generate table of contents if h2 tags exist
(function() {
  var headings = document.querySelectorAll('#post-content h2');
  if (headings.length < 3) return;
  var toc = '<div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:20px 24px;margin-bottom:28px"><div style="font-size:13px;font-weight:700;color:#374151;margin-bottom:12px">📋 In This Article</div><ol style="margin:0;padding-left:20px">';
  headings.forEach(function(h, i) {
    h.id = 'section-' + (i+1);
    toc += '<li style="margin-bottom:6px"><a href="#section-'+(i+1)+'" style="color:#6c47ff;text-decoration:none;font-size:13px">'+h.textContent+'</a></li>';
  });
  toc += '</ol></div>';
  document.getElementById('post-content').insertAdjacentHTML('afterbegin', toc);
})();
</script>
