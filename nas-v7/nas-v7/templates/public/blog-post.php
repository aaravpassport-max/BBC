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

?>
<div class="nas-portal-page">
<?php if(!empty($post['seo_title'])): ?>
<title><?php echo esc_html($post['seo_title']); ?> — <?php echo esc_html($brand); ?></title>
<?php endif; ?>
<meta property="og:title"       content="<?php echo esc_attr($post['seo_title']??$post['title']); ?>">
<meta property="og:description" content="<?php echo esc_attr($post['seo_desc']??$post['excerpt']??''); ?>">
<meta property="og:url"         content="<?php echo esc_url($post_url); ?>">
<?php if(!empty($post['featured_image'])): ?>
<meta property="og:image" content="<?php echo esc_url($post['featured_image']); ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">

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

  <div class="nas-post-cta-box">
    <h3>Ready to book a newspaper ad?</h3>
    <p>Stop reading about it — our platform makes newspaper advertising in India fast, easy, and affordable.</p>
    <a href="<?php echo esc_url( $book_url ); ?>" class="nhp-btn nhp-btn--white nhp-btn--lg">
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

  <div style="margin-top:40px;text-align:center">
    <a href="<?php echo esc_url( $blog_url ); ?>" class="nhp-btn nhp-btn--ghost" style="border:2px solid var(--nas-primary);color:var(--nas-primary)">
      <i class="fa-solid fa-arrow-left"></i> Back to All Articles
    </a>
  </div>
</div>

<?php nas_portal_block_quick_links(); ?>
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
  var toc = '<div class="nas-post-toc"><div class="nas-post-toc__title"><i class="fa-solid fa-list"></i> In This Article</div><ol>';
  headings.forEach(function(h, i) {
    h.id = 'section-' + (i+1);
    toc += '<li><a href="#section-'+(i+1)+'">'+h.textContent+'</a></li>';
  });
  toc += '</ol></div>';
  document.getElementById('post-content').insertAdjacentHTML('afterbegin', toc);
})();
</script>
