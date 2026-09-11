<?php
/**
 * Reusable newspaper listing card — used on homepage, newspapers index, city pages.
 *
 * Expected vars: $np (array), $card_index (int), $card_mode (string), $booking_url (string)
 * Optional: $city_name (string), $cta_label (string), $show_badge (bool), $show_price (bool), $show_rates (bool)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$card_index  = isset( $card_index ) ? (int) $card_index : 0;
$card_mode   = $card_mode ?? 'featured';
$booking_url = $booking_url ?? nas_portal_booking_url();
$city_name   = $city_name ?? '';
$show_badge  = isset( $show_badge ) ? (bool) $show_badge : ( $card_index < 3 && $card_mode === 'featured' );
$show_price  = isset( $show_price ) ? (bool) $show_price : true;
$show_rates  = isset( $show_rates ) ? (bool) $show_rates : ( $card_mode === 'city' );

$detail_url = ! empty( $np['slug'] )
    ? home_url( '/newspapers/' . $np['slug'] . '/' )
    : $booking_url . ( strpos( $booking_url, '?' ) !== false ? '&' : '?' ) . 'newspaper=' . urlencode( $np['id'] );

$book_param = $booking_url . ( strpos( $booking_url, '?' ) !== false ? '&' : '?' ) . 'newspaper=' . urlencode( $np['id'] );

if ( ! empty( $cta_label ) ) {
    $cta_text = $cta_label;
    $cta_href = $book_param;
} elseif ( $card_mode === 'city' && $city_name ) {
    $cta_text = 'Book in ' . $city_name;
    $cta_href = $book_param;
} elseif ( $card_mode === 'category' ) {
    $cta_text = $cta_label ?? 'Book Ad';
    $cta_href = $book_param;
} else {
    $cta_text = 'Book This Newspaper';
    $cta_href = $detail_url;
}

$from_price = max( (float) ( $np['min_charge'] ?? 0 ), (float) ( $np['base_rate_classified'] ?? 0 ) );
$classified = (float) ( $np['base_rate_classified'] ?? 0 );
$display    = (float) ( $np['base_rate_display'] ?? 0 );

$meta = esc_html( $np['language'] ?: 'English' );
if ( $city_name ) {
    $meta .= ' · ' . esc_html( $city_name ) . ' edition';
}
?>
<article class="nhp-paper-card nhp-paper-card--a<?php echo (int) ( $card_index % 6 ); ?>"
         data-name="<?php echo esc_attr( strtolower( $np['name'] ) ); ?>"
         data-lang="<?php echo esc_attr( strtolower( $np['language'] ?: 'english' ) ); ?>">
  <div class="nhp-paper-card__top">
    <div class="nhp-paper-card__logo">
      <?php if ( ! empty( $np['logo_url'] ) ) : ?>
      <img src="<?php echo esc_url( $np['logo_url'] ); ?>" alt="<?php echo esc_attr( $np['name'] ); ?>" loading="lazy">
      <?php else : ?>
      <span class="nhp-paper-card__logo-fallback"><?php echo esc_html( strtoupper( substr( $np['name'], 0, 2 ) ) ); ?></span>
      <?php endif; ?>
    </div>
    <div class="nhp-paper-card__head">
      <h3 class="nhp-paper-card__name"><?php echo esc_html( $np['name'] ); ?></h3>
      <p class="nhp-paper-card__meta"><?php echo $meta; ?></p>
      <?php if ( $show_badge ) : ?>
      <span class="nhp-paper-card__badge"><i class="fa-solid fa-star"></i> Popular</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="nhp-paper-card__footer">
    <?php if ( $show_price && $from_price > 0 && ! $show_rates ) : ?>
    <p class="nhp-paper-card__price">From <strong>₹<?php echo number_format( $from_price, 0 ); ?></strong><?php echo $card_mode === 'home' ? ' <span>classified ads</span>' : '/word'; ?></p>
    <?php endif; ?>
    <?php if ( $show_rates && ( $classified > 0 || $display > 0 ) ) : ?>
    <p class="nhp-paper-card__rates">
      <?php if ( $classified > 0 ) : ?>Classified <strong>₹<?php echo number_format( $classified, 0 ); ?>/word</strong><?php endif; ?>
      <?php if ( $classified > 0 && $display > 0 ) : ?> · <?php endif; ?>
      <?php if ( $display > 0 ) : ?>Display <strong>₹<?php echo number_format( $display, 0 ); ?>/sq.cm</strong><?php endif; ?>
    </p>
    <?php endif; ?>
    <a href="<?php echo esc_url( $cta_href ); ?>" class="nhp-paper-card__cta"><?php echo esc_html( $cta_text ); ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
  </div>
</article>
