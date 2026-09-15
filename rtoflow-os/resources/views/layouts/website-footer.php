<?php if (!defined('ABSPATH')) exit;
$company = get_option('rtoflow_company_name', 'RTOASSIST');
$phone   = get_option('rtoflow_company_phone', '');
$email   = get_option('rtoflow_support_email', '');
global $wpdb;
$footer_cities = $wpdb->get_results(
    "SELECT name, COALESCE(slug, LOWER(REPLACE(name,' ','-'))) as slug FROM {$wpdb->prefix}rto_cities WHERE is_active=1 ORDER BY name LIMIT 20",
    ARRAY_A
) ?: [];
// CORRECTED (service-claims audit): "300+ cities" was a hardcoded number
// unrelated to the real rto_cities table this same file already queries.
$realCityCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rto_cities WHERE is_active=1");
?>
</main>

<!-- Footer -->
<style>
/* Mobile responsiveness for the shared site footer (resources/assets/css/website.css
   collapses .rto-footer__inner to a single column at <=768px; this restores at
   least a 2-column layout on mobile with cleaner spacing/typography/touch targets,
   matching home.php's own footer breakpoints). Scoped to .rto-footer only. */
@media(max-width:768px){
  .rto-footer{padding:40px 16px 0}
  .rto-footer__inner{grid-template-columns:1fr 1fr!important;gap:28px 20px;padding-bottom:28px}
  .rto-footer__col h3,.rto-footer__col h4{font-size:14px;margin-bottom:12px}
  .rto-footer__col p,.rto-footer__col li{font-size:13px;margin-bottom:4px}
  .rto-footer__col ul{display:flex;flex-direction:column}
  .rto-footer__col a{display:inline-block;padding:6px 0;min-height:32px}
  .rto-footer__bottom{padding:16px}
  .rto-footer__bottom-inner{display:flex;flex-direction:column;gap:10px;text-align:center}
  .rto-footer__bottom-inner div{display:flex;flex-direction:column;gap:8px;align-items:center}
  .rto-footer__bottom-inner a{display:inline-block;padding:6px 4px;min-height:32px}
}
@media(max-width:480px){
  .rto-footer{padding:32px 14px 0}
  .rto-footer__inner{gap:24px 16px}
  .rto-footer__col p,.rto-footer__col li{font-size:13px}
}
</style>
<footer class="rto-footer">
  <div class="rto-footer__inner">
    <div class="rto-footer__col">
      <h3>🚗 <?= esc_html($company) ?></h3>
      <p>RTO application assistance and consultancy<?= $realCityCount > 0 ? ', with agents in ' . $realCityCount . ' cities' : '' ?>. Online support everywhere; doorstep document pickup/drop where our courier/agent network covers your city (additional charges may apply).</p>
      <?php if ($phone): ?><p style="margin-top:8px">📞 <a href="tel:<?= esc_attr($phone) ?>" style="color:#cbd5e1"><?= esc_html($phone) ?></a></p><?php endif; ?>
      <?php if ($email): ?><p>✉ <a href="mailto:<?= esc_attr($email) ?>" style="color:#cbd5e1"><?= esc_html($email) ?></a></p><?php endif; ?>
    </div>
    <div class="rto-footer__col">
      <h4>Quick Links</h4>
      <ul>
        <li><a href="<?= home_url('/rto-apply/') ?>">Get a Free Quote</a></li>
        <li><a href="<?= home_url('/rto-service/all') ?>">All Services</a></li>
        <li><a href="<?= home_url('/pricing') ?>">Pricing</a></li>
        <li><a href="<?= home_url('/how-it-works') ?>">How It Works</a></li>
        <li><a href="<?= home_url('/rto-services-cities') ?>">Service Cities</a></li>
        <li><a href="<?= home_url('/about') ?>">About Us</a></li>
        <li><a href="<?= home_url('/contact') ?>">Contact</a></li>
      </ul>
    </div>
    <div class="rto-footer__col">
      <h4>Our Services</h4>
      <ul>
        <li><a href="<?= home_url('/rto-apply/') ?>">RC / Ownership Transfer</a></li>
        <li><a href="<?= home_url('/rto-apply/') ?>">Driving License</a></li>
        <li><a href="<?= home_url('/rto-apply/') ?>">NOC for Vehicle</a></li>
        <li><a href="<?= home_url('/rto-apply/') ?>">Hypothecation Services</a></li>
        <li><a href="<?= home_url('/rto-apply/') ?>">RC Renewal</a></li>
        <li><a href="<?= home_url('/rto-apply/') ?>">Duplicate RC</a></li>
        <li><a href="<?= home_url('/rto-apply/') ?>">Change of Address</a></li>
      </ul>
    </div>
    <?php if ($footer_cities): ?>
    <div class="rto-footer__col">
      <h4>Top Cities</h4>
      <ul>
        <?php foreach (array_slice($footer_cities, 0, 12) as $c): ?>
        <li><a href="<?= home_url('/rto-agent-in-' . esc_attr($c['slug'])) ?>">RTO in <?= esc_html($c['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
  <div class="rto-footer__bottom">
    <div class="rto-footer__bottom-inner">
      <span>© <?= date('Y') ?> <?= esc_html($company) ?>. All rights reserved.</span>
      <div>
        <a href="<?= home_url('/privacy-policy') ?>">Privacy Policy</a>
        <a href="<?= home_url('/terms-conditions') ?>">Terms & Conditions</a>
      </div>
    </div>
    <!--
      SITEWIDE DISCLOSURE (service-claims audit): appears on every page that
      includes this footer, so the scope-of-service and no-outcome-guarantee
      disclosure is not something a customer has to hunt for on one specific
      page. Kept short here by design — the fuller version lives on
      Terms & Conditions and on individual service pages.
    -->
    <div class="rto-footer__bottom-inner" style="padding-top:10px;font-size:11px;color:#94a3b8;line-height:1.6">
      <?= esc_html($company) ?> is a service provider, application-assistance provider, and consultant — not a
      government body. Doorstep document pickup/drop is available only where our courier/agent network covers
      your city, is not included in every service's base fee, and may involve an additional charge. Final approval,
      appointment allocation, document verification, processing time, and issuance are decided solely by the
      concerned RTO/authority; we do not control or guarantee these outcomes.
    </div>
  </div>
</footer>

<?php require RTOFLOW_DIR . 'resources/views/partials/cookie-consent.php'; ?>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/public.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/public.js')) ?>"></script>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/mobile-nav.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/mobile-nav.js')) ?>"></script>
<?php wp_footer(); ?>
</body>
</html>
