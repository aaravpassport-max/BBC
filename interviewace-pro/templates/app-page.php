<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0F0F1A">
<meta name="robots" content="noindex,nofollow">
<title>InterviewAce</title>
<?php
// Inline boot CSS so user sees spinner immediately, before any JS loads
?>
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:100%;height:100%;overflow:hidden}
body{background:#0F0F1A;font-family:-apple-system,'Segoe UI',sans-serif}
#ia-root{width:100%;height:100%;display:flex;flex-direction:column}
/* Boot spinner - centered within ia-root while React loads */
#ia-boot{
  position:fixed;top:0;left:0;width:100%;height:100%;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  background:#0F0F1A;z-index:9999;
}
.ia-boot-logo{font-size:26px;font-weight:800;color:#A78BFA;letter-spacing:-0.5px;margin-bottom:18px}
.ia-boot-spin{width:34px;height:34px;border:3px solid rgba(167,139,250,.2);border-top-color:#A78BFA;border-radius:50%;animation:spin .75s linear infinite;margin:0 auto}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
<?php
// Remove admin bar and its margin
remove_action('wp_head','_admin_bar_bump_cb');
// Only output our plugin's enqueued scripts/styles — nothing from the WP theme
wp_head();
?>
</head>
<body style="margin-top:0!important">
<style>#wpadminbar{display:none!important}html{margin-top:0!important}</style>
<div id="ia-root">
    <div id="ia-boot">
        <div class="ia-boot-logo">InterviewAce</div>
        <div class="ia-boot-spin"></div>
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>
