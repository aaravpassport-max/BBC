<?php if (!defined('ABSPATH')) exit;
/**
 * TRACE: included via rto_help_box($slug) from any admin view's own file
 *        → looks up HelpContent::get($slug) (the same registry the central
 *        Help Centre reads) → renders an EXPANDED "Need Help With This
 *        Screen?" box: the full multi-paragraph "What This Screen Is"
 *        explanation (not a one-line summary), a "Known Limitations"
 *        panel, and a "Common Mistakes & Solutions" panel, each pulled
 *        from the same structured HelpContent data the central Help
 *        Centre article renders in full
 *        → if the slug has no article yet, renders nothing at all (never a
 *        broken/empty-looking box) — this is the mechanism that keeps this
 *        partial from ever showing fake or placeholder content on a screen
 *        this pass hasn't documented yet.
 * @var array $article
 *
 * FIX (this revision): the previous version of this box showed only the
 * one-line $article['summary'] and, at most, the first common mistake as a
 * bare string — genuinely too thin to satisfy "what this screen is, what
 * it's used for, what admins can do, how it fits into the platform" in
 * place, which is the whole point of a screen-level help box rather than
 * always forcing a click into the central Help Centre. This revision
 * renders the article's full narrative fields directly on the screen, and
 * upgrades Known Limitations / Common Mistakes from bare strings to the
 * structured {category, where/symptom, why, impact, fix} shape so an
 * admin gets the complete diagnostic picture without leaving the page.
 */
if (empty($article)) return;

$limitCategoryColor = [
    'ui' => 'secondary', 'functional' => 'danger', 'workflow' => 'warning',
    'permission' => 'warning', 'validation' => 'warning', 'technical' => 'secondary',
    'css' => 'secondary', 'integration' => 'info',
];
?>
<div class="rto-card" style="margin-top:24px;border-top:3px solid #2563eb">
  <h3 style="margin-top:0">❓ Need Help With This Screen?</h3>

  <div style="color:#374151;line-height:1.65">
    <p style="font-weight:600;color:#111827;margin-bottom:6px">What this screen is, and what it's for</p>
    <?php if (!empty($article['what_is'])): ?><p><?= esc_html($article['what_is']) ?></p><?php endif; ?>
    <?php if (!empty($article['why_exists'])): ?><p><strong>Why it exists:</strong> <?= esc_html($article['why_exists']) ?></p><?php endif; ?>
    <?php if (!empty($article['who'])): ?><p><strong>Who uses it:</strong> <?= esc_html(implode(', ', $article['who'])) ?></p><?php endif; ?>
    <?php if (!empty($article['fits_into'])): ?><p><strong>How it fits into the platform:</strong> <?= esc_html($article['fits_into']) ?></p><?php endif; ?>
    <?php if (!empty($article['sections'])): ?>
      <p style="font-weight:600;color:#111827;margin:14px 0 6px">What you can do here</p>
      <ul style="margin:0;padding-left:20px">
        <?php foreach ($article['sections'] as $s): ?>
          <li style="margin-bottom:4px"><strong><?= esc_html($s['name']) ?>:</strong> <?= esc_html($s['body']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <?php if (!empty($article['known_limitations'])): ?>
    <p style="font-weight:600;color:#111827;margin:18px 0 8px">⚠ Known Limitations (verified against the platform's code)</p>
    <?php foreach ($article['known_limitations'] as $lim): ?>
      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:8px">
        <span class="rto-badge rto-badge-<?= esc_attr($limitCategoryColor[$lim['category']] ?? 'secondary') ?>" style="text-transform:capitalize"><?= esc_html($lim['category']) ?></span>
        <p style="margin:6px 0 4px;font-weight:600;color:#78350f"><?= esc_html($lim['limitation']) ?></p>
        <p style="margin:2px 0;font-size:13px"><strong>Where:</strong> <?= esc_html($lim['where']) ?></p>
        <?php if (!empty($lim['why'])): ?><p style="margin:2px 0;font-size:13px"><strong>Why it exists:</strong> <?= esc_html($lim['why']) ?></p><?php endif; ?>
        <p style="margin:2px 0;font-size:13px"><strong>Impact:</strong> <?= esc_html($lim['impact']) ?></p>
        <p style="margin:2px 0;font-size:13px"><strong>Recommended fix:</strong> <?= esc_html($lim['recommended_fix']) ?></p>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if (!empty($article['common_mistakes'])): ?>
    <p style="font-weight:600;color:#111827;margin:18px 0 8px">🛑 Common Mistakes &amp; Solutions</p>
    <?php foreach (array_slice($article['common_mistakes'], 0, 3) as $m): ?>
      <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;margin-bottom:8px">
        <span class="rto-badge rto-badge-secondary" style="text-transform:capitalize"><?= esc_html($m['category']) ?></span>
        <p style="margin:6px 0 4px;font-weight:600;color:#7f1d1d"><?= esc_html($m['mistake']) ?></p>
        <p style="margin:2px 0;font-size:13px"><strong>What you'll see:</strong> <?= esc_html($m['symptom']) ?></p>
        <p style="margin:2px 0;font-size:13px"><strong>Fix:</strong> <?= esc_html($m['fix']) ?></p>
        <?php if (!empty($m['prevention'])): ?><p style="margin:2px 0;font-size:13px"><strong>Prevent it next time:</strong> <?= esc_html($m['prevention']) ?></p><?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <a class="rto-btn rto-btn-primary rto-btn-sm" style="margin-top:10px" href="?rto_area=admin&amp;rto_page=help&amp;article=<?= esc_attr($articleSlugForHelp) ?>">Learn More — full field reference, statuses &amp; diagnostics →</a>
</div>
