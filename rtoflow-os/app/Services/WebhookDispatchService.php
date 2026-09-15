<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * Webhook Dispatch Service
 *
 * Scoped outbound-webhook mechanism for the Form Engine / lead pipeline
 * (see database/migrations/2024_01_01_000020_create_webhook_subscriptions.php
 * for the storage half). Looks up active subscriptions for a given event
 * type, signs the JSON payload with each subscription's own HMAC-SHA256
 * secret, and attempts real delivery via wp_remote_post() — following the
 * same wp_remote_post() usage pattern already established in
 * LeadSummaryService::generateViaLLM() (timeout + headers + body array,
 * is_wp_error()/wp_remote_retrieve_response_code() to read the result).
 *
 * ENTERPRISE GAP FIX (Phase 1, item 1 — "webhook dispatch blocks the
 * triggering request"): dispatch() used to call deliverOne() SYNCHRONOUSLY,
 * inline, for every active subscriber, in the same request that created or
 * changed a lead's status — this docblock previously (incorrectly) called
 * that "non-blocking for the caller" because it never THROWS, but it very
 * much still BLOCKS: up to TIMEOUT_SECONDS (5s) of real wall-clock wait per
 * subscriber, serially, before the lead-creation/status-change response
 * could return to whoever/whatever was waiting on it (an admin's browser,
 * an API caller, a bulk-import loop). With 3 slow subscribers that is up to
 * 15 real seconds added to an otherwise-fast write. dispatch() now does the
 * MINIMUM synchronous work (one fast indexed SELECT for active
 * subscriptions) and hands every actual delivery attempt to WP-Cron via
 * wp_schedule_single_event(time(), ...) — the same job-runner mechanism
 * already used for the retry queue below, so this introduces no new
 * infrastructure dependency, just moves the FIRST attempt onto it too. The
 * event fires as close to "now" as WP-Cron's own request-triggered timing
 * allows (see the platform-wide "WP-Cron is the only job runner, no
 * dead-man's-switch" gap — unrelated to and not fixed by this change; that
 * is a separate, larger infrastructure item).
 *
 * ENTERPRISE GAP FIX (Section 7): the retry/backoff queue named above as an
 * explicit scope exclusion is now implemented — see recordAttempt() and
 * processRetryQueue() below. A failed delivery is scheduled for retry with
 * exponential backoff (1m, 5m, 30m, 2h, 6h) instead of being lost after the
 * first failed attempt; after MAX_RETRIES exhausted attempts it is marked
 * 'exhausted' for a human to see in the Webhooks admin screen, rather than
 * retrying forever against a genuinely dead endpoint.
 *
 * Still out of scope for this pass: no per-subscriber rate limiting.
 */
class WebhookDispatchService
{
    /** Keep the outbound call short — this must never meaningfully delay
     *  the lead-creation/status-change request that triggered it. */
    private const TIMEOUT_SECONDS = 5;

    /** Exponential backoff schedule, in minutes, indexed by retry attempt
     *  number (0 = first retry after the initial failed attempt). After the
     *  last entry is exhausted with no success, the row is marked
     *  'exhausted' and stops being retried automatically. */
    private const RETRY_BACKOFF_MINUTES = [1, 5, 30, 120, 360];

    private \wpdb $db;
    private string $p;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->p  = $wpdb->prefix;
    }

    /**
     * Look up active subscriptions for $eventType and attempt delivery of
     * $payload to each. Never throws — a delivery failure is logged, not
     * propagated, so it cannot break or delay the caller.
     */
    public function dispatch(string $eventType, array $payload): void
    {
        try {
            $subscriptions = $this->db->get_results(
                $this->db->prepare(
                    "SELECT * FROM {$this->p}rto_webhook_subscriptions WHERE event_type = %s AND is_active = 1",
                    $eventType
                ),
                ARRAY_A
            ) ?: [];
        } catch (\Throwable $e) {
            // Lookup failure must not break the caller either.
            error_log('RTOFLOW WebhookDispatchService: subscription lookup failed — ' . $e->getMessage());
            return;
        }

        if (empty($subscriptions)) return;

        $body      = wp_json_encode(['event' => $eventType, 'data' => $payload]);
        $timestamp = time();

        foreach ($subscriptions as $sub) {
            try {
                // Schedule the real HTTP call for WP-Cron instead of making
                // it here — this is the only line that changed the actual
                // blocking behaviour; everything else about "look up active
                // subscriptions, sign, deliver, log" is unchanged. Passing
                // the already-fetched $sub (not just its id) means the
                // scheduled job doesn't need a second lookup for the common
                // case, but deliverScheduled() below still re-verifies
                // is_active before sending, in case the subscription was
                // deactivated in the gap between scheduling and firing.
                wp_schedule_single_event(time(), 'rtoflow_webhook_deliver_now', [
                    (int)$sub['id'], $eventType, $body, $timestamp,
                ]);
            } catch (\Throwable $e) {
                // Guarantee: one bad subscription (malformed row, unexpected
                // exception scheduling the event) can never stop the loop or
                // bubble to the caller.
                error_log('RTOFLOW WebhookDispatchService: scheduling delivery threw — ' . $e->getMessage());
            }
        }
    }

    /**
     * Cron target for 'rtoflow_webhook_deliver_now' (registered in
     * Bootstrap.php). Re-fetches the subscription by id — not just trusting
     * the row dispatch() already had — because time has passed since
     * scheduling and the subscription may have been deactivated or deleted
     * in the meantime; a deactivated target should not receive a delivery
     * just because it was still active a few seconds ago when queued.
     */
    public function deliverScheduled(int $subscriptionId, string $eventType, string $body, int $timestamp): void
    {
        try {
            $sub = $this->db->get_row($this->db->prepare(
                "SELECT * FROM {$this->p}rto_webhook_subscriptions WHERE id=%d AND is_active=1",
                $subscriptionId
            ), ARRAY_A);
            if (!$sub) return; // deactivated/deleted since scheduling — nothing to deliver

            $this->deliverOne($sub, $eventType, $body, $timestamp);
        } catch (\Throwable $e) {
            error_log('RTOFLOW WebhookDispatchService: scheduled delivery threw for subscription_id=' . $subscriptionId . ' — ' . $e->getMessage());
        }
    }

    /** Computes the HMAC-SHA256 signature this class sends and that a
     *  subscriber (or this class's own verification/tests) can recompute
     *  to authenticate the delivery. Exposed as public + static so it is
     *  independently verifiable without spinning up a whole dispatch. */
    public static function signature(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    private function deliverOne(array $sub, string $eventType, string $body, int $timestamp): void
    {
        $signature = self::signature($body, (string)$sub['secret']);
        $targetUrl = (string)$sub['target_url'];

        $response = wp_remote_post($targetUrl, [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Content-Type'          => 'application/json',
                'X-RTOFlow-Event'       => $eventType,
                'X-RTOFlow-Signature'   => $signature,
                'X-RTOFlow-Timestamp'   => (string)$timestamp,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $this->recordAttempt($sub, $eventType, $targetUrl, false, null, $response->get_error_message(), 0, $body);
            return;
        }

        $code    = (int)wp_remote_retrieve_response_code($response);
        $success = $code >= 200 && $code < 300;
        $this->recordAttempt($sub, $eventType, $targetUrl, $success, $code, $success ? null : ('HTTP ' . $code), 0, $body);
    }

    // ENTERPRISE GAP FIX (Section 7 — webhook retry queue): $retryCount and
    // $payload are new — on a fresh (non-retry) delivery $retryCount is 0
    // and $payload is the body just sent (needed so a FUTURE retry, if this
    // attempt fails, has the exact original body to resend); on a retry
    // (see processRetryQueue() below) $retryCount is the attempt number
    // being recorded, used to look up the next backoff delay.
    private function recordAttempt(
        array $sub,
        string $eventType,
        string $targetUrl,
        bool $success,
        ?int $responseCode,
        ?string $errorMessage,
        int $retryCount,
        string $payload
    ): int {
        $queueStatus = 'final';
        $nextRetryAt = null;

        if (!$success && isset(self::RETRY_BACKOFF_MINUTES[$retryCount])) {
            $queueStatus = 'pending_retry';
            $nextRetryAt = date('Y-m-d H:i:s', strtotime('+' . self::RETRY_BACKOFF_MINUTES[$retryCount] . ' minutes'));
        } elseif (!$success) {
            $queueStatus = 'exhausted';
        }

        $this->db->insert($this->p . 'rto_webhook_delivery_log', [
            'subscription_id' => (int)$sub['id'],
            'event_type'      => $eventType,
            'target_url'      => $targetUrl,
            'success'         => $success ? 1 : 0,
            'response_code'   => $responseCode,
            'error_message'   => $errorMessage !== null ? substr($errorMessage, 0, 500) : null,
            'retry_count'     => $retryCount,
            'next_retry_at'   => $nextRetryAt,
            'queue_status'    => $queueStatus,
            'payload'         => $payload,
            'attempted_at'    => current_time('mysql'),
        ]);
        $logId = (int)$this->db->insert_id;

        AuditService::log($success ? 'webhook.delivered' : ($queueStatus === 'exhausted' ? 'webhook.delivery_exhausted' : 'webhook.delivery_failed'), null, [
            'subscription_id' => (int)$sub['id'],
            'event_type'      => $eventType,
            'target_url'      => $targetUrl,
            'response_code'   => $responseCode,
            'error'           => $errorMessage,
            'retry_count'     => $retryCount,
            'next_retry_at'   => $nextRetryAt,
        ]);

        return $logId;
    }

    // TRACE: fired by the hourly rtoflow_sla_check cron (see Bootstrap::
    //        runSlaCheck(), which now also calls this) →
    //        queries rto_webhook_delivery_log WHERE queue_status='pending_retry'
    //        AND next_retry_at <= NOW() → for each, re-fetches the still-active
    //        subscription (a subscription deactivated since the original
    //        attempt is correctly skipped — no point retrying a target the
    //        admin turned off) → re-signs and resends the ORIGINAL stored
    //        payload → records the retry attempt as a new log row via the
    //        same recordAttempt() path, so retry_count increments and the
    //        next backoff (or 'exhausted') is computed identically to a
    //        fresh delivery →
    //        preconditions: WP cron fires; rows exist with next_retry_at in the past →
    //        postconditions: each due row's queue_status becomes 'final' (delivered),
    //        'pending_retry' (scheduled again, further out), or 'exhausted' →
    //        edge cases: subscription deleted/deactivated since original attempt →
    //        skipped, original row's queue_status left as-is (neither retried nor
    //        silently marked final, so it's still visible as unresolved); DB failure
    //        mid-loop → caught per-row, does not stop remaining rows
    public function processRetryQueue(): void
    {
        $due = $this->db->get_results(
            "SELECT * FROM {$this->p}rto_webhook_delivery_log
             WHERE queue_status = 'pending_retry' AND next_retry_at <= NOW()
             ORDER BY next_retry_at ASC LIMIT 100",
            ARRAY_A
        ) ?: [];

        if (empty($due)) return;

        foreach ($due as $row) {
            try {
                $sub = $this->db->get_row($this->db->prepare(
                    "SELECT * FROM {$this->p}rto_webhook_subscriptions WHERE id=%d AND is_active=1",
                    (int)$row['subscription_id']
                ), ARRAY_A);

                if (!$sub) {
                    // Subscription gone/deactivated — nothing sane to retry
                    // against, and leaving next_retry_at in the past would
                    // make this row match the "due" query again on every
                    // future cron run forever. Mark it exhausted so it stops
                    // being picked up but stays visible as unresolved.
                    $this->db->update($this->p . 'rto_webhook_delivery_log',
                        ['queue_status' => 'exhausted'], ['id' => (int)$row['id']]);
                    continue;
                }

                $body      = (string)($row['payload'] ?? wp_json_encode(['event' => $row['event_type']]));
                $signature = self::signature($body, (string)$sub['secret']);
                $timestamp = time();

                $response = wp_remote_post($sub['target_url'], [
                    'timeout' => self::TIMEOUT_SECONDS,
                    'headers' => [
                        'Content-Type'        => 'application/json',
                        'X-RTOFlow-Event'     => $row['event_type'],
                        'X-RTOFlow-Signature' => $signature,
                        'X-RTOFlow-Timestamp' => (string)$timestamp,
                        'X-RTOFlow-Retry'     => (string)((int)$row['retry_count'] + 1),
                    ],
                    'body' => $body,
                ]);

                $nextRetryCount = (int)$row['retry_count'] + 1;

                if (is_wp_error($response)) {
                    $this->recordAttempt($sub, $row['event_type'], $sub['target_url'], false, null, $response->get_error_message(), $nextRetryCount, $body);
                } else {
                    $code    = (int)wp_remote_retrieve_response_code($response);
                    $success = $code >= 200 && $code < 300;
                    $this->recordAttempt($sub, $row['event_type'], $sub['target_url'], $success, $code, $success ? null : ('HTTP ' . $code), $nextRetryCount, $body);
                }

                // BUGFIX (found while implementing this method): recordAttempt()
                // above always INSERTs a fresh row for the retry attempt — it
                // never touches $row (the ORIGINAL pending_retry row) itself.
                // Without this update, $row's queue_status/next_retry_at stay
                // exactly as they were (still 'pending_retry', still a
                // next_retry_at that is now in the past), so the very next
                // cron run's "WHERE queue_status='pending_retry' AND
                // next_retry_at <= NOW()" query would match it again — firing
                // an unbounded flood of duplicate retries for the same
                // original failure every single cron tick, forever. The
                // original row is superseded the moment its retry has been
                // dispatched (the new row is now the current state of that
                // delivery chain), regardless of whether this retry itself
                // succeeded, failed, or scheduled a further retry.
                $this->db->update($this->p . 'rto_webhook_delivery_log',
                    ['queue_status' => 'superseded'], ['id' => (int)$row['id']]);
            } catch (\Throwable $e) {
                error_log('RTOFLOW WebhookDispatchService: retry attempt threw for log_id=' . $row['id'] . ' — ' . $e->getMessage());
            }
        }
    }

    // ENTERPRISE GAP FIX (Phase 1, item 4 — "outbound webhooks have ... no
    // delivery-log UI ... Build: ... a delivery-log admin screen with a
    // manual 'replay' action"): the automatic exponential-backoff queue
    // above (processRetryQueue()) stops after RETRY_BACKOFF_MINUTES is
    // exhausted and marks the row 'exhausted' — a genuine dead-letter state
    // that, until now, had no way out short of a database console once the
    // admin had actually fixed the receiving endpoint. This lets an admin
    // manually re-fire ANY logged delivery attempt (typically 'exhausted' or
    // 'final'/failed) on demand from the Webhooks screen, re-sending the
    // ORIGINAL stored payload against the CURRENT subscription secret/target
    // (re-fetched fresh, not the stale one on the log row, so a rotated
    // secret or repointed URL is honoured). Returns a small result array the
    // controller turns straight into a JSON response; never throws.
    public function replayLog(int $logId): array
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->p}rto_webhook_delivery_log WHERE id=%d", $logId
        ), ARRAY_A);
        if (!$row) return ['ok' => false, 'message' => 'Delivery log entry not found.'];

        $sub = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->p}rto_webhook_subscriptions WHERE id=%d AND is_active=1",
            (int)$row['subscription_id']
        ), ARRAY_A);
        if (!$sub) return ['ok' => false, 'message' => 'The subscription for this delivery is missing or inactive — nothing to replay against.'];

        $body      = (string)($row['payload'] ?? wp_json_encode(['event' => $row['event_type']]));
        $signature = self::signature($body, (string)$sub['secret']);
        $timestamp = time();

        $response = wp_remote_post($sub['target_url'], [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Content-Type'        => 'application/json',
                'X-RTOFlow-Event'     => $row['event_type'],
                'X-RTOFlow-Signature' => $signature,
                'X-RTOFlow-Timestamp' => (string)$timestamp,
                'X-RTOFlow-Replay'    => '1',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $this->recordAttempt($sub, $row['event_type'], $sub['target_url'], false, null, $response->get_error_message(), 0, $body);
            return ['ok' => false, 'message' => 'Replay failed: ' . $response->get_error_message()];
        }

        $code    = (int)wp_remote_retrieve_response_code($response);
        $success = $code >= 200 && $code < 300;
        $this->recordAttempt($sub, $row['event_type'], $sub['target_url'], $success, $code, $success ? null : ('HTTP ' . $code), 0, $body);

        // A manual replay is a deliberate, one-off action independent of the
        // automatic backoff chain — mark the row that was replayed as
        // 'superseded' (same convention processRetryQueue() uses) so it
        // stops showing as an unresolved dead letter once someone has acted
        // on it, regardless of whether this particular replay succeeded.
        $this->db->update($this->p . 'rto_webhook_delivery_log', ['queue_status' => 'superseded'], ['id' => $logId]);

        return $success
            ? ['ok' => true, 'message' => 'Replayed successfully (HTTP ' . $code . ').']
            : ['ok' => false, 'message' => 'Replay attempted but the endpoint responded with HTTP ' . $code . '.'];
    }
}
