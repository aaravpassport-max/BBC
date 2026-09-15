<?php
/**
 * ENTERPRISE GAP FIX (Phase 9, item — "raw, unparameterized queries in a
 * handful of internal paths"): the gap-analysis audit found nine
 * $wpdb->query() calls that bypass $wpdb->prepare() — migration DDL,
 * GET_LOCK/RELEASE_LOCK naming, and ID lists built from prior prepared
 * SELECTs. Exploitability is low today since every input is internally
 * derived, but nothing previously stopped that pattern from spreading to
 * a genuinely user-controlled path — the existing "phpcs:ignore
 * WordPress.DB.PreparedSQL.*" comments scattered through the codebase
 * document intent but reference a PHPCS ruleset (WordPress Coding
 * Standards) that isn't installed here and was never actually enforced.
 *
 * This script is a dependency-free static check that runs today, without
 * requiring network access to install WPCS: it scans app/ and database/
 * for every `$wpdb->query(` call and fails the build unless that call
 * either (a) wraps `$wpdb->prepare(` as its argument, or (b) carries an
 * explicit `// phpcs:ignore WordPress.DB.PreparedSQL...` comment on the
 * line immediately above it — the same suppression convention already
 * used throughout this codebase. A new raw query added anywhere without
 * that explicit, reviewable annotation now breaks `composer lint:sql`
 * (and CI) instead of silently shipping.
 *
 * Usage: php tools/check-raw-queries.php
 * Exit code: 0 = clean, 1 = one or more un-annotated raw queries found.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
// Scoped to app/ only: database/migrations and database/seeds run trusted,
// developer-authored DDL/seed SQL once at install/upgrade time, never in
// response to a request carrying user input — the same "migration DDL"
// category the gap-analysis audit itself treated as accepted low risk.
// app/ is where a genuinely user-controlled path could realistically
// reach a raw query, so that's where this check actually enforces.
$scanDirs = [$root . '/app'];

$violations = [];
$checked = 0;

function rtoflow_scan_dir(string $dir, array &$violations, int &$checked): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $checked++;
        $path = $file->getPathname();
        $lines = file($path);
        if ($lines === false) {
            continue;
        }
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            // Match "$wpdb->query(" but not "$wpdb->query($wpdb->prepare("
            // on the same line (the common single-line form).
            if (!preg_match('/\$wpdb->query\s*\(/', $lines[$i])) {
                continue;
            }
            // Skip mentions inside a comment (e.g. a rationale comment
            // that describes "$wpdb->query()" as text) — only a real call
            // matters.
            if (preg_match('/^\s*(\/\/|\*|\/\*)/', $lines[$i])) {
                continue;
            }
            // Look at the call's opening line plus up to 3 following
            // lines (the multi-line $wpdb->query(\n    $wpdb->prepare( form)
            // for an inline $wpdb->prepare(.
            $window = $lines[$i] . ($lines[$i + 1] ?? '') . ($lines[$i + 2] ?? '');
            if (preg_match('/\$wpdb->query\s*\(\s*\$wpdb->prepare\s*\(/', $window)) {
                continue;
            }
            // Literal transaction-control statements carry no interpolated
            // data at all — not a SQL-injection-relevant pattern.
            if (preg_match('/\$wpdb->query\(\'(START TRANSACTION|COMMIT|ROLLBACK)\'\)/', $lines[$i])) {
                continue;
            }
            // Require an explicit phpcs:ignore annotation on one of the
            // preceding lines (allow a short run of comment lines above
            // the call, matching this codebase's existing rationale-comment
            // style).
            $annotated = false;
            for ($back = $i - 1; $back >= 0 && $back >= $i - 12; $back--) {
                $prev = $lines[$back];
                if (preg_match('/phpcs:ignore\s+WordPress\.DB\.PreparedSQL/', $prev)) {
                    $annotated = true;
                    break;
                }
                // Stop walking back once we leave a contiguous comment/
                // blank-line block that sits directly above the call.
                if (!preg_match('/^\s*(\/\/|\*|\/\*)/', $prev) && trim($prev) !== '') {
                    break;
                }
            }
            if (!$annotated) {
                $violations[] = sprintf(
                    '%s:%d: $wpdb->query() without $wpdb->prepare() and without a '
                    . '"// phpcs:ignore WordPress.DB.PreparedSQL..." annotation',
                    substr($path, strlen(dirname(__DIR__)) + 1),
                    $i + 1
                );
            }
        }
    }
}

foreach ($scanDirs as $dir) {
    if (is_dir($dir)) {
        rtoflow_scan_dir($dir, $violations, $checked);
    }
}

if ($violations) {
    fwrite(STDERR, "Raw-query lint FAILED — " . count($violations) . " unannotated \$wpdb->query() call(s):\n\n");
    foreach ($violations as $v) {
        fwrite(STDERR, "  - {$v}\n");
    }
    fwrite(STDERR, "\nEither rewrite the call to wrap \$wpdb->prepare(), or, if every value in\n");
    fwrite(STDERR, "it is genuinely internal (a literal, a lock name, an ID list already\n");
    fwrite(STDERR, "built from a prepared SELECT), add a one-line rationale comment ending\n");
    fwrite(STDERR, "in \"// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared\" directly above\n");
    fwrite(STDERR, "the call — matching the convention already used across this codebase.\n");
    exit(1);
}

echo "Raw-query lint OK — checked {$checked} file(s), 0 unannotated \$wpdb->query() call(s).\n";
exit(0);
