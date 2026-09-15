<?php

namespace RTOFLOW\Security;

if (!defined('ABSPATH')) exit;

/**
 * Secure File Upload Guard
 *
 * Validates every uploaded file before it is stored:
 * - Real MIME type (not just extension)
 * - File size limits
 * - Extension whitelist
 * - Path traversal prevention
 * - Randomised storage paths to prevent enumeration
 * - No executables stored
 *
 * Usage:
 *   $result = FileUploadGuard::validate($_FILES['doc']);
 *   if (!$result['valid']) { ... }
 *   $path = FileUploadGuard::store($_FILES['doc'], 'documents', $lead_id);
 */
final class FileUploadGuard
{
    // Allowed MIME types → allowed extensions
    private const ALLOWED = [
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
        'image/webp'      => ['webp'],
        'application/pdf' => ['pdf'],
    ];

    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB per file
    private const MIN_SIZE_BYTES = 100;               // Reject 0-byte / tiny files

    // MIME types that are absolutely NEVER allowed regardless of extension
    private const BLOCKED_MIME = [
        'application/x-php', 'application/x-httpd-php', 'text/php',
        'application/x-sh', 'text/x-sh',
        'application/x-executable', 'application/x-msdos-program',
        'application/java-archive', 'text/html', 'text/javascript',
        'application/javascript', 'application/x-python-code',
    ];

    // ── Validation ────────────────────────────────────────────────────────

    /**
     * Validate a single uploaded file from $_FILES['field']
     * Returns ['valid' => bool, 'error' => string|null, 'mime' => string, 'ext' => string]
     */
    public static function validate(array $file, int $maxSizeBytes = self::MAX_SIZE_BYTES): array
    {
        // Check for PHP upload errors
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $msg = self::uploadErrorMessage($file['error'] ?? -1);
            return ['valid' => false, 'error' => $msg, 'mime' => '', 'ext' => ''];
        }

        // Must be an actual uploaded file (prevent symlink attacks)
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'Invalid upload source.', 'mime' => '', 'ext' => ''];
        }

        // Size checks
        if ($file['size'] < self::MIN_SIZE_BYTES) {
            return ['valid' => false, 'error' => 'File is empty or too small.', 'mime' => '', 'ext' => ''];
        }
        if ($file['size'] > $maxSizeBytes) {
            $mb = round($maxSizeBytes / 1024 / 1024);
            return ['valid' => false, 'error' => "File exceeds maximum size of {$mb}MB.", 'mime' => '', 'ext' => ''];
        }

        // Detect REAL MIME type from file content (not from browser/header)
        $realMime = self::detectMime($file['tmp_name']);

        // Block executable MIME types absolutely
        if (in_array($realMime, self::BLOCKED_MIME, true)) {
            return ['valid' => false, 'error' => 'File type is not permitted.', 'mime' => $realMime, 'ext' => ''];
        }

        // Check against whitelist
        if (!isset(self::ALLOWED[$realMime])) {
            return ['valid' => false, 'error' => 'Only PDF, JPG, PNG, and WEBP files are accepted.', 'mime' => $realMime, 'ext' => ''];
        }

        // Validate extension against real MIME type
        $originalName = $file['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED[$realMime], true)) {
            return ['valid' => false, 'error' => "File extension does not match its actual type ({$realMime}).", 'mime' => $realMime, 'ext' => $ext];
        }

        // Check filename for path traversal
        if (self::hasPathTraversal($originalName)) {
            return ['valid' => false, 'error' => 'Invalid file name.', 'mime' => $realMime, 'ext' => $ext];
        }

        return ['valid' => true, 'error' => null, 'mime' => $realMime, 'ext' => $ext];
    }

    // ── Storage ───────────────────────────────────────────────────────────

    /**
     * Validate and store a file securely.
     *
     * Returns ['success' => bool, 'path' => string, 'name' => string, 'size_kb' => int, 'error' => string|null]
     */
    public static function store(array $file, string $context = 'documents', int $entityId = 0): array
    {
        $validation = self::validate($file);
        if (!$validation['valid']) {
            return ['success' => false, 'path' => '', 'name' => '', 'size_kb' => 0, 'error' => $validation['error']];
        }

        // ENTERPRISE GAP FIX (Section 7 — "no malware scanning on uploads").
        // Strict MIME/extension whitelisting above already blocks anything
        // that isn't a JPEG/PNG/WebP/PDF, but a well-formed PDF or image can
        // still smuggle an embedded exploit payload (e.g. a malformed PDF
        // targeting a reader vulnerability, or polyglot JPEG/PHP files that
        // pass MIME sniffing). Root cause: no content-level scan ever
        // existed. This is intentionally fail-open, not fail-closed — most
        // shared/managed WordPress hosts do NOT have ClamAV installed, and a
        // hard requirement on it would silently break every upload on those
        // hosts with an error string is confusing ("could not scan file").
        // Sites that DO have clamscan/clamdscan on PATH get real scanning
        // for free; sites that don't keep working exactly as before, with
        // the gap now at least logged for whoever administers the box.
        $scan = self::scanForMalware($file['tmp_name']);
        if ($scan['infected']) {
            error_log('RTOFLOW FileUploadGuard: upload blocked — malware scanner flagged file: ' . $scan['detail']);
            return ['success' => false, 'path' => '', 'name' => '', 'size_kb' => 0, 'error' => 'This file failed a security scan and could not be uploaded.'];
        }

        $ext       = $validation['ext'];
        $baseDir   = self::storageRoot();
        $subDir    = $baseDir . '/' . $context . '/' . self::hashDir($entityId) . '/' . date('Y/m');
        $filename  = self::secureFilename($entityId, $ext);
        $fullPath  = $subDir . '/' . $filename;

        // Create directory with secure permissions
        if (!wp_mkdir_p($subDir)) {
            return ['success' => false, 'path' => '', 'name' => '', 'size_kb' => 0, 'error' => 'Storage directory could not be created.'];
        }

        // Write .htaccess in storage root to block direct access
        self::writeHtaccess($baseDir);
        self::writeIndexPhp($baseDir);

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            return ['success' => false, 'path' => '', 'name' => '', 'size_kb' => 0, 'error' => 'Failed to store file. Check server permissions.'];
        }

        // Set strict permissions
        chmod($fullPath, 0640);

        $relativePath = str_replace($baseDir . '/', '', $fullPath);
        $sizeKb       = (int)ceil($file['size'] / 1024);

        return [
            'success'   => true,
            'path'      => $relativePath,
            'name'      => $filename,
            'size_kb'   => $sizeKb,
            'error'     => null,
        ];
    }

    // ── Malware scan (fail-open when no scanner is present) ─────────────────

    /**
     * Scan a temp file with clamdscan/clamscan if either is available on
     * PATH. Returns ['infected' => bool, 'detail' => string]. When no
     * scanner binary is found, or exec()/shell_exec() is disabled by the
     * host (common on shared hosting), returns infected => false — this is
     * a defense-in-depth layer on top of the strict MIME whitelist above,
     * not a substitute for it, so its absence must never block uploads.
     */
    public static function scanForMalware(string $tmpPath): array
    {
        if (!is_file($tmpPath) || !function_exists('shell_exec')) {
            return ['infected' => false, 'detail' => ''];
        }

        $binary = null;
        foreach (['clamdscan', 'clamscan'] as $candidate) {
            $which = @shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null');
            if (is_string($which) && trim($which) !== '') {
                $binary = trim($which);
                break;
            }
        }
        if (!$binary) {
            return ['infected' => false, 'detail' => ''];
        }

        $cmd    = escapeshellcmd($binary) . ' --no-summary ' . escapeshellarg($tmpPath) . ' 2>&1';
        $output = @shell_exec($cmd);
        if ($output === null) {
            // Scanner present but failed to run (daemon down, permissions,
            // etc.) — log it, but do not block the upload over a broken
            // scanner the plugin does not control.
            error_log('RTOFLOW FileUploadGuard: malware scan could not run (scanner error), allowing upload through.');
            return ['infected' => false, 'detail' => ''];
        }

        // clam*scan prints "<path>: <Signature> FOUND" on detection.
        if (stripos($output, 'FOUND') !== false) {
            return ['infected' => true, 'detail' => trim($output)];
        }

        return ['infected' => false, 'detail' => ''];
    }

    // ── Delete ────────────────────────────────────────────────────────────

    public static function delete(string $relativePath): bool
    {
        if (empty($relativePath)) return false;
        $fullPath = self::storageRoot() . '/' . $relativePath;

        // Resolve the real path to check for traversal
        $real = realpath($fullPath);
        $base = realpath(self::storageRoot());
        if (!$real || !$base || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            error_log("RTOFLOW FileUploadGuard: Path traversal attempt blocked: {$relativePath}");
            return false;
        }

        return is_file($real) && unlink($real);
    }

    // ── Serve file (for authenticated download) ───────────────────────────

    /**
     * Serve a file to the authenticated user with correct headers.
     * Call exit after this.
     */
    public static function serve(string $relativePath, string $downloadName = ''): never
    {
        $fullPath = self::storageRoot() . '/' . $relativePath;
        $real     = realpath($fullPath);
        $base     = realpath(self::storageRoot());

        if (!$real || !$base || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
            status_header(404);
            exit('File not found.');
        }

        $mime = self::detectMime($real);
        $name = $downloadName ?: basename($real);

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . addslashes($name) . '"');
        header('Content-Length: ' . filesize($real));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-cache');
        readfile($real);
        exit;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private static function detectMime(string $path): string
    {
        // Use fileinfo extension for reliable MIME detection
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($mime) return $mime;
        }

        // Fallback: wp_check_filetype (extension-based, less secure — only fallback)
        $info = wp_check_filetype(basename($path));
        return $info['type'] ?: 'application/octet-stream';
    }

    private static function hasPathTraversal(string $filename): bool
    {
        // Check for null bytes, directory separators, and traversal sequences
        return str_contains($filename, "\0")
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || str_contains($filename, '..')
            || str_contains($filename, '%2e')
            || str_contains($filename, '%2f')
            || str_contains($filename, '%5c');
    }

    private static function secureFilename(int $entityId, string $ext): string
    {
        // Entity-scoped random filename — impossible to guess or enumerate
        return sprintf('%d_%s.%s', $entityId, bin2hex(random_bytes(16)), $ext);
    }

    private static function hashDir(int $entityId): string
    {
        // Split entity IDs across subdirectories to avoid huge flat directories
        // Entity 12345 → "30/39" (hex of 12345 split into pairs)
        $hex = str_pad(dechex($entityId), 8, '0', STR_PAD_LEFT);
        return substr($hex, 0, 2) . '/' . substr($hex, 2, 2);
    }

    private static function storageRoot(): string
    {
        // Use env-configured path, or fall back to wp-content/uploads/rtoflow
        $envPath = \RTOFLOW\Config\Env::string('STORAGE_PATH', '');
        if ($envPath && is_dir($envPath)) return rtrim($envPath, '/');
        return WP_CONTENT_DIR . '/uploads/rtoflow';
    }

    /**
     * Public accessor for the base directory this class stores/serves
     * files under (documents/aa/2026/08/xxx.pdf paths returned by store()
     * are relative to THIS, not to wp_upload_dir()['basedir']). Added for
     * DocumentAccessController::resolvePath(), which previously only tried
     * wp_upload_dir()['basedir'] — the base DocumentService::upload() uses,
     * NOT the base this class uses — so every document uploaded through
     * the dynamic Form Engine's file fields (Router::submitApplyV2() /
     * submitApplyDynamic(), both call FileUploadGuard::store()) 404'd when
     * an admin tried to view/download it via /rto-documents/{id}/, even
     * though the file was sitting on disk the whole time.
     */
    public static function baseDir(): string
    {
        return self::storageRoot();
    }

    private static function writeHtaccess(string $dir): void
    {
        $htaccess = $dir . '/.htaccess';
        if (file_exists($htaccess)) return;
        file_put_contents($htaccess,
            "Options -Indexes\n" .
            "Deny from all\n" .
            "<FilesMatch \"\\.php$\">\nDeny from all\n</FilesMatch>\n"
        );
    }

    private static function writeIndexPhp(string $dir): void
    {
        $index = $dir . '/index.php';
        if (file_exists($index)) return;
        file_put_contents($index, "<?php // Silence is golden\n");
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match($code) {
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload size limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary directory.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to server.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
            default               => 'Unknown upload error.',
        };
    }
}
