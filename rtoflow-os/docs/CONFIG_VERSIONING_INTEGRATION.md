# Integrating a config screen with `ConfigVersionService`

This describes, mechanically, how to wire an existing admin config screen
into the generic versioning infrastructure:

- `app/Services/ConfigVersionService.php`
- `app/Controllers/Admin/ConfigVersionController.php`
- `resources/views/admin/partials/config-version-history.php`
- `database/migrations/2024_01_01_000011_create_config_versions.php` (table `rto_config_versions`)

The worked example below is the **Matching Config** tab, backed today by
`RTOFLOW\Config\MatchingConfig::save()` and presumably invoked from a
`SettingsController` matching-tab action. **No changes are made to
`SettingsController.php` or `MatchingConfig.php` by this doc or by this
task** — this is a plan for a future integration pass to follow.

## 1. Pick the `config_key`

Use a short, stable, snake_case string that will never collide with another
surface. For matching config: `'matching_config'`. (Other surfaces already
anticipated by the migration: `'feature_flags'`, `'eligibility_rules'`,
`'city_service_pricing'`.)

## 2. Save a draft instead of (or alongside) `MatchingConfig::save()`

Today, the matching-tab save handler in `SettingsController` presumably does
something like:

```php
public function saveMatchingConfig(): void
{
    if (!check_ajax_referer('rto_admin_lead', false, false)) rto_json_err('Security check failed.', 403);
    if (!rto_is_admin()) rto_json_err('Access denied.', 403);

    MatchingConfig::save($_POST);
    rto_json_ok(null, 'Matching config saved.');
}
```

To integrate versioning, replace the direct `MatchingConfig::save($_POST)`
call with two steps: **validate + normalise the submitted payload the same
way `MatchingConfig::save()` already does**, then hand that clean array to
`ConfigVersionService::saveDraft()` instead of writing straight to
`wp_options`:

```php
use RTOFLOW\Services\ConfigVersionService;

public function saveMatchingConfigDraft(): void
{
    if (!check_ajax_referer('rto_admin_lead', false, false)) rto_json_err('Security check failed.', 403);
    if (!rto_is_admin()) rto_json_err('Access denied.', 403);

    // Reuse MatchingConfig's own clamping/validation logic so a draft is
    // stored in exactly the same normalised shape MatchingConfig::save()
    // would have written to wp_options. If that clamping logic is not
    // already extracted into its own method, extract it first so both the
    // direct-save path and the draft path share one source of truth.
    $payload = MatchingConfig::normalise($_POST); // see note below

    $notes = \RTOFLOW\Security\Sanitiser::text($_POST['notes'] ?? '', 500);

    $versionId = ConfigVersionService::saveDraft(
        'matching_config',
        $payload,
        get_current_user_id(),
        $notes
    );

    if (!$versionId) rto_json_err('Could not save draft. Please try again.');

    rto_json_ok(['version_id' => $versionId], 'Draft saved. Publish it to make it live.');
}
```

> **Note on `MatchingConfig::normalise()`**: `MatchingConfig::save()`
> currently does clamping and writing to `wp_options` in one method. A
> mechanical, low-risk refactor is to split it into a pure
> `normalise(array $submitted): array` (returns the clamped config array,
> no side effects) and have `save()` become
> `update_option(self::OPTION_KEY, wp_json_encode(self::normalise($submitted)))`.
> That gives the draft path and the live-write path exactly one validation
> implementation, so they can never drift out of sync.

## 3. Publish a draft to make it live

Publishing a version needs to do two things: flip its status via
`ConfigVersionService::publish()`, **and** apply that version's payload to
the actual runtime config store (`wp_options`, in `MatchingConfig`'s case),
since `MatchingConfig::get()`/`all()` read from `wp_options`, not from
`rto_config_versions`.

Two ways to do this, in increasing order of decoupling:

**A. Publish-time apply (simplest, do this first).** In the publish AJAX
handler for this surface (or by extending
`ConfigVersionController::publish()` behind a `config_key`-keyed dispatch
table, see below), after `ConfigVersionService::publish($versionId)`
succeeds, fetch that version's `payload_json`, decode it, and call
`MatchingConfig::save()` (or `update_option(MatchingConfig::OPTION_KEY, ...)`
directly) with it:

```php
$ok = ConfigVersionService::publish($versionId);
if ($ok) {
    $version = ConfigVersionService::getCurrentPublished('matching_config');
    if ($version) {
        MatchingConfig::save(json_decode($version['payload_json'], true));
    }
}
```

**B. Event-driven apply (more decoupled, do this once more surfaces are
wired).** Have `ConfigVersionService::publish()` fire a
`do_action('rtoflow_config_published', $configKey, $payload)` WordPress
hook, and have each config surface (`MatchingConfig`, `FeatureFlags`, etc.)
register a listener that applies the payload to its own storage. This keeps
`ConfigVersionService` fully config-agnostic (it already is — it never
imports `MatchingConfig`) while still letting a publish become "live"
automatically for any wired surface.

Either way, **`ConfigVersionController::publish()` as delivered only flips
status in `rto_config_versions`** — it deliberately does not know about
`MatchingConfig`, `FeatureFlags`, etc. Wiring the "apply to runtime storage"
step is the one piece each integration must add for its own surface,
using approach A or B above.

## 4. Rollback

No new code is needed for rollback itself: `ConfigVersionService::rollback()`
already clones the target version and calls `publish()` on the clone
internally, so as long as step 3's "apply to runtime storage" logic is
attached to `publish()` (approach A or B), rollback automatically becomes
live the same way a fresh publish does.

## 5. Render the history UI

In `resources/views/admin/settings/matching.php` (or wherever the matching
tab's view lives), include the reusable partial:

```php
<?php
$configVersionKey = 'matching_config';
include RTOFLOW_DIR . 'resources/views/admin/partials/config-version-history.php';
?>
```

The partial is self-contained: it fetches its own history via
`admin.config_version.history` and wires up its own "Rollback to this
version" buttons against `admin.config_version.rollback`. Include it once
per config surface per page (pass a distinct `$configVersionContainerId` if
a page ever needs to show more than one surface's history at once).

## 6. Repeat for the other three surfaces

The same four steps apply to:

- **Feature Flags** (`config_key = 'feature_flags'`): draft the payload
  `FeatureFlags::bulk_save()` would have written; publish-time apply calls
  `FeatureFlags::bulk_save()` (or a new `FeatureFlags::apply(array $flags)`
  that skips the "only known keys" `isset()` filtering already done at
  submit time, since the stored draft is already the full normalised set).
- **Eligibility Rules** (`config_key = 'eligibility_rules'`): the payload is
  the full rule set (an array of rule rows), not a single scalar blob like
  the other two. Draft/publish here wraps the *whole rule set* as one
  versioned snapshot in addition to (not instead of) the existing per-rule
  CRUD in `EligibilityController`, so "publish" means "replace all active
  rules with this snapshot's rules" — apply-on-publish would diff the
  snapshot against `rto_eligibility_rules` and upsert/deactivate rows to
  match.
- **City/Service Pricing** (`config_key = 'city_service_pricing'`): the
  payload is the full `rto_city_service_config` table content for one city
  (or all cities); apply-on-publish re-runs the same upsert loop
  `CityServiceConfigController::saveConfig()` already uses, sourced from the
  version's payload instead of `$_POST`.

In every case, `ConfigVersionService` and `ConfigVersionController` need no
changes — only each surface's own controller gains a "save as draft" action
and an "apply on publish" hook.
