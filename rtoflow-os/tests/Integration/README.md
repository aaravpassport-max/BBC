# Integration Tests — Not Implemented

This directory intentionally contains no test cases yet.

The `tests/Unit` suite covers pure-logic classes (GST math, eligibility rule
evaluation, config defaults/clamping, encryption round-trips) either with no
WordPress dependency at all, or with the handful of WordPress functions they
call (`get_option()`, `update_option()`, `wp_json_encode()`, `current_time()`)
stubbed in `tests/bootstrap.php` as simple in-memory implementations.

That approach does not scale to the rest of the codebase. Most of the
business logic in `app/Services` (e.g. `LeadService`, `VendorService`,
`WorkflowService`, `PaymentService`, `AutomationService`,
`EligibilityService::evaluate()`) talks to `$wpdb` directly with real SQL
(`SELECT`/`INSERT`/`JOIN` against `wp_rto_*` tables), calls WordPress core
functions beyond the small stubbed set (`wp_insert_post`, `wp_mail`,
`current_user_can`, hooks via `do_action`/`apply_filters`, etc.), and in
several places depends on request state (`$_POST`, nonces, capability
checks) wired up by `RTOFLOW\Http\Router`. None of that can be faithfully
exercised by hand-stubbing individual functions — doing so would either
under-test the SQL (most bugs in this codebase are in WHERE clauses, JOINs,
and state transitions) or require reimplementing large chunks of `$wpdb`
and WordPress core, which is a maintenance trap of its own.

This sandbox has no live WordPress install, no MySQL server, and no network
access to fetch one — so a real integration suite could not actually be run
here, and nothing under this directory is presented as if it does.

## What a real integration suite needs

1. **wp-phpunit scaffold.** `wp-phpunit/wp-phpunit` is already a dev
   dependency in `composer.json`. It needs `WP_TESTS_DIR` (or the package's
   own path) plus a `wp-tests-config.php` pointing at a real test database,
   and a bootstrap that calls WordPress's own
   `tests/phpunit/includes/bootstrap.php` (loads WP core, then this plugin
   via `tests_add_filter('muplugins_loaded', ...)`) instead of
   `tests/bootstrap.php`'s function stubs.

2. **A MySQL test database.** WordPress's test bootstrap creates and tears
   down the `wp_*` core tables itself, but this plugin's own tables
   (`wp_rto_leads`, `wp_rto_services`, `wp_rto_eligibility_rules`,
   `wp_rto_eligibility_checks`, `wp_rto_states`, `wp_rto_cities`,
   `wp_rto_vendors`, etc.) need their own install routine run first — check
   `rtoflow-os.php`'s activation hook / `database/` migrations — against a
   disposable MySQL/MariaDB instance (e.g. a `mysql:8` service container in
   CI), reset between tests via `WP_UnitTestCase`'s transaction rollback or
   an explicit `TRUNCATE`.

3. **Seed data.** Fixture rows for at least: one or two `rto_services`
   (GST-applicable and exempt), `rto_states`/`rto_cities` covering an
   intra-state and an inter-state pair, a handful of `rto_vendors` with
   varying ratings/active-job counts to exercise `MatchingConfig`-driven
   auto-assignment, and `rto_eligibility_rules` rows to test
   `EligibilityService::evaluate()`'s actual DB-backed path (rule loading,
   ordering, and the `rto_eligibility_checks` audit insert) as opposed to
   the pure operator logic already covered by
   `tests/Unit/Services/EligibilityServiceTest.php`.

4. **A separate PHPUnit bootstrap and testsuite.** `phpunit.xml.dist`
   already declares an `Integration` testsuite pointed at this directory;
   once the above exists, add an `Integration`-specific bootstrap (or branch
   `tests/bootstrap.php` on an `RTOFLOW_INTEGRATION` env var) that loads the
   wp-phpunit scaffold instead of the in-memory stubs, and wire a MySQL
   service container into `.github/workflows/ci.yml`'s `Integration` job
   (currently commented out there for the same reason: no DB available).

Until that infrastructure exists, treat `composer test-integration` as a
placeholder that will report "no tests executed" rather than a signal that
integration behavior is verified.
