<?php

namespace RTOFLOW\Config;

if (!defined('ABSPATH')) exit;

/**
 * Matching / Lead-Allocation Configuration
 *
 * FIX P1 (Gap-Fix Plan Part 2, item 4 — "Matching/Lead-Allocation
 * configurability"): the scoring weights, minimum-rating gate, active-job
 * cap, and candidate-pool size used by vendor auto-assignment used to be
 * inline numeric literals split across two files
 * (VendorService::auto_assign() and VendorRepository::get_eligible()).
 * Both are now read from this single configured source, stored in
 * wp_options as one JSON blob — the same storage pattern FeatureFlags
 * already uses elsewhere in this codebase, chosen for consistency rather
 * than introducing a second config-storage convention.
 *
 * Usage:
 *   MatchingConfig::get('rating_weight')
 *   MatchingConfig::all()
 *   MatchingConfig::save($_POST)
 */
class MatchingConfig
{
    const OPTION_KEY = 'rtoflow_matching_config';

    // These defaults are exactly the values that were previously hard-coded
    // inline in VendorService::auto_assign() (weights, active-job cap) and
    // VendorRepository::get_eligible() (min rating, candidate pool size) —
    // changing nothing about current behaviour until an admin edits them.
    const DEFAULTS = [
        'rating_weight'       => 30.0, // % of score from vendor's average rating (out of 5)
        'completion_weight'   => 25.0, // % of score from completion_rate (0-100)
        'acceptance_weight'   => 20.0, // % of score from acceptance_rate (0-100)
        'load_weight'         => 25.0, // % of score from (max_active_jobs - current active), i.e. spare capacity
        'min_rating'          => 3.0,  // vendors below this rating are not eligible for auto-assignment at all
        'max_active_jobs'     => 20,   // a vendor at or above this many active jobs is skipped for new assignments
        'candidate_pool_size' => 10,   // how many top-rated eligible vendors are considered per assignment
    ];

    const META = [
        'rating_weight'       => ['Rating Weight (%)',        'How much a vendor\'s average star rating influences their assignment score.'],
        'completion_weight'   => ['Completion Rate Weight (%)','How much a vendor\'s job-completion rate influences their assignment score.'],
        'acceptance_weight'   => ['Acceptance Rate Weight (%)', 'How much a vendor\'s job-acceptance rate influences their assignment score.'],
        'load_weight'         => ['Spare Capacity Weight (%)',  'How much a vendor\'s remaining capacity (vs. Max Active Jobs) influences their assignment score.'],
        'min_rating'          => ['Minimum Rating',             'Vendors below this average rating are excluded from auto-assignment entirely.'],
        'max_active_jobs'     => ['Max Active Jobs per Vendor',  'A vendor with this many or more active jobs is skipped for new auto-assignments.'],
        'candidate_pool_size' => ['Candidate Pool Size',         'How many top-rated eligible vendors are pulled per assignment attempt before scoring.'],
    ];

    private static ?array $config = null;

    private static function load(): array
    {
        if (self::$config !== null) return self::$config;
        $stored = get_option(self::OPTION_KEY, null);
        if ($stored === null) {
            update_option(self::OPTION_KEY, wp_json_encode(self::DEFAULTS));
            return self::$config = self::DEFAULTS;
        }
        $decoded = json_decode($stored, true) ?: [];
        return self::$config = array_merge(self::DEFAULTS, $decoded);
    }

    public static function get(string $key): float
    {
        return (float)(self::load()[$key] ?? self::DEFAULTS[$key] ?? 0);
    }

    public static function all(): array
    {
        $config = self::load();
        $result = [];
        foreach (self::DEFAULTS as $key => $default) {
            [$label, $desc] = self::META[$key] ?? [$key, ''];
            $result[$key] = [
                'value'       => $config[$key] ?? $default,
                'label'       => $label,
                'description' => $desc,
            ];
        }
        return $result;
    }

    // FIX (Vendor Matching preview/validation gap): the four scoring weights
    // feed directly into VendorService's score formula as fractions of a
    // 0-100 scale (see VendorService::scoreVendor()). If they don't sum to
    // 100, the resulting "score" is not actually a 0-100 scale any more —
    // it silently over- or under-weights every vendor by the same skew,
    // which is invisible to an admin just looking at four independent
    // numbers. The view's old JS-only sum hint never blocked a save, so an
    // inconsistent config could reach production. This is the real,
    // server-side gate; SUM_TOLERANCE absorbs float/rounding noise from the
    // 0.1-step number inputs without opening the door to a meaningfully
    // wrong total.
    private const WEIGHT_SUM_TARGET = 100.0;
    private const SUM_TOLERANCE     = 0.05;

    /**
     * @param array $submitted Raw $_POST-shaped array; only known keys are read,
     *              each clamped to a sane range so a malformed value can't zero
     *              out the scoring formula or make every vendor ineligible.
     * @return array{success:bool,message?:string,sum?:float} success=false means
     *              nothing was written — the caller must not treat this as saved.
     */
    public static function save(array $submitted): array
    {
        $config = [
            'rating_weight'       => max(0, min(100, (float)($submitted['rating_weight']     ?? self::DEFAULTS['rating_weight']))),
            'completion_weight'   => max(0, min(100, (float)($submitted['completion_weight'] ?? self::DEFAULTS['completion_weight']))),
            'acceptance_weight'   => max(0, min(100, (float)($submitted['acceptance_weight'] ?? self::DEFAULTS['acceptance_weight']))),
            'load_weight'         => max(0, min(100, (float)($submitted['load_weight']       ?? self::DEFAULTS['load_weight']))),
            'min_rating'          => max(0, min(5,   (float)($submitted['min_rating']        ?? self::DEFAULTS['min_rating']))),
            'max_active_jobs'     => max(1, min(1000,(int)  ($submitted['max_active_jobs']   ?? self::DEFAULTS['max_active_jobs']))),
            'candidate_pool_size' => max(1, min(100, (int)  ($submitted['candidate_pool_size'] ?? self::DEFAULTS['candidate_pool_size']))),
        ];

        $sum = $config['rating_weight'] + $config['completion_weight']
             + $config['acceptance_weight'] + $config['load_weight'];

        if (abs($sum - self::WEIGHT_SUM_TARGET) > self::SUM_TOLERANCE) {
            return [
                'success' => false,
                'message' => sprintf(
                    'Scoring weights must add up to 100. The values you entered add up to %s.',
                    rtrim(rtrim(number_format($sum, 2), '0'), '.')
                ),
                'sum' => $sum,
            ];
        }

        self::$config = $config;
        update_option(self::OPTION_KEY, wp_json_encode($config));

        // FIX (Config Versioning wiring): every save now also records a
        // version snapshot, so "Version History" on the Matching tab
        // reflects real saves instead of always being empty, and an admin
        // can roll back to a prior set of weights. publish(..., false):
        // the value is already live (update_option() above) — we are only
        // recording history here, not asking ConfigVersionService to apply
        // anything back (that path is for the "activate an older version"
        // case in Bootstrap.php's 'rtoflow_config_published' listener,
        // which itself calls back into this method — passing false here
        // avoids turning that into save→publish→apply→save recursion).
        $versionId = \RTOFLOW\Services\ConfigVersionService::saveDraft(
            'matching_config', $config, get_current_user_id() ?: 0, 'Saved via Settings → Matching'
        );
        if ($versionId) {
            \RTOFLOW\Services\ConfigVersionService::publish($versionId, false);
        }

        return ['success' => true, 'sum' => $sum];
    }
}
