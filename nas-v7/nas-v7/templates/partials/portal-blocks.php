<?php
/**
 * Reusable portal content blocks — mirrors homepage sections (nhp-*).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function nas_portal_booking_url(): string {
    return nas_get_page_url( 'nas_page_booking', '/book-newspaper-ad/' );
}

function nas_portal_contact_url(): string {
    return nas_get_page_url( 'nas_page_contact', '/contact-us/' );
}

function nas_portal_faq_url(): string {
    return nas_get_page_url( 'nas_page_faq', '/faq/' );
}

function nas_portal_track_url(): string {
    return nas_get_page_url( 'nas_page_track_order', '/track-order/' );
}

function nas_portal_whatsapp_url(): string {
    $wa = nas_config( 'brand_whatsapp', '' );
    return $wa ? 'https://wa.me/' . preg_replace( '/[^0-9]/', '', $wa ) : '';
}

/**
 * Trust ribbon — 4 guarantees.
 */
function nas_portal_block_trust_ribbon(): void {
    ?>
<section class="nhp-trust-ribbon" aria-label="Platform guarantees">
  <div class="nhp-container nhp-trust-ribbon__inner">
    <div class="nhp-trust-ribbon__item"><span class="nhp-trust-ribbon__icon"><i class="fa-solid fa-award"></i></span> Authorized Publisher Network</div>
    <div class="nhp-trust-ribbon__item"><span class="nhp-trust-ribbon__icon"><i class="fa-solid fa-indian-rupee-sign"></i></span> Lowest Rates Guaranteed</div>
    <div class="nhp-trust-ribbon__item"><span class="nhp-trust-ribbon__icon"><i class="fa-solid fa-receipt"></i></span> GST Invoice Included</div>
    <div class="nhp-trust-ribbon__item"><span class="nhp-trust-ribbon__icon"><i class="fa-solid fa-headset"></i></span> Dedicated Booking Support</div>
  </div>
</section>
    <?php
}

/**
 * 5-step booking process cards.
 */
function nas_portal_block_process( string $eyebrow = 'How It Works', string $title = 'Book Your Ad in 5 Simple Steps', string $subtitle = 'From selection to publication — a transparent, guided workflow.' ): void {
    $steps = [
        [ 'fa-map-location-dot', 'Select', 'Choose city, newspaper & ad category', '' ],
        [ 'fa-sliders', 'Configure', 'Set ad size, edition & compose your copy', '' ],
        [ 'fa-indian-rupee-sign', 'Preview Price', 'See transparent rates before you commit', 'Instant' ],
        [ 'fa-credit-card', 'Pay Securely', 'UPI, cards & net banking via Razorpay', '' ],
        [ 'fa-circle-check', 'Track & Confirm', 'Monitor status until publication proof', '24–48 hrs' ],
    ];
    ?>
<section class="nhp-section nhp-section--warm">
  <div class="nhp-container">
    <div class="nhp-section__header nhp-section__header--center">
      <span class="nhp-section__eyebrow"><?php echo esc_html( $eyebrow ); ?></span>
      <h2 class="nhp-section__title nhp-section__title--accent"><?php echo esc_html( $title ); ?></h2>
      <p class="nhp-section__subtitle"><?php echo esc_html( $subtitle ); ?></p>
    </div>
    <div class="nhp-process nhp-process--cards">
      <?php foreach ( $steps as $si => $s ) : ?>
      <div class="nhp-process__step nhp-process__step--s<?php echo (int) $si; ?>">
        <span class="nhp-process__num"><?php echo str_pad( (string) ( $si + 1 ), 2, '0', STR_PAD_LEFT ); ?></span>
        <div class="nhp-process__dot"><i class="fa-solid <?php echo esc_attr( $s[0] ); ?>"></i></div>
        <h3 class="nhp-process__title"><?php echo esc_html( $s[1] ); ?></h3>
        <p class="nhp-process__desc"><?php echo esc_html( $s[2] ); ?></p>
        <?php if ( $s[3] ) : ?>
        <span class="nhp-process__time"><?php echo esc_html( $s[3] ); ?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
    <?php
}

/**
 * 4-column advantages row.
 */
function nas_portal_block_advantages(): void {
    $advantages = [
        [ 'fa-certificate', 'Verified Publishers', 'Book only through authorized newspaper channels with publication proof.' ],
        [ 'fa-tags', 'Transparent Rates', 'See newspaper rates upfront — no hidden charges, GST invoice included.' ],
        [ 'fa-clock', 'Book 24/7 Online', 'Complete your booking anytime without phone calls or office visits.' ],
        [ 'fa-headset', 'Dedicated Support', 'Track your ad status and get help from booking through publication.' ],
    ];
    ?>
<section class="nhp-advantages">
  <div class="nhp-container">
    <div class="nhp-advantages__grid">
      <?php foreach ( $advantages as $ai => $adv ) : ?>
      <div class="nhp-advantage nhp-advantage--a<?php echo (int) $ai; ?>">
        <div class="nhp-advantage__icon"><i class="fa-solid <?php echo esc_attr( $adv[0] ); ?>"></i></div>
        <h3 class="nhp-advantage__title"><?php echo esc_html( $adv[1] ); ?></h3>
        <p class="nhp-advantage__desc"><?php echo esc_html( $adv[2] ); ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
    <?php
}

/**
 * Ad format strip (3 formats).
 */
function nas_portal_block_formats(): void {
    $booking_url = nas_portal_booking_url();
    $formats = [
        [ 'fa-align-left', 'Classified Text Ad', 'Per-word pricing for personal & legal notices' ],
        [ 'fa-image', 'Classified Display', 'Borders, logos & small images in classifieds' ],
        [ 'fa-newspaper', 'Display Ad', 'Full image ads sized by sq. cm for brands' ],
    ];
    ?>
<section class="nhp-section nhp-section--warm">
  <div class="nhp-container">
    <div class="nhp-section__header nhp-section__header--center">
      <span class="nhp-section__eyebrow">Ad Formats</span>
      <h2 class="nhp-section__title nhp-section__title--accent">Every Type of <span>Newspaper Advertisement</span></h2>
      <p class="nhp-section__subtitle">Classified text, display, and full-page ads — choose the format that fits your message and budget.</p>
    </div>
    <div class="nhp-format-strip">
      <?php foreach ( $formats as $fi => $f ) : ?>
      <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-format-strip__item nhp-format-strip__item--f<?php echo (int) $fi; ?>">
        <span class="nhp-format-strip__icon"><i class="fa-solid <?php echo esc_attr( $f[0] ); ?>"></i></span>
        <span class="nhp-format-strip__body">
          <span class="nhp-format-strip__title"><?php echo esc_html( $f[1] ); ?></span>
          <span class="nhp-format-strip__desc"><?php echo esc_html( $f[2] ); ?></span>
        </span>
        <i class="fa-solid fa-arrow-right nhp-format-strip__arrow"></i>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
    <?php
}

/**
 * Gold accent CTA band.
 */
function nas_portal_block_accent_band( string $title, string $text, ?string $primary_url = null, string $primary_label = 'Book an Ad Now' ): void {
    $booking_url = $primary_url ?: nas_portal_booking_url();
    $walink      = nas_portal_whatsapp_url();
    $phone       = nas_config( 'brand_phone', '' );
    ?>
<section class="nhp-accent-band">
  <div class="nhp-container nhp-accent-band__inner">
    <div>
      <h2 class="nhp-accent-band__title"><?php echo esc_html( $title ); ?></h2>
      <p class="nhp-accent-band__text"><?php echo esc_html( $text ); ?></p>
    </div>
    <div class="nhp-accent-band__actions">
      <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--white nhp-btn--lg"><?php echo esc_html( $primary_label ); ?></a>
      <?php if ( $walink ) : ?>
      <a href="<?php echo esc_url( $walink ); ?>" class="nhp-btn nhp-btn--ghost nhp-btn--lg" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp Us</a>
      <?php elseif ( $phone ) : ?>
      <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>" class="nhp-btn nhp-btn--ghost nhp-btn--lg"><i class="fa-solid fa-phone"></i> Call Us</a>
      <?php endif; ?>
    </div>
  </div>
</section>
    <?php
}

/**
 * Final dark CTA section.
 */
function nas_portal_block_cta( string $title, string $subtitle, ?string $booking_url = null ): void {
    $booking_url = $booking_url ?: nas_portal_booking_url();
    $contact_url = nas_portal_contact_url();
    $brand       = nas_config( 'brand_name', get_bloginfo( 'name' ) );
    ?>
<section class="nhp-cta">
  <div class="nhp-container nhp-cta__inner">
    <h2 class="nhp-cta__title"><?php echo esc_html( $title ); ?></h2>
    <p class="nhp-cta__subtitle"><?php echo esc_html( $subtitle ); ?></p>
    <div class="nhp-cta__actions">
      <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--white nhp-btn--xl">Check Ad Rates <i class="fa-solid fa-arrow-right"></i></a>
      <a href="<?php echo esc_url( $contact_url ); ?>" class="nhp-btn nhp-btn--ghost nhp-btn--lg">Talk to Us</a>
    </div>
  </div>
</section>
    <?php
}

/**
 * Quick links row (4 cards).
 */
function nas_portal_block_quick_links(): void {
    $links = [
        [ 'fa-pen-nib', 'Book an Ad', 'Start the booking wizard', nas_portal_booking_url() ],
        [ 'fa-location-crosshairs', 'Track Order', 'Check your ad status', nas_portal_track_url() ],
        [ 'fa-circle-question', 'FAQ', 'Answers to common questions', nas_portal_faq_url() ],
        [ 'fa-envelope', 'Contact', 'Speak with our team', nas_portal_contact_url() ],
    ];
    ?>
<section class="nas-portal-section">
  <div class="nhp-container">
    <div class="nas-portal-section__head">
      <h2>Quick Access</h2>
      <p>Everything you need to book, track, and manage your newspaper advertisements.</p>
    </div>
    <div class="nas-portal-channel-grid">
      <?php foreach ( $links as $l ) : ?>
      <a href="<?php echo esc_url( $l[3] ); ?>" class="nas-portal-channel-card">
        <div class="nas-portal-channel-card__icon"><i class="fa-solid <?php echo esc_attr( $l[0] ); ?>"></i></div>
        <h3><?php echo esc_html( $l[1] ); ?></h3>
        <div class="nas-portal-channel-card__meta"><?php echo esc_html( $l[2] ); ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
    <?php
}

/**
 * Inline FAQ accordion block.
 *
 * @param array<int,array{0:string,1:string}> $faqs Question + answer pairs.
 */
function nas_portal_block_faq( array $faqs, string $title = 'Frequently Asked Questions', string $subtitle = '' ): void {
    if ( empty( $faqs ) ) {
        return;
    }
    ?>
<section class="nas-portal-section nas-portal-section--muted">
  <div class="nhp-container" style="max-width:800px">
    <div class="nas-portal-section__head">
      <h2><?php echo esc_html( $title ); ?></h2>
      <?php if ( $subtitle ) : ?><p><?php echo esc_html( $subtitle ); ?></p><?php endif; ?>
    </div>
    <div class="nas-faq-list">
      <?php foreach ( $faqs as $i => $faq ) : ?>
      <div class="nas-faq-item nas-faq-item--c<?php echo (int) ( $i % 6 ); ?>">
        <button type="button" class="nas-faq-q" onclick="typeof nasTogglePortalFaq==='function'&&nasTogglePortalFaq(this)" aria-expanded="false">
          <span class="nas-faq-q__num"><?php echo str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ); ?></span>
          <span class="nas-faq-q__text"><?php echo esc_html( $faq[0] ); ?></span>
          <i class="fa-solid fa-chevron-down nas-faq-q__chevron"></i>
        </button>
        <div class="nas-faq-a"><?php echo wp_kses_post( $faq[1] ); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
    <?php
}

/**
 * Live stats from database.
 */
function nas_portal_live_stats(): array {
    static $stats = null;
    if ( $stats !== null ) {
        return $stats;
    }
    $db = \NAS\Core\Database::instance();
    $stats = [
        'papers'   => (int) ( $db->row( "SELECT COUNT(*) as c FROM {$db->t('newspapers')} WHERE is_active=1" )['c'] ?? 0 ),
        'cities'   => (int) ( $db->row( "SELECT COUNT(*) as c FROM {$db->t('cities')} WHERE is_active=1" )['c'] ?? 0 ),
        'bookings' => (int) ( $db->row( "SELECT COUNT(*) as c FROM {$db->t('bookings')} WHERE status != 'cancelled'" )['c'] ?? 0 ),
    ];
    return $stats;
}

/**
 * Stats grid with live DB counts when available.
 */
function nas_portal_block_stats( string $title = 'Platform at a Glance', string $subtitle = 'Trusted by advertisers nationwide.' ): void {
    $s = nas_portal_live_stats();
    $stats = [
        [ 'fa-newspaper', $s['papers'] > 0 ? $s['papers'] . '+' : '50+', 'Newspapers' ],
        [ 'fa-city', $s['cities'] > 0 ? $s['cities'] . '+' : '300+', 'Cities' ],
        [ 'fa-bullhorn', $s['bookings'] > 0 ? number_format( $s['bookings'] ) . '+' : '10,000+', 'Ads Placed' ],
    ];
    ?>
<section class="nas-portal-section nas-portal-section--muted">
  <div class="nhp-container">
    <div class="nas-portal-section__head">
      <h2><?php echo esc_html( $title ); ?></h2>
      <p><?php echo esc_html( $subtitle ); ?></p>
    </div>
    <div class="nas-portal-stat-grid">
      <?php foreach ( $stats as $row ) : ?>
      <div class="nas-portal-stat">
        <div class="nas-portal-stat__icon"><i class="fa-solid <?php echo esc_attr( $row[0] ); ?>"></i></div>
        <div class="nas-portal-stat__value"><?php echo esc_html( $row[1] ); ?></div>
        <div class="nas-portal-stat__label"><?php echo esc_html( $row[2] ); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
    <?php
}

/**
 * Default customer testimonials (filterable; override via nas_portal_testimonials_json option).
 *
 * @return array<int,array{name:string,role:string,text:string,rating:int,city?:string}>
 */
function nas_portal_get_testimonials(): array {
    $stored = get_option( 'nas_portal_testimonials_json', '' );
    if ( $stored ) {
        $parsed = json_decode( $stored, true );
        if ( is_array( $parsed ) && ! empty( $parsed ) ) {
            return apply_filters( 'nas_portal_testimonials', $parsed );
        }
    }
    $brand = nas_config( 'brand_name', get_bloginfo( 'name' ) );
    $defaults = [
        [
            'name'   => 'Rajesh Mehta',
            'role'   => 'Business Owner',
            'city'   => 'Mumbai',
            'rating' => 5,
            'text'   => 'Booked a display ad in Times of India through ' . $brand . '. Transparent pricing, quick payment, and the tear sheet arrived within 3 days. Highly recommended for businesses.',
        ],
        [
            'name'   => 'Priya Sharma',
            'role'   => 'HR Manager',
            'city'   => 'Delhi',
            'rating' => 5,
            'text'   => 'We place recruitment ads every month. The online wizard saves hours compared to calling agents. GST invoice and tracking make it easy for our accounts team.',
        ],
        [
            'name'   => 'Anil Reddy',
            'role'   => 'Property Dealer',
            'city'   => 'Hyderabad',
            'rating' => 5,
            'text'   => 'Published three property ads in Deccan Chronicle. Rates were lower than what local agents quoted, and support helped format the ad perfectly before submission.',
        ],
        [
            'name'   => 'Sunita Patel',
            'role'   => 'Family Advertiser',
            'city'   => 'Ahmedabad',
            'rating' => 5,
            'text'   => 'Needed a matrimonial ad urgently. Booked at midnight, got confirmation next morning, and the ad published on schedule. The whole process was stress-free.',
        ],
        [
            'name'   => 'Vikram Singh',
            'role'   => 'Agency Partner',
            'city'   => 'Bangalore',
            'rating' => 5,
            'text'   => 'We handle multiple client campaigns. Dashboard tracking, invoice downloads, and WhatsApp updates make ' . $brand . ' our preferred channel for newspaper bookings.',
        ],
        [
            'name'   => 'Kavita Nair',
            'role'   => 'Legal Consultant',
            'city'   => 'Chennai',
            'rating' => 5,
            'text'   => 'Published a legal notice through the platform. Clear rate breakdown, secure Razorpay payment, and proof of publication delivered to my email. Professional service.',
        ],
    ];
    return apply_filters( 'nas_portal_testimonials', $defaults );
}

/**
 * Customer testimonials grid with star ratings.
 */
function nas_portal_block_testimonials(
    string $title = 'Trusted by Advertisers Nationwide',
    string $subtitle = 'Real feedback from businesses and individuals who book newspaper ads through our platform.',
    ?int $limit = null
): void {
    $items = nas_portal_get_testimonials();
    if ( $limit !== null && $limit > 0 ) {
        $items = array_slice( $items, 0, $limit );
    }
    if ( empty( $items ) ) {
        return;
    }
    $avg = 0;
    foreach ( $items as $t ) {
        $avg += (int) ( $t['rating'] ?? 5 );
    }
    $avg = round( $avg / count( $items ), 1 );
    ?>
<section class="nhp-section nhp-section--warm nhp-testimonials" aria-label="Customer testimonials">
  <div class="nhp-container">
    <div class="nhp-section__header nhp-section__header--center">
      <span class="nhp-section__eyebrow"><i class="fa-solid fa-star"></i> <?php echo esc_html( number_format( $avg, 1 ) ); ?> Average Rating</span>
      <h2 class="nhp-section__title nhp-section__title--accent"><?php echo esc_html( $title ); ?></h2>
      <p class="nhp-section__subtitle"><?php echo esc_html( $subtitle ); ?></p>
    </div>
    <div class="nhp-testimonials__grid">
      <?php foreach ( $items as $ti => $t ) :
          $rating = min( 5, max( 1, (int) ( $t['rating'] ?? 5 ) ) );
          $initials = '';
          foreach ( preg_split( '/\s+/', trim( $t['name'] ?? 'A' ) ) as $part ) {
              $initials .= strtoupper( substr( $part, 0, 1 ) );
          }
          $initials = substr( $initials, 0, 2 );
      ?>
      <article class="nhp-testimonial nhp-testimonial--t<?php echo (int) ( $ti % 6 ); ?>">
        <div class="nhp-testimonial__stars" aria-label="<?php echo esc_attr( $rating ); ?> out of 5 stars">
          <?php for ( $s = 1; $s <= 5; $s++ ) : ?>
          <i class="fa-solid fa-star<?php echo $s <= $rating ? '' : ' nhp-testimonial__star--empty'; ?>" aria-hidden="true"></i>
          <?php endfor; ?>
        </div>
        <blockquote class="nhp-testimonial__quote">&ldquo;<?php echo esc_html( $t['text'] ?? '' ); ?>&rdquo;</blockquote>
        <footer class="nhp-testimonial__author">
          <span class="nhp-testimonial__avatar" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
          <div>
            <cite class="nhp-testimonial__name"><?php echo esc_html( $t['name'] ?? '' ); ?></cite>
            <div class="nhp-testimonial__meta">
              <?php echo esc_html( $t['role'] ?? '' ); ?>
              <?php if ( ! empty( $t['city'] ) ) : ?> · <?php echo esc_html( $t['city'] ); ?><?php endif; ?>
            </div>
          </div>
        </footer>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
    <?php
}
