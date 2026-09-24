# Real WordPress PHPUnit Test Bootstrap — Feasibility Investigation (2026-09-02)

## Goal

`tests/php/JournalAndCircuitBreakerTest.php` and `tests/php/FactorHealthTest.php`
both require a real `WP_UnitTestCase` base class plus a real WordPress core
checkout and a real MySQL/MariaDB database (the standard `wp-phpunit` /
`wordpress-develop` test scaffold). Every other PHP test in this repo was
verified by extracting the target function and running it standalone against
a hand-built `$wpdb` mock — real code, but never against real WordPress
machinery. This document records a real, timed attempt to close that gap in
this sandbox, its outcome, and exactly what a capable environment would need.

## What was checked

| Component | Status | Evidence |
|---|---|---|
| PHP | Present, 8.4.21 CLI | `php -v` |
| Composer | Present | `which composer` -> `/usr/local/bin/composer` |
| MySQL / MariaDB / SQLite server binaries | **Not installed**, but `apt` candidates exist | `apt-cache policy mysql-server mariadb-server sqlite3` shows installable candidates (mysql-server 8.0.45, mariadb-server 10.11.14, sqlite3 3.45.1) from the local Ubuntu noble mirror |
| Outbound network in general | Restricted to an explicit allowlist enforced by a policy-checking egress proxy (see `/root/.ccr/README.md`) | `curl -sS "$HTTPS_PROXY/__agentproxy/status"` |
| `wordpress.org` / `api.wordpress.org` (WP core zip, version-check API) | **Blocked** | `curl` to both returns `CONNECT tunnel failed, response 403`; proxy status log records `connect_rejected` / "gateway answered 403 to CONNECT (policy denial)" for both hosts |
| `develop.svn.wordpress.org` (official WP core+test-suite SVN checkout, the method the WordPress Handbook recommends for `install-wp-tests.sh`) | **Blocked** | Same `403 CONNECT tunnel failed` |
| `repo.packagist.org` (Composer's default repository, needed for `composer require wp-phpunit/wp-phpunit` or `yoast/wp-test-utils`) | **Blocked** | Same `403 CONNECT tunnel failed` |
| `github.com`, `codeload.github.com` (git clone / zip download of `WordPress/wordpress-develop` or `wp-phpunit/wp-phpunit` mirrors) | **Blocked** | `github.com` GET -> 403; `codeload.github.com` zip -> 403 |
| `api.github.com` | Reachable, but sandboxed to Claude's own repo-access broker, not general GitHub API | Any repo call returns `{"message":"GitHub access to this repository is not enabled for this session. Use add_repo to request access."}` — this is Claude Code's own repo allowlist, not a path to arbitrary WordPress-org repos, and even if a repo were added it only grants API/content access to that specific added repo, not a general clone/tarball mechanism sufficient for a large monorepo like `wordpress-develop` |
| `raw.githubusercontent.com` | Reachable for **individual known file paths** (e.g. fetched `wp-phpunit/wp-phpunit`'s `README.md` successfully) | `curl` returned real file content, HTTP 200 |
| `cdn.jsdelivr.net`, `data.jsdelivr.com` (jsDelivr's GitHub-mirror CDN, which would let a whole repo be listed/fetched without `git clone`) | **Blocked** | `403 CONNECT tunnel failed` |
| `registry.npmjs.org` (checked as a long-shot alternate mirror) | Reachable (it's on the explicit proxy allowlist), but no npm package bundles a full WordPress core + test suite — only `@wordpress/*` are Gutenberg JS component packages, unrelated to PHP core | `npm search` results confirmed no relevant package |

## Why "`raw.githubusercontent.com` works" does not actually solve this

`raw.githubusercontent.com` will serve any *individual, known* file path from
a public GitHub repo, and it is not blocked by the proxy policy the way
`github.com`/`codeload.github.com`/`packagist.org`/`jsdelivr` are. In
principle this could be used to reconstruct a repository file-by-file — but
that requires first *enumerating* the file list, and every avenue that would
provide that enumeration is itself blocked:

- `git clone` (needs `github.com` over HTTPS) — blocked.
- GitHub's tarball/zip endpoints (`codeload.github.com`, `api.github.com/.../tarball/...`) — blocked (the latter also gated behind Claude's own repo-allowlist, unrelated to network policy).
- The GitHub Trees API (`api.github.com/repos/.../git/trees/...?recursive=1`) — reachable as a host, but rejected per-repo unless the repo has been explicitly added via `add_repo`, which is a Claude Code repo-access mechanism, not a general path to `WordPress/wordpress-develop` (a huge, unrelated third-party mirror repo that has no reason to be added to this session).
- jsDelivr's directory-listing API (`data.jsdelivr.com`) — blocked.

`WordPress/wordpress-develop` (the repo that contains both WP core and the
`WP_UnitTestCase` test-suite libraries) has several thousand files. Without
any of the above enumeration mechanisms, downloading it file-by-file via
`raw.githubusercontent.com` alone is not practically possible — there is no
way to discover the complete, correct file list from inside this sandbox.

## Verdict: NOT feasible in this sandbox, for a concrete and provable reason

- MySQL/MariaDB itself **is** installable here (`apt` has working candidates
  from the local package mirror) — that part of the gap is closeable.
- **The blocker is exclusively getting a copy of WordPress core + the
  WP_UnitTestCase test-suite library into this sandbox.** Every standard
  distribution channel for that (wordpress.org zip, WordPress SVN, Packagist,
  GitHub clone/tarball/API-tree-listing, jsDelivr's GitHub mirror) is denied
  by this session's egress policy with an explicit `403` at the CONNECT
  level — not a timeout, not a DNS failure, a policy denial recorded in the
  proxy's own status log (`recentRelayFailures`, `kind: "connect_rejected"`,
  `"gateway answered 403 to CONNECT (policy denial or upstream failure)"`).
  Per this environment's own operating instructions
  (`/root/.ccr/README.md`: *"do not retry organization policy denials
  (403/407) — report them instead"*), these are not to be worked around.
- This was time-boxed deliberately: the investigation above (roughly a dozen
  targeted `curl` probes against the specific hosts the official WordPress
  test-suite install process requires, plus two plausible alternate mirrors)
  took a few minutes and produced a clean, reproducible negative — not a
  vague "seems hard." No partial WP core checkout, no partial database
  bootstrap, and no test run was attempted past this point, because without
  WordPress core + `WP_UnitTestCase` itself there is nothing for MySQL to
  bootstrap against.

## What a capable environment would need

To actually close this gap, run this in an environment with outbound network
access to at least one of `wordpress.org`, `develop.svn.wordpress.org`, or
`github.com`/`codeload.github.com` (or with the WordPress core+test-suite
tarball pre-staged locally), plus a MySQL/MariaDB server (`apt install
mariadb-server mysql-server` works fine wherever apt has that access, as
confirmed here):

```bash
# 1. Install a database server (confirmed installable via apt in this sandbox
#    itself — this part is NOT the blocker)
sudo apt-get update && sudo apt-get install -y mariadb-server
sudo service mariadb start
mysql -u root -e "CREATE DATABASE wordpress_test; \
  CREATE USER 'wp_test'@'localhost' IDENTIFIED BY 'wp_test'; \
  GRANT ALL PRIVILEGES ON wordpress_test.* TO 'wp_test'@'localhost'; \
  FLUSH PRIVILEGES;"

# 2. Fetch WordPress core + the WP_UnitTestCase test-suite library
#    (THIS is the step that is blocked in this sandbox — needs network
#    access to one of the hosts below)
svn export https://develop.svn.wordpress.org/trunk/ /tmp/wordpress-develop
# or: composer require --dev wp-phpunit/wp-phpunit yoast/wp-test-utils
# or: the classic WP-CLI helper script:
#     bash bin/install-wp-tests.sh wordpress_test wp_test wp_test localhost latest

# 3. Point this repo's phpunit bootstrap at the fetched test-suite
#    (WP_TESTS_DIR / wp-phpunit's `includes/` directory), configure
#    wp-tests-config.php with the DB credentials from step 1, then:
vendor/bin/phpunit -c tests/php/phpunit.xml.dist \
  tests/php/JournalAndCircuitBreakerTest.php tests/php/FactorHealthTest.php
```

Once network access to any one of those hosts is available, this is a
standard, well-documented WordPress plugin test setup (the exact process
described in the WordPress core Handbook's "Plugin Unit Tests" page) — there
is nothing repo-specific blocking it beyond the network restriction recorded
above.

## Bottom line

This is a genuine, evidenced "cannot verify in this specific sandboxed
environment" finding, not an unexplored gap: MySQL/MariaDB is installable
here, but every channel for obtaining WordPress core + `WP_UnitTestCase`
(wordpress.org, WP SVN, Packagist, GitHub clone/tarball/tree-API, jsDelivr's
GitHub mirror) returns a policy-level `403` at the network layer, and this
environment's own operating instructions say not to work around such denials.
`tests/php/JournalAndCircuitBreakerTest.php` and `tests/php/FactorHealthTest.php`
remain unexecuted against real WordPress/MySQL machinery — exactly as every
prior pass in this audit already documented — but this pass adds the first
concrete, reproducible proof of *why*, rather than an assumption.
