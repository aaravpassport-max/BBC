<?php
/**
 * S2NRI Premium Blog Template
 *
 * Serves /blog and /blog/:slug as fully PHP-rendered premium pages.
 * Intercepts these paths BEFORE the React SPA so we can provide a
 * rich, SEO-optimised blog experience without compiling new JS.
 *
 * Layout:
 *   /blog        → Featured post hero + filtered grid + sidebar
 *   /blog/:slug  → Full article with ToC, reading time, author, related posts
 */

namespace S2NRI;

defined( 'ABSPATH' ) || exit;

class Blog {

    /** Returns true when the current path is a blog page we should handle. */
    public static function isBlogPath( string $path ): bool {
        return $path === '/blog'
            || preg_match( '#^/blog/[^/?#]+#', $path );
    }

    /** Main entry point from SEO.php::render() */
    public static function render( string $path, array $settings, array $config ): void {
        if ( preg_match( '#^/blog/([^/?#]+)#', $path, $m ) ) {
            self::renderDetail( $m[1], $settings, $config );
        } else {
            self::renderListing( $settings, $config );
        }
    }

    // ── Blog Listing Page ─────────────────────────────────────────────────────

    private static function renderListing( array $settings, array $config ): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $design = \S2NRI\Design\DesignSystem::resolve( [ 'page_type' => 'blog' ] );
        $brand  = esc_attr( $design['colors']['primary'] ?? ( $settings['primary_color'] ?? '#4A6FA5' ) );
        $site   = esc_html( $settings['platform_name'] ?? 'Services2NRI' );
        $design_head = \S2NRI\Design\DesignSystem::renderFontLinks( $design )
            . '<style id="s2-design-system">' . \S2NRI\Design\DesignSystem::renderInlineCss( '/blog' ) . '</style>';

        $cat_filter = sanitize_text_field( $_GET['category'] ?? '' );
        $search_q   = sanitize_text_field( $_GET['s'] ?? '' );
        $page_num   = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
        $per_page   = 9;

        $where = "WHERE is_published = 1";
        $params = [];
        if ( $cat_filter ) {
            $where   .= " AND category = %s";
            $params[] = $cat_filter;
        }
        if ( $search_q ) {
            $where   .= " AND (title LIKE %s OR excerpt LIKE %s OR content LIKE %s)";
            $like     = '%' . $wpdb->esc_like( $search_q ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $total_sql  = "SELECT COUNT(*) FROM `{$p}s2nri_blog_posts` {$where}";
        if ( $params ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) );
        } else {
            $total = (int) $wpdb->get_var( $total_sql );
        }
        $offset = ( $page_num - 1 ) * $per_page;

        $list_sql = "SELECT id, title, slug, excerpt, category, image_url, created_at FROM `{$p}s2nri_blog_posts` {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        if ( $params ) {
            $all_params = array_merge( $params, [ $per_page, $offset ] );
            $posts = $wpdb->get_results( $wpdb->prepare( $list_sql, $all_params ), ARRAY_A );
        } else {
            $posts = $wpdb->get_results( $wpdb->prepare( $list_sql, $per_page, $offset ), ARRAY_A );
        }
        $posts = $posts ?: [];

        // Featured = first post on page 1 with no filters
        $featured = ( $page_num === 1 && ! $cat_filter && ! $search_q && ! empty( $posts ) )
            ? array_shift( $posts ) : null;

        // Unique categories for filter
        $cats = $wpdb->get_col( "SELECT DISTINCT category FROM `{$p}s2nri_blog_posts` WHERE is_published=1 ORDER BY category ASC" ) ?: [];

        $total_pages = (int) ceil( $total / $per_page );
        $site_url    = home_url();

        if ( ! headers_sent() ) {
            header( 'Content-Type: text/html; charset=UTF-8' );
            header( 'Cache-Control: no-store' );
            header( 'CDN-Cache-Control: no-store' );
        }
        ?>
<!doctype html>
<html lang="en">
<head>
  <?php echo $design_head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>NRI Knowledge Hub — <?php echo $site; ?></title>
  <meta name="description" content="Expert guides, tips, and insights on NRI services, property management, taxation, immigration, and more.">
  <link rel="stylesheet" href="<?php echo esc_url( S2NRI_ASSETS_URL . 'app.css' ); ?>">
  <style>
    *,*::before,*::after{box-sizing:border-box}
    body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#F5F7FA;color:#1E2D40}
    a{text-decoration:none;color:inherit}
    img{display:block;width:100%;object-fit:cover}
    .blog-wrap{max-width:var(--s2-width-page-max,1200px);margin:0 auto;padding:0 var(--s2-width-padding-x,20px);width:100%;box-sizing:border-box}

    /* ── Header ── */
    .blog-header{background:#fff;border-bottom:1px solid #EBF0F8;padding:16px 0;position:sticky;top:0;z-index:100;box-shadow:0 1px 8px rgba(0,0,0,.06)}
    .blog-header-inner{display:flex;align-items:center;justify-content:space-between;gap:16px}
    .blog-logo{font-size:18px;font-weight:800;color:<?php echo $brand; ?>;display:flex;align-items:center;gap:8px}
    .blog-nav{display:flex;gap:20px;font-size:14px;font-weight:600}
    .blog-nav a{color:#374151;transition:color .15s}
    .blog-nav a:hover{color:<?php echo $brand; ?>}
    .blog-nav a.active{color:<?php echo $brand; ?>}

    /* ── Page Hero ── */
    .blog-page-hero{background:linear-gradient(135deg,<?php echo $brand; ?> 0%,<?php echo $brand; ?>cc 100%);padding:64px 0 56px;color:#fff;text-align:center}
    .blog-page-hero h1{font-size:clamp(28px,4vw,48px);font-weight:900;margin:0 0 12px;line-height:1.1}
    .blog-page-hero p{font-size:18px;opacity:.9;margin:0 auto 28px;max-width:var(--s2-width-section-compact,560px)}
    .blog-search-form{display:flex;gap:0;max-width:var(--s2-width-inner-max,480px);margin:0 auto;border-radius:8px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.15)}
    .blog-search-form input{flex:1;padding:14px 18px;border:none;font-size:15px;outline:none;font-family:inherit}
    .blog-search-form button{background:#1E2D40;color:#fff;border:none;padding:0 22px;font-size:14px;font-weight:700;cursor:pointer;transition:background .15s}
    .blog-search-form button:hover{background:#1a2f45}

    /* ── Categories ── */
    .blog-cats{display:flex;gap:10px;flex-wrap:wrap;padding:28px 0}
    .blog-cat-btn{padding:8px 18px;border:2px solid #e5e7eb;border-radius:99px;font-size:13px;font-weight:700;color:#374151;background:#fff;cursor:pointer;transition:all .15s}
    .blog-cat-btn:hover,.blog-cat-btn.active{border-color:<?php echo $brand; ?>;color:<?php echo $brand; ?>;background:<?php echo $brand; ?>12}

    /* ── Featured Post ── */
    .blog-featured{border-radius:16px;overflow:hidden;background:#fff;box-shadow:0 4px 24px rgba(0,0,0,.08);margin-bottom:40px;display:grid;grid-template-columns:1fr 1fr;min-height:380px}
    .blog-featured-img{height:100%;min-height:320px}
    .blog-featured-img img{height:100%;object-fit:cover}
    .blog-featured-body{padding:40px 36px;display:flex;flex-direction:column;justify-content:center}
    .blog-feat-badge{display:inline-flex;align-items:center;gap:6px;background:<?php echo $brand; ?>18;color:<?php echo $brand; ?>;font-size:12px;font-weight:800;letter-spacing:.5px;padding:5px 14px;border-radius:99px;text-transform:uppercase;margin-bottom:16px}
    .blog-feat-title{font-size:clamp(20px,2.5vw,28px);font-weight:900;color:#1E2D40;line-height:1.25;margin:0 0 14px}
    .blog-feat-excerpt{font-size:15px;color:#6b7280;line-height:1.75;margin:0 0 24px;flex:1}
    .blog-feat-meta{display:flex;align-items:center;gap:16px;font-size:13px;color:#9ca3af;margin-bottom:24px}
    .blog-feat-cta{display:inline-flex;align-items:center;gap:8px;background:<?php echo $brand; ?>;color:#fff;padding:12px 24px;border-radius:8px;font-weight:700;font-size:14px;transition:opacity .15s}
    .blog-feat-cta:hover{opacity:.9}

    /* ── Post Grid ── */
    .blog-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:28px;margin-bottom:48px}
    .blog-card{background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.07);transition:transform .2s,box-shadow .2s;display:flex;flex-direction:column}
    .blog-card:hover{transform:translateY(-4px);box-shadow:0 8px 28px rgba(0,0,0,.12)}
    .blog-card-img{height:200px}
    .blog-card-img img{height:100%}
    .blog-card-body{padding:22px;flex:1;display:flex;flex-direction:column}
    .blog-card-cat{font-size:11px;font-weight:800;color:<?php echo $brand; ?>;text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px}
    .blog-card-title{font-size:17px;font-weight:800;color:#1E2D40;line-height:1.35;margin:0 0 10px}
    .blog-card-excerpt{font-size:13px;color:#6b7280;line-height:1.7;margin:0 0 16px;flex:1}
    .blog-card-meta{display:flex;align-items:center;justify-content:space-between;font-size:12px;color:#9ca3af;border-top:1px solid #f3f4f6;padding-top:14px}
    .blog-card-read{color:<?php echo $brand; ?>;font-weight:700;font-size:13px}

    /* ── Pagination ── */
    .blog-pagination{display:flex;justify-content:center;gap:8px;padding:0 0 60px}
    .blog-page-btn{padding:9px 16px;border:1px solid #e5e7eb;border-radius:8px;font-size:14px;font-weight:600;color:#374151;background:#fff;cursor:pointer;transition:all .15s}
    .blog-page-btn:hover,.blog-page-btn.active{border-color:<?php echo $brand; ?>;color:<?php echo $brand; ?>;background:<?php echo $brand; ?>12}
    .blog-page-btn:disabled{opacity:.4;cursor:default}

    /* ── Empty ── */
    .blog-empty{text-align:center;padding:80px 24px;color:#9ca3af}
    .blog-empty h3{font-size:20px;color:#374151;margin-bottom:8px}

    /* ── Footer ── */
    .blog-footer{background:#1E2D40;color:rgba(255,255,255,.6);text-align:center;padding:24px;font-size:13px}
    .blog-footer a{color:<?php echo $brand; ?>;font-weight:600}

    @media(max-width:768px){
      .blog-featured{grid-template-columns:1fr}
      .blog-featured-img{height:220px}
      .blog-featured-body{padding:24px}
      .blog-grid{grid-template-columns:1fr}
    }
  </style>
</head>
<body class="s2-ds s2-page-wrap">

<!-- Header -->
<header class="blog-header">
  <div class="blog-wrap blog-header-inner">
    <a href="<?php echo esc_url( $site_url ); ?>" class="blog-logo">
      <span>✦</span> <?php echo $site; ?>
    </a>
    <nav class="blog-nav">
      <a href="<?php echo esc_url( $site_url ); ?>">Home</a>
      <a href="<?php echo esc_url( $site_url . '/services' ); ?>">Services</a>
      <a href="<?php echo esc_url( $site_url . '/blog' ); ?>" class="active">Knowledge Hub</a>
    </nav>
  </div>
</header>

<!-- Page Hero -->
<section class="blog-page-hero" data-s2-section="hero">
  <div class="blog-wrap">
    <h1>NRI Knowledge Hub</h1>
    <p>Expert guides, tips, and updates on NRI services, property, taxation, and more.</p>
    <form class="blog-search-form" action="<?php echo esc_url( $site_url . '/blog' ); ?>" method="GET">
      <input type="text" name="s" placeholder="Search articles…" value="<?php echo esc_attr( $search_q ); ?>">
      <button type="submit">Search →</button>
    </form>
  </div>
</section>

<!-- Category Filters -->
<?php if ( $cats ) : ?>
<div class="blog-wrap">
  <div class="blog-cats">
    <a href="<?php echo esc_url( $site_url . '/blog' ); ?>" class="blog-cat-btn<?php echo ! $cat_filter ? ' active' : ''; ?>">All</a>
    <?php foreach ( $cats as $cat ) : ?>
    <a href="<?php echo esc_url( add_query_arg( 'category', urlencode( $cat ), $site_url . '/blog' ) ); ?>"
       class="blog-cat-btn<?php echo $cat_filter === $cat ? ' active' : ''; ?>">
      <?php echo esc_html( $cat ); ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="blog-wrap">
  <?php if ( $search_q ) : ?>
  <p style="font-size:15px;color:#6b7280;margin:0 0 24px">
    <?php echo $total; ?> result<?php echo $total !== 1 ? 's' : ''; ?> for "<strong><?php echo esc_html( $search_q ); ?></strong>"
    — <a href="<?php echo esc_url( $site_url . '/blog' ); ?>" style="color:<?php echo $brand; ?>">Clear</a>
  </p>
  <?php endif; ?>

  <!-- Featured Post -->
  <?php if ( $featured ) :
    $feat_slug   = esc_attr( $featured['slug'] );
    $feat_img    = esc_url( $featured['image_url'] ?: $site_url . '/?s2nri_img=about' );
    $feat_date   = date( 'M j, Y', strtotime( $featured['created_at'] ) );
    $feat_wc     = str_word_count( wp_strip_all_tags( $featured['excerpt'] ?? '' ) );
    $feat_rt     = max( 1, (int) ceil( $feat_wc / 200 ) );
  ?>
  <a href="<?php echo esc_url( $site_url . '/blog/' . $feat_slug ); ?>" class="blog-featured">
    <div class="blog-featured-img">
      <img src="<?php echo $feat_img; ?>" alt="<?php echo esc_attr( $featured['title'] ); ?>" loading="eager">
    </div>
    <div class="blog-featured-body">
      <span class="blog-feat-badge">⭐ Featured Post</span>
      <h2 class="blog-feat-title"><?php echo esc_html( $featured['title'] ); ?></h2>
      <p class="blog-feat-excerpt"><?php echo esc_html( wp_trim_words( $featured['excerpt'] ?? '', 30 ) ); ?></p>
      <div class="blog-feat-meta">
        <span><?php echo esc_html( $featured['category'] ); ?></span>
        <span>📅 <?php echo $feat_date; ?></span>
        <span>⏱ <?php echo $feat_rt; ?> min read</span>
      </div>
      <span class="blog-feat-cta">Read Full Article →</span>
    </div>
  </a>
  <?php endif; ?>

  <!-- Posts Grid -->
  <?php if ( empty( $posts ) && ! $featured ) : ?>
  <div class="blog-empty">
    <div style="font-size:48px;margin-bottom:16px">📰</div>
    <h3><?php echo $search_q ? 'No articles found' : 'No posts yet'; ?></h3>
    <p><?php echo $search_q ? 'Try a different search term.' : 'Check back soon for expert NRI guides.'; ?></p>
  </div>
  <?php else : ?>
  <div class="blog-grid">
    <?php foreach ( $posts as $post ) :
      $post_url  = esc_url( $site_url . '/blog/' . $post['slug'] );
      $post_img  = esc_url( $post['image_url'] ?: $site_url . '/?s2nri_img=about' );
      $post_date = date( 'M j, Y', strtotime( $post['created_at'] ) );
      $wc        = str_word_count( wp_strip_all_tags( $post['excerpt'] ?? '' ) );
      $rt        = max( 1, (int) ceil( $wc / 200 ) );
    ?>
    <a href="<?php echo $post_url; ?>" class="blog-card">
      <div class="blog-card-img">
        <img src="<?php echo $post_img; ?>" alt="<?php echo esc_attr( $post['title'] ); ?>" loading="lazy">
      </div>
      <div class="blog-card-body">
        <div class="blog-card-cat"><?php echo esc_html( $post['category'] ); ?></div>
        <h3 class="blog-card-title"><?php echo esc_html( $post['title'] ); ?></h3>
        <p class="blog-card-excerpt"><?php echo esc_html( wp_trim_words( $post['excerpt'] ?? '', 20 ) ); ?></p>
        <div class="blog-card-meta">
          <span><?php echo $post_date; ?></span>
          <span class="blog-card-read">⏱ <?php echo $rt; ?> min read →</span>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Pagination -->
  <?php if ( $total_pages > 1 ) : ?>
  <nav class="blog-pagination">
    <?php if ( $page_num > 1 ) : ?>
    <a href="<?php echo esc_url( add_query_arg( 'pg', $page_num - 1, $site_url . '/blog' ) ); ?>" class="blog-page-btn">← Prev</a>
    <?php endif; ?>
    <?php for ( $i = 1; $i <= min( $total_pages, 7 ); $i++ ) : ?>
    <a href="<?php echo esc_url( add_query_arg( 'pg', $i, $site_url . '/blog' ) ); ?>"
       class="blog-page-btn<?php echo $i === $page_num ? ' active' : ''; ?>">
      <?php echo $i; ?>
    </a>
    <?php endfor; ?>
    <?php if ( $page_num < $total_pages ) : ?>
    <a href="<?php echo esc_url( add_query_arg( 'pg', $page_num + 1, $site_url . '/blog' ) ); ?>" class="blog-page-btn">Next →</a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>

<footer class="blog-footer">
  <p>© <?php echo date('Y'); ?> <?php echo $site; ?> — 
    <a href="<?php echo esc_url( $site_url ); ?>">Back to Home</a> · 
    <a href="<?php echo esc_url( $site_url . '/services' ); ?>">All Services</a>
  </p>
</footer>

</body>
</html>
        <?php
    }

    // ── Blog Detail Page ──────────────────────────────────────────────────────

    private static function renderDetail( string $slug, array $settings, array $config ): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $design = \S2NRI\Design\DesignSystem::resolve( [ 'page_type' => 'blog' ] );
        $brand  = esc_attr( $design['colors']['primary'] ?? ( $settings['primary_color'] ?? '#4A6FA5' ) );
        $site   = esc_html( $settings['platform_name'] ?? 'Services2NRI' );
        $design_head = \S2NRI\Design\DesignSystem::renderFontLinks( $design )
            . '<style id="s2-design-system">' . \S2NRI\Design\DesignSystem::renderInlineCss( '/blog' ) . '</style>';
        $site_url = home_url();

        $post = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$p}s2nri_blog_posts` WHERE slug = %s AND is_published = 1 LIMIT 1",
            sanitize_key( $slug )
        ), ARRAY_A );

        if ( ! $post ) {
            // 404 - fall through to React
            return;
        }

        // Calculate reading time
        $word_count  = str_word_count( wp_strip_all_tags( $post['content'] ?? '' ) );
        $reading_min = max( 1, (int) ceil( $word_count / 220 ) );
        $pub_date    = date( 'F j, Y', strtotime( $post['created_at'] ) );

        // Extract headings from content for Table of Contents
        $toc = [];
        if ( $post['content'] && preg_match_all( '#<h([23])[^>]*>(.*?)</h\1>#i', $post['content'], $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $text  = wp_strip_all_tags( $m[2] );
                $level = (int) $m[1];
                $id    = sanitize_title( $text );
                $toc[] = [ 'id' => $id, 'text' => $text, 'level' => $level ];
            }
        }

        // Add IDs to headings in content
        $content_html = $post['content'] ?? '';
        $content_html = preg_replace_callback(
            '#<(h[23])([^>]*)>(.*?)</h[23]>#i',
            function( $m ) {
                $id = sanitize_title( wp_strip_all_tags( $m[3] ) );
                return "<{$m[1]} id=\"{$id}\"{$m[2]}>{$m[3]}</{$m[1]}>";
            },
            $content_html
        );

        // Related posts
        $related = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, slug, image_url, category, created_at
             FROM `{$p}s2nri_blog_posts`
             WHERE is_published = 1 AND id != %d AND category = %s
             ORDER BY created_at DESC LIMIT 3",
            $post['id'], $post['category']
        ), ARRAY_A ) ?: [];

        if ( ! headers_sent() ) {
            header( 'Content-Type: text/html; charset=UTF-8' );
            header( 'Cache-Control: no-store' );
            header( 'CDN-Cache-Control: no-store' );
        }

        $post_img = esc_url( $post['image_url'] ?: $site_url . '/?s2nri_img=about' );
        ?>
<!doctype html>
<html lang="en">
<head>
  <?php echo $design_head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo esc_html( $post['title'] ); ?> — <?php echo $site; ?></title>
  <meta name="description" content="<?php echo esc_attr( wp_trim_words( $post['excerpt'] ?? $post['title'], 30 ) ); ?>">
  <meta property="og:title" content="<?php echo esc_attr( $post['title'] ); ?>">
  <meta property="og:image" content="<?php echo $post_img; ?>">
  <meta property="og:type" content="article">
  <link rel="stylesheet" href="<?php echo esc_url( S2NRI_ASSETS_URL . 'app.css' ); ?>">
  <style>
    *,*::before,*::after{box-sizing:border-box}
    body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#F5F7FA;color:#1E2D40}
    a{color:<?php echo $brand; ?>}
    img{display:block;max-width:100%}
    .wrap{max-width:var(--s2-width-page-max,1200px);margin:0 auto;padding:0 var(--s2-width-padding-x,20px);width:100%;box-sizing:border-box}

    .blog-header{background:#fff;border-bottom:1px solid #EBF0F8;padding:16px 0;position:sticky;top:0;z-index:100;box-shadow:0 1px 8px rgba(0,0,0,.06)}
    .blog-header-inner{display:flex;align-items:center;justify-content:space-between;gap:16px}
    .blog-logo{font-size:18px;font-weight:800;color:<?php echo $brand; ?>;text-decoration:none;display:flex;align-items:center;gap:8px}
    .blog-nav{display:flex;gap:20px;font-size:14px;font-weight:600}
    .blog-nav a{color:#374151;text-decoration:none;transition:color .15s}
    .blog-nav a:hover{color:<?php echo $brand; ?>}

    /* Hero */
    .article-hero{position:relative;height:460px;overflow:hidden;margin-bottom:0}
    .article-hero img{width:100%;height:100%;object-fit:cover}
    .article-hero-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.8) 0%,rgba(0,0,0,.3) 50%,transparent 100%)}
    .article-hero-content{position:absolute;bottom:0;left:0;right:0;padding:40px}
    .article-cat-badge{display:inline-flex;background:<?php echo $brand; ?>;color:#fff;font-size:12px;font-weight:800;padding:5px 14px;border-radius:99px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;text-decoration:none}
    .article-title{font-size:clamp(22px,3.5vw,40px);font-weight:900;color:#fff;margin:0 0 16px;line-height:1.15;max-width:var(--s2-width-content-max,800px);text-shadow:0 2px 12px rgba(0,0,0,.4)}
    .article-meta-bar{display:flex;align-items:center;gap:20px;color:rgba(255,255,255,.85);font-size:14px;flex-wrap:wrap}
    .article-meta-bar span{display:flex;align-items:center;gap:6px}

    /* Layout */
    .article-layout{display:grid;grid-template-columns:1fr 280px;gap:36px;padding:40px 0 60px;align-items:flex-start}

    /* Content */
    .article-content{background:#fff;border-radius:14px;padding:40px;box-shadow:0 2px 12px rgba(0,0,0,.06);min-width:0}
    .article-content h2{font-size:22px;font-weight:800;color:#1E2D40;margin:32px 0 12px;padding-bottom:8px;border-bottom:2px solid <?php echo $brand; ?>20}
    .article-content h3{font-size:18px;font-weight:700;color:#1a2f45;margin:24px 0 10px}
    .article-content p{font-size:16px;line-height:1.85;color:#374151;margin:0 0 18px}
    .article-content ul,
    .article-content ol{font-size:16px;line-height:1.85;color:#374151;margin:0 0 18px;padding-left:24px}
    .article-content li{margin-bottom:8px}
    .article-content strong{color:#1E2D40}
    .article-content blockquote{border-left:4px solid <?php echo $brand; ?>;background:<?php echo $brand; ?>0d;margin:24px 0;padding:16px 20px;border-radius:0 8px 8px 0;font-style:italic;color:#374151}
    .article-content a{color:<?php echo $brand; ?>;font-weight:600}
    .article-content img{border-radius:10px;margin:20px 0}

    /* Breadcrumb */
    .breadcrumb{font-size:13px;color:#9ca3af;padding:20px 0;display:flex;align-items:center;gap:6px}
    .breadcrumb a{color:<?php echo $brand; ?>;font-weight:600;text-decoration:none}

    /* Share Bar */
    .share-bar{display:flex;align-items:center;gap:12px;padding:20px 0;border-top:1px solid #f3f4f6;margin-top:32px}
    .share-bar span{font-size:13px;font-weight:700;color:#374151}
    .share-btn{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:opacity .15s}
    .share-btn:hover{opacity:.85}

    /* Sidebar */
    .article-sidebar{position:sticky;top:72px}
    .sidebar-card{background:#fff;border-radius:14px;padding:22px;box-shadow:0 2px 12px rgba(0,0,0,.06);margin-bottom:20px}
    .sidebar-card h4{font-size:13px;font-weight:800;color:#9ca3af;text-transform:uppercase;letter-spacing:1px;margin:0 0 14px}
    .toc-list{list-style:none;margin:0;padding:0}
    .toc-list li{margin-bottom:6px}
    .toc-list a{font-size:13px;color:#374151;text-decoration:none;display:flex;align-items:flex-start;gap:8px;padding:6px 8px;border-radius:6px;transition:all .15s;line-height:1.4}
    .toc-list a:hover,.toc-list a.toc-active{background:<?php echo $brand; ?>12;color:<?php echo $brand; ?>;font-weight:600}
    .toc-list .toc-h3{padding-left:20px}

    /* CTA Sidebar */
    .sidebar-cta{background:linear-gradient(135deg,<?php echo $brand; ?> 0%,<?php echo $brand; ?>cc 100%);border-radius:14px;padding:28px 22px;color:#fff;text-align:center}
    .sidebar-cta h4{font-size:17px;font-weight:900;margin:0 0 10px}
    .sidebar-cta p{font-size:13px;opacity:.9;margin:0 0 18px;line-height:1.6}
    .sidebar-cta a{display:block;background:#fff;color:<?php echo $brand; ?>;padding:12px;border-radius:8px;font-weight:800;font-size:14px;text-decoration:none}

    /* Related Posts */
    .related-section{padding:0 0 60px}
    .related-section h2{font-size:22px;font-weight:900;margin:0 0 24px;color:#1E2D40}
    .related-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px}
    .related-card{background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.07);text-decoration:none;display:flex;flex-direction:column;transition:transform .2s}
    .related-card:hover{transform:translateY(-3px)}
    .related-card img{height:160px;width:100%;object-fit:cover}
    .related-card-body{padding:16px}
    .related-card-cat{font-size:11px;font-weight:800;color:<?php echo $brand; ?>;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
    .related-card-title{font-size:15px;font-weight:700;color:#1E2D40;line-height:1.35;margin:0 0 8px}
    .related-card-date{font-size:12px;color:#9ca3af}

    .article-footer-cta{background:<?php echo $brand; ?>;color:#fff;padding:60px 20px;text-align:center}
    .article-footer-cta h2{font-size:clamp(22px,3vw,32px);font-weight:900;margin:0 0 12px}
    .article-footer-cta p{font-size:16px;opacity:.9;margin:0 0 24px}
    .article-footer-cta a{display:inline-block;background:#fff;color:<?php echo $brand; ?>;padding:14px 36px;border-radius:8px;font-weight:800;font-size:15px;text-decoration:none}

    .page-footer{background:#1E2D40;color:rgba(255,255,255,.6);text-align:center;padding:24px;font-size:13px}
    .page-footer a{color:<?php echo $brand; ?>;font-weight:600;text-decoration:none}

    @media(max-width:900px){
      .article-layout{grid-template-columns:1fr}
      .article-sidebar{position:static}
    }
    @media(max-width:640px){
      .article-hero{height:300px}
      .article-content{padding:24px}
    }
  </style>
</head>
<body class="s2-ds s2-page-wrap">

<!-- Header -->
<header class="blog-header">
  <div class="wrap blog-header-inner">
    <a href="<?php echo esc_url( $site_url ); ?>" class="blog-logo">✦ <?php echo $site; ?></a>
    <nav class="blog-nav">
      <a href="<?php echo esc_url( $site_url ); ?>">Home</a>
      <a href="<?php echo esc_url( $site_url . '/services' ); ?>">Services</a>
      <a href="<?php echo esc_url( $site_url . '/blog' ); ?>">Knowledge Hub</a>
    </nav>
  </div>
</header>

<!-- Hero Image -->
<div class="article-hero" data-s2-section="hero">
  <img src="<?php echo $post_img; ?>" alt="<?php echo esc_attr( $post['title'] ); ?>">
  <div class="article-hero-overlay"></div>
  <div class="article-hero-content">
    <div class="wrap">
      <a href="<?php echo esc_url( add_query_arg( 'category', urlencode( $post['category'] ), $site_url . '/blog' ) ); ?>" class="article-cat-badge">
        <?php echo esc_html( $post['category'] ); ?>
      </a>
      <h1 class="article-title"><?php echo esc_html( $post['title'] ); ?></h1>
      <div class="article-meta-bar">
        <span>📅 <?php echo esc_html( $pub_date ); ?></span>
        <span>⏱ <?php echo $reading_min; ?> min read</span>
        <span>✏️ <?php echo esc_html( $settings['platform_name'] ?? 'NRI Experts' ); ?></span>
      </div>
    </div>
  </div>
</div>

<div class="wrap">
  <!-- Breadcrumb -->
  <nav class="breadcrumb">
    <a href="<?php echo esc_url( $site_url ); ?>">Home</a> ›
    <a href="<?php echo esc_url( $site_url . '/blog' ); ?>">Knowledge Hub</a> ›
    <span><?php echo esc_html( $post['title'] ); ?></span>
  </nav>

  <!-- Main Layout -->
  <div class="article-layout">
    <!-- Article Content -->
    <div>
      <article class="article-content">
        <?php
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo wp_kses_post( $content_html );
        ?>

        <!-- Share Bar -->
        <div class="share-bar">
          <span>Share:</span>
          <?php
          $share_url   = urlencode( home_url( '/blog/' . $post['slug'] ) );
          $share_title = urlencode( $post['title'] );
          ?>
          <a class="share-btn" href="https://twitter.com/intent/tweet?url=<?php echo $share_url; ?>&text=<?php echo $share_title; ?>"
             target="_blank" rel="noopener" style="background:#1da1f2;color:#fff">
            𝕏 Twitter
          </a>
          <a class="share-btn" href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo $share_url; ?>"
             target="_blank" rel="noopener" style="background:#0077b5;color:#fff">
            in LinkedIn
          </a>
          <a class="share-btn" href="https://wa.me/?text=<?php echo $share_title; ?>%20<?php echo $share_url; ?>"
             target="_blank" rel="noopener" style="background:#25d366;color:#fff">
            💬 WhatsApp
          </a>
        </div>
      </article>
    </div>

    <!-- Sidebar -->
    <aside class="article-sidebar">
      <!-- Table of Contents -->
      <?php if ( $toc ) : ?>
      <div class="sidebar-card">
        <h4>📋 Table of Contents</h4>
        <ul class="toc-list" id="tocList">
          <?php foreach ( $toc as $item ) : ?>
          <li>
            <a href="#<?php echo esc_attr( $item['id'] ); ?>"
               class="<?php echo $item['level'] === 3 ? 'toc-h3' : ''; ?>">
              <?php echo esc_html( $item['text'] ); ?>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <!-- Service CTA -->
      <div class="sidebar-cta">
        <h4>Need Expert Assistance?</h4>
        <p>Our team handles all NRI documentation and services remotely, anywhere in the world.</p>
        <a href="<?php echo esc_url( $site_url . '/services' ); ?>">Explore Services →</a>
      </div>

      <!-- Back to Blog -->
      <div class="sidebar-card" style="text-align:center">
        <a href="<?php echo esc_url( $site_url . '/blog' ); ?>" style="color:<?php echo $brand; ?>;font-weight:700;font-size:14px;text-decoration:none">
          ← Back to Knowledge Hub
        </a>
      </div>
    </aside>
  </div>
</div>

<!-- Related Posts -->
<?php if ( $related ) : ?>
<section class="related-section">
  <div class="wrap">
    <h2>More Articles on <?php echo esc_html( $post['category'] ); ?></h2>
    <div class="related-grid">
      <?php foreach ( $related as $rel ) :
        $rel_img  = esc_url( $rel['image_url'] ?: $site_url . '/?s2nri_img=about' );
        $rel_date = date( 'M j, Y', strtotime( $rel['created_at'] ) );
      ?>
      <a href="<?php echo esc_url( $site_url . '/blog/' . $rel['slug'] ); ?>" class="related-card">
        <img src="<?php echo $rel_img; ?>" alt="<?php echo esc_attr( $rel['title'] ); ?>" loading="lazy">
        <div class="related-card-body">
          <div class="related-card-cat"><?php echo esc_html( $rel['category'] ); ?></div>
          <h3 class="related-card-title"><?php echo esc_html( $rel['title'] ); ?></h3>
          <div class="related-card-date"><?php echo $rel_date; ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Footer CTA -->
<section class="article-footer-cta">
  <h2>Ready to Start Your Application?</h2>
  <p>Expert NRI assistance, fully remote, delivered worldwide.</p>
  <a href="<?php echo esc_url( $site_url . '/services' ); ?>">View All Services →</a>
</section>

<footer class="page-footer">
  <p>© <?php echo date('Y'); ?> <?php echo $site; ?> — 
    <a href="<?php echo esc_url( $site_url ); ?>">Home</a> · 
    <a href="<?php echo esc_url( $site_url . '/blog' ); ?>">Knowledge Hub</a>
  </p>
</footer>

<!-- Sticky ToC active state -->
<script>
(function(){
  var toc = document.getElementById('tocList');
  if (!toc) return;
  var links = toc.querySelectorAll('a');
  var headings = Array.from(links).map(function(l){
    return document.getElementById(l.getAttribute('href').slice(1));
  }).filter(Boolean);
  
  window.addEventListener('scroll', function(){
    var scrollY = window.scrollY + 100;
    var active = null;
    headings.forEach(function(h){ if(h.offsetTop <= scrollY) active = h; });
    links.forEach(function(l){ l.classList.remove('toc-active'); });
    if(active){
      var href = '#' + active.id;
      var activeLink = toc.querySelector('a[href="' + href + '"]');
      if(activeLink) activeLink.classList.add('toc-active');
    }
  }, {passive:true});
})();
</script>
</body>
</html>
        <?php
    }
}
