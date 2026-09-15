<?php

namespace RTOFLOW\Controllers;

use RTOFLOW\Repositories\DocumentRepository;
use RTOFLOW\Repositories\LeadRepository;
use RTOFLOW\Repositories\VendorRepository;
use RTOFLOW\Services\AuditService;

if (!defined('ABSPATH')) exit;

/**
 * Document Access Controller
 *
 * Serves uploaded client documents (ID proofs, etc.) through an
 * authenticated, authorised proxy instead of the raw wp-content/uploads
 * URL. Documents uploaded via DocumentService::upload() are written to
 * wp_upload_dir()['basedir'] . '/' . DocumentService::UPLOAD_DIR — a
 * public, unprotected path with no access control of its own. Anyone who
 * learns or guesses that URL can currently view a client's uploaded
 * document with no login and no ownership check. This controller reads
 * the file from disk and streams it, so the direct upload URL is no
 * longer the access path once callers are pointed here.
 */
class DocumentAccessController
{
    public function __construct(
        private DocumentRepository $docs,
        private LeadRepository     $leads,
        private VendorRepository   $vendors
    ) {}

    /**
     * Stream a document to the current user if they are authorised to see it.
     * Call exit after this (or let it exit itself on denial).
     */
    public function serve(int $documentId): void
    {
        if (!is_user_logged_in()) {
            wp_die('You must be logged in to access this file.', 'Access Denied', ['response' => 401, 'back_link' => true]);
        }

        $doc = $this->docs->find($documentId);
        if (!$doc) {
            wp_die('Document not found.', 'Not Found', ['response' => 404, 'back_link' => true]);
        }

        $lead = $this->leads->find((int)$doc['lead_id']);
        if (!$lead) {
            wp_die('Document not found.', 'Not Found', ['response' => 404, 'back_link' => true]);
        }

        if (!$this->isAuthorised($lead)) {
            wp_die('You do not have permission to access this file.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        }

        // ENTERPRISE GAP FIX (Phase 2, item 7 — file storage abstraction):
        // try the configured FileStorage driver first (this is how
        // DocumentService::upload() now writes new documents — local disk
        // by default, or S3 when RTOFLOW_STORAGE_DRIVER=s3 is set). Falls
        // back to the pre-existing local-disk resolvePath() lookup for
        // documents written before this change, or by the separate
        // FileUploadGuard intake path this rollout does not cover yet (see
        // FileStorage's class docblock for the stated scope).
        $relativePath = (string)$doc['file_path'];
        $contents = \RTOFLOW\Storage\FileStorage::get($relativePath);
        $fullPath = null;
        if ($contents === null) {
            $fullPath = $this->resolvePath($relativePath);
            if ($fullPath === null) {
                wp_die('File not found.', 'Not Found', ['response' => 404, 'back_link' => true]);
            }
        }

        AuditService::log('document.accessed', (int)$lead['id'], [
            'document_id' => $documentId,
            'file_name'   => $doc['file_name'] ?? '',
        ]);

        $mime = $contents !== null ? $this->detectMimeFromBuffer($contents) : $this->detectMime($fullPath);
        $name = $doc['file_name'] ?: basename($relativePath);

        // The Documents panel offers separate "Preview" (inline, for
        // images/PDF) and "Download" (forced save-as) actions against this
        // SAME endpoint — ?dl=1 is the only difference between the two.
        $disposition = !empty($_GET['dl']) ? 'attachment' : 'inline';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($name) . '"');
        header('Content-Length: ' . ($contents !== null ? strlen($contents) : filesize($fullPath)));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-cache');
        if ($contents !== null) {
            echo $contents;
        } else {
            readfile($fullPath);
        }
        exit;
    }

    /**
     * True if the current user is the owning client, the vendor assigned
     * to this lead, or an admin/staff member.
     */
    private function isAuthorised(array $lead): bool
    {
        if (rto_is_staff()) {
            return true; // covers admin and staff via rto_is_staff()
        }

        $uid = get_current_user_id();

        if (rto_is_client($uid) && (int)$lead['client_id'] === $uid) {
            return true;
        }

        if (rto_is_vendor($uid) && !empty($lead['vendor_id'])) {
            $vendor = $this->vendors->find_by_user($uid);
            if ($vendor && (int)$vendor['id'] === (int)$lead['vendor_id']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the stored relative file_path against the real uploads
     * basedir and guard against path traversal. Returns null if the
     * resolved path does not exist or escapes the uploads directory.
     */
    private function resolvePath(string $relativePath): ?string
    {
        if ($relativePath === '') return null;

        // FIX (Order Details 360° audit — real bug found, not hypothetical):
        // stored file_path values are relative to whichever writer created
        // them, and there are two independent writers using two different
        // bases — DocumentService::upload() (wp_upload_dir()['basedir'] .
        // '/rtoflow-docs/...') and FileUploadGuard::store(), used by every
        // Form-Engine intake path (Router::submitApplyV2()/
        // submitApplyDynamic()), which stores under its own storageRoot()
        // (wp-content/uploads/rtoflow/documents/... by default, or
        // STORAGE_PATH env). This method only ever tried the first base, so
        // every document attached via the intake form's own file fields —
        // exactly the documents an admin most needs to open from the Order
        // Details screen — 404'd here even though the file existed on disk.
        // Try both bases; first one whose resolved, real path both exists
        // and stays inside that base wins.
        $bases = array_unique(array_filter([
            rtrim(wp_upload_dir()['basedir'], '/'),
            \RTOFLOW\Security\FileUploadGuard::baseDir(),
        ]));

        foreach ($bases as $base) {
            $full     = $base . '/' . ltrim($relativePath, '/');
            $realFull = realpath($full);
            $realBase = realpath($base);
            if ($realFull && $realBase && str_starts_with($realFull, $realBase . DIRECTORY_SEPARATOR) && is_file($realFull)) {
                return $realFull;
            }
        }

        return null;
    }

    private function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? finfo_file($finfo, $path) : false;
            if ($finfo) finfo_close($finfo);
            if ($mime) return $mime;
        }
        $info = wp_check_filetype(basename($path));
        return $info['type'] ?: 'application/octet-stream';
    }

    /** Same as detectMime() but for content already read into memory (the
     * FileStorage/S3 path, where there is no local path to finfo_file()). */
    private function detectMimeFromBuffer(string $contents): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? finfo_buffer($finfo, $contents) : false;
            if ($finfo) finfo_close($finfo);
            if ($mime) return $mime;
        }
        return 'application/octet-stream';
    }
}
