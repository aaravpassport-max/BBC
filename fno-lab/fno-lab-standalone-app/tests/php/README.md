# PHP Tests — What's Actually Runnable, and What Isn't

This project's JavaScript has real, automated, continuously-run test
coverage (`tests/greeks-engine.test.js`, 361 tests). PHP did not, for
most of this project's history — only `php -l` syntax linting.

That gap mattered: a real, production-breaking bug was found in the
JavaScript layer specifically *because* a function finally got its
first real test written for it, after being trusted, untested, since
the very start of the project. This directory exists to start closing
the same gap on the PHP side, honestly — not by claiming more coverage
than actually exists.

## Two genuinely different categories in this folder

### 1. Standalone, dependency-light — runnable right now

- `CredentialEncryptionTest.php`
- `DataQualityEngineTest.php`
- `CircuitBreakerTest.php`
- `ResolveCapabilityTest.php`
- `OrderExecutionSafetyTest.php` — **the most important file in this folder.** Proves, with an actual executed test rather than manual code reading, that real-money order execution is genuinely unreachable (Master Development Prompt §60). If this one ever fails, stop and investigate before deploying anything.
- `RawTickIngestTest.php` — found and fixed a real type mismatch (a DECIMAL column's placeholder using `%s` instead of `%f`) by tracing every column position by hand, then verified the test genuinely catches the bug by temporarily reverting the fix and confirming the test fails on exactly that assertion.
- `EvaluateRejectionsTest.php` — found and fixed a real logic bug: the rejection-outcome classifier assumed any upward price move meant a rejected trade "would have profited," which is backwards for a rejected bearish setup. Verified the same way as `RawTickIngestTest.php`.
- `HypothesisEngineTest.php` — real storage and evaluation for the Participant Payoff Hypothesis Engine (the user's founding vision document's central "test hypotheses over time" ask). Verifies the real PHP evaluation formula matches the real JS formula line for line, not just by assumption.

These test PHP functions that have **zero, or almost zero, WordPress
dependency**. Each file loads the *real* function bodies straight out
of the actual plugin source (`fno-lab.php` / `fno-data-layer.php`) via
a targeted `eval()` — never a reimplementation, since testing a
rewritten copy would only prove the author understood the code, not
that the real code is correct. Where a function needs one specific
WordPress call (`wp_salt()`, for the encryption test), that single
function is stubbed with a fixed, deterministic value — nothing else.

**Run them with:**
```bash
php tests/php/CredentialEncryptionTest.php
php tests/php/DataQualityEngineTest.php
php tests/php/CircuitBreakerTest.php
php tests/php/ResolveCapabilityTest.php
php tests/php/OrderExecutionSafetyTest.php
php tests/php/RawTickIngestTest.php
php tests/php/EvaluateRejectionsTest.php
php tests/php/HypothesisEngineTest.php
```

No WordPress, no MySQL, no Composer, no PHPUnit required. If either of
these ever reports a FAIL, that is real code testing real code — not
a false alarm from an incomplete test environment.

### 2. Requires a full WordPress test scaffold — written, but genuinely NOT executable in this sandbox

- `JournalAndCircuitBreakerTest.php`

This tests real functions that genuinely need `$wpdb`, real database
tables, `wp_set_current_user()`, and the rest of a live WordPress
install to mean anything — there is no honest way to stub around that
without testing something other than the real code path. This file's
own header says so directly: it was written correctly, against
`WP_UnitTestCase` conventions, but has **never actually been run**,
because this development sandbox has no MySQL server and no WordPress
core installed (confirmed directly, more than once across this
project — not assumed).

**To actually run it**, on a real machine with WordPress + MySQL:
```bash
wp scaffold plugin-tests fno-lab-standalone-app
composer require --dev yoast/phpunit-polyfills wp-phpunit/wp-phpunit
# copy this file into the scaffolded tests/ directory, or point
# phpunit.xml at tests/php/ in this repo
vendor/bin/phpunit
```

Until someone runs it for real, every result in that file is
UNVERIFIED, not PASS — stated as such in the file itself, and repeated
here so the two categories in this folder are never confused with each
other.

## Why this split matters

It would have been easy to write more `WP_UnitTestCase`-style files
and call the coverage "done." That would have been a real overclaim —
untested code presented as tested is worse than honestly-labeled gaps,
because it creates false confidence exactly where confidence matters
most. The two files in category 1 are a smaller, honest step: real
functions, really tested, really run, right now — chosen specifically
because they're consequential (the encryption that protects every
stored credential; the data-quality checks that decide whether a
market reading is trustworthy enough to influence a trade), not
because they were the easiest ones to reach.
