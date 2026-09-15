<?php
if (!defined('ABSPATH')) exit;
/** @var array $categories  One row per category (dl/rc/hp/noc/vehicle/
 *  commercial/other) — key, title, service_count, services (array of real
 *  service names in it), active_count (how many of those are currently
 *  bookable — rto_services.is_active=1), is_visible (active_count>0),
 *  schema_id, schema_name, schema_version, schema_updated_at. Part 4.10: the
 *  Form Builder's unit is a CATEGORY, not a service — 7 forms, not 44. Each
 *  category form covers every real service in it via a selected_service
 *  picker field. Part 4.14: active_count/is_visible answer a DIFFERENT
 *  question than schema_id — "is this category's form customised" vs "can a
 *  customer even pick this category's services on the live Apply page at
 *  all" — see the Deactivate/Activate Category button below. */
$pageTitle = 'Dynamic Form Builder';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>
<div class="rto-page-header">
  <div>
    <h2>Dynamic Form Builder</h2>
    <p style="color:#6b7280;font-size:13px;margin:2px 0 0;max-width:760px">
      This is <strong>your real, live Apply form</strong> at <code>/rto-apply/</code> — it is not a separate form.
      That single page has <?= count($categories) ?> categories (Driving License, RC Services, HP/Hypothecation,
      NOC, Vehicle Services, Commercial Vehicle, Other Services); each category below is ONE form covering every
      service inside it — a service picker plus every field for every service in that category, each field shown
      only for the service(s) it belongs to. Editing a row here changes the fields a customer sees the moment they
      pick any service in that category on the live page. A category with no custom form yet just uses its
      original built-in fields — nothing breaks or changes until you build and activate one.
    </p>
  </div>
</div>

<div id="formGroups">
<?php foreach ($categories as $cat): ?>
  <div class="rto-card" style="margin-bottom:14px"><div class="rto-card__body" style="padding:0">
    <div style="display:flex;align-items:center;gap:14px;padding:16px 18px">
      <div style="flex:1;min-width:0">
        <div style="font-size:15px;font-weight:700;color:#1e293b"><?= esc_html($cat['title']) ?></div>
        <div style="font-size:12px;color:#94a3b8;margin-top:3px">
          Covers <?= (int)$cat['service_count'] ?> service<?= $cat['service_count'] === 1 ? '' : 's' ?>:
          <?= esc_html(implode(', ', $cat['services'])) ?>
        </div>
        <div style="font-size:11px;color:#94a3b8;margin-top:4px">
          <?php if ($cat['schema_id']): ?>
            <?= esc_html($cat['schema_name']) ?> · v<?= (int)$cat['schema_version'] ?>
            <?php if ($cat['schema_updated_at']): ?> · updated <?= esc_html(date('d M Y', strtotime($cat['schema_updated_at']))) ?><?php endif; ?>
          <?php else: ?>
            <em>No custom form yet — using the original built-in fields</em>
          <?php endif; ?>
        </div>
      </div>
      <span style="background:<?= $cat['schema_id'] ? '#DCFCE7' : '#F3F4F6' ?>;color:<?= $cat['schema_id'] ? '#166534' : '#6B7280' ?>;font-size:11px;font-weight:700;padding:3px 10px;border-radius:10px;white-space:nowrap">
        <?= $cat['schema_id'] ? 'Custom' : 'Default' ?>
      </span>
      <span class="cat-visibility-badge" data-category="<?= esc_attr($cat['key']) ?>"
            style="background:<?= $cat['is_visible'] ? '#EFF6FF' : '#FEF3C7' ?>;color:<?= $cat['is_visible'] ? '#1d4ed8' : '#B45309' ?>;font-size:11px;font-weight:700;padding:3px 10px;border-radius:10px;white-space:nowrap"
            title="<?= (int)$cat['active_count'] ?> of <?= (int)$cat['service_count'] ?> real services in this category are currently bookable on the live Apply page">
        <?= $cat['is_visible'] ? '👁 Visible to customers' : '🚫 Hidden from customers' ?>
      </span>
      <div style="display:flex;gap:6px;flex-shrink:0">
        <a class="rto-btn rto-btn--sm rto-btn--primary" href="<?= esc_url(home_url('/rto-admin/forms/' . esc_attr($cat['key']) . '/')) ?>">
          <?= $cat['schema_id'] ? 'Edit Form' : 'Build Form' ?>
        </a>
        <?php if ($cat['schema_id']): ?>
          <a class="rto-btn rto-btn--sm" target="_blank" title="Opens the real Apply page — pick any service in this category to see your fields"
             href="<?= esc_url(home_url('/rto-apply/')) ?>">View on Live Apply Page</a>
        <?php endif; ?>
        <button type="button" class="rto-btn rto-btn--sm <?= $cat['is_visible'] ? '' : 'rto-btn--primary' ?> cat-visibility-btn"
                data-category="<?= esc_attr($cat['key']) ?>" data-title="<?= esc_attr($cat['title']) ?>"
                data-visible="<?= $cat['is_visible'] ? '1' : '0' ?>">
          <?= $cat['is_visible'] ? '🚫 Deactivate Category' : '👁 Activate Category' ?>
        </button>
        <?php if ($cat['schema_id']): ?>
          <button type="button" class="rto-btn rto-btn--sm rto-btn--danger form-delete-btn" data-category="<?= esc_attr($cat['key']) ?>">Remove</button>
        <?php endif; ?>
      </div>
    </div>
  </div></div>
<?php endforeach; ?>
<?php if (empty($categories)): ?>
  <div class="rto-card"><div class="rto-card__body" style="text-align:center;padding:40px;color:#94a3b8">No categories found.</div></div>
<?php endif; ?>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  var nonce = (window.rtoflowAdmin || {}).nonce || document.querySelector('meta[name="rto-admin-nonce"]')?.content || '';
  var ajaxUrl = (window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php';

  Array.prototype.forEach.call(document.querySelectorAll('.form-delete-btn'), function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Remove the custom form for this category? It will fall back to the default apply-form fields for every service in it. Version history is kept, not deleted.')) return;
      var fd = new FormData();
      fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','forms.delete');
      fd.append('rto_nonce', nonce); fd.append('category', btn.getAttribute('data-category'));
      fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(r){ if (r.success) location.reload(); else alert(r.message || 'Could not remove this form.'); });
    });
  });

  // Part 4.14: hide/show an entire category's real services on the live
  // Apply page — a bulk rto_services.is_active write, not a form-schema
  // change, so it's kept as its own button/confirmation distinct from
  // Edit/Remove above.
  Array.prototype.forEach.call(document.querySelectorAll('.cat-visibility-btn'), function(btn){
    btn.addEventListener('click', function(){
      var category = btn.getAttribute('data-category');
      var title = btn.getAttribute('data-title');
      var currentlyVisible = btn.getAttribute('data-visible') === '1';
      var msg = currentlyVisible
        ? 'Deactivate "' + title + '"? Every real service in this category will be hidden from the live Apply page immediately — customers will not be able to select or book any of them until you activate it again. Existing leads and submissions are not affected.'
        : 'Activate "' + title + '" again? Every real service in this category becomes bookable on the live Apply page immediately.';
      if (!confirm(msg)) return;
      btn.disabled = true;
      var fd = new FormData();
      fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','forms.toggle_category');
      fd.append('rto_nonce', nonce); fd.append('category', category);
      fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(r){
          btn.disabled = false;
          if (r.success) { location.reload(); return; }
          alert(r.message || 'Could not change this category\'s visibility.');
        })
        .catch(function(){ btn.disabled = false; alert('Network error — visibility was not changed.'); });
    });
  });
})();
</script>

<?php rto_help_box('forms'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
