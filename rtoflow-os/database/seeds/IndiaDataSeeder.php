<?php

if (!defined('ABSPATH')) exit;

/**
 * India RTO Data Seeder
 * Seeds states, cities (RTO offices) and services required for the plugin to work.
 * Safe to run multiple times — uses INSERT IGNORE.
 */
class IndiaDataSeeder
{
    private \wpdb $db;
    private string $p;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->p  = $wpdb->prefix;
    }

    public function run(): array
    {
        $results = [];
        $results[] = $this->seedStates();
        $results[] = $this->seedCities();
        $results[] = $this->seedServices();
        return $results;
    }

    // ── States ────────────────────────────────────────────────────────────────

    private function seedStates(): string
    {
        $states = [
            ['Andhra Pradesh',        'AP', '28'],
            ['Arunachal Pradesh',     'AR', '12'],
            ['Assam',                 'AS', '18'],
            ['Bihar',                 'BR', '10'],
            ['Chhattisgarh',          'CG', '22'],
            ['Delhi',                 'DL', '07'],
            ['Goa',                   'GA', '30'],
            ['Gujarat',               'GJ', '24'],
            ['Haryana',               'HR', '06'],
            ['Himachal Pradesh',      'HP', '02'],
            ['Jharkhand',             'JH', '20'],
            ['Karnataka',             'KA', '29'],
            ['Kerala',                'KL', '32'],
            ['Madhya Pradesh',        'MP', '23'],
            ['Maharashtra',           'MH', '27'],
            ['Manipur',               'MN', '14'],
            ['Meghalaya',             'ML', '17'],
            ['Mizoram',               'MZ', '15'],
            ['Nagaland',              'NL', '13'],
            ['Odisha',                'OD', '21'],
            ['Punjab',                'PB', '03'],
            ['Rajasthan',             'RJ', '08'],
            ['Sikkim',                'SK', '11'],
            ['Tamil Nadu',            'TN', '33'],
            ['Telangana',             'TS', '36'],
            ['Tripura',               'TR', '16'],
            ['Uttar Pradesh',         'UP', '09'],
            ['Uttarakhand',           'UK', '05'],
            ['West Bengal',           'WB', '19'],
            ['Andaman & Nicobar',     'AN', '35'],
            ['Chandigarh',            'CH', '04'],
            ['Dadra & Nagar Haveli',  'DN', '26'],
            ['Daman & Diu',           'DD', '25'],
            ['Jammu & Kashmir',       'JK', '01'],
            ['Ladakh',                'LA', '38'],
            ['Lakshadweep',           'LD', '31'],
            ['Puducherry',            'PY', '34'],
        ];

        $inserted = 0;
        foreach ($states as [$name, $code, $gst]) {
            $result = $this->db->query($this->db->prepare(
                "INSERT IGNORE INTO {$this->p}rto_states (name, code, gst_code) VALUES (%s, %s, %s)",
                $name, $code, $gst
            ));
            if ($result) $inserted++;
        }
        return "States: {$inserted} inserted (of " . count($states) . " total)";
    }

    // ── Cities / RTO Offices ──────────────────────────────────────────────────

    private function seedCities(): string
    {
        $stateId = fn(string $code) => (int)$this->db->get_var(
            $this->db->prepare("SELECT id FROM {$this->p}rto_states WHERE code=%s", $code)
        );

        $cities = [
            // Maharashtra
            [$stateId('MH'), 'Mumbai (Central)',  'MH-01'],
            [$stateId('MH'), 'Mumbai (West)',      'MH-02'],
            [$stateId('MH'), 'Mumbai (East)',      'MH-03'],
            [$stateId('MH'), 'Thane',              'MH-04'],
            [$stateId('MH'), 'Pune',               'MH-12'],
            [$stateId('MH'), 'Pimpri-Chinchwad',   'MH-14'],
            [$stateId('MH'), 'Nashik',             'MH-15'],
            [$stateId('MH'), 'Aurangabad',         'MH-20'],
            [$stateId('MH'), 'Nagpur',             'MH-31'],
            [$stateId('MH'), 'Kolhapur',           'MH-09'],
            [$stateId('MH'), 'Solapur',            'MH-13'],
            [$stateId('MH'), 'Navi Mumbai',        'MH-43'],

            // Delhi
            [$stateId('DL'), 'Delhi (Central)',    'DL-01'],
            [$stateId('DL'), 'Delhi (West)',       'DL-02'],
            [$stateId('DL'), 'Delhi (East)',       'DL-03'],
            [$stateId('DL'), 'Delhi (North)',      'DL-04'],
            [$stateId('DL'), 'Delhi (South)',      'DL-05'],
            [$stateId('DL'), 'Delhi (Shahdara)',   'DL-06'],
            [$stateId('DL'), 'Rohini',             'DL-07'],
            [$stateId('DL'), 'Dwarka',             'DL-08'],

            // Karnataka
            [$stateId('KA'), 'Bengaluru (East)',   'KA-05'],
            [$stateId('KA'), 'Bengaluru (West)',   'KA-51'],
            [$stateId('KA'), 'Bengaluru (North)',  'KA-52'],
            [$stateId('KA'), 'Bengaluru (South)',  'KA-53'],
            [$stateId('KA'), 'Mysuru',             'KA-09'],
            [$stateId('KA'), 'Hubli',              'KA-25'],
            [$stateId('KA'), 'Mangaluru',          'KA-19'],
            [$stateId('KA'), 'Belagavi',           'KA-22'],
            [$stateId('KA'), 'Kalaburagi',         'KA-32'],

            // Tamil Nadu
            [$stateId('TN'), 'Chennai (Central)', 'TN-01'],
            [$stateId('TN'), 'Chennai (North)',   'TN-02'],
            [$stateId('TN'), 'Chennai (South)',   'TN-03'],
            [$stateId('TN'), 'Chennai (West)',    'TN-04'],
            [$stateId('TN'), 'Coimbatore',        'TN-37'],
            [$stateId('TN'), 'Madurai',           'TN-58'],
            [$stateId('TN'), 'Tiruchirappalli',   'TN-45'],
            [$stateId('TN'), 'Salem',             'TN-30'],
            [$stateId('TN'), 'Tirunelveli',       'TN-72'],
            [$stateId('TN'), 'Erode',             'TN-33'],

            // Gujarat
            [$stateId('GJ'), 'Ahmedabad (West)',  'GJ-01'],
            [$stateId('GJ'), 'Ahmedabad (East)',  'GJ-18'],
            [$stateId('GJ'), 'Surat',             'GJ-05'],
            [$stateId('GJ'), 'Vadodara',          'GJ-06'],
            [$stateId('GJ'), 'Rajkot',            'GJ-03'],
            [$stateId('GJ'), 'Bhavnagar',         'GJ-04'],
            [$stateId('GJ'), 'Gandhinagar',       'GJ-21'],

            // Uttar Pradesh
            [$stateId('UP'), 'Lucknow',           'UP-32'],
            [$stateId('UP'), 'Kanpur',            'UP-78'],
            [$stateId('UP'), 'Agra',              'UP-80'],
            [$stateId('UP'), 'Varanasi',          'UP-65'],
            [$stateId('UP'), 'Prayagraj',         'UP-70'],
            [$stateId('UP'), 'Ghaziabad',         'UP-14'],
            [$stateId('UP'), 'Noida (GB Nagar)',  'UP-16'],
            [$stateId('UP'), 'Meerut',            'UP-15'],

            // Rajasthan
            [$stateId('RJ'), 'Jaipur',            'RJ-14'],
            [$stateId('RJ'), 'Jodhpur',           'RJ-19'],
            [$stateId('RJ'), 'Udaipur',           'RJ-27'],
            [$stateId('RJ'), 'Kota',              'RJ-13'],
            [$stateId('RJ'), 'Ajmer',             'RJ-01'],
            [$stateId('RJ'), 'Bikaner',           'RJ-04'],

            // Madhya Pradesh
            [$stateId('MP'), 'Bhopal',            'MP-04'],
            [$stateId('MP'), 'Indore',            'MP-09'],
            [$stateId('MP'), 'Gwalior',           'MP-07'],
            [$stateId('MP'), 'Jabalpur',          'MP-20'],
            [$stateId('MP'), 'Ujjain',            'MP-30'],

            // Telangana
            [$stateId('TS'), 'Hyderabad (East)',  'TS-09'],
            [$stateId('TS'), 'Hyderabad (West)',  'TS-10'],
            [$stateId('TS'), 'Hyderabad (North)', 'TS-11'],
            [$stateId('TS'), 'Hyderabad (South)', 'TS-12'],
            [$stateId('TS'), 'Warangal',          'TS-05'],
            [$stateId('TS'), 'Karimnagar',        'TS-02'],

            // Andhra Pradesh
            [$stateId('AP'), 'Visakhapatnam',     'AP-02'],
            [$stateId('AP'), 'Vijayawada',        'AP-16'],
            [$stateId('AP'), 'Guntur',            'AP-04'],
            [$stateId('AP'), 'Kurnool',           'AP-05'],

            // West Bengal
            [$stateId('WB'), 'Kolkata',           'WB-06'],
            [$stateId('WB'), 'Howrah',            'WB-02'],
            [$stateId('WB'), 'Durgapur',          'WB-16'],
            [$stateId('WB'), 'Asansol',           'WB-15'],
            [$stateId('WB'), 'Siliguri',          'WB-73'],

            // Kerala
            [$stateId('KL'), 'Thiruvananthapuram','KL-01'],
            [$stateId('KL'), 'Ernakulam (Kochi)', 'KL-07'],
            [$stateId('KL'), 'Kozhikode',         'KL-11'],
            [$stateId('KL'), 'Thrissur',          'KL-08'],
            [$stateId('KL'), 'Kollam',            'KL-02'],

            // Punjab
            [$stateId('PB'), 'Ludhiana',          'PB-10'],
            [$stateId('PB'), 'Amritsar',          'PB-02'],
            [$stateId('PB'), 'Jalandhar',         'PB-08'],
            [$stateId('PB'), 'Patiala',           'PB-11'],

            // Haryana
            [$stateId('HR'), 'Gurugram',          'HR-26'],
            [$stateId('HR'), 'Faridabad',         'HR-29'],
            [$stateId('HR'), 'Hisar',             'HR-18'],
            [$stateId('HR'), 'Ambala',            'HR-01'],
        ];

        $inserted = 0;
        foreach ($cities as [$stId, $name, $rtoCode]) {
            if (!$stId) continue;
            $result = $this->db->query($this->db->prepare(
                "INSERT IGNORE INTO {$this->p}rto_cities (state_id, name, rto_code) VALUES (%d, %s, %s)",
                $stId, $name, $rtoCode
            ));
            if ($result) $inserted++;
        }
        return "Cities: {$inserted} inserted (of " . count($cities) . " total)";
    }

    // ── RTO Services ──────────────────────────────────────────────────────────

    private function seedServices(): string
    {
        $services = [
            // ── DRIVING LICENSE (10 services) ─────────────────────────────────
            ['Driving License', 'Learning License',                          800,  1, 21, 50, 10, 'New or reissue of Learning Licence. Includes LL application, test booking at RTO, and issuance.'],
            ['Driving License', 'New Driving License',                      1800,  1, 30, 50, 11, 'New Driving Licence for LMV/2-wheeler. Includes LL (if needed), skill test booking, and DL issuance.'],
            ['Driving License', 'Duplicate Driving License',                 900,  1,  7, 50, 12, 'Duplicate DL for lost, stolen, or damaged licence. FIR required for lost/stolen.'],
            ['Driving License', 'Driving License Renewal',                  1200,  1, 10, 50, 13, 'Renewal of expiring or recently expired DL. Includes medical form if required.'],
            ['Driving License', 'Transport License',                        2800,  1, 30, 45, 14, 'Transport/Commercial vehicle driving licence. New issue or renewal.'],
            ['Driving License', 'Smart Card Driving License',               1000,  1, 14, 50, 15, 'Convert old DL booklet to new Smart Card format.'],
            ['Driving License', 'Change of Address in Driving License',      700,  1,  7, 50, 16, 'Update residential address on Driving Licence. Proof of new address required.'],
            ['Driving License', 'Renewal of Driving License After Expiry',  1400,  1, 14, 50, 17, 'Renewal of DL that has already expired. Includes medical test if over 1 year expired.'],
            ['Driving License', 'Permanent License Service',                 600,  1,  7, 50, 18, 'Convert LL to permanent DL after successful driving test. LL must be 30+ days old.'],
            ['Driving License', 'Information Changes / Correction on DL',   700,  1,  7, 50, 19, 'Correct name, DOB, father name, or address errors on Driving Licence.'],

            // ── RC SERVICES (7 services) ──────────────────────────────────────
            ['RC Services', 'Transfer of Ownership',                        2500,  1, 15, 45, 20, 'Transfer RC ownership from seller to buyer. Includes Form 29, 30, and RTO submission.'],
            ['RC Services', 'Change of Address in RC',                      1000,  1, 10, 50, 21, 'Update registered address on vehicle RC. Within-state or inter-state.'],
            ['RC Services', 'Duplicate RC (Lost / Damaged / Stolen)',       1100,  1,  7, 50, 22, 'Duplicate RC for lost, damaged, or stolen Registration Certificate.'],
            ['RC Services', 'RC Particulars / RC Extract',                   600,  1,  5, 55, 23, 'RC extract report for ownership verification, legal cases, or buyer verification.'],
            ['RC Services', 'RC Correction',                                 700,  1,  7, 50, 24, 'Correct name, engine number, chassis number, or other details on RC.'],
            ['RC Services', 'RC Cancellation',                              1200,  1, 10, 45, 25, 'Cancel RC for written-off, destroyed, or total-loss vehicles.'],
            ['RC Services', 'RC Surrender',                                 1000,  1, 10, 45, 26, 'Surrender RC for scrapped, exported, or permanently off-road vehicles.'],

            // ── HP / HYPOTHECATION (5 services) ──────────────────────────────
            ['HP / Hypothecation', 'Hypothecation Addition',                1500,  1, 10, 50, 30, 'Add bank/NBFC endorsement to RC when taking a new vehicle loan.'],
            ['HP / Hypothecation', 'Hypothecation Termination / Removal',   1200,  1,  7, 50, 31, 'Remove bank endorsement from RC after loan closure. Bank NOC (Form 35) required.'],
            ['HP / Hypothecation', 'Hypothecation Continuation',            1500,  1, 10, 50, 32, 'Extend, refinance, or change bank for existing hypothecation endorsement.'],
            ['HP / Hypothecation', 'RC Release after HP Removal',            800,  1,  7, 50, 33, 'Get fresh RC copy with HP endorsement removed after loan closure.'],
            ['HP / Hypothecation', 'Hypothecation Transfer',                2000,  1, 14, 45, 34, 'Transfer HP to new owner when vehicle is sold before loan closure.'],

            // ── NOC (4 types) ─────────────────────────────────────────────────
            ['NOC', 'Inter-state NOC',                                      1800,  1, 14, 45, 40, 'NOC from current RTO for re-registering vehicle in another state.'],
            ['NOC', 'Within-state Transfer NOC',                             900,  1,  7, 50, 41, 'NOC for vehicle transfer within same state but different RTO office.'],
            ['NOC', 'Hypothecation Removal NOC',                            1000,  1,  7, 50, 42, 'Bank NOC for removing hypothecation from RC. Coordination with financer required.'],
            ['NOC', 'Export / Out-of-Country NOC',                          3500,  1, 21, 40, 43, 'NOC for exporting vehicle out of India. Includes customs and RTO clearance.'],

            // ── VEHICLE SERVICES (16 services) ───────────────────────────────
            ['Vehicle Services', 'New Vehicle Registration',                 1500,  1, 15, 50, 50, 'Fresh registration of a new vehicle. Includes Form 20, insurance verification, and RC issuance.'],
            ['Vehicle Services', 'RC Ownership Transfer',                   2500,  1, 15, 45, 51, 'Transfer vehicle RC from current to new owner.'],
            ['Vehicle Services', 'RC Renewal (After 15 Years)',             2000,  1, 14, 50, 52, 'Renew RC for vehicles over 15 years old. Includes fitness test coordination.'],
            ['Vehicle Services', 'Duplicate RC',                            1100,  1,  7, 50, 53, 'Duplicate RC for lost/damaged Registration Certificate.'],
            ['Vehicle Services', 'Address Change / Correction in RC',       1000,  1, 10, 50, 54, 'Update address or correct details on RC.'],
            ['Vehicle Services', 'Re-Registration (10–15 Years Old)',       1800,  1, 14, 50, 55, 'Re-registration for vehicles aged 10–15 years.'],
            ['Vehicle Services', 'Re-Assignment',                           1200,  1, 10, 50, 56, 'Re-assignment of vehicle registration number.'],
            ['Vehicle Services', 'Fitness Certificate',                     2000,  1, 10, 45, 57, 'Fresh fitness certificate for commercial vehicles. Includes inspection scheduling.'],
            ['Vehicle Services', 'Fitness Certificate Renewal',             1800,  1, 10, 45, 58, 'Renewal of expiring fitness certificate for commercial vehicles.'],
            ['Vehicle Services', 'Duplicate Fitness Certificate',            900,  1,  7, 50, 59, 'Duplicate fitness certificate for lost or damaged original.'],
            ['Vehicle Services', 'No Objection Certificate (NOC)',          1800,  1, 14, 45, 60, 'NOC for vehicle re-registration or inter-state transfer.'],
            ['Vehicle Services', 'Road Tax Payment',                         800,  0,  5, 55, 61, 'Road tax payment for new or renewed vehicle registration.'],
            ['Vehicle Services', 'Road Tax Refund',                         1000,  0, 14, 50, 62, 'Road tax refund for vehicles being re-registered in another state.'],
            ['Vehicle Services', 'Permit Services / National Permit',       4500,  1, 30, 40, 63, 'National permit for goods/passenger commercial vehicles operating across India.'],
            ['Vehicle Services', 'Vehicle Insurance',                        500,  0,  3, 55, 64, 'Assistance with vehicle insurance — new policy, renewal, or endorsement changes.'],
            ['Vehicle Services', 'Alteration / Conversion of Vehicle',      2800,  1, 21, 45, 65, 'RC endorsement for vehicle alteration (CNG/LPG conversion, body change, etc.).'],

            // ── COMMERCIAL VEHICLE (general) ──────────────────────────────────
            ['Commercial Vehicle', 'Commercial Vehicle Service',            2000,  1, 21, 45, 70, 'General commercial vehicle services including permits, fitness, and registration.'],

            // ── OTHER SERVICES ────────────────────────────────────────────────
            ['Other Services', 'RTO Consultation & Documentation',           500,  0,  5, 60, 80, 'Expert consultation and document preparation for any RTO-related matter.'],
        ];

        // BUG FIX (root cause of "only 1 option shows in the dropdown" report):
        // rto_services.slug is NOT NULL with a UNIQUE KEY (see both
        // database/migrations/2024_01_01_000001_create_core_tables.php and
        // app/Database/Schema.php), but this INSERT never listed a `slug`
        // column. On a non-strict MySQL/MariaDB server that silently
        // substitutes the empty-string implicit default for the missing
        // NOT NULL column, EVERY row in this list got slug=''. Only the very
        // first INSERT IGNORE could succeed — every later row (every other
        // category, and every other service beyond the first in each
        // category) collided with that same empty-string unique value and
        // was silently discarded, forever, because Bootstrap.php's
        // `$svcCount < 44` guard re-runs this seeder on every admin page
        // load and it failed the exact same way every single time. Now each
        // row gets a real, deterministic, unique slug derived from its own
        // name, so INSERT IGNORE only skips a row that has already been
        // seeded (the actual intended idempotency), not every row after the
        // first.
        $inserted = 0;
        foreach ($services as [$cat, $name, $price, $gst, $sla, $share, $order, $desc]) {
            $slug = sanitize_title($name);
            $result = $this->db->query($this->db->prepare(
                "INSERT IGNORE INTO {$this->p}rto_services
                 (name, slug, category, base_price, gst_applicable, sla_days, vendor_share,
                  is_active, display_order, description)
                 VALUES (%s, %s, %s, %d, %d, %d, %d, 1, %d, %s)",
                $name, $slug, $cat, $price, $gst, $sla, $share, $order, $desc
            ));
            if ($result) $inserted++;
        }

        // One-time repair for rows already broken by the bug above: any
        // existing row with an empty/duplicate-blocking slug gets a real one
        // backfilled via UPDATE (never INSERT, so no duplicate rows are
        // created for a service that's already present).
        $blank = $this->db->get_results(
            "SELECT id, name FROM {$this->p}rto_services WHERE slug = '' OR slug IS NULL",
            ARRAY_A
        ) ?: [];
        foreach ($blank as $row) {
            $newSlug = sanitize_title($row['name']) ?: ('service-' . $row['id']);
            $this->db->query($this->db->prepare(
                "UPDATE {$this->p}rto_services SET slug = %s WHERE id = %d",
                $newSlug, $row['id']
            ));
        }

        return "Services: {$inserted} inserted (of " . count($services) . " total)";
    }
}
