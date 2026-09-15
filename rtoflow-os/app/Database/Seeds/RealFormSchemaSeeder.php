<?php

namespace RTOFLOW\Database\Seeds;

use RTOFLOW\Services\FormEngineService;

if (!defined('ABSPATH')) exit;

/**
 * RealFormSchemaSeeder — Part 4.10 re-architecture.
 *
 * ORIGIN OF THIS CHANGE: Parts 4.5-4.9 built ONE schema PER REAL SERVICE —
 * 44 separate rows, one per "Learning License", "Transfer of Ownership",
 * etc. The user then shared a reference plugin (gs23) and pointed at its
 * "Documentation Services" inquiry form: ONE form, 21 services inside it, a
 * single service-picker field plus every field for every service flattened
 * into one list, each field's visibility gated by a condition on the
 * picker's value. Their explicit correction: "they are not 44 but 7 forms."
 *
 * This file now builds SEVEN schemas — one per real category (Driving
 * License, RC Services, HP / Hypothecation, NOC, Vehicle Services,
 * Commercial Vehicle, Other Services) — matching apply.php's own 7
 * step-1 category buttons exactly. Each category schema contains:
 *   - one 'selected_service' field (type select, options = every real
 *     service name in that category) — the same role gs23's
 *     `doc_sub_service` plays;
 *   - every field for every real service in that category, flattened into
 *     the SAME fields list, each one's visible_if wrapped in an AND group
 *     with a rule on 'selected_service' — so FormEngineService's existing
 *     evaluateCondition() (nested AND/OR groups) does exactly what gs23's
 *     simpler {field,op,value} `cond` does, just via the richer shape this
 *     engine already had. No new evaluator logic was needed for this.
 *
 * A field that already had its own visible_if in the old per-service
 * schema (e.g. `fir_copy` only when `dup_dl_reason` is Lost/Stolen) keeps
 * that nested logic — it is now nested one level deeper, inside the
 * 'selected_service' AND group, so `fir_copy` shows only when BOTH the
 * customer picked "Duplicate Driving License" AND chose Lost/Stolen as the
 * reason. Field keys were not renamed: every field below came from the
 * SAME single apply.php page where all these blocks already coexist in one
 * DOM (hidden/shown by category+service), so their original `name`
 * attributes were already guaranteed unique within a category by
 * construction — no collision risk from flattening them together.
 *
 * HOW TO RUN: cannot execute inside this sandbox (no live WordPress/MySQL).
 * On the real site, run once via WP-CLI or a temporary admin trigger:
 *     RTOFLOW\Database\Seeds\RealFormSchemaSeeder::run();
 *
 * WHAT DID NOT CHANGE from Parts 4.6/4.7's field-level migration work:
 * every field definition below (key, type, required, options, help text,
 * nested conditions) is the exact same content already verified against
 * apply.php and already executed once through FormEngineService::
 * normaliseSchema() successfully (Part 4.7's 44/44 pass). Only the
 * packaging — 44 schemas vs. 7 — changed in this pass.
 *
 * ROLE MIGRATION SCOPE (Part 4.14 — explicit field 'role' contract added to
 * FormEngineService; disclosed honestly rather than claimed complete):
 * only a REPRESENTATIVE SUBSET of these 7 category schemas declares an
 * explicit role so far —
 *   - 'vehicle' category: $vehType ('veh_type_main') declared role =>
 *     'vehicle_type'.
 *   - 'noc' category: $nocBaseFields's 'veh_type_noc' declared role =>
 *     'vehicle_type'.
 * Both were chosen because each is the SINGLE, unambiguous vehicle-type
 * field in its category schema (already merged into one gated field across
 * every service that shares it — see categorySchema()'s collision
 * handling), so declaring the role has exactly one correct target.
 * NOT migrated, and still relying on the legacy key-name fallback in
 * FormEngineService::resolveByRole():
 *   - No 'city' role was declared on ANY of the 7 category schemas. None
 *     of them has a single unambiguous "the applicant's city" field —
 *     'rc' has 'seller_city'/'buyer_city' as two distinct people's cities,
 *     and the rest have no city-shaped field at all (city is derived from
 *     rto_state/rto_office instead, same as before this pass). Declaring
 *     role=>'city' on just one of seller/buyer would silently prefer that
 *     person's city for any rule keyed on 'city', which is worse than the
 *     honest "not modeled yet" this file leaves it as.
 *   - 'dl', 'hp', 'commercial', 'other' categories: no role declared on any
 *     field yet (nothing in serviceFieldSets() needed one for the harness
 *     this pass shipped with — see tests/role_contract_harness.php).
 * All of the above is backward compatible either way: a schema with no
 * declared role for a given field simply falls back to the same key-name
 * guess this codebase already used.
 *
 * STILL OPEN, unchanged from before:
 * - HP fallback fields: 3 of 5 real HP services (Addition, RC Release
 *   after HP Removal, Transfer) still share the same thin 2-field generic
 *   set the live form itself gives them today.
 * - Radio/checkbox required-attribute gap in dynRenderFieldHtml() (server
 *   validation still catches it; no client-side HTML `required`).
 * - Not executed against a live database or browser — see run()'s
 *   docblock.
 *
 * NEW OPEN ITEM from this restructure: the "not reachable" NOC/Commercial/
 * Other issues from Parts 4.6/4.7 are now moot for form ACTIVATION — a
 * category schema activates the moment ANY of its category's real UI flow
 * sets `svc` to a value found in that category — but the apply.php
 * integration layer (Router::routeApply()/checkDynamicForService()) still
 * needs to be switched from "one schema per selected service name" to "one
 * schema per category, render all its fields, evaluate visible_if against
 * {selected_service: <picked name>}" — that integration change is made
 * alongside this file in the same pass (see Router.php / apply.php diffs,
 * Part 4.10), not left as a dangling TODO.
 */
class RealFormSchemaSeeder
{
    private static function field(array $f): array
    {
        return array_merge([
            'placeholder' => '', 'help_text' => '', 'width' => 'full',
            'options' => [], 'validation' => [], 'dependent_source' => null,
            'visible_if' => null, 'required' => false, 'role' => null,
        ], $f);
    }

    private static function cond(string $field, string $op, $value): array
    {
        return ['logic' => 'AND', 'rules' => [['field' => $field, 'op' => $op, 'value' => $value]], 'groups' => []];
    }

    /** The category's own 'selected_service' picker field. */
    private static function picker(array $serviceNames): array
    {
        return self::field([
            'key' => 'selected_service', 'label' => 'Which service do you need?', 'type' => 'select',
            'required' => true, 'options' => array_combine($serviceNames, $serviceNames),
        ]);
    }

    /** Gate a single field on {selected_service equals $serviceName}, preserving any pre-existing condition as a nested group under the same AND. */
    private static function gateOne(array $f, string $serviceName): array
    {
        $serviceRule = ['field' => 'selected_service', 'op' => 'equals', 'value' => $serviceName];
        $f['visible_if'] = $f['visible_if']
            ? ['logic' => 'AND', 'rules' => [$serviceRule], 'groups' => [$f['visible_if']]]
            : ['logic' => 'AND', 'rules' => [$serviceRule], 'groups' => []];
        return $f;
    }

    /** Gate a field shared identically by several services on {selected_service in [names]}. */
    private static function gateMany(array $f, array $serviceNames): array
    {
        $serviceRule = ['field' => 'selected_service', 'op' => 'in', 'value' => $serviceNames];
        $f['visible_if'] = $f['visible_if']
            ? ['logic' => 'AND', 'rules' => [$serviceRule], 'groups' => [$f['visible_if']]]
            : ['logic' => 'AND', 'rules' => [$serviceRule], 'groups' => []];
        return $f;
    }

    /**
     * Build one category schema from an ordered [serviceName => fields[]]
     * map, flattening every service's fields into ONE fields list (the
     * gs23-style "one form, N services inside it" shape).
     *
     * KEY-COLLISION HANDLING (needed because this is new in Part 4.10):
     * several real services legitimately share a literally identical field
     * definition — e.g. every non-"New Vehicle Registration" Vehicle
     * Services entry asks the same "Vehicle Reg. No." text field under the
     * same original `name="veh_reg_main"`; all 4 NOC services and all 3 HP
     * fallback services are entirely identical field sets. When those are
     * flattened into one form, FormEngineService::normaliseSchema() would
     * reject the result outright — it requires every field key to be
     * unique across the WHOLE form, not just per step (see its "used more
     * than once across the whole form" check). Rather than let that throw,
     * fields that are byte-identical across the services sharing a key are
     * MERGED into a single field gated with an `in` condition over every
     * service that uses it (the same collapsing gs23 itself does for its
     * category-wide "Callback & Remarks" fields, which use op=in over all
     * 21 of its services). A field that shares a key but is NOT identical
     * across services (the one real case here: "New Vehicle Registration"
     * labels the shared reg-number field "Temp. Reg. No. / Chassis No."
     * instead of "Vehicle Reg. No.") is instead split into a distinct,
     * per-service-suffixed key so the differing label is preserved exactly
     * — disclosed here rather than silently picking one label to win.
     */
    private static function categorySchema(string $title, array $byService): array
    {
        $serviceNames = array_keys($byService);

        // Group by field key: key => [ [service, fieldDefWithoutOrder], ... ]
        $groups = [];
        foreach ($byService as $serviceName => $fields) {
            foreach ($fields as $f) {
                $groups[$f['key']][] = ['service' => $serviceName, 'field' => $f];
            }
        }

        $allFields = [self::picker($serviceNames)];
        $renamed = []; // for the plan doc / debugging: key => [old service-specific key => service]

        foreach ($groups as $key => $occurrences) {
            if (count($occurrences) === 1) {
                $allFields[] = self::gateOne($occurrences[0]['field'], $occurrences[0]['service']);
                continue;
            }

            // Multiple services share this key. Cluster occurrences by
            // whether their field definition (label/type/options/etc,
            // ignoring 'order' which isn't set yet) is byte-identical —
            // most keys have exactly one cluster (fully identical
            // everywhere), but a key like 'veh_reg_main' has two: the
            // ~12 services asking "Vehicle Reg. No." (one cluster) and
            // "New Vehicle Registration" alone asking "Temp. Reg. No. /
            // Chassis No." (a second, different cluster).
            $clusters = []; // signature => occurrences[]
            foreach ($occurrences as $o) { $clusters[serialize($o['field'])][] = $o; }

            if (count($clusters) === 1) {
                $names = array_map(fn($o) => $o['service'], $occurrences);
                $allFields[] = self::gateMany($occurrences[0]['field'], $names);
                continue;
            }

            // More than one distinct variant of this key: the LARGEST
            // cluster keeps the original key (merged with 'in' the same
            // way a fully-identical key would be); every smaller/differing
            // cluster gets a service-suffixed key instead, so no field
            // definition is silently dropped or overwritten by another.
            uasort($clusters, fn($a, $b) => count($b) <=> count($a));
            $isPrimary = true;
            foreach ($clusters as $cluster) {
                if ($isPrimary) {
                    $isPrimary = false;
                    $names = array_map(fn($o) => $o['service'], $cluster);
                    $allFields[] = count($cluster) > 1
                        ? self::gateMany($cluster[0]['field'], $names)
                        : self::gateOne($cluster[0]['field'], $cluster[0]['service']);
                    continue;
                }
                foreach ($cluster as $o) {
                    $suffix = '_' . preg_replace('/[^a-z0-9]+/', '', strtolower(substr($o['service'], 0, 12)));
                    $f = $o['field'];
                    $f['key'] = $f['key'] . $suffix;
                    $allFields[] = self::gateOne($f, $o['service']);
                    $renamed[$key][] = $f['key'] . ' <- ' . $o['service'];
                }
            }
        }

        foreach ($allFields as $i => &$f) { $f['order'] = $i * 10; }
        return [
            'meta' => ['title' => $title],
            'steps' => [['key' => 'details', 'label' => 'Service Details', 'order' => 0, 'fields' => $allFields]],
            'documents' => [],
        ];
    }

    /** @return array<string,array> real service name => raw (ungated) field list, exactly as migrated in Parts 4.6/4.7. */
    private static function serviceFieldSets(): array
    {
        $F = fn($f) => self::field($f);
        $s = [];

        // ── DRIVING LICENSE (10) ──────────────────────────────────────────
        $s['Learning License'] = [
            $F(['key' => 'll_first_time', 'label' => 'First Time or Reissue?', 'type' => 'select', 'options' => ['Yes' => 'First Time', 'No' => 'Reissue']]),
            $F(['key' => 'll_age18', 'label' => 'Are you 18+ (light vehicle)?', 'type' => 'select', 'required' => true, 'options' => ['Yes' => 'Yes', 'No' => 'No'], 'help_text' => 'Light vehicles only']),
            $F(['key' => 'proof_age', 'label' => 'Upload Proof of Age', 'type' => 'file', 'help_text' => 'Aadhaar, Birth Cert., Class 10 Marksheet']),
            $F(['key' => 'proof_addr', 'label' => 'Upload Proof of Address', 'type' => 'file']),
        ];
        $s['New Driving License'] = [
            $F(['key' => 'has_ll', 'label' => 'Do you have a Learning License?', 'type' => 'select', 'required' => true, 'options' => ['Yes' => 'Yes', 'No' => 'No']]),
            $F(['key' => 'new_dl_age18', 'label' => 'Are you 18+?', 'type' => 'select', 'required' => true, 'options' => ['Yes' => 'Yes', 'No' => 'No']]),
            $F(['key' => 'proof_age_new', 'label' => 'Upload Proof of Age', 'type' => 'file']),
            $F(['key' => 'proof_addr_new', 'label' => 'Upload Proof of Address', 'type' => 'file']),
        ];
        $s['Duplicate Driving License'] = [
            $F(['key' => 'dup_dl_reason', 'label' => 'Reason', 'type' => 'select', 'required' => true,
                'options' => ['Lost' => 'Lost', 'Stolen' => 'Stolen (FIR required)', 'Damaged' => 'Damaged']]),
            $F(['key' => 'fir_copy', 'label' => 'Upload FIR Copy', 'type' => 'file',
                'visible_if' => ['logic' => 'OR', 'rules' => [['field' => 'dup_dl_reason', 'op' => 'equals', 'value' => 'Lost'], ['field' => 'dup_dl_reason', 'op' => 'equals', 'value' => 'Stolen']], 'groups' => []]]),
            $F(['key' => 'damaged_dl_photo', 'label' => 'Upload Photo of Damaged DL', 'type' => 'file',
                'visible_if' => self::cond('dup_dl_reason', 'equals', 'Damaged')]),
        ];
        $s['Driving License Renewal'] = [
            $F(['key' => 'dl_number', 'label' => 'DL Number', 'type' => 'text', 'required' => true, 'placeholder' => 'e.g. DL-0420110012345']),
            $F(['key' => 'dl_expiry', 'label' => 'DL Expiry Date', 'type' => 'date', 'required' => true]),
            $F(['key' => 'renewal_reason', 'label' => 'Renewal Reason', 'type' => 'select', 'options' => ['Regular Renewal' => 'Regular Renewal', 'Expired' => 'Expired', 'Address Change' => 'Address Change']]),
            $F(['key' => 'current_dl', 'label' => 'Upload Current DL', 'type' => 'file']),
        ];
        $s['Transport License'] = [
            $F(['key' => 'transport_type', 'label' => 'Type', 'type' => 'select', 'options' => ['New Transport License' => 'New Transport License', 'Renew Existing' => 'Renew Existing']]),
            $F(['key' => 'biz_reg_doc', 'label' => 'Upload Business Registration Document', 'type' => 'file']),
            $F(['key' => 'veh_ownership_proof', 'label' => 'Upload Vehicle Ownership Proof', 'type' => 'file']),
        ];
        $s['Smart Card Driving License'] = [
            $F(['key' => 'has_existing_dl', 'label' => 'Do you have an Existing DL?', 'type' => 'select', 'options' => ['Yes' => 'Yes', 'No' => 'No']]),
            $F(['key' => 'sc_change_addr', 'label' => 'Change of Address Required?', 'type' => 'select', 'required' => true, 'options' => ['Yes' => 'Yes', 'No' => 'No']]),
            $F(['key' => 'sc_dl_number', 'label' => 'Existing DL Number', 'type' => 'text', 'visible_if' => self::cond('has_existing_dl', 'equals', 'Yes')]),
            $F(['key' => 'sc_dl_copy', 'label' => 'Upload Existing DL Copy', 'type' => 'file', 'visible_if' => self::cond('has_existing_dl', 'equals', 'Yes')]),
        ];
        $s['Change of Address in Driving License'] = [
            $F(['key' => 'dl_new_address', 'label' => 'New Address', 'type' => 'textarea', 'required' => true, 'placeholder' => 'Complete new address...']),
            $F(['key' => 'dl_copy_addr', 'label' => 'Upload DL Copy', 'type' => 'file']),
            $F(['key' => 'addr_proof_dl', 'label' => 'Upload Address Proof', 'type' => 'file', 'required' => true]),
        ];
        $s['Renewal of Driving License After Expiry'] = [
            $F(['key' => 'expired_dl_no', 'label' => 'Expired DL Number', 'type' => 'text', 'required' => true]),
            $F(['key' => 'expired_dl_date', 'label' => 'Expiry Date', 'type' => 'date', 'required' => true]),
            $F(['key' => 'expiry_period', 'label' => 'Expiry Period', 'type' => 'select', 'options' => ['Under 1 Year Expired' => 'Under 1 Year Expired', 'Over 1 Year Expired' => 'Over 1 Year Expired']]),
            $F(['key' => 'expired_dl_copy', 'label' => 'Upload Expired DL Copy', 'type' => 'file']),
        ];
        $s['Permanent License Service'] = [
            $F(['key' => 'll_no_perm', 'label' => 'Learning License Number', 'type' => 'text', 'required' => true]),
            $F(['key' => 'll_issue_date', 'label' => 'LL Issue Date', 'type' => 'date', 'required' => true]),
            $F(['key' => 'll_copy', 'label' => 'Upload LL Copy', 'type' => 'file']),
        ];
        $s['Information Changes / Correction on DL'] = [
            $F(['key' => 'dl_change_type', 'label' => 'Type of Change', 'type' => 'select', 'required' => true,
                'options' => ['Name' => 'Name', 'Address' => 'Address', 'Date of Birth' => 'Date of Birth', 'Father Name' => 'Father Name']]),
            $F(['key' => 'dl_corr_addr', 'label' => 'New Address', 'type' => 'textarea', 'visible_if' => self::cond('dl_change_type', 'equals', 'Address')]),
            $F(['key' => 'name_change_proof', 'label' => 'Upload Name Change Proof', 'type' => 'file', 'visible_if' => self::cond('dl_change_type', 'equals', 'Name')]),
            $F(['key' => 'dob_proof', 'label' => 'Upload Date of Birth Proof', 'type' => 'file', 'visible_if' => self::cond('dl_change_type', 'equals', 'Date of Birth')]),
        ];

        // ── RC SERVICES (7) ───────────────────────────────────────────────
        $s['Transfer of Ownership'] = [
            $F(['key' => 'transfer_role', 'label' => 'Buyer or Seller?', 'type' => 'select', 'required' => true, 'options' => ['Buyer' => 'Buyer', 'Seller' => 'Seller']]),
            $F(['key' => 'family_transfer', 'label' => 'Family Transfer?', 'type' => 'select', 'options' => ['No' => 'No', 'Yes' => 'Yes (lower fee)']]),
            $F(['key' => 'veh_reg_transfer', 'label' => 'Vehicle Registration No.', 'type' => 'text', 'required' => true, 'placeholder' => 'e.g. DL01AB1234']),
            $F(['key' => 'owner_name_transfer', 'label' => 'Owner Name (As per RC)', 'type' => 'text', 'required' => true, 'placeholder' => 'Name on RC']),
            $F(['key' => 'seller_city', 'label' => 'Seller City', 'type' => 'text', 'required' => true]),
            $F(['key' => 'buyer_city', 'label' => 'Buyer City', 'type' => 'text', 'required' => true]),
            $F(['key' => 'rc_copy_transfer', 'label' => 'Upload RC Copy', 'type' => 'file', 'required' => true]),
        ];
        $s['Change of Address in RC'] = [
            $F(['key' => 'veh_reg_rcaddr', 'label' => 'Vehicle Reg. No.', 'type' => 'text', 'required' => true]),
            $F(['key' => 'owner_name_rcaddr', 'label' => 'Owner Name (As per RC)', 'type' => 'text', 'required' => true]),
            $F(['key' => 'rc_addr_type', 'label' => 'Type of Address Change', 'type' => 'select', 'options' => ['Within same state' => 'Within same state', 'New state' => 'New state (inter-state)']]),
            $F(['key' => 'rc_addr_reason', 'label' => 'Reason for Change', 'type' => 'select', 'options' => ['Shifted Residence' => 'Shifted Residence', 'Correction' => 'Address Correction']]),
            $F(['key' => 'rc_rto_type', 'label' => 'RTO Type', 'type' => 'select', 'options' => ['Within same RTO office' => 'Within same RTO office', 'New RTO office' => 'New RTO office']]),
            $F(['key' => 'rc_copy_rcaddr', 'label' => 'Upload RC Copy', 'type' => 'file']),
        ];
        $s['Duplicate RC (Lost / Damaged / Stolen)'] = [
            $F(['key' => 'veh_reg_rcdup', 'label' => 'Vehicle Reg. No.', 'type' => 'text', 'required' => true]),
            $F(['key' => 'owner_name_rcdup', 'label' => 'Owner Name (As per RC)', 'type' => 'text', 'required' => true]),
            $F(['key' => 'rc_dup_reason', 'label' => 'Reason', 'type' => 'select', 'required' => true, 'options' => ['Lost' => 'Lost', 'Damaged' => 'Damaged', 'Stolen' => 'Stolen (FIR required)']]),
            $F(['key' => 'rc_copy_rcdup', 'label' => 'Upload RC Copy (if available)', 'type' => 'file']),
        ];
        $s['RC Particulars / RC Extract'] = [
            $F(['key' => 'veh_reg_rcpart', 'label' => 'Vehicle Reg. No.', 'type' => 'text', 'required' => true]),
            $F(['key' => 'rc_extract_purpose', 'label' => 'Purpose of Extract', 'type' => 'select', 'options' => ['Ownership Check' => 'Ownership Check', 'Legal Case' => 'Legal Case', 'Buyer Verification' => 'Buyer Verification']]),
        ];
        $s['RC Correction'] = [
            $F(['key' => 'veh_reg_rccorr', 'label' => 'Vehicle Reg. No.', 'type' => 'text', 'required' => true]),
            $F(['key' => 'owner_name_rccorr', 'label' => 'Owner Name (As per RC)', 'type' => 'text', 'required' => true]),
            $F(['key' => 'rc_corr_type', 'label' => 'Type of Correction', 'type' => 'select', 'required' => true,
                'options' => ['Name Correction' => 'Name Correction', 'Engine No Correction' => 'Engine Number Correction', 'Chassis No' => 'Chassis Number Correction', 'Other Info' => 'Other Information']]),
            $F(['key' => 'rc_copy_rccorr', 'label' => 'Upload RC Copy', 'type' => 'file']),
        ];
        $s['RC Cancellation'] = [
            $F(['key' => 'veh_reg_rccancel', 'label' => 'Vehicle Reg. No.', 'type' => 'text', 'required' => true]),
            $F(['key' => 'rc_cancel_reason', 'label' => 'Reason', 'type' => 'select', 'required' => true,
                'options' => ['Vehicle Written Off' => 'Vehicle Written Off', 'Destroyed' => 'Destroyed', 'Total Loss' => 'Total Loss']]),
            $F(['key' => 'incident_date', 'label' => 'Date of Incident (approx.)', 'type' => 'date']),
            $F(['key' => 'insurance_claim', 'label' => 'Insurance Claim / Settlement?', 'type' => 'select', 'options' => ['No' => 'No', 'Yes' => 'Yes, claim in progress / settled']]),
        ];
        $s['RC Surrender'] = [
            $F(['key' => 'veh_reg_rcsurr', 'label' => 'Vehicle Reg. No.', 'type' => 'text', 'required' => true]),
            $F(['key' => 'surrender_reason', 'label' => 'Reason for Surrender', 'type' => 'select', 'options' => ['Scrapped' => 'Scrapped / Condemned', 'Exported' => 'Exported', 'Vehicle Not in Use' => 'Not in Use']]),
        ];

        // ── HP / HYPOTHECATION (5 real names, 3 unique field sets) ────────
        $banks = ['State Bank of India','Punjab National Bank','Bank of Baroda','Canara Bank','Union Bank of India','Bank of India','Indian Bank','Central Bank of India','UCO Bank','Indian Overseas Bank','Punjab & Sind Bank','HDFC Bank','ICICI Bank','Axis Bank','Kotak Mahindra Bank','Yes Bank','IndusInd Bank','IDFC FIRST Bank','Federal Bank','South Indian Bank','RBL Bank','Bandhan Bank','DCB Bank','Karnataka Bank','CitiBank','HSBC Bank','Standard Chartered Bank','AU Small Finance Bank','Ujjivan Small Finance Bank','Equitas Small Finance Bank','Jana Small Finance Bank','Tata Capital','Bajaj Finance','Mahindra Finance','HDB Financial Services','Cholamandalam Finance','Shriram Finance','Sundaram Finance','Muthoot Fincorp','Hero FinCorp','Maruti Suzuki Financial Services','Tata Motors Finance','Hyundai Finance','Toyota Financial Services'];
        $s['Hypothecation Termination / Removal'] = [
            $F(['key' => 'veh_reg_hpterm', 'label' => 'Vehicle Reg. No.', 'type' => 'text', 'required' => true]),
            $F(['key' => 'loan_closed', 'label' => 'Loan Closed (Month/Year)', 'type' => 'text', 'required' => true, 'help_text' => 'Month input in the live form; stored as text here.']),
            $F(['key' => 'financer_name', 'label' => 'Financer / Bank Name', 'type' => 'select', 'required' => true, 'options' => array_combine($banks, $banks)]),
        ];
        $s['Hypothecation Continuation'] = [
            $F(['key' => 'veh_reg_hpcont', 'label' => 'Vehicle Reg. No.', 'type' => 'text', 'required' => true]),
            $F(['key' => 'hp_cont_reason', 'label' => 'Reason', 'type' => 'select', 'required' => true,
                'options' => ['Extension' => 'Extension (same bank)', 'Refinance' => 'Refinance (new bank)', 'Bank Change' => 'Bank Change']]),
            $F(['key' => 'current_bank', 'label' => 'Current Bank Name', 'type' => 'text', 'required' => true, 'placeholder' => 'Current financer']),
            $F(['key' => 'new_bank', 'label' => 'New Bank Name (Refinance)', 'type' => 'text', 'placeholder' => 'New bank / NBFC name', 'visible_if' => self::cond('hp_cont_reason', 'equals', 'Refinance')]),
        ];
        // FIX (Part 4.12 — disclosed content gap closed, not carried forward
        // silently a second time): these 3 real services previously shared
        // the SAME thin 2-field ($hpOtherFields) set — a real thinness that
        // existed in the live apply.php form itself and was faithfully
        // migrated as-is in Part 4.6/4.7, then correctly disclosed as still
        // open through Parts 4.10/4.11. Each is now its own real,
        // operation-specific field set — this is new field content beyond
        // a strict migration (the live static form never had these), a
        // deliberate content improvement now that the user has asked for
        // the whole workstream to be complete, not just re-architected.
        $s['Hypothecation Addition'] = [
            // A vehicle that currently has NO loan/hypothecation on its RC
            // is taking a fresh loan — the bank/NBFC needs to be added to
            // the RC as the hypothecatee for the first time.
            $F(['key' => 'veh_reg_hpadd', 'label' => 'Vehicle Reg. No.', 'type' => 'text', 'required' => true]),
            $F(['key' => 'owner_name_hpadd', 'label' => 'Owner Name (As per RC)', 'type' => 'text', 'required' => true, 'placeholder' => 'As on RC']),
            $F(['key' => 'financer_name_hpadd', 'label' => 'Financer / Bank Name to be Added', 'type' => 'select', 'required' => true, 'options' => array_combine($banks, $banks)]),
            $F(['key' => 'loan_amount_hpadd', 'label' => 'Loan Amount (₹)', 'type' => 'number', 'required' => true, 'validation' => ['min' => 1]]),
            $F(['key' => 'loan_agreement_date', 'label' => 'Loan Agreement Date', 'type' => 'date', 'required' => true]),
            $F(['key' => 'loan_agreement_upload', 'label' => 'Upload Loan Agreement Copy', 'type' => 'file', 'required' => true]),
            $F(['key' => 'rc_copy_hpadd', 'label' => 'Upload Current RC Copy', 'type' => 'file', 'required' => true]),
        ];
        $s['RC Release after HP Removal'] = [
            // HP has ALREADY been removed (via a separate Termination/
            // Removal request or the financer's own Form 35 process) — this
            // service is specifically about getting the physical/updated RC
            // book re-issued showing no hypothecation, not removing it.
            $F(['key' => 'veh_reg_hprelease', 'label' => 'Vehicle Reg. No.', 'type' => 'text', 'required' => true]),
            $F(['key' => 'owner_name_hprelease', 'label' => 'Owner Name (As per RC)', 'type' => 'text', 'required' => true, 'placeholder' => 'As on RC']),
            $F(['key' => 'hp_removal_ref', 'label' => 'HP Removal Reference / Application No. (if known)', 'type' => 'text', 'placeholder' => 'Leave blank if not available']),
            $F(['key' => 'hp_removal_date', 'label' => 'Date HP Was Removed (approx.)', 'type' => 'date']),
            $F(['key' => 'form35_upload', 'label' => 'Upload Form 35 / Financer NOC (proof HP is removed)', 'type' => 'file', 'required' => true]),
            $F(['key' => 'rc_copy_hprelease', 'label' => 'Upload Current RC Copy', 'type' => 'file', 'required' => true]),
            $F(['key' => 'release_reason', 'label' => 'Reason for Requesting Release', 'type' => 'select', 'required' => true,
                'options' => ['Loan closed, need clean RC' => 'Loan closed, need clean RC', 'RC damaged/lost, reprinting' => 'RC damaged/lost, reprinting', 'Selling vehicle, buyer needs clean RC' => 'Selling vehicle, buyer needs clean RC', 'Other' => 'Other']]),
        ];
        $s['Hypothecation Transfer'] = [
            // Existing, ACTIVE hypothecation is being moved from one
            // financer to another (loan bought out / assigned) WITHOUT the
            // loan itself being closed first — distinct from "Continuation"
            // above (which covers extension/refinance/bank-change framed as
            // continuing the same relationship) and distinct from Removal
            // (which ends hypothecation entirely).
            $F(['key' => 'veh_reg_hptransfer', 'label' => 'Vehicle Reg. No.', 'type' => 'text', 'required' => true]),
            $F(['key' => 'owner_name_hptransfer', 'label' => 'Owner Name (As per RC)', 'type' => 'text', 'required' => true, 'placeholder' => 'As on RC']),
            $F(['key' => 'existing_financer', 'label' => 'Existing Financer / Bank Name', 'type' => 'select', 'required' => true, 'options' => array_combine($banks, $banks)]),
            $F(['key' => 'new_financer_hptransfer', 'label' => 'New Financer / Bank Name', 'type' => 'select', 'required' => true, 'options' => array_combine($banks, $banks)]),
            $F(['key' => 'transfer_reason_hp', 'label' => 'Reason for Transfer', 'type' => 'select', 'required' => true,
                'options' => ['Loan bought out by new lender' => 'Loan bought out by new lender', 'Better interest rate elsewhere' => 'Better interest rate elsewhere', 'NBFC-to-bank transfer' => 'NBFC-to-bank transfer', 'Other' => 'Other']]),
            $F(['key' => 'existing_financer_noc', 'label' => 'Upload NOC from Existing Financer', 'type' => 'file', 'required' => true]),
            $F(['key' => 'new_loan_agreement', 'label' => 'Upload New Loan Agreement', 'type' => 'file', 'required' => true]),
        ];

        // ── NOC (4 real names) ──────────────────────────────────────────────
        $nocDocs = ['RC' => 'RC', 'Insurance' => 'Insurance', 'PUC' => 'PUC', 'Aadhaar / ID Proof' => 'Aadhaar / ID Proof', 'Bank NOC + Form 35 (if loan active)' => 'Bank NOC + Form 35 (if loan active)'];
        $nocBaseFields = [
            // 'role' => 'vehicle_type' declared here too, for the same
            // reason as $vehType above — this is the single vehicle-type
            // field in the NOC category schema (shared identically by all
            // 4 NOC services, merged into one gated field).
            $F(['key' => 'veh_type_noc', 'label' => 'Vehicle Type', 'type' => 'select', 'required' => true, 'role' => 'vehicle_type',
                'options' => ['Two-wheeler' => 'Two-Wheeler', 'Four-wheeler' => 'Four-Wheeler', 'Commercial' => 'Commercial']]),
            $F(['key' => 'veh_reg_noc', 'label' => 'Vehicle Reg. No.', 'type' => 'text', 'required' => true]),
            $F(['key' => 'owner_name_noc', 'label' => 'Owner Name (As per RC)', 'type' => 'text', 'required' => true]),
            $F(['key' => 'noc_docs', 'label' => 'Select Available Documents', 'type' => 'multiselect', 'required' => true, 'options' => $nocDocs]),
        ];
        $s['Inter-state NOC'] = $nocBaseFields;
        $s['Within-state Transfer NOC'] = $nocBaseFields;
        $s['Hypothecation Removal NOC'] = $nocBaseFields;
        $s['Export / Out-of-Country NOC'] = $nocBaseFields;

        // ── VEHICLE SERVICES (16) ───────────────────────────────────────────
        $vehBase = fn($regLabel, $required = true) => $F(['key' => 'veh_reg_main', 'label' => $regLabel, 'type' => 'text', 'required' => $required, 'placeholder' => 'e.g. DL01AB1234']);
        // 'role' => 'vehicle_type' declared explicitly (Part 4.14 role
        // contract fix): this is the ONE field in the whole Vehicle
        // Services category schema asking for vehicle type (every one of
        // the 16 services below shares this single flattened/gated field —
        // see categorySchema()'s key-collision merging), so the role
        // resolves unambiguously. Router::submitApplyDynamic() and
        // EligibilityService rules can now find "the vehicle type answer"
        // by role regardless of this field ever being renamed.
        $vehType = $F(['key' => 'veh_type_main', 'label' => 'Vehicle Type', 'type' => 'select', 'required' => true, 'role' => 'vehicle_type',
            'options' => ['Two-wheeler' => 'Two-Wheeler', 'Four-wheeler' => 'Four-Wheeler', 'Commercial' => 'Commercial']]);
        $vehOwnerRow = [
            $F(['key' => 'owner_name_veh', 'label' => 'Owner Name (As per RC)', 'type' => 'text', 'placeholder' => 'As on RC']),
            $F(['key' => 'rc_copy_veh', 'label' => 'Upload RC Copy', 'type' => 'file']),
        ];
        $fitnessFields = [
            $F(['key' => 'old_fitness_copy', 'label' => 'Old Fitness Certificate Copy', 'type' => 'file']),
            $F(['key' => 'vehicle_photos', 'label' => 'Vehicle Photos (Front + Back)', 'type' => 'file', 'required' => true]),
        ];
        $s['New Vehicle Registration'] = array_merge([$vehType, $vehBase('Temp. Reg. No. / Chassis No.')], $vehOwnerRow, [$F(['key' => 'invoice_copy', 'label' => 'Invoice Copy', 'type' => 'file', 'required' => true])]);
        $s['RC Ownership Transfer'] = array_merge([$vehType, $vehBase('Vehicle Reg. No.')], $vehOwnerRow);
        $s['RC Renewal (After 15 Years)'] = array_merge([$vehType, $vehBase('Vehicle Reg. No.')], $vehOwnerRow);
        $s['Duplicate RC'] = array_merge([$vehType, $vehBase('Vehicle Reg. No.')], $vehOwnerRow);
        $s['Address Change / Correction in RC'] = array_merge([$vehType, $vehBase('Vehicle Reg. No.')], $vehOwnerRow);
        $s['Re-Registration (10–15 Years Old)'] = array_merge([$vehType, $vehBase('Vehicle Reg. No.')], $vehOwnerRow);
        $s['Re-Assignment'] = array_merge([$vehType, $vehBase('Vehicle Reg. No.')], $vehOwnerRow);
        $s['Fitness Certificate'] = array_merge([$vehType, $vehBase('Vehicle Reg. No.')], $vehOwnerRow, $fitnessFields);
        $s['Fitness Certificate Renewal'] = array_merge([$vehType, $vehBase('Vehicle Reg. No.')], $vehOwnerRow, $fitnessFields);
        $s['Duplicate Fitness Certificate'] = array_merge([$vehType, $vehBase('Vehicle Reg. No.')], $vehOwnerRow, $fitnessFields);
        $s['No Objection Certificate (NOC)'] = array_merge([$vehType, $vehBase('Vehicle Reg. No.')], $vehOwnerRow);
        $s['Road Tax Payment'] = array_merge([$vehType, $vehBase('Vehicle Reg. No.')], $vehOwnerRow);
        $s['Road Tax Refund'] = array_merge([$vehType, $vehBase('Vehicle Reg. No.')], $vehOwnerRow);
        $s['Permit Services / National Permit'] = array_merge([$vehType, $vehBase('Vehicle Reg. No.')], $vehOwnerRow, [$F(['key' => 'permit_type', 'label' => 'Permit Type', 'type' => 'select', 'required' => true, 'options' => ['National Permit' => 'National Permit', 'State Permit' => 'State Permit', 'Temporary Permit' => 'Temporary Permit']])]);
        $s['Vehicle Insurance'] = array_merge([$vehType, $vehBase('Vehicle Reg. No.')], [$F(['key' => 'insurance_need', 'label' => 'What do you need?', 'type' => 'select', 'required' => true, 'options' => ['New Policy' => 'New Policy', 'Renewal' => 'Renewal', 'Endorsement Change' => 'Endorsement Change']])]);
        $s['Alteration / Conversion of Vehicle'] = array_merge([$vehType, $vehBase('Vehicle Reg. No.')], $vehOwnerRow, [$F(['key' => 'alteration_type', 'label' => 'Type of Alteration', 'type' => 'select', 'required' => true, 'options' => ['CNG/LPG Conversion' => 'CNG/LPG Conversion', 'Body Change' => 'Body Change', 'Engine Change' => 'Engine Change', 'Other' => 'Other']])]);

        // ── COMMERCIAL VEHICLE (1) ───────────────────────────────────────────
        $s['Commercial Vehicle Service'] = [
            $F(['key' => 'commercial_veh_type', 'label' => 'Vehicle Type', 'type' => 'select',
                'options' => ['Truck' => 'Truck / HGV', 'Bus' => 'Bus / Passenger', 'Tempo' => 'Tempo / LCV', 'Auto' => 'Auto Rickshaw', 'Taxi' => 'Taxi / Cab', 'Other' => 'Other Commercial']]),
            $F(['key' => 'commercial_veh_reg', 'label' => 'Vehicle Registration No.', 'type' => 'text', 'placeholder' => 'e.g. DL01G1234']),
            $F(['key' => 'commercial_description', 'label' => 'Describe the service needed', 'type' => 'textarea', 'required' => true, 'placeholder' => 'E.g. National Permit renewal, Fitness Certificate, Route Permit, etc.']),
        ];

        // ── OTHER SERVICES (1) ────────────────────────────────────────────────
        $s['RTO Consultation & Documentation'] = [
            $F(['key' => 'other_description', 'label' => 'Describe what you need', 'type' => 'textarea', 'placeholder' => 'Describe the RTO service you require...']),
        ];

        return $s;
    }

    /**
     * The 7 real categories, in the same order apply.php's step-1 category
     * buttons use, each listing its real service names in the same order
     * their real dropdown/JS mapping already uses.
     *
     * @return array<string,array{title:string,services:string[]}>
     */
    public static function categoryMap(): array
    {
        return [
            'dl' => ['title' => 'Driving License', 'services' => [
                'Learning License', 'New Driving License', 'Duplicate Driving License', 'Driving License Renewal',
                'Transport License', 'Smart Card Driving License', 'Change of Address in Driving License',
                'Renewal of Driving License After Expiry', 'Permanent License Service', 'Information Changes / Correction on DL',
            ]],
            'rc' => ['title' => 'RC Services', 'services' => [
                'Transfer of Ownership', 'Change of Address in RC', 'Duplicate RC (Lost / Damaged / Stolen)',
                'RC Particulars / RC Extract', 'RC Correction', 'RC Cancellation', 'RC Surrender',
            ]],
            'hp' => ['title' => 'HP / Hypothecation', 'services' => [
                'Hypothecation Addition', 'Hypothecation Termination / Removal', 'Hypothecation Continuation',
                'RC Release after HP Removal', 'Hypothecation Transfer',
            ]],
            'noc' => ['title' => 'NOC', 'services' => [
                'Inter-state NOC', 'Within-state Transfer NOC', 'Hypothecation Removal NOC', 'Export / Out-of-Country NOC',
            ]],
            'vehicle' => ['title' => 'Vehicle Services', 'services' => [
                'New Vehicle Registration', 'RC Ownership Transfer', 'RC Renewal (After 15 Years)', 'Duplicate RC',
                'Address Change / Correction in RC', 'Re-Registration (10–15 Years Old)', 'Re-Assignment',
                'Fitness Certificate', 'Fitness Certificate Renewal', 'Duplicate Fitness Certificate',
                'No Objection Certificate (NOC)', 'Road Tax Payment', 'Road Tax Refund',
                'Permit Services / National Permit', 'Vehicle Insurance', 'Alteration / Conversion of Vehicle',
            ]],
            'commercial' => ['title' => 'Commercial Vehicle', 'services' => ['Commercial Vehicle Service']],
            'other' => ['title' => 'Other Services', 'services' => ['RTO Consultation & Documentation']],
        ];
    }

    /** @return array<string,array> category key (dl, rc, hp, noc, vehicle, commercial, other) => v2 schema */
    public static function categorySchemas(): array
    {
        $allServiceFields = self::serviceFieldSets();
        $out = [];
        foreach (self::categoryMap() as $catKey => $cat) {
            $byService = [];
            foreach ($cat['services'] as $name) {
                if (!isset($allServiceFields[$name])) {
                    throw new \RuntimeException("RealFormSchemaSeeder: no field set defined for '{$name}' in category '{$catKey}' — categoryMap() and serviceFieldSets() are out of sync.");
                }
                $byService[$name] = $allServiceFields[$name];
            }
            $out[$catKey] = self::categorySchema($cat['title'], $byService);
        }
        return $out;
    }

    /**
     * Runs the migration against the live database: builds and saves all 7
     * category schemas through FormEngineService::saveForCategory() (same
     * normalisation/versioning/audit pipeline a human editing in the
     * builder UI goes through — no bypass).
     *
     * @return array{saved: array<string,int>, failed: array<string,string>}
     */
    public static function run(): array
    {
        $result = ['saved' => [], 'failed' => []];
        // FIX (production crash found after first delivery): this used to
        // do `new FormEngineService(new FormRepository())` — but
        // FormRepository::__construct() requires a QueryBuilder, which
        // itself requires \wpdb, neither of which were being passed here.
        // That would have thrown a TypeError the instant run() executed.
        // The container already knows how to build this whole dependency
        // chain correctly (every other call site in this codebase resolves
        // FormEngineService the same way — see FormBuilderController,
        // Router::routeApply(), etc.) — reuse it instead of re-wiring by
        // hand a second time.
        $engine = \RTOFLOW\Bootstrap::container()->make(FormEngineService::class);

        foreach (self::categorySchemas() as $catKey => $schema) {
            $title = self::categoryMap()[$catKey]['title'];
            try {
                $schemaId = $engine->saveForCategory($catKey, $title, $schema, 0, 'Migrated from live apply.php static fields (RealFormSchemaSeeder, Part 4.10 category re-architecture)');
                $result['saved'][$catKey] = $schemaId;
            } catch (\InvalidArgumentException $e) {
                $result['failed'][$catKey] = $e->getMessage();
            }
        }

        return $result;
    }
}
