<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * Complaint Note Service — Append-Only Internal Note Thread
 *
 * ENTERPRISE GAP FIX (Phase 3, item 5 — "Complaints module ... lacks ...
 * internal-only notes distinct from the client-facing response"):
 * ComplaintsController::respond() writes ONLY the client-facing
 * resolution_note — there was no separate place for staff to record
 * something the complainant should never see. Mirrors LeadNoteService /
 * rto_lead_notes exactly (see that class's docblock for the pattern this
 * follows: insert-only, author resolved live via LEFT JOIN, not
 * denormalized).
 */
class ComplaintNoteService
{
    public const MAX_LENGTH = 5000;

    /** @return array ['success' => bool, 'message' => string, 'note' => array|null] */
    public function addNote(int $complaintId, int $authorUserId, string $noteText): array
    {
        $noteText = trim($noteText);
        if ($complaintId < 1) {
            return ['success' => false, 'message' => 'Invalid complaint.', 'note' => null];
        }
        if ($noteText === '') {
            return ['success' => false, 'message' => 'Note cannot be empty.', 'note' => null];
        }
        if (mb_strlen($noteText) > self::MAX_LENGTH) {
            return ['success' => false, 'message' => 'Note is too long (max ' . self::MAX_LENGTH . ' characters).', 'note' => null];
        }

        global $wpdb;
        $createdAt = current_time('mysql');
        $ok = $wpdb->insert($wpdb->prefix . 'rto_complaint_notes', [
            'complaint_id'   => $complaintId,
            'author_user_id' => $authorUserId,
            'note_text'      => $noteText,
            'created_at'     => $createdAt,
        ]);

        if ($ok === false) {
            return ['success' => false, 'message' => 'Failed to save note.', 'note' => null];
        }

        $authorName = $authorUserId > 0 && ($u = get_userdata($authorUserId)) ? $u->display_name : 'Unknown';

        return [
            'success' => true,
            'message' => 'Note added.',
            'note'    => [
                'id'             => (int)$wpdb->insert_id,
                'complaint_id'   => $complaintId,
                'author_user_id' => $authorUserId,
                'author_name'    => $authorName,
                'note_text'      => $noteText,
                'created_at'     => $createdAt,
            ],
        ];
    }

    /** All notes for a complaint, oldest-first — same chat-style ordering as LeadNoteService::getNotes(). */
    public function getNotes(int $complaintId): array
    {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT n.*, u.display_name as author_name
             FROM {$p}rto_complaint_notes n
             LEFT JOIN {$p}users u ON u.ID = n.author_user_id
             WHERE n.complaint_id = %d
             ORDER BY n.created_at ASC, n.id ASC",
            $complaintId
        ), ARRAY_A) ?: [];
    }
}
