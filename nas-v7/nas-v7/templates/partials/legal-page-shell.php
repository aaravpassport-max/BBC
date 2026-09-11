<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Legal page shell — shared layout for policy pages.
 *
 * @var string $legal_eyebrow
 * @var string $legal_title
 * @var string $legal_intro
 * @var array  $legal_sections  [ ['heading' => '', 'body' => ''], ... ]
 */
$brand = nas_config( 'brand_name', get_bloginfo( 'name' ) );
$contact_url = function_exists( 'nas_portal_contact_url' ) ? nas_portal_contact_url() : home_url( '/contact-us/' );
?>
<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow"><?php echo esc_html( $legal_eyebrow ?? 'Legal' ); ?></span>
      <h1><?php echo wp_kses_post( $legal_title ?? 'Policy' ); ?></h1>
      <?php if ( ! empty( $legal_intro ) ) : ?>
      <p><?php echo esc_html( $legal_intro ); ?></p>
      <?php endif; ?>
    </div>
  </div>

  <section class="nas-portal-section">
    <div class="nhp-container">
      <div class="nas-portal-card-block" style="max-width:860px;margin:0 auto">
        <div class="nas-portal-prose">
          <p><em>Last updated: <?php echo esc_html( date_i18n( 'F j, Y' ) ); ?></em></p>
          <?php foreach ( (array) ( $legal_sections ?? [] ) as $section ) : ?>
          <h2 style="font-family:var(--nas-font-display);font-size:1.25rem;font-weight:800;margin:32px 0 12px;color:var(--nas-text)">
            <?php echo esc_html( $section['heading'] ?? '' ); ?>
          </h2>
          <?php echo wp_kses_post( $section['body'] ?? '' ); ?>
          <?php endforeach; ?>
          <p style="margin-top:32px">Questions about this policy? <a href="<?php echo esc_url( $contact_url ); ?>">Contact <?php echo esc_html( $brand ); ?></a>.</p>
        </div>
      </div>
    </div>
  </section>
</div>
