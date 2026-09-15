<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * Autosave / Draft Persistence for the public dynamic apply form
 *
 * See database/migrations/2024_01_01_000019_create_form_drafts.php for the
 * rto_form_drafts table this reads/writes, and Router::dispatchPublicAjax()
 * for the two AJAX actions ('save_draft' / 'load_draft') that call this.
 *
 * A draft is addressed purely by its opaque `draft_token` — never by
 * session or user id, since most visitors filling this form are anonymous
 * pre-login. The token is generated client-side-triggered but
 * server-issued (see issueToken()) the first time a visitor's browser has
 * none, then round-tripped by the client (localStorage) on every autosave
 * and on page load.
 *
 * Re-validation on load deliberately reuses FormEngineService's own
 * schema/condition evaluation (getForCategory() + evaluateCondition())
 * rather than re-implementing field visibility/requiredness here — a draft
 * loaded against a schema that changed since it was saved is filtered down
 * to only the fields still present in the CURRENT schema, so a restored
 * draft can never inject a stale/removed field back into the live form.
 */
class FormDraftService
{
    /** Random token length in bytes (hex-encoded, so the stored/transmitted string is double this). */
    public const TOKEN_BYTES = 24;

    /** How long an autosaved draft survives before it is no longer offered for restore. */
    public const TTL_HOURS = 48;

    /** Hard ceiling on the serialized answers payload accepted per draft (bytes of JSON). */
    public const MAX_ANSWERS_JSON_BYTES = 200000;

    public function __construct(private FormEngineService $forms) {}

    /** Generate a fresh, unused draft token. */
    public function issueToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * Create or update the draft identified by $draftToken.
     *
     * @return array{success:bool, message:string, expires_at?:string}
     */
    public function saveDraft(string $draftToken, string $category, ?int $serviceId, array $answers): array
    {
        global $wpdb;

        $draftToken = $this->normaliseToken($draftToken);
        if (!$draftToken) {
            return ['success' => false, 'message' => 'A valid draft token is required.'];
        }
        $category = trim($category);
        if ($category === '') {
            return ['success' => false, 'message' => 'A form category is required to save a draft.'];
        }

        $answersJson = wp_json_encode($answers);
        if ($answersJson === false) {
            return ['success' => false, 'message' => 'Draft answers could not be encoded.'];
        }
        if (strlen($answersJson) > self::MAX_ANSWERS_JSON_BYTES) {
            return ['success' => false, 'message' => 'Draft is too large to save.'];
        }

        $now       = current_time('mysql');
        $expiresAt = date('Y-m-d H:i:s', strtotime($now) + self::TTL_HOURS * HOUR_IN_SECONDS);
        $p         = $wpdb->prefix;

        $existingId = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$p}rto_form_drafts WHERE draft_token=%s", $draftToken
        ));

        $data = [
            'category'     => substr($category, 0, 100),
            'service_id'   => $serviceId ?: null,
            'answers_json' => $answersJson,
            'ip_hash'      => self::hashIp(),
            'updated_at'   => $now,
            'expires_at'   => $expiresAt,
        ];

        if ($existingId) {
            $ok = $wpdb->update($p . 'rto_form_drafts', $data, ['id' => $existingId]) !== false;
        } else {
            $ok = $wpdb->insert($p . 'rto_form_drafts', $data + ['draft_token' => $draftToken]) !== false;
        }

        if (!$ok) {
            return ['success' => false, 'message' => 'Unable to save draft. Please try again.'];
        }

        return ['success' => true, 'message' => 'Draft saved.', 'expires_at' => $expiresAt];
    }

    /**
     * Load a non-expired draft by token, re-validated against the CURRENT
     * form schema for its category. Returns null when the token is
     * unknown, expired, or already consumed.
     *
     * @return array{draft_token:string, category:string, service_id:?int, answers:array, updated_at:string, expires_at:string}|null
     */
    public function loadDraft(string $draftToken): ?array
    {
        global $wpdb;

        $draftToken = $this->normaliseToken($draftToken);
        if (!$draftToken) return null;

        $p   = $wpdb->prefix;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}rto_form_drafts WHERE draft_token=%s", $draftToken
        ), ARRAY_A);

        if (!$row) return null;
        if (strtotime($row['expires_at']) <= strtotime(current_time('mysql'))) return null;

        $answers = json_decode((string)$row['answers_json'], true);
        if (!is_array($answers)) $answers = [];

        // Re-validate against the CURRENT schema for this category: strip
        // any answer key that no longer names a real field, so a schema
        // change since the draft was saved can never resurrect a removed
        // field. Fields the current schema still declares are kept
        // regardless of current visible_if state — visibility is a
        // client-side rendering concern the form itself re-evaluates as it
        // restores each answer.
        $schema = $this->forms->getForCategory($row['category']);
        if ($schema) {
            $validKeys = array_column($schema['all_fields'], 'key');
            $answers   = array_intersect_key($answers, array_flip($validKeys));
        }

        return [
            'draft_token' => $row['draft_token'],
            'category'    => $row['category'],
            'service_id'  => $row['service_id'] !== null ? (int)$row['service_id'] : null,
            'answers'     => $answers,
            'updated_at'  => $row['updated_at'],
            'expires_at'  => $row['expires_at'],
        ];
    }

    /**
     * Consume (delete) a draft — called once its answers have become a real
     * submission (Router::submitApplyDynamic()) so it is never offered for
     * restore again. Safe to call with an unknown/already-consumed token.
     */
    public function consumeDraft(string $draftToken): void
    {
        global $wpdb;
        $draftToken = $this->normaliseToken($draftToken);
        if (!$draftToken) return;
        $wpdb->delete($wpdb->prefix . 'rto_form_drafts', ['draft_token' => $draftToken]);
    }

    /**
     * Purge every expired draft row. Intended to be called from the same
     * scheduled-cleanup path other TTL-bound data in this plugin already
     * uses (AutomationService::run_scheduled()), not on every request.
     */
    public function cleanupExpired(): int
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$p}rto_form_drafts WHERE expires_at <= %s",
            current_time('mysql')
        ));
        return (int)$deleted;
    }

    /** sha256(ip + site auth salt) — abuse-control signal, never the raw IP at rest. */
    public static function hashIp(): string
    {
        $ip   = \RTOFLOW\Http\Middleware\RateLimiter::clientIp();
        $salt = defined('AUTH_SALT') ? AUTH_SALT : 'rtoflow-form-drafts';
        return hash('sha256', $ip . '|' . $salt);
    }

    /** A draft token must be a plausible hex string of the expected length range. */
    private function normaliseToken(string $draftToken): string
    {
        $draftToken = trim($draftToken);
        if ($draftToken === '' || !preg_match('/^[a-f0-9]{16,64}$/', $draftToken)) return '';
        return $draftToken;
    }
}
