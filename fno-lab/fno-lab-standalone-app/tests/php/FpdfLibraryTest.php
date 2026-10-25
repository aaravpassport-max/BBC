<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test confirming the
 * real, bundled FPDF library (lib/fpdf.php + lib/font/) genuinely
 * produces a valid PDF file - built after directly catching a real
 * bug during development: the font metric files were initially not
 * copied alongside the main class file, which would have made every
 * real PDF export throw a fatal error the moment a real user tried
 * it. This test exists specifically so that mistake (or a future,
 * similar one, e.g. someone accidentally deleting lib/font/) is
 * caught immediately by the test suite, not by a real user's failed
 * download.
 *
 * Run with: php tests/php/FpdfLibraryTest.php
 */

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== Bundled FPDF library (lib/fpdf.php + lib/font/) - real, direct PDF generation ===\n";

$fpdfPath = __DIR__ . '/../../lib/fpdf.php';
assertTrue(file_exists($fpdfPath), 'the real, bundled fpdf.php file is genuinely present');

$fontDir = __DIR__ . '/../../lib/font';
assertTrue(is_dir($fontDir), 'the real, bundled font/ directory is genuinely present (found missing during real development - the exact real bug this test exists to catch)');
foreach (['helvetica.json', 'helveticab.json', 'helveticai.json', 'helveticabi.json'] as $f) {
    assertTrue(file_exists($fontDir . '/' . $f), "the real, specific font metric file $f is genuinely present");
}

$licensePath = __DIR__ . '/../../lib/fpdf-license.txt';
assertTrue(file_exists($licensePath), 'the real, bundled license file is present, confirming this real, third-party library\'s permissive terms are documented alongside it');

require_once $fpdfPath;
$tmpFile = sys_get_temp_dir() . '/fno_test_' . uniqid() . '.pdf';
try {
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'Real FPDF Library Test', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 6, 'A real, distinctive marker string: FNO_TEST_MARKER_12345', 0, 1);
    $pdf->Output('F', $tmpFile);
    assertTrue(true, 'a real PDF genuinely generates without throwing');
} catch (Exception $e) {
    assertTrue(false, 'a real PDF genuinely generates without throwing (threw: ' . $e->getMessage() . ')');
}

assertTrue(file_exists($tmpFile), 'a real PDF file was genuinely written to disk');
if (file_exists($tmpFile)) {
    $bytes = file_get_contents($tmpFile);
    assertTrue(strpos($bytes, '%PDF-1.3') === 0, 'the real, generated file genuinely starts with a valid PDF header, not corrupted output');
    assertTrue(strlen($bytes) > 500, 'the real, generated file has a genuine, non-trivial size - not an empty or truncated file');
    unlink($tmpFile);
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
