<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * Lead Note Service — Append-Only Note Thread
 *
 * Replaces the old single-textarea `rto_leads.internal_notes` overwrite
 * behaviour (see database/migrations/2024_01_01_000021_create_lead_notes.php
 * for the full root-cause writeup). Every note is a new INSERT into
 * `rto_lead_notes` — never an UPDATE — so one staff member's note can
 * never destroy another's. This mirrors the "insert-only" discipline
 * AuditService already documents for `rto_logs`.
 *
 * Author display names are deliberately NOT stored on this table — see
 * the migration docblock. getNotes() resolves them live via the same
 * `LEFT JOIN {$p}users` pattern LeadsController::show()'s $messages query
 * and AuditService::forLead() already use.
 */
class LeadNoteService
{
    // Same 5000-char cap the old internal_notes field enforced
    // (LeadsController::updateNotes(), Sanitiser::text($_POST['notes'] ?? '', 5000))
    // — kept identical since this is a direct successor of that field.
    public const MAX_LENGTH = 5000;

    /**
     * Add a new note to a lead's thread. Pure INSERT — never touches any
     * previously written note.
     *
     * @return array ['success' => bool, 'message' => string, 'note' => array|null]
     */
    public function addNote(int $leadId, int $authorUserId, string $noteText): array
    {
        $noteText = trim($noteText);
        if ($leadId < 1) {
            return ['success' => false, 'message' => 'Invalid order.', 'note' => null];
        }
        if ($noteText === '') {
            return ['success' => false, 'message' => 'Note cannot be empty.', 'note' => null];
        }
        if (function_exists('mb_strlen') ? mb_strlen($noteText) > self::MAX_LENGTH : strlen($noteText) > self::MAX_LENGTH) {
            return ['success' => false, 'message' => 'Note is too long (max ' . self::MAX_LENGTH . ' characters).', 'note' => null];
        }

        global $wpdb;
        $createdAt = current_time('mysql');
        $ok = $wpdb->insert($wpdb->prefix . 'rto_lead_notes', [
            'lead_id'        => $leadId,
            'author_user_id' => $authorUserId,
            'note_text'      => $noteText,
            'created_at'     => $createdAt,
        ]);

        if ($ok === false) {
            return ['success' => false, 'message' => 'Failed to save note.', 'note' => null];
        }

        $authorName = $authorUserId > 0 && function_exists('get_userdata')
            ? (($u = get_userdata($authorUserId)) ? $u->display_name : 'Unknown')
            : 'Unknown';

        return [
            'success' => true,
            'message' => 'Note added.',
            'note'    => [
                'id'             => (int)$wpdb->insert_id,
                'lead_id'        => $leadId,
                'author_user_id' => $authorUserId,
                'author_name'    => $authorName,
                'note_text'      => $noteText,
                'created_at'     => $createdAt,
            ],
        ];
    }

    /**
     * All notes for a lead, oldest-first — the same chronological,
     * chat-style ordering this codebase's Communication Thread
     * (rto_messages, `ORDER BY m.created_at ASC`) already uses for its
     * compose-at-bottom / auto-scroll-to-bottom UI, which this note
     * thread's UI matches. (The Audit Trail panel is a separate,
     * DESC-ordered log table, not a conversation — not the pattern
     * matched here.)
     */
    public function getNotes(int $leadId): array
    {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT n.*, u.display_name as author_name
             FROM {$p}rto_lead_notes n
             LEFT JOIN {$p}users u ON u.ID = n.author_user_id
             WHERE n.lead_id = %d
             ORDER BY n.created_at ASC, n.id ASC",
            $leadId
        ), ARRAY_A) ?: [];
    }
}
