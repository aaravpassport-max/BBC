<?php
/**
 * benchmark_vendor_coverage.php
 *
 * CLI-only benchmark/verification script comparing the existing
 * JSON_CONTAINS-based vendor lookup (VendorRepository::get_eligible()) with
 * the new indexed join-table lookup on rto_vendor_coverage
 * (VendorCoverageRepository::get_eligible_by_coverage()).
 *
 * It seeds N vendors x M cities/services (in a throwaway pair of tables,
 * NOT the real rto_vendors/rto_vendor_coverage tables — see "SAFETY" below),
 * runs EXPLAIN against both query shapes, times a batch of lookups against
 * each, and prints a comparison.
 *
 * USAGE (CLI only):
 *   php -f tools/benchmark_vendor_coverage.php -- --vendors=5000 --cities=300 --services=40
 *
 * Requires a MySQL connection (host/user/pass/db via env vars or CLI flags,
 * see CONFIG below). This sandbox has no MySQL instance available, so this
 * script has been written but NOT executed — see the final report for what
 * a real run is expected to show.
 *
 * SAFETY: refuses to run unless invoked from the CLI SAPI, and only ever
 * touches two tables it creates itself (bench_json_vendors,
 * bench_coverage_vendors / bench_coverage) — it never reads or writes the
 * real rto_vendors or rto_vendor_coverage tables, so it's safe to run
 * against a copy of the production schema without risking real data.
 */

declare(strict_types=1);

// ── Guard: CLI only, never includable via a web request ────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is a CLI benchmark tool and cannot be run over the web.\n");
}

// ── CONFIG (override via env vars or --flag=value CLI args) ────────────────
function bench_opt(string $name, $default)
{
    foreach ($GLOBALS['argv'] as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen("--{$name}="));
        }
    }
    $env = getenv(strtoupper($name));
    return $env !== false ? $env : $default;
}

$dbHost = (string) bench_opt('host', getenv('DB_HOST') ?: '127.0.0.1');
$dbUser = (string) bench_opt('user', getenv('DB_USER') ?: 'root');
$dbPass = (string) bench_opt('pass', getenv('DB_PASS') ?: '');
$dbName = (string) bench_opt('db',   getenv('DB_NAME') ?: 'rtoflow_bench');
$dbPort = (int)    bench_opt('port', getenv('DB_PORT') ?: 3306);

$numVendors  = (int) bench_opt('vendors', 5000);
$numCities   = (int) bench_opt('cities', 300);
$numServices = (int) bench_opt('services', 40);
$citiesPerVendor   = (int) bench_opt('cities_per_vendor', 15);
$servicesPerVendor = (int) bench_opt('services_per_vendor', 8);
$lookups     = (int) bench_opt('lookups', 200);

echo "== Vendor Coverage Benchmark ==\n";
echo "vendors={$numVendors} cities={$numCities} services={$numServices} ";
echo "cities/vendor={$citiesPerVendor} services/vendor={$servicesPerVendor} lookups={$lookups}\n\n";

if (!extension_loaded('mysqli')) {
    fwrite(STDERR, "mysqli extension not available — cannot run benchmark in this environment.\n");
    exit(1);
}

$mysqli = @mysqli_init();
if (!$mysqli || !@$mysqli->real_connect($dbHost, $dbUser, $dbPass, null, $dbPort)) {
    fwrite(STDERR, "Could not connect to MySQL at {$dbHost}:{$dbPort} as {$dbUser}. " .
        "This benchmark requires a real MySQL connection — none is available in this sandbox.\n" .
        (mysqli_connect_error() ? ('mysqli error: ' . mysqli_connect_error() . "\n") : ''));
    exit(1);
}

$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
$mysqli->select_db($dbName);

// ── Schema: throwaway tables mirroring the shapes under test ───────────────
$mysqli->query("DROP TABLE IF EXISTS bench_coverage");
$mysqli->query("DROP TABLE IF EXISTS bench_vendors");

$mysqli->query("CREATE TABLE bench_vendors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    kyc_status VARCHAR(20) NOT NULL DEFAULT 'verified',
    rating DECIMAL(3,2) NOT NULL DEFAULT 4.0,
    completion_rate DECIMAL(5,2) NOT NULL DEFAULT 90.0,
    acceptance_rate DECIMAL(5,2) NOT NULL DEFAULT 90.0,
    total_jobs INT UNSIGNED NOT NULL DEFAULT 0,
    cities JSON NULL,
    services JSON NULL,
    INDEX idx_status (status),
    INDEX idx_kyc (kyc_status),
    INDEX idx_rating (rating)
) ENGINE=InnoDB");

$mysqli->query("CREATE TABLE bench_coverage (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id BIGINT UNSIGNED NOT NULL,
    city_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_vendor_city_service (vendor_id, city_id, service_id),
    INDEX idx_vendor (vendor_id),
    INDEX idx_city (city_id),
    INDEX idx_service (service_id)
) ENGINE=InnoDB");

// ── Seed ─────────────────────────────────────────────────────────────────
echo "Seeding {$numVendors} vendors...\n";
mt_srand(42);

for ($i = 0; $i < $numVendors; $i++) {
    $cities = pick_random_ids($numCities, $citiesPerVendor);
    $services = pick_random_ids($numServices, $servicesPerVendor);

    $citiesJson = $mysqli->real_escape_string(json_encode(array_values($cities)));
    $servicesJson = $mysqli->real_escape_string(json_encode(array_values($services)));
    $rating = round(mt_rand(300, 500) / 100, 2);

    $mysqli->query(
        "INSERT INTO bench_vendors (status, kyc_status, rating, completion_rate, acceptance_rate, total_jobs, cities, services)
         VALUES ('active','verified',{$rating},95.0,95.0,10,'{$citiesJson}','{$servicesJson}')"
    );
    $vendorId = $mysqli->insert_id;

    $rows = [];
    foreach ($cities as $cityId) {
        foreach ($services as $serviceId) {
            $rows[] = "({$vendorId},{$cityId},{$serviceId})";
        }
    }
    if ($rows) {
        foreach (array_chunk($rows, 500) as $chunk) {
            $mysqli->query("INSERT IGNORE INTO bench_coverage (vendor_id, city_id, service_id) VALUES " . implode(',', $chunk));
        }
    }
}

function pick_random_ids(int $poolSize, int $count): array
{
    $count = min($count, $poolSize);
    $pool = range(1, $poolSize);
    shuffle($pool);
    return array_slice($pool, 0, $count);
}

echo "Seed complete.\n\n";

// ── EXPLAIN comparison for a representative lookup ─────────────────────────
$sampleCity = mt_rand(1, $numCities);
$sampleService = mt_rand(1, $numServices);

echo "-- EXPLAIN: JSON_CONTAINS approach (city={$sampleCity}, service={$sampleService}) --\n";
print_explain($mysqli, "
    SELECT id, rating, completion_rate, acceptance_rate, total_jobs
    FROM bench_vendors
    WHERE status='active' AND kyc_status='verified' AND rating >= 3.0
      AND JSON_CONTAINS(cities, '{$sampleCity}') AND JSON_CONTAINS(services, '{$sampleService}')
    ORDER BY rating DESC, completion_rate DESC LIMIT 10
");

echo "\n-- EXPLAIN: join-table approach (city={$sampleCity}, service={$sampleService}) --\n";
print_explain($mysqli, "
    SELECT v.id, v.rating, v.completion_rate, v.acceptance_rate, v.total_jobs
    FROM bench_coverage cov
    JOIN bench_vendors v ON v.id = cov.vendor_id
    WHERE cov.city_id = {$sampleCity} AND cov.service_id = {$sampleService}
      AND v.status='active' AND v.kyc_status='verified' AND v.rating >= 3.0
    ORDER BY v.rating DESC, v.completion_rate DESC LIMIT 10
");

function print_explain(mysqli $mysqli, string $sql): void
{
    $result = $mysqli->query("EXPLAIN {$sql}");
    if (!$result) {
        echo "EXPLAIN failed: " . $mysqli->error . "\n";
        return;
    }
    while ($row = $result->fetch_assoc()) {
        echo implode(' | ', array_map(
            static fn($k, $v) => "{$k}={$v}",
            array_keys($row),
            array_values($row)
        )) . "\n";
    }
}

// ── Timed batch comparison ──────────────────────────────────────────────────
echo "\n-- Timing {$lookups} random lookups per approach --\n";

$jsonTotal = 0.0;
$coverageTotal = 0.0;

for ($i = 0; $i < $lookups; $i++) {
    $city = mt_rand(1, $numCities);
    $service = mt_rand(1, $numServices);

    $t0 = microtime(true);
    $mysqli->query("
        SELECT id, rating, completion_rate, acceptance_rate, total_jobs
        FROM bench_vendors
        WHERE status='active' AND kyc_status='verified' AND rating >= 3.0
          AND JSON_CONTAINS(cities, '{$city}') AND JSON_CONTAINS(services, '{$service}')
        ORDER BY rating DESC, completion_rate DESC LIMIT 10
    ")->free();
    $jsonTotal += microtime(true) - $t0;

    $t0 = microtime(true);
    $mysqli->query("
        SELECT v.id, v.rating, v.completion_rate, v.acceptance_rate, v.total_jobs
        FROM bench_coverage cov
        JOIN bench_vendors v ON v.id = cov.vendor_id
        WHERE cov.city_id = {$city} AND cov.service_id = {$service}
          AND v.status='active' AND v.kyc_status='verified' AND v.rating >= 3.0
        ORDER BY v.rating DESC, v.completion_rate DESC LIMIT 10
    ")->free();
    $coverageTotal += microtime(true) - $t0;
}

printf("JSON_CONTAINS approach:  total=%.4fs  avg=%.4fs/query\n", $jsonTotal, $jsonTotal / $lookups);
printf("Join-table approach:     total=%.4fs  avg=%.4fs/query\n", $coverageTotal, $coverageTotal / $lookups);
printf("Speedup: %.2fx\n", $jsonTotal / max($coverageTotal, 0.000001));

// ── Cleanup ──────────────────────────────────────────────────────────────
if (bench_opt('keep', '0') !== '1') {
    $mysqli->query("DROP TABLE IF EXISTS bench_coverage");
    $mysqli->query("DROP TABLE IF EXISTS bench_vendors");
    echo "\n(cleaned up bench tables; pass --keep=1 to retain them for inspection)\n";
}

$mysqli->close();
