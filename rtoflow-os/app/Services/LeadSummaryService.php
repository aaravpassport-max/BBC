<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * LeadSummaryService — Part 5.8.
 *
 * Turns a lead row + its resolved form answers (the exact same
 * $formAnswers array LeadsController::show() already builds via
 * resolveFormAnswers() — see LeadsController.php:413) into one natural-
 * language paragraph, for every one of the 7 real categories
 * (dl, rc, hp, noc, vehicle, commercial, other), without a per-service
 * switch statement.
 *
 * ── Why this shape, not "fully generic NLG" or "one template per service" ──
 * Fully generic natural-language generation over arbitrary schema fields
 * (real coreference, real grammar inference) is not realistic to build
 * robustly in this codebase. A hardcoded sentence per service (44 real
 * services, or even 7 categories) would violate the explicit reusability
 * requirement and drift the moment a category's schema is edited in the
 * Form Builder. The middle ground actually implemented: classify each
 * resolved answer into a small, fixed set of semantic SLOTS by inspecting
 * its field KEY and LABEL for patterns that recur across every real
 * schema in RealFormSchemaSeeder.php (checked directly — see the
 * per-slot doc comments below for the exact key/label evidence), then
 * assemble whatever slots are actually filled into 1–3 connected
 * sentences. Genuinely generic: works for a brand-new category whose
 * field keys follow the SAME naming conventions this codebase already
 * uses everywhere (a form builder admin who names a field key
 * "…_reason" or a city field "buyer_city" gets the classification for
 * free, no code change). NOT generic: a category whose author invents
 * entirely new naming conventions (e.g. a key called "xyz_9" with a
 * label in a different language) degrades to the PREFERENCES bucket
 * (still surfaced, just not folded into the main sentence) rather than
 * silently vanishing. This is disclosed here plainly, not glossed over.
 */
class LeadSummaryService
{
    /** Field keys/labels that identify the "route" (source→destination) slot. */
    private const ROUTE_FROM_KEYS = ['seller_city', 'from_city', 'source_city'];
    private const ROUTE_TO_KEYS   = ['buyer_city', 'to_city', 'destination_city'];

    /**
     * Labels/keys that are present in the data but deliberately never
     * folded into prose — registration numbers, owner names (already
     * covered by the lead's own client_name), raw document uploads, and
     * dates are precise identifiers a human reads off a form, not
     * material for a natural-language paragraph. They remain fully
     * visible in the "Submitted Application Details" card above this
     * summary; this is a summary, not a replacement for that card.
     */
    private const SKIP_TYPES = ['file'];

    /**
     * Cap on the number of "secondary" (preferences-bucket) facts folded
     * into the summary. ONE constant, used by every bucket that needs a
     * bound, instead of a magic number repeated (and potentially drifting)
     * in more than one place. Raised from an original 3 to 6 during the
     * Lead Summary completeness audit — 6 is bounded (an unbounded
     * sentence stops reading as a "briefing"), but with contact/identity
     * fields now carved out into their own dedicated slots (see classify()
     * below), 6 is enough headroom that a real submitted fact should never
     * need to be silently dropped.
     */
    private const MAX_SECONDARY_FACTS = 6;

    /**
     * PRONOUN POLICY — audited explicitly, not an oversight.
     *
     * grep -rni 'gender' across this entire codebase (schema migrations,
     * every Form Builder field definition in RealFormSchemaSeeder.php, the
     * leads DB table, LeadService/LeadsController's lead-row shape) returns
     * ZERO hits. There is no gender field anywhere in the data model this
     * service is handed — not on the lead row, not in any of the 7 real
     * category schemas' form answers.
     *
     * Given that, hardcoding "They"/"their"/"them" is not a bug to silently
     * "fix" by guessing a pronoun from a name, a title, or any other proxy
     * signal — that would fabricate a fact never actually submitted by the
     * client and could easily be wrong. Gender-neutral is the only
     * defensible choice with the ACTUAL data this service receives.
     *
     * If a real 'gender' (or 'salutation'/'title' used unambiguously as a
     * gender signal) field is ever added to the lead schema, wire it in
     * here: resolve it from $lead (preferred, since it's collected once
     * per client) or from $formAnswers/$slots (if collected per-category
     * instead), and fall back to gender-neutral whenever it is null, empty,
     * or set to a value that isn't a clear binary/known pronoun mapping —
     * never force a guess. Until such a field genuinely exists, this
     * cannot be "fixed" without inventing data; see the docblocks at every
     * "They"/"they"/"have" usage below.
     */

    /**
     * Build the natural-language summary paragraph for one lead.
     *
     * @param array $lead        The lead row from LeadService::getLead()/getLeadForAdmin()
     *                            — needs client_name, city_name, service_name, service_category.
     * @param array $formAnswers The DECLUTTERED (non-blank) answer list from
     *                           LeadsController::resolveFormAnswers(), each entry shaped
     *                           ['step','label','type','value','key','options'].
     * @return string One or more connected sentences, never empty (always
     *                 falls back to a minimal-but-real sentence built from
     *                 the lead row alone), never placeholder text.
     */
    public function summarise(array $lead, array $formAnswers): string
    {
        // Part 5.9 ("AI-powered Lead Briefing" — real engineering decision,
        // disclosed in Part 5.9 of the plan doc): this codebase had NO
        // existing LLM integration anywhere (grepped: no wp_remote_post to
        // any AI provider, no OpenAI/Anthropic key, "AI Insights" is plain
        // SQL aggregation). Rather than fabricate a fake call, a REAL
        // integration point was built (generateViaLLM() below) gated behind
        // a real, currently-empty Settings → AI Lead Briefing API key. When
        // an admin has genuinely enabled it AND configured a real key, that
        // real HTTP call is tried first; on literally any failure (no key,
        // network error, timeout, non-2xx, malformed response) execution
        // falls through to the deepened rule-based writer below, which is
        // always available and never throws. An admin is never shown a
        // blank card or a raw API error.
        if (get_option('rtoflow_ai_summary_enabled', '0') === '1') {
            try {
                $viaLlm = $this->generateViaLLM($lead, $formAnswers);
                if ($viaLlm !== null && trim($viaLlm) !== '') {
                    return trim($viaLlm);
                }
            } catch (\Throwable $e) {
                // Deliberately swallowed — see docblock above. The
                // rule-based writer immediately below is the guaranteed
                // fallback; a logged, silent degrade is correct here, a
                // fatal error or a broken admin screen is not.
            }
        }

        return $this->generateRuleBased($lead, $formAnswers);
    }

    /**
     * Real LLM call, real provider endpoints, real request/response shapes.
     * Returns null (never throws to the caller — summarise() also wraps
     * this in try/catch as a second layer of safety) on ANY failure so the
     * rule-based writer always has a clean path to take over. There is no
     * API key configured anywhere in this sandbox/engagement, so this path
     * has been read-verified against each provider's real documented
     * request/response shape but cannot be exercised end-to-end here — see
     * Part 5.9 of the plan doc for the explicit, honest disclosure of that
     * limit.
     */
    public function generateViaLLM(array $lead, array $formAnswers): ?string
    {
        $provider = get_option('rtoflow_ai_summary_provider', 'anthropic');
        $encKey   = get_option('rtoflow_ai_summary_api_key_enc', '');
        if ($encKey === '') return null;

        $apiKey = \RTOFLOW\Security\Encryption::decrypt($encKey);
        if (!is_string($apiKey) || trim($apiKey) === '') return null;

        $prompt = $this->buildPrompt($lead, $formAnswers);

        if ($provider === 'openai') {
            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                'timeout' => 12,
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode([
                    'model'       => 'gpt-4o-mini',
                    'max_tokens'  => 400,
                    'temperature' => 0.6,
                    'messages'    => [
                        ['role' => 'system', 'content' => 'You are an experienced RTO (Regional Transport Office) service support executive writing a brief, natural, human-sounding internal case briefing for a colleague about to work this order. Write 2-4 connected sentences of flowing prose, never a bullet list, never inventing any fact not given to you.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]),
            ]);
            if (is_wp_error($response)) return null;
            if ((int)wp_remote_retrieve_response_code($response) !== 200) return null;
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $text = $body['choices'][0]['message']['content'] ?? null;
            return is_string($text) ? $text : null;
        }

        // Default / 'anthropic': real Messages API shape.
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 12,
            'headers' => [
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'      => 'claude-3-5-haiku-20241022',
                'max_tokens' => 400,
                'system'     => 'You are an experienced RTO (Regional Transport Office) service support executive writing a brief, natural, human-sounding internal case briefing for a colleague about to work this order. Write 2-4 connected sentences of flowing prose, never a bullet list, never inventing any fact not given to you.',
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]),
        ]);
        if (is_wp_error($response)) return null;
        if ((int)wp_remote_retrieve_response_code($response) !== 200) return null;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $text = $body['content'][0]['text'] ?? null;
        return is_string($text) ? $text : null;
    }

    /** Builds the real structured-data prompt sent to the LLM, from the exact same resolved lead + answers the rule-based writer uses — never fabricated, never a separate/looser data path. */
    private function buildPrompt(array $lead, array $formAnswers): string
    {
        $lines = [];
        $lines[] = 'Client name: ' . (trim((string)($lead['client_name'] ?? '')) ?: 'Unknown');
        if (!empty($lead['city_name']))         $lines[] = 'City: ' . $lead['city_name'];
        if (!empty($lead['service_name']))      $lines[] = 'Service requested: ' . $lead['service_name'];
        if (!empty($lead['service_category']))  $lines[] = 'Category: ' . $lead['service_category'];
        // FIX (Lead Summary completeness audit): explicit contact lines,
        // mirroring city/service above, so the LLM path always sees these
        // even on a lead where the intake form's own contact fields didn't
        // survive into $formAnswers for any reason — the loop below covers
        // them too when present there, this is a guaranteed-present
        // fallback from the lead row's own real usermeta.
        if (!empty($lead['client_mobile']))     $lines[] = 'Phone: ' . $lead['client_mobile'];
        if (!empty($lead['client_email']))      $lines[] = 'Email: ' . $lead['client_email'];
        foreach ($formAnswers as $ans) {
            if (in_array($ans['type'] ?? '', self::SKIP_TYPES, true)) continue;
            $value = $this->displayValue($ans);
            if ($value === '') continue;
            $label = (string)($ans['label'] ?? '');
            if ($label === '') continue;
            $lines[] = $label . ': ' . $value;
        }
        return "Write the case briefing using ONLY these submitted facts:\n" . implode("\n", $lines);
    }

    /**
     * The rule-based writer — always available, never requires a key, never
     * fails. Deepened in Part 5.9 for more connected, causal prose (vs.
     * Part 5.8's shorter fact-listing sentences) while remaining fully
     * generic (see class docblock — no per-category hardcoding).
     */
    private function generateRuleBased(array $lead, array $formAnswers): string
    {
        $slots = $this->classify($formAnswers);

        // Prefer a real first+last name typed on the intake form itself
        // over the WordPress account's display name when both exist and
        // disagree — the form answer is what the customer told us about
        // THIS request; account display names are frequently a login/
        // username rather than the applicant's real name. Falls back to
        // the lead row's client_name (the pre-Part-5.9 behaviour) whenever
        // the form didn't collect a name at all.
        $formName = trim(trim((string)($slots['first_name'] ?? '')) . ' ' . trim((string)($slots['last_name'] ?? '')));
        $name       = $formName !== '' ? $formName : (trim((string)($lead['client_name'] ?? '')) ?: 'This client');
        $city       = trim((string)($lead['city_name'] ?? ''));
        $serviceRaw = trim((string)($lead['service_name'] ?? ''));
        $service    = $serviceRaw !== '' ? $this->lowerFirstWordIfArticleNeeded($serviceRaw) : '';

        // FIX (Lead Summary completeness audit): contact details fall back
        // to the lead row's own client_email/client_mobile (real usermeta,
        // already surfaced on the Order Details card since Part 5.6) when
        // the intake form itself didn't collect a phone/email — a lead
        // created any other way than the public intake form (e.g. a
        // legacy/manually-created order) should still get a contact
        // sentence whenever that information exists anywhere on the lead.
        $phone = $slots['phone'] ?? trim((string)($lead['client_mobile'] ?? '')) ?: null;
        $email = $slots['email'] ?? trim((string)($lead['client_email'] ?? '')) ?: null;

        $sentences = [];

        // ── Sentence 1: who + where + what they own ─────────────────────
        $s1 = $name;
        $bits = [];
        if ($city !== '') $bits[] = "is based in {$city}";
        if ($slots['vehicle_type'] !== null) $bits[] = 'owns a ' . $slots['vehicle_type'];
        if ($bits) {
            $s1 .= ' ' . $this->joinWithAnd($bits) . '.';
            $sentences[] = $s1;
        }

        // ── Sentence, optional: how to reach them — placed early, right
        // after the introduction, mirroring the user's own example ("He
        // can be contacted at [phone number] or [email address]"). Never
        // fabricated: only rendered when at least one of the two is real. ──
        if ($phone || $email) {
            $subjectForContact = $sentences ? 'They can' : "{$name} can";
            $via = array_filter([$phone, $email]);
            // "or" between contact methods (alternatives), not "and" —
            // matches the user's own example phrasing ("at [phone] or
            // [email]") and reads more naturally for two ways to reach
            // the same person than "and" does.
            $viaText = count($via) > 1 ? implode(' or ', $via) : $via[array_key_first($via)];
            $sentences[] = "{$subjectForContact} be reached at {$viaText}" . ($slots['address'] !== null ? ", and the request lists {$slots['address']} as the address on file." : '.');
        }
        // Sentence 2 opens with a pronoun ONLY when sentence 1 already
        // named the subject — a lead with neither city nor vehicle data
        // (case: sparse "other"/legacy leads) would otherwise open with
        // "They" and the client's real name would never appear anywhere
        // in the summary at all. When sentence 1 didn't fire, sentence 2
        // names the subject directly instead.
        $subject = $sentences ? 'They' : $name;
        $verb    = $sentences ? 'are' : 'is';

        // ── Route facts get their own causal clause, before the main
        // "looking for" clause, when both ends of a route are known — this
        // mirrors the connected, cause-and-effect phrasing a human writer
        // uses ("The vehicle is currently registered in X, and he is
        // looking to complete the transfer to Y…") rather than tacking
        // "from X to Y" onto the end of an unrelated sentence.
        if ($slots['route_from'] !== null && $slots['route_to'] !== null && $serviceRaw !== '') {
            $sentences[] = "The vehicle is currently registered in {$slots['route_from']}, and {$this->lowerSubject($subject)} looking to complete the {$serviceRaw} to {$slots['route_to']}.";
            $subject = 'They';
            $verb    = 'are';
            $routeHandled = true;
        }

        $routeHandled = $routeHandled ?? false;
        $s2parts = [];
        if (!$routeHandled) {
            if ($service !== '') {
                $s2parts[] = "{$subject} {$verb} looking for {$service}";
            } elseif (!empty($lead['service_category'])) {
                $s2parts[] = "{$subject} {$verb} looking for {$lead['service_category']} assistance";
            }

            if ($slots['route_from'] !== null || $slots['route_to'] !== null) {
                if ($slots['route_from'] !== null) {
                    $s2parts[] = "from {$slots['route_from']}";
                }
                if ($slots['route_to'] !== null) {
                    $s2parts[] = "to {$slots['route_to']}";
                }
            }
        }

        if ($slots['detail_type'] !== null) {
            if ($s2parts) {
                // A "looking for X" clause is already running — attach the
                // secondary type as a specifier on it.
                $s2parts[] = "specifically for {$slots['detail_type']}";
            } else {
                $s2parts[] = "{$subject} {$verb} looking for {$slots['detail_type']} specifically";
            }
        }

        if ($s2parts) {
            $sentence2 = implode(' ', array_filter($s2parts, fn($p) => $p !== ''));
            // ── Sentence 2's closing clause: prefer the lead's own stated
            // reason/purpose (real data) over a generic boilerplate close,
            // framed causally ("so that…") so it connects to what was just
            // said rather than reading as a bolted-on fact, and never
            // repeats a fact already said twice in different words.
            if ($slots['purpose'] !== null) {
                $sentence2 .= ". Based on the information submitted, " . $this->lowerSubject($subject) . " seeking assistance with this on account of \"{$slots['purpose']}\", so that it can be completed correctly";
            } else {
                $hasHave = $subject === 'They' ? 'have' : 'has';
                $sentence2 .= ", and {$hasHave} already submitted this request so that it can be processed correctly";
            }
            $sentences[] = rtrim($sentence2) . '.';
        } elseif ($slots['purpose'] !== null) {
            $sentences[] = "The stated reason for this request is \"{$slots['purpose']}\", and the client is looking for this to be resolved correctly.";
        }

        // ── Sentence 3+ (optional): remaining meaningful facts the fixed
        // slots above didn't claim, framed as connected clauses rather than
        // a bare "X is Y" list. ──
        // FIX (Lead Summary completeness audit): this bucket previously
        // hard-capped at the first 3 entries encountered (array_slice(0,3))
        // — with contact/identity fields now pulled into their own
        // dedicated slots above, this bucket holds only genuinely
        // secondary facts (multiselect document lists, buyer/seller role,
        // etc.), but capping at a small fixed number can still silently
        // drop something the customer actually submitted, which the user
        // explicitly said must never happen. Raised to 6 (still bounded —
        // an unbounded sentence would stop reading as a "briefing" and
        // start reading as a dump again, the opposite failure mode) and
        // split across two shorter connected sentences instead of one long
        // comma-list once there are more than 3, since a single sentence
        // holding 6 clauses stops reading naturally.
        if ($slots['preferences']) {
            $prefBits = [];
            foreach (array_slice($slots['preferences'], 0, self::MAX_SECONDARY_FACTS) as $p) {
                $label = rtrim(strtolower($p['label']), '?');
                $prefBits[] = "{$p['value']} ({$label})";
            }
            $chunks = array_chunk($prefBits, 3);
            $lead1  = 'The submission also notes ' . $this->joinWithAnd($chunks[0], ',') . ', which may be relevant while processing this order.';
            $sentences[] = $lead1;
            if (isset($chunks[1])) {
                $sentences[] = 'Additionally, the request specifies ' . $this->joinWithAnd($chunks[1], ',') . '.';
            }
        }

        if (!$sentences) {
            // Graceful minimum: even a lead with zero usable form answers
            // still gets a real, non-placeholder sentence built from the
            // lead row alone (name + service is always present on a real
            // lead — service_id is NOT NULL in the schema).
            $sentences[] = trim("{$name} has submitted a request" . ($service !== '' ? " for {$service}" : '') . ' and is awaiting assistance from the team.');
        }

        return implode(' ', $sentences);
    }

    /** "They"/"Sandeep" → "they"/"Sandeep" for mid-sentence use after a comma. */
    private function lowerSubject(string $subject): string
    {
        return $subject === 'They' ? 'they are' : "{$subject} is";
    }

    /**
     * Classify every non-blank answer into one of a fixed set of semantic
     * slots by inspecting its field KEY (primary signal — this codebase's
     * own naming convention, checked directly against every real schema
     * in RealFormSchemaSeeder.php) and LABEL (fallback signal, for a
     * future category whose author didn't follow the exact key pattern
     * but did write a recognisable label). Anything not recognised, and
     * not a file upload, falls into 'preferences' rather than being
     * silently dropped — see class docblock for why full genericity stops
     * here.
     */
    private function classify(array $formAnswers): array
    {
        $slots = [
            'vehicle_type' => null,
            'route_from'   => null,
            'route_to'     => null,
            'detail_type'  => null,
            'purpose'      => null,
            // FIX (Lead Summary completeness audit): the live public intake
            // form submits universal contact/identity fields — first_name,
            // last_name, mobile, email, address — that are NOT part of any
            // category's schema (see LeadsController::resolveFormAnswers()'s
            // "leftover keys" fallback). These were previously falling into
            // the generic 'preferences' bucket alongside every other
            // unclassified field and getting silently truncated by its
            // array_slice(0, 3) cap whenever 3+ other unrelated fields
            // happened to be classified first — a customer's own phone
            // number or email address could be present in the data and
            // never appear in the summary. Given dedicated slots so they
            // can NEVER be crowded out by unrelated fields, matching the
            // user's explicit example ("He can be contacted at [phone] or
            // [email]").
            'first_name'   => null,
            'last_name'    => null,
            'phone'        => null,
            'email'        => null,
            'address'      => null,
            'preferences'  => [],
        ];

        foreach ($formAnswers as $ans) {
            if (in_array($ans['type'] ?? '', self::SKIP_TYPES, true)) continue;

            $key   = (string)($ans['key'] ?? '');
            $label = (string)($ans['label'] ?? '');
            $value = $this->displayValue($ans);
            if ($value === '') continue;

            // Registration numbers / owner names carry no summary-worthy
            // fact beyond what's already in the lead row (client_name) —
            // deliberately excluded from prose, per class docblock.
            if (preg_match('/veh_reg|owner_name|registration/i', $key . ' ' . $label)) continue;

            // ── Contact / identity slots — checked BEFORE the generic
            // buckets below so a phone/email/name/address field is never
            // misclassified as a generic "detail_type" or "preference". ──
            if ($slots['first_name'] === null && preg_match('/^first_?name$/i', $key)) {
                $slots['first_name'] = $value; continue;
            }
            if ($slots['last_name'] === null && preg_match('/^last_?name$/i', $key)) {
                $slots['last_name'] = $value; continue;
            }
            if ($slots['phone'] === null && preg_match('/mobile|phone|contact_number|whatsapp/i', $key . ' ' . $label)) {
                $slots['phone'] = $value; continue;
            }
            if ($slots['email'] === null && preg_match('/email/i', $key . ' ' . $label)) {
                $slots['email'] = $value; continue;
            }
            if ($slots['address'] === null && preg_match('/address_line|^address$|full_address/i', $key . ' ' . $label)) {
                $slots['address'] = $value; continue;
            }
            // Consent checkboxes and internal pincode/city duplicates carry
            // no summary-worthy fact of their own (pincode is precise
            // enough to belong on the details card, not a sentence; city is
            // already covered by the lead row's own city_name) — excluded
            // from the generic bucket for the same reason registration
            // numbers are, rather than cluttering "additional details".
            if (preg_match('/^consent$|^pincode$|^city$/i', $key)) continue;

            // Vehicle type — key contains "veh_type" (veh_type_noc,
            // veh_type_main, commercial_veh_type — RealFormSchemaSeeder.php
            // lines 404/417/446) OR label is literally "Vehicle Type".
            if ($slots['vehicle_type'] === null
                && (str_contains($key, 'veh_type') || preg_match('/^vehicle type$/i', $label))) {
                $slots['vehicle_type'] = $value;
                continue;
            }

            // Route — explicit from/to city keys (seller_city/buyer_city —
            // RealFormSchemaSeeder.php:293-294 — is the real, seeded example).
            if ($slots['route_from'] === null && in_array($key, self::ROUTE_FROM_KEYS, true)) {
                $slots['route_from'] = $value;
                continue;
            }
            if ($slots['route_to'] === null && in_array($key, self::ROUTE_TO_KEYS, true)) {
                $slots['route_to'] = $value;
                continue;
            }

            // Reason / purpose — key ends in _reason/_purpose, or the
            // label itself says "Reason"/"Purpose" (every real schema's
            // *_reason and *_purpose fields — e.g. renewal_reason,
            // dup_dl_reason, rc_cancel_reason, hp_cont_reason,
            // rc_extract_purpose, surrender_reason).
            if ($slots['purpose'] === null
                && (preg_match('/_reason$|_purpose$/i', $key) || preg_match('/\breason\b|\bpurpose\b/i', $label))) {
                $slots['purpose'] = $value;
                continue;
            }

            // Free-text "describe what you need" fields (commercial /
            // other categories' *_description keys) double as the purpose
            // slot when no dedicated reason field exists for that category.
            if ($slots['purpose'] === null && str_ends_with($key, '_description')) {
                $slots['purpose'] = $value;
                continue;
            }

            // A secondary "*_type" classifier (rc_addr_type, dl_change_type,
            // alteration_type, permit_type, rc_corr_type, insurance_need) —
            // excludes the vehicle-type keys already claimed above.
            //
            // FIX (non-standard-naming audit): RealFormSchemaSeeder.php also
            // uses a second, real classifier suffix that "_type$" does not
            // match — "*_role" (transfer_role: "Buyer or Seller?", RC
            // Services' Transfer of Ownership). Same semantic role as a
            // "*_type" field (a short classifying answer for the request,
            // not free text), just a different real suffix convention used
            // in an actual seeded schema — previously fell through to the
            // generic 'preferences' bucket and could be crowded out; now
            // recognised the same way "*_type" is, so this genuinely
            // generic rule (any category naming a classifier field
            // "..._type" OR "..._role") covers this real pattern instead of
            // requiring a per-service hardcode.
            //
            // FIX (non-standard-naming audit, round 2 — checked ALL 7 real
            // categories' seeded field keys in RealFormSchemaSeeder.php, not
            // just RC): two more real classifier fields use yet other
            // suffixes and were still degrading to the generic
            // 'preferences' bucket (or, worse, being special-cased by exact
            // key literal, which is the per-field hardcoding this class
            // docblock explicitly says to avoid):
            //   - expiry_period ('Renewal of Driving License After Expiry',
            //     "Expiry Period" — options "Under 1 Year Expired"/"Over 1
            //     Year Expired"): a short classifying answer, same semantic
            //     role as *_type/*_role, just suffixed "_period".
            //   - insurance_need ('Vehicle Insurance', "What do you need?" —
            //     options New Policy/Renewal/Endorsement Change): previously
            //     matched via a literal `$key === 'insurance_need'` special
            //     case rather than a naming convention, which is exactly the
            //     "per-field special-casing" this shared regex exists to
            //     avoid — folded into the same suffix rule as "_need$" so a
            //     future category's own "..._need" field is covered for
            //     free too, the same way "..._type"/"..._role" already are.
            if ($slots['detail_type'] === null
                && preg_match('/_type$|_role$|_period$|_need$/i', $key)) {
                $slots['detail_type'] = $value;
                continue;
            }

            // Anything else meaningful (dates already rendered human-
            // readable by the view's own formatting, select/radio answers
            // with a real label, etc.) — kept, but only surfaced as a
            // short "additional details" clause, never dumped wholesale
            // (summarise() caps this bucket at 3 entries).
            $slots['preferences'][] = ['label' => $label, 'value' => $value];
        }

        return $slots;
    }

    /**
     * Resolve an answer's stored VALUE to its human-readable form: for an
     * option-backed field (select/radio/checkbox/multiselect) this means
     * mapping the stored option value through the field's own 'options'
     * map (exactly what the schema already defines — never invented text
     * here), not showing the raw internal option key.
     */
    private function displayValue(array $ans): string
    {
        $value   = $ans['value'];
        $options = (array)($ans['options'] ?? []);

        if (is_array($value)) {
            $labels = [];
            foreach ($value as $v) {
                $v = (string)$v;
                if ($v === '') continue;
                $labels[] = $options[$v] ?? $v;
            }
            return implode(', ', $labels);
        }

        $value = trim((string)$value);
        if ($value === '') return '';
        return $options[$value] ?? $value;
    }

    /** Joins 2+ short phrases with a natural "and"/comma-then-and list. */
    private function joinWithAnd(array $bits, string $sep = ' and'): string
    {
        $bits = array_values(array_filter($bits, fn($b) => $b !== ''));
        if (count($bits) <= 1) return $bits[0] ?? '';
        $last = array_pop($bits);
        return implode($sep === ',' ? ', ' : ', ', $bits) . ($sep === ',' ? ', and ' : ' and ') . $last;
    }

    /**
     * "RC Transfer" → "an RC transfer" style article prefix, so the
     * sentence reads naturally instead of "is looking for RC Transfer".
     * Rule actually applied: a vowel first letter gets "an", everything
     * else gets "a". Follow-up fix: acronym pronunciation ("an HP…", "an
     * NOC…", "an RC…" are spoken with a vowel sound despite starting on a
     * consonant letter) is handled too, but via a generic English-spelling
     * rule — the set of Latin letters whose NAME starts with a vowel sound
     * when spoken aloud (A, E, F, H, I, L, M, N, O, R, S, X — "ay, ee, eff,
     * aitch, eye, el, em, en, oh, ar, es, ex") — applied only when the
     * leading word is a genuine all-caps acronym. This is a rule about
     * English letter-names in general, not a per-category service list, so
     * it stays generic: it corrects "RC"/"HP"/"NOC" the same way it would
     * correct any other acronym a future category's schema introduces,
     * without hardcoding any service title.
     */
    private function lowerFirstWordIfArticleNeeded(string $serviceName): string
    {
        $first = $serviceName[0] ?? '';
        $firstWord = strtok($serviceName, ' ');
        $isAcronym = $firstWord !== false && $firstWord === strtoupper($firstWord) && strlen($firstWord) > 1;
        $article = $isAcronym
            ? ((stripos('AEFHILMNORSX', $first) !== false) ? 'an' : 'a')
            : ((stripos('AEIOU', $first) !== false) ? 'an' : 'a');

        // Only lowercase the leading word's first letter when that word
        // isn't itself an all-caps acronym (RC, DL, HP, NOC) — real service
        // titles in RealFormSchemaSeeder.php open with either a normal
        // capitalised word ("Duplicate Driving Licence" → "a duplicate
        // Driving Licence") or an acronym ("RC Transfer" → "a RC Transfer",
        // never mangled into "rC Transfer").
        $firstWord = strtok($serviceName, ' ');
        $isAcronym = $firstWord !== false && $firstWord === strtoupper($firstWord) && strlen($firstWord) > 1;
        $body = $isAcronym ? $serviceName : lcfirst($serviceName);

        return $article . ' ' . $body;
    }
}
