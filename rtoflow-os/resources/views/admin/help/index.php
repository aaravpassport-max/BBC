<?php if (!defined('ABSPATH')) exit;
/** @var array|null $article @var string $articleSlug @var string $query @var array $moduleIndex */
$pageTitle = 'Help Centre';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$statusLabel = ['documented' => ['Documented', 'success'], 'planned' => ['Not yet documented', 'warning'], 'not_implemented' => ['Not implemented', 'secondary']];
?>
<div class="rto-page-wrap">
  <div class="rto-page-header">
    <h1 class="rto-page-title">📚 Help Centre</h1>
  </div>

  <div class="rto-card rto-mb-4">
    <form id="helpSearchForm">
      <label for="helpSearchInput" class="rto-visually-hidden">Search Help Centre</label>
      <input type="text" id="helpSearchInput" class="rto-input" style="font-size:15px;padding:12px"
             placeholder="Search — e.g. &quot;why isn't this provider showing&quot;, &quot;change service pricing&quot;, &quot;deactivate a category&quot;…"
             value="<?= esc_attr($query) ?>">
    </form>
    <div id="helpSearchResults" style="margin-top:10px"></div>
  </div>

  <?php if ($article): ?>
    <div class="rto-card">
      <p><a href="?rto_area=admin&amp;rto_page=help">← Back to Help Centre</a></p>
      <span class="rto-badge rto-badge-secondary"><?= esc_html($article['module']) ?></span>
      <h2 style="margin-top:8px"><?= esc_html($article['title']) ?></h2>
      <p style="color:#6b7280"><?= esc_html($article['summary']) ?></p>

      <?php if (!empty($article['what_is'])): ?>
        <h3>What is this screen?
          <?php if (!empty($article['admin_overridden'])): ?>
          <span class="rto-badge rto-badge-secondary rto-badge-xs">Admin-edited</span>
          <?php endif; ?>
        </h3>
        <p id="helpWhatIsText"><?= esc_html($article['what_is']) ?></p>
      <?php endif; ?>

      <?php
      // ENTERPRISE GAP FIX (Phase 5, item 4 — "Help Center becomes
      // admin-editable"): rest of the article stays hand-curated PHP by
      // design (see HelpContent.php's own docblock); only this one
      // narrative field is editable, and always visibly labelled when it
      // has been — see HelpContent::getMerged().
      if (rto_is_admin()):
      ?>
      <div class="rto-card rto-mt-4" style="background:var(--gray-50)">
        <div class="rto-card-header"><h4 style="margin:0">Edit "What is this screen?" (Admin Note)</h4></div>
        <div class="rto-card-body">
          <p class="rto-small rto-muted" style="margin:0 0 8px">This overrides the built-in narrative above for every viewer. Leave blank and save to revert to the default text.</p>
          <textarea id="helpOverrideText" class="rto-input" rows="5"><?= esc_textarea(!empty($article['admin_overridden']) ? $article['what_is'] : '') ?></textarea>
          <div id="helpOverrideMsg" style="margin-top:8px"></div>
          <button type="button" id="helpOverrideSaveBtn" class="rto-btn rto-btn-primary rto-mt-2">Save</button>
        </div>
      </div>
      <script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
      (function() {
        var btn = document.getElementById('helpOverrideSaveBtn');
        if (!btn) return;
        btn.addEventListener('click', function() {
          var text = document.getElementById('helpOverrideText').value;
          var msgEl = document.getElementById('helpOverrideMsg');
          var fd = new FormData();
          fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin');
          fd.append('rto_action', 'help.save_override');
          fd.append('slug', <?= json_encode($articleSlug) ?>);
          fd.append('what_is', text);
          fd.append('rto_nonce', rtoflowAdmin.nonce);
          btn.disabled = true;
          fetch(rtoflowAdmin.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(r) {
              btn.disabled = false;
              var msg = (r.data && r.data.message) ? r.data.message : (r.success ? 'Saved.' : 'Failed.');
              msgEl.innerHTML = '<div class="rto-msg ' + (r.success ? 'rto-msg-success' : 'rto-msg-error') + '">' + msg + '</div>';
              if (r.success) setTimeout(function() { location.reload(); }, 900);
            })
            .catch(function() { btn.disabled = false; msgEl.innerHTML = '<div class="rto-msg rto-msg-error">Request failed.</div>'; });
        });
      })();
      </script>
      <?php endif; ?>
      <?php if (!empty($article['why_exists'])): ?>
        <h3>Why does it exist?</h3><p><?= esc_html($article['why_exists']) ?></p>
      <?php endif; ?>
      <?php if (!empty($article['who'])): ?>
        <h3>Who should use it?</h3><p><?= esc_html(implode(', ', $article['who'])) ?></p>
      <?php endif; ?>
      <?php if (!empty($article['when'])): ?>
        <h3>When should it be used?</h3><ul><?php foreach ($article['when'] as $w): ?><li><?= esc_html($w) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <?php if (!empty($article['fits_into'])): ?>
        <h3>How this fits into the marketplace</h3><p><?= esc_html($article['fits_into']) ?></p>
      <?php endif; ?>
      <?php if (!empty($article['source_of_truth'])): ?>
        <h3>Source of truth</h3><p><?= esc_html($article['source_of_truth']) ?></p>
      <?php endif; ?>
      <?php if (!empty($article['sections'])): ?>
        <h3>Sections</h3>
        <?php foreach ($article['sections'] as $s): ?>
          <p><strong><?= esc_html($s['name']) ?>:</strong> <?= esc_html($s['body']) ?></p>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if (!empty($article['fields'])): ?>
        <h3>Field guide</h3>
        <?php foreach ($article['fields'] as $f): ?>
          <p><strong><?= esc_html($f['name']) ?></strong> — <?= esc_html($f['meaning']) ?><br>
          <em>Effect:</em> <?= esc_html($f['affects']) ?></p>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if (!empty($article['buttons'])): ?>
        <h3>Buttons</h3>
        <?php foreach ($article['buttons'] as $b): ?>
          <p><strong><?= esc_html($b['name']) ?></strong> — <?= esc_html($b['does']) ?>
          <?php if (!empty($b['confirmation'])): ?><br><em>Confirmation:</em> <?= esc_html($b['confirmation']) ?><?php endif; ?>
          <?php if (!empty($b['reversible'])): ?><br><em>Reversible:</em> <?= esc_html($b['reversible']) ?><?php endif; ?>
          <?php if (!empty($b['does_not'])): ?><br><em>Does NOT:</em> <?= esc_html($b['does_not']) ?><?php endif; ?></p>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if (!empty($article['statuses'])): ?>
        <h3>Statuses</h3>
        <table class="rto-table" data-rto-responsive="cards"><thead><tr><th>Status</th><th>Meaning</th><th>Can move to</th><th>Manual change?</th></tr></thead><tbody>
        <?php foreach ($article['statuses'] as $s): ?>
          <tr><td data-label="Status"><code><?= esc_html($s['name']) ?></code></td><td data-label="Meaning"><?= esc_html($s['meaning']) ?></td><td data-label="Can move to"><?= esc_html($s['can_go_to']) ?></td><td data-label="Manual change?"><?= esc_html($s['manual']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
      <?php if (!empty($article['known_note'])): ?>
        <h3>Note</h3><p><?= esc_html($article['known_note']) ?></p>
      <?php endif; ?>
      <?php if (!empty($article['does_not_control'])): ?>
        <h3>What this screen does NOT control</h3>
        <ul><?php foreach ($article['does_not_control'] as $d): ?><li><?= esc_html($d) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>

      <?php
      // FIX (this revision): Known Limitations and Common Mistakes were
      // upgraded from a single free-text string / a plain string list into
      // structured, code-verified entries (category, where, why, impact,
      // recommended_fix / category, mistake, symptom, fix, prevention) —
      // see HelpContent.php's SCHEMA NOTE. This view now renders the full
      // structured form; the legacy 'known_limitation' singular string
      // (still present on a couple of older articles alongside the new
      // array, for backward compatibility) is rendered too, if present,
      // so nothing that was previously documented silently disappears.
      $limitCategoryColor = [
          'ui' => 'secondary', 'functional' => 'danger', 'workflow' => 'warning',
          'permission' => 'warning', 'validation' => 'warning', 'technical' => 'secondary',
          'css' => 'secondary', 'integration' => 'info',
      ];
      ?>
      <?php if (!empty($article['known_limitation'])): ?>
        <h3>⚠ Known limitation</h3><p><?= esc_html($article['known_limitation']) ?></p>
      <?php endif; ?>
      <?php if (!empty($article['known_limitations'])): ?>
        <h3>⚠ Known Limitations (verified against the platform's code)</h3>
        <?php foreach ($article['known_limitations'] as $lim): ?>
          <div class="rto-card" style="background:#fffbeb;border:1px solid #fde68a;margin-bottom:10px">
            <span class="rto-badge rto-badge-<?= esc_attr($limitCategoryColor[$lim['category']] ?? 'secondary') ?>" style="text-transform:capitalize"><?= esc_html($lim['category']) ?></span>
            <p style="margin:8px 0 4px;font-weight:600;color:#78350f"><?= esc_html($lim['limitation']) ?></p>
            <p style="margin:2px 0"><strong>Where:</strong> <?= esc_html($lim['where']) ?></p>
            <?php if (!empty($lim['why'])): ?><p style="margin:2px 0"><strong>Why it exists:</strong> <?= esc_html($lim['why']) ?></p><?php endif; ?>
            <p style="margin:2px 0"><strong>Impact:</strong> <?= esc_html($lim['impact']) ?></p>
            <p style="margin:2px 0"><strong>Recommended fix:</strong> <?= esc_html($lim['recommended_fix']) ?></p>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (!empty($article['common_mistakes'])): ?>
        <h3>🛑 Common Mistakes &amp; Solutions</h3>
        <?php foreach ($article['common_mistakes'] as $m): ?>
          <?php if (is_array($m)): ?>
            <div class="rto-card" style="background:#fef2f2;border:1px solid #fecaca;margin-bottom:10px">
              <span class="rto-badge rto-badge-secondary" style="text-transform:capitalize"><?= esc_html($m['category']) ?></span>
              <p style="margin:8px 0 4px;font-weight:600;color:#7f1d1d"><?= esc_html($m['mistake']) ?></p>
              <p style="margin:2px 0"><strong>What you'll see:</strong> <?= esc_html($m['symptom']) ?></p>
              <p style="margin:2px 0"><strong>Fix:</strong> <?= esc_html($m['fix']) ?></p>
              <?php if (!empty($m['prevention'])): ?><p style="margin:2px 0"><strong>Prevent it next time:</strong> <?= esc_html($m['prevention']) ?></p><?php endif; ?>
            </div>
          <?php else: ?>
            <p>• <?= esc_html($m) ?></p>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if (!empty($article['troubleshooting'])): ?>
        <h3>Troubleshooting</h3>
        <ul><?php foreach ($article['troubleshooting'] as $t): ?><li><?= esc_html($t) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <?php if (!empty($article['troubleshooting_guide'])): $g = $article['troubleshooting_guide']; ?>
        <h3>🔎 Diagnostic guide: <?= esc_html($g['title']) ?></h3>
        <ol><?php foreach ($g['steps'] as $st): ?><li><?= esc_html($st) ?></li><?php endforeach; ?></ol>
      <?php endif; ?>
      <?php if (!empty($article['related'])): ?>
        <h3>Related screens</h3>
        <p><?php foreach ($article['related'] as $r):
          $rl = $moduleIndex[$r]['label'] ?? $r; ?>
          <a href="?rto_area=admin&amp;rto_page=help&amp;article=<?= esc_attr($r) ?>" class="rto-btn rto-btn-sm" style="margin:0 6px 6px 0"><?= esc_html($rl) ?></a>
        <?php endforeach; ?></p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="rto-card">
      <h2>Browse by module</h2>
      <p style="color:#6b7280">Grouped exactly as the real admin menu is structured — nothing here is a generic marketplace category.</p>
      <table class="rto-table" data-rto-responsive="cards"><thead><tr><th>Screen</th><th>Coverage</th></tr></thead><tbody>
      <?php foreach ($moduleIndex as $slug => $m): [$label, $badge] = $statusLabel[$m['status']]; ?>
        <tr>
          <td data-label="Screen"><?php if ($m['status'] === 'documented'): ?><a href="?rto_area=admin&amp;rto_page=help&amp;article=<?= esc_attr($slug) ?>"><?= esc_html($m['label']) ?></a><?php else: ?><?= esc_html($m['label']) ?><?php endif; ?></td>
          <td data-label="Coverage"><span class="rto-badge rto-badge-<?= $badge ?>"><?= esc_html($label) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
      <p style="color:#6b7280;font-size:13px;margin-top:10px">"Not yet documented" and "Not implemented" screens are listed honestly rather than hidden — see the project's Phase 2 roadmap for when full coverage is planned.</p>
    </div>
  <?php endif; ?>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  var form = document.getElementById('helpSearchForm');
  var input = document.getElementById('helpSearchInput');
  var results = document.getElementById('helpSearchResults');
  // CSP fix: 'nonce-...' only covers <script> elements, not inline
  // onsubmit= attributes — prevent the default submit here instead.
  if (form) form.addEventListener('submit', function(e){ e.preventDefault(); });
  var nonce = (window.rtoflowAdmin || {}).nonce || '';
  var ajaxUrl = (window.rtoflowAdmin || {}).ajax_url || '';
  var timer = null;
  function render(list){
    if (!list.length) { results.innerHTML = input.value.trim() ? '<p style="color:#6b7280">No matching help articles. Try different words, or browse by module below.</p>' : ''; return; }
    results.innerHTML = list.map(function(r){
      return '<div class="rto-card" style="margin-bottom:8px"><a href="?rto_area=admin&rto_page=help&article='+encodeURIComponent(r.slug)+'"><strong>'+r.title+'</strong></a> <span class="rto-badge rto-badge-secondary">'+r.module+'</span><p style="margin:4px 0 0;color:#6b7280">'+r.snippet+'</p></div>';
    }).join('');
  }
  function search(){
    var q = input.value.trim();
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','help.search');
    fd.append('rto_nonce', nonce); fd.append('q', q);
    fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body: fd})
      .then(function(r){ return r.json(); })
      .then(function(d){ render((d.data && d.data.results) || []); })
      .catch(function(){ results.innerHTML = '<p style="color:#991B1B">Search failed — network error.</p>'; });
  }
  input.addEventListener('input', function(){ clearTimeout(timer); timer = setTimeout(search, 250); });
  if (input.value.trim()) search();
})();
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
