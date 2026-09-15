<?php

namespace RTOFLOW\Services;

use RTOFLOW\Repositories\DocumentRepository;
use RTOFLOW\Support\EventBus;

if (!defined('ABSPATH')) exit;

class DocumentService
{
    const UPLOAD_DIR = 'rtoflow-docs';

    public function __construct(
        private DocumentRepository $docs,
        private EventBus           $events
    ) {}

    public function upload(int $lead_id, int $doc_type_id, array $file): array
    {
        $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];

        // Use finfo for real MIME detection — not client-supplied type
        $finfo     = finfo_open(FILEINFO_MIME_TYPE);
        $real_mime = $finfo ? finfo_file($finfo, $file['tmp_name'] ?? '') : ($file['type'] ?? '');
        if ($finfo) finfo_close($finfo);

        if (!in_array($real_mime, $allowed, true)) {
            return ['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, PDF'];
        }
        if ($file['size'] > 10485760) {
            return ['success' => false, 'message' => 'File too large (max 10 MB)'];
        }

        // ENTERPRISE GAP FIX (Section 7 — "no malware scanning on uploads"):
        // this upload path (client document uploads via Router::routeClient()
        // 'documents' arm) had its own inline validation, entirely separate
        // from FileUploadGuard::store(), so adding scanning only there would
        // have left this path uncovered. Reuses the same fail-open scanner
        // (see FileUploadGuard::scanForMalware() for the full rationale).
        $scan = \RTOFLOW\Security\FileUploadGuard::scanForMalware($file['tmp_name'] ?? '');
        if ($scan['infected']) {
            error_log('RTOFLOW DocumentService: upload blocked — malware scanner flagged file: ' . $scan['detail']);
            return ['success' => false, 'message' => 'This file failed a security scan and could not be uploaded.'];
        }

        $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fname = 'doc-' . $lead_id . '-' . time() . '-' . wp_generate_password(8, false) . '.' . $ext;
        $relativePath = self::UPLOAD_DIR . '/' . date('Y/m') . '/' . $fname;

        // ENTERPRISE GAP FIX (Phase 2, item 7 — file storage abstraction):
        // this used to call move_uploaded_file() straight to
        // wp_upload_dir(), meaning every uploaded client document lived
        // only on this one app server's local disk — a real problem on any
        // multi-server or ephemeral-disk hosting setup (a document uploaded
        // via one server would 404 if a later request for it landed on a
        // different server, and vanish entirely on redeploy of an ephemeral
        // filesystem). Now goes through FileStorage, which is 'local'
        // (unchanged behaviour) unless RTOFLOW_STORAGE_DRIVER=s3 is set —
        // see FileStorage's class docblock.
        if (!\RTOFLOW\Storage\FileStorage::putFromUploadedFile($relativePath, $file['tmp_name'])) {
            return ['success' => false, 'message' => 'File could not be saved. Please try again.'];
        }

        $id = $this->docs->create([
            'lead_id'      => $lead_id,
            'doc_type_id'  => $doc_type_id,
            'file_path'    => $relativePath,
            'file_name'    => sanitize_file_name($file['name']),
            'file_size_kb' => round($file['size'] / 1024),
            'status'       => 'pending',
            'uploaded_by'  => get_current_user_id(),
        ]);

        $this->events->fire('document.uploaded', ['lead_id' => $lead_id, 'document_id' => $id]);
        return ['success' => true, 'document_id' => $id];
    }

    public function verify(int $doc_id): bool
    {
        $ok = $this->docs->update($doc_id, [
            'status'      => 'verified',
            'verified_by' => get_current_user_id(),
            'verified_at' => current_time('mysql'),
        ]);
        if ($ok) {
            $d = $this->docs->find($doc_id);
            if ($d) $this->events->fire('document.verified', ['lead_id' => $d['lead_id'], 'document_id' => $doc_id]);
        }
        return $ok;
    }

    public function reject(int $doc_id, string $reason): bool
    {
        $ok = $this->docs->update($doc_id, [
            'status'        => 'rejected',
            'reject_reason' => $reason,
            'verified_by'   => get_current_user_id(),
            'verified_at'   => current_time('mysql'),
        ]);
        if ($ok) {
            $d = $this->docs->find($doc_id);
            if ($d) $this->events->fire('document.rejected', ['lead_id' => $d['lead_id'], 'document_id' => $doc_id, 'reason' => $reason]);
        }
        return $ok;
    }
}
