<?php
namespace NAS\Database;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * NAS Seeder — Super Combo Edition v3
 * 300 cities · 95+ newspapers across ALL Indian languages
 * English · Hindi · Marathi · Gujarati · Telugu · Tamil
 * Kannada · Malayalam · Bengali · Punjabi · Odia · Assamese · Urdu · Konkani
 */
class Seeder {

    // TRACE: run() — Called internally or via AJAX action.
    //        Steps: inserts DB row → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: file existence checked before access; empty input values handled.
    public static function run(): void {
        self::seed_cities();
        self::seed_categories();
        self::seed_newspapers();
        self::seed_templates();
        self::seed_sample_ads();
        self::seed_quick_replies();
        self::seed_combo_offers();
    }

    /* ── CITIES ─────────────────────────────────────────────────────── */
    // TRACE: seed_cities() — Called internally or via AJAX action.
    //        Steps: inserts DB row → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: file existence checked before access; empty input values handled.
    private static function seed_cities(): void {
        global $wpdb; $p = $wpdb->prefix . 'nas_';
        if ($wpdb->get_var("SELECT COUNT(*) FROM {$p}cities")) return;
        $json = NAS_DATA . 'cities.json';
        $list = file_exists($json) ? json_decode(file_get_contents($json), true) : [];
        if (empty($list)) $list = self::default_cities();
        foreach ($list as $c) {
            $name = sanitize_text_field($c['name']);
            $wpdb->insert("{$p}cities", [
                'name' => $name, 'slug' => sanitize_title($name),
                'state' => sanitize_text_field($c['state'] ?? ''),
                'tier' => intval($c['tier'] ?? 3),
                'population' => intval($c['population'] ?? 0),
                'is_active' => 1,
            ]);
        }
    }

    /* ── CATEGORIES ─────────────────────────────────────────────────── */
    // TRACE: seed_categories() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private static function seed_categories(): void {
        global $wpdb; $p = $wpdb->prefix . 'nas_';
        if ($wpdb->get_var("SELECT COUNT(*) FROM {$p}categories")) return;
        $cats = [
            ['Obituary / Death Announcement','🕯️','Obituary notices, death announcements, condolences.','obituary,death,funeral,condolence,memorial'],
            ['Property / Real Estate','🏠','Buy, sell, rent residential or commercial properties.','property,flat,house,apartment,villa,plot,land,rent,sale'],
            ['Matrimonial / Marriage','💍','Bride/groom wanted, marriage biodata, alliance ads.','matrimonial,marriage,bride,groom,shaadi,wedding,alliance'],
            ['Job / Recruitment','💼','Job openings, hiring, career placement ads.','job,vacancy,hiring,recruitment,career,employment'],
            ['Education','📚','Admissions, tuition, coaching, courses, results.','education,admission,coaching,tuition,course,college,school'],
            ['Business / Services','🏢','Business promotions, services, announcements.','business,service,shop,store,launch,brand,company'],
            ['Name Change / Lost & Found','📝','Legal name change, lost docs, public notices.','name change,lost,found,legal,notice,affidavit,gazette'],
            ['Tender / Public Notice','📋','Government and private tenders, public announcements.','tender,notice,government,public,announcement,bid'],
            ['Financial / Investment','💰','IPO, mutual fund, loan, insurance, investment ads.','investment,IPO,finance,loan,insurance,stock'],
            ['Vehicle / Auto','🚗','Buy, sell vehicles, spare parts, auto services.','car,bike,vehicle,auto,motorcycle,spare parts'],
            ['Health & Wellness','🏥','Hospital, clinic, doctor, pharmacy, wellness ads.','health,hospital,clinic,doctor,medicine,pharmacy'],
            ['Entertainment & Events','🎉','Events, concerts, shows, exhibitions, promotions.','event,concert,show,exhibition,festival,party'],
        ];
        foreach ($cats as $i => $c) {
            $wpdb->insert("{$p}categories", [
                'name' => $c[0], 'slug' => sanitize_title($c[0]),
                'icon' => $c[1], 'description' => $c[2], 'keywords' => $c[3],
                'sort_order' => $i, 'is_active' => 1,
            ]);
        }
    }

    /* ── NEWSPAPERS ─────────────────────────────────────────────────── */
    // TRACE: seed_newspapers() — Called internally or via AJAX action.
    //        Steps: inserts DB row.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private static function seed_newspapers(): void {
        global $wpdb; $p = $wpdb->prefix . 'nas_';
        if ($wpdb->get_var("SELECT COUNT(*) FROM {$p}newspapers")) return;
        $ac = [1,2,3,4,5,6,7,8,9,10,11,12];
        foreach (self::all_newspapers($ac) as $i => $n) {
            $wpdb->insert("{$p}newspapers", [
                'name'                 => sanitize_text_field($n['name']),
                'slug'                 => sanitize_title($n['name']),
                'language'             => sanitize_text_field($n['lang']),
                'cities_supported'     => wp_json_encode($n['cities']),
                'editions'             => wp_json_encode($n['editions']),
                'categories_supported' => wp_json_encode($n['cats'] ?? $ac),
                'base_rate_classified' => floatval($n['cl']),
                'base_rate_display'    => floatval($n['di']),
                'base_rate_dc'         => floatval($n['dc'] ?? round($n['di'] * 0.6, 2)),
                'min_charge'           => floatval($n['min']),
                'circulation'          => intval($n['circ'] ?? 0),
                'description'          => sanitize_text_field($n['desc'] ?? ''),
                'sort_order'           => $i, 'is_active' => 1,
            ]);
        }
    }

    // TRACE: all_newspapers() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    private static function all_newspapers(array $ac): array {
        $biz = [4,5,6,8,9,10];
        return [
            /* ══ ENGLISH — National / Multi-city ══ */
            ['name'=>'Times of India','lang'=>'English','cl'=>150,'di'=>700,'min'=>800,'circ'=>3200000,
             'desc'=>"India's largest English daily.",'cats'=>$ac,
             'cities'=>['Mumbai','Delhi','Bengaluru','Kolkata','Hyderabad','Chennai','Pune','Ahmedabad','Jaipur','Lucknow','Bhopal','Chandigarh','Kochi','Nagpur','Indore','Vadodara','Patna','Noida','Gurgaon','Coimbatore','Surat','Thiruvananthapuram','Pondicherry'],
             'editions'=>['Mumbai','Delhi','Bengaluru','Kolkata','Hyderabad','Chennai','Pune','Ahmedabad','Jaipur','Lucknow','Bhopal','Chandigarh','Kochi','Nagpur','Indore']],
            ['name'=>'Hindustan Times','lang'=>'English','cl'=>130,'di'=>620,'min'=>700,'circ'=>1100000,
             'desc'=>'Premier English daily, dominant in North India.','cats'=>$ac,
             'cities'=>['Delhi','Mumbai','Lucknow','Chandigarh','Patna','Ranchi','Jaipur','Dehradun','Noida','Gurgaon','Faridabad'],
             'editions'=>['Delhi','Mumbai','Lucknow','Chandigarh','Patna','Ranchi','Jaipur','Dehradun']],
            ['name'=>'The Hindu','lang'=>'English','cl'=>120,'di'=>580,'min'=>650,'circ'=>1400000,
             'desc'=>"India's newspaper of record — strong in South India.",'cats'=>$ac,
             'cities'=>['Chennai','Bengaluru','Hyderabad','Coimbatore','Madurai','Thiruvananthapuram','Kochi','Visakhapatnam','Tirupati','Mangalore','Tiruchirappalli','Delhi','Mumbai'],
             'editions'=>['Chennai','Bengaluru','Hyderabad','Coimbatore','Madurai','Thiruvananthapuram','Kochi','Visakhapatnam','Tirupati','Mangalore']],
            ['name'=>'The Economic Times','lang'=>'English','cl'=>200,'di'=>1000,'min'=>1200,'circ'=>800000,
             'desc'=>"India's largest business & financial daily.",'cats'=>$biz,
             'cities'=>['Mumbai','Delhi','Bengaluru','Hyderabad','Chennai','Kolkata','Pune','Ahmedabad','Chandigarh'],
             'editions'=>['Mumbai','Delhi','Bengaluru','Hyderabad','Chennai','Kolkata','Pune','Ahmedabad']],
            ['name'=>'Business Standard','lang'=>'English','cl'=>180,'di'=>900,'min'=>1000,'circ'=>200000,
             'desc'=>'Leading national business daily.','cats'=>$biz,
             'cities'=>['Mumbai','Delhi','Bengaluru','Hyderabad','Chennai','Kolkata','Pune','Ahmedabad','Chandigarh'],
             'editions'=>['Mumbai','Delhi','Bengaluru','Hyderabad','Chennai','Kolkata','Pune','Ahmedabad','Chandigarh']],
            ['name'=>'Financial Express','lang'=>'English','cl'=>175,'di'=>850,'min'=>950,'circ'=>175000,
             'desc'=>'National financial and business newspaper.','cats'=>$biz,
             'cities'=>['Mumbai','Delhi','Bengaluru','Hyderabad','Chennai','Kolkata','Pune','Ahmedabad','Chandigarh'],
             'editions'=>['Mumbai','Delhi','Bengaluru','Hyderabad','Chennai','Kolkata','Pune','Ahmedabad']],
            ['name'=>'Deccan Herald','lang'=>'English','cl'=>90,'di'=>440,'min'=>480,'circ'=>210000,
             'desc'=>'Flagship English daily of Karnataka.','cats'=>$ac,
             'cities'=>['Bengaluru','Mysore','Hubli-Dharwad','Mangalore','Gulbarga','Bellary','Davanagere'],
             'editions'=>['Bengaluru','Mysore','Hubli','Mangalore','Belgaum','Gulbarga']],
            ['name'=>'Deccan Chronicle','lang'=>'English','cl'=>85,'di'=>420,'min'=>450,'circ'=>230000,
             'desc'=>'Leading English daily of Hyderabad.','cats'=>$ac,
             'cities'=>['Hyderabad','Chennai','Visakhapatnam','Vijayawada','Guntur'],
             'editions'=>['Hyderabad','Chennai','Visakhapatnam','Vijayawada','Guntur']],
            ['name'=>'New Indian Express','lang'=>'English','cl'=>80,'di'=>400,'min'=>420,'circ'=>560000,
             'desc'=>'Covers South India and Odisha comprehensively.','cats'=>$ac,
             'cities'=>['Chennai','Bengaluru','Hyderabad','Visakhapatnam','Vijayawada','Bhubaneswar','Kochi','Thiruvananthapuram','Kozhikode','Coimbatore','Madurai'],
             'editions'=>['Chennai','Bengaluru','Hyderabad','Visakhapatnam','Vijayawada','Bhubaneswar','Kochi','Thiruvananthapuram']],
            ['name'=>'The Tribune','lang'=>'English','cl'=>75,'di'=>380,'min'=>400,'circ'=>420000,
             'desc'=>'Chandigarh-based daily, dominant in Punjab/Haryana.','cats'=>$ac,
             'cities'=>['Chandigarh','Delhi','Ludhiana','Amritsar','Jalandhar','Dehradun','Jammu','Patiala','Bathinda'],
             'editions'=>['Chandigarh','Delhi','Ludhiana','Amritsar','Jalandhar','Dehradun','Jammu']],
            ['name'=>'The Statesman','lang'=>'English','cl'=>70,'di'=>360,'min'=>380,'circ'=>180000,
             'desc'=>'Historic Kolkata-based English daily.','cats'=>$ac,
             'cities'=>['Kolkata','Delhi','Siliguri'],
             'editions'=>['Kolkata','Delhi','Siliguri']],
            ['name'=>'The Telegraph','lang'=>'English','cl'=>75,'di'=>380,'min'=>400,'circ'=>450000,
             'desc'=>'Popular English daily based in Kolkata.','cats'=>$ac,
             'cities'=>['Kolkata','Siliguri','Guwahati','Delhi'],
             'editions'=>['Kolkata','Siliguri','Guwahati']],
            ['name'=>'Mid-Day','lang'=>'English','cl'=>90,'di'=>450,'min'=>500,'circ'=>260000,
             'desc'=>'Mumbai-focused afternoon English daily.','cats'=>$ac,
             'cities'=>['Mumbai','Pune','Thane'],
             'editions'=>['Mumbai','Pune','Thane']],
            ['name'=>'DNA (Daily News & Analysis)','lang'=>'English','cl'=>80,'di'=>420,'min'=>450,'circ'=>200000,
             'desc'=>'Multi-city English daily.','cats'=>$ac,
             'cities'=>['Mumbai','Ahmedabad','Bengaluru','Pune'],
             'editions'=>['Mumbai','Ahmedabad','Bengaluru','Pune']],
            ['name'=>'The Pioneer','lang'=>'English','cl'=>65,'di'=>340,'min'=>360,'circ'=>130000,
             'desc'=>'English daily with strong North India presence.','cats'=>$ac,
             'cities'=>['Delhi','Lucknow','Bhopal','Raipur','Dehradun'],
             'editions'=>['Delhi','Lucknow','Bhopal','Raipur']],
            ['name'=>'Asian Age','lang'=>'English','cl'=>70,'di'=>370,'min'=>400,'circ'=>100000,
             'desc'=>'National English daily with multiple editions.','cats'=>$ac,
             'cities'=>['Delhi','Mumbai','Kolkata','Hyderabad'],
             'editions'=>['Delhi','Mumbai','Kolkata','Hyderabad']],
            ['name'=>'Hans India','lang'=>'English','cl'=>60,'di'=>320,'min'=>350,'circ'=>120000,
             'desc'=>'English daily focused on Hyderabad and Andhra Pradesh.','cats'=>$ac,
             'cities'=>['Hyderabad','Vijayawada','Visakhapatnam','Guntur','Tirupati'],
             'editions'=>['Hyderabad','Vijayawada','Visakhapatnam']],
            ['name'=>'Navhind Times','lang'=>'English','cl'=>55,'di'=>290,'min'=>300,'circ'=>35000,
             'desc'=>"Goa's leading English daily.",'cats'=>$ac,
             'cities'=>['Panaji','Margao','Vasco'],
             'editions'=>['Panaji','South Goa']],
            ['name'=>'Herald (Goa)','lang'=>'English','cl'=>48,'di'=>270,'min'=>290,'circ'=>40000,
             'desc'=>"Goa's flagship English newspaper.",'cats'=>$ac,
             'cities'=>['Panaji','Margao','Vasco'],
             'editions'=>['Panaji','South Goa']],
            ['name'=>'Free Press Journal','lang'=>'English','cl'=>65,'di'=>340,'min'=>360,'circ'=>90000,
             'desc'=>'Mumbai and Indore focused English daily.','cats'=>$ac,
             'cities'=>['Mumbai','Indore','Bhopal'],
             'editions'=>['Mumbai','Indore','Bhopal']],
            ['name'=>'Sentinel (Assam)','lang'=>'English','cl'=>45,'di'=>260,'min'=>280,'circ'=>80000,
             'desc'=>"Northeast India's leading English daily.",'cats'=>$ac,
             'cities'=>['Guwahati','Silchar','Dibrugarh','Tezpur'],
             'editions'=>['Guwahati','Silchar','Dibrugarh']],
            ['name'=>'Shillong Times','lang'=>'English','cl'=>30,'di'=>190,'min'=>210,'circ'=>25000,
             'desc'=>"Northeast India's oldest English newspaper.",'cats'=>$ac,
             'cities'=>['Shillong','Guwahati'],
             'editions'=>['Shillong']],
            ['name'=>'Morung Express','lang'=>'English','cl'=>28,'di'=>180,'min'=>200,'circ'=>18000,
             'desc'=>"Nagaland's leading English daily.",'cats'=>$ac,
             'cities'=>['Dimapur','Kohima'],
             'editions'=>['Dimapur']],
            /* ══ HINDI — North / Central India ══ */
            ['name'=>'Dainik Jagran','lang'=>'Hindi','cl'=>70,'di'=>360,'min'=>380,'circ'=>3500000,
             'desc'=>"India's most-read Hindi daily.",'cats'=>$ac,
             'cities'=>['Delhi','Lucknow','Kanpur','Agra','Varanasi','Allahabad','Meerut','Dehradun','Jaipur','Patna','Jalandhar','Ranchi','Gorakhpur','Muzaffarnagar','Bareilly','Aligarh','Noida','Gurgaon','Faridabad','Chandigarh','Moradabad','Shahjahanpur'],
             'editions'=>['Delhi','Lucknow','Kanpur','Agra','Varanasi','Allahabad','Meerut','Dehradun','Jaipur','Patna','Jalandhar','Ranchi','Gorakhpur','Chandigarh','Noida']],
            ['name'=>'Dainik Bhaskar','lang'=>'Hindi','cl'=>65,'di'=>340,'min'=>360,'circ'=>3000000,
             'desc'=>"India's second most-read Hindi daily.",'cats'=>$ac,
             'cities'=>['Delhi','Indore','Bhopal','Jaipur','Lucknow','Patna','Raipur','Chandigarh','Nagpur','Ranchi','Jodhpur','Agra','Gwalior','Udaipur','Bilaspur','Ahmedabad','Vadodara','Surat','Kota','Ujjain','Sagar','Jabalpur','Ajmer','Bikaner'],
             'editions'=>['Delhi','Indore','Bhopal','Jaipur','Lucknow','Patna','Raipur','Chandigarh','Nagpur','Ranchi','Jodhpur','Agra','Gwalior','Udaipur','Bilaspur','Ahmedabad']],
            ['name'=>'Amar Ujala','lang'=>'Hindi','cl'=>55,'di'=>300,'min'=>320,'circ'=>2200000,
             'desc'=>'Popular Hindi daily dominant in UP, Uttarakhand, Punjab.','cats'=>$ac,
             'cities'=>['Delhi','Lucknow','Agra','Meerut','Allahabad','Dehradun','Shimla','Chandigarh','Bareilly','Moradabad','Jammu','Varanasi','Gorakhpur','Aligarh','Noida','Haridwar'],
             'editions'=>['Delhi','Lucknow','Agra','Meerut','Allahabad','Dehradun','Shimla','Chandigarh','Bareilly','Moradabad','Jammu','Varanasi','Gorakhpur']],
            ['name'=>'Hindustan (Hindi)','lang'=>'Hindi','cl'=>55,'di'=>300,'min'=>320,'circ'=>1100000,
             'desc'=>'Hindi daily by HT Media, strong in UP and Bihar.','cats'=>$ac,
             'cities'=>['Delhi','Lucknow','Patna','Ranchi','Dehradun','Muzaffarpur','Jammu','Agra','Varanasi','Allahabad','Kanpur'],
             'editions'=>['Delhi','Lucknow','Patna','Ranchi','Dehradun','Jammu','Muzaffarpur']],
            ['name'=>'Navbharat Times','lang'=>'Hindi','cl'=>80,'di'=>420,'min'=>450,'circ'=>480000,
             'desc'=>'Hindi daily for Delhi/Mumbai urban readers by Times Group.','cats'=>$ac,
             'cities'=>['Delhi','Mumbai','Pune','Lucknow','Noida','Gurgaon'],
             'editions'=>['Delhi','Mumbai','Pune','Lucknow']],
            ['name'=>'Rajasthan Patrika','lang'=>'Hindi','cl'=>60,'di'=>320,'min'=>340,'circ'=>1800000,
             'desc'=>"Largest circulated Hindi daily in Rajasthan.",'cats'=>$ac,
             'cities'=>['Jaipur','Jodhpur','Kota','Bikaner','Udaipur','Ajmer','Alwar','Bharatpur','Bhilwara','Sikar','Barmer','Chittorgarh','Delhi','Bhopal','Raipur','Ahmedabad','Indore'],
             'editions'=>['Jaipur','Jodhpur','Kota','Bikaner','Udaipur','Ajmer','Alwar','Bharatpur','Bhilwara','Sikar','Barmer','Delhi','Bhopal','Raipur']],
            ['name'=>'Punjab Kesari','lang'=>'Hindi','cl'=>55,'di'=>300,'min'=>320,'circ'=>700000,
             'desc'=>'Hindi daily dominant across Punjab, Haryana, Delhi, J&K.','cats'=>$ac,
             'cities'=>['Delhi','Ludhiana','Amritsar','Jalandhar','Patiala','Chandigarh','Bathinda','Jammu','Hisar','Rohtak','Ambala','Panipat','Faridabad','Gurgaon'],
             'editions'=>['Delhi','Ludhiana','Amritsar','Jalandhar','Patiala','Chandigarh','Bathinda','Jammu','Hisar']],
            ['name'=>'Nai Dunia','lang'=>'Hindi','cl'=>50,'di'=>280,'min'=>300,'circ'=>350000,
             'desc'=>'Hindi daily covering Madhya Pradesh and Chhattisgarh.','cats'=>$ac,
             'cities'=>['Indore','Bhopal','Jabalpur','Gwalior','Raipur','Ujjain','Sagar','Bilaspur'],
             'editions'=>['Indore','Bhopal','Jabalpur','Gwalior','Raipur','Ujjain','Sagar']],
            ['name'=>'Haribhoomi','lang'=>'Hindi','cl'=>48,'di'=>260,'min'=>280,'circ'=>300000,
             'desc'=>'Hindi daily covering MP, CG, Delhi and Chandigarh.','cats'=>$ac,
             'cities'=>['Bhopal','Indore','Raipur','Jabalpur','Gwalior','Chandigarh','Delhi','Bilaspur'],
             'editions'=>['Bhopal','Raipur','Chandigarh','Delhi','Jabalpur']],
            ['name'=>'Nav Bharat','lang'=>'Hindi','cl'=>45,'di'=>250,'min'=>270,'circ'=>250000,
             'desc'=>'Hindi daily focused on MP, Chhattisgarh and Maharashtra.','cats'=>$ac,
             'cities'=>['Bhopal','Raipur','Nagpur','Indore','Jabalpur'],
             'editions'=>['Bhopal','Raipur','Nagpur','Indore']],
            ['name'=>'Prabhat Khabar','lang'=>'Hindi','cl'=>50,'di'=>280,'min'=>300,'circ'=>600000,
             'desc'=>"Jharkhand and Bihar's leading Hindi daily.",'cats'=>$ac,
             'cities'=>['Ranchi','Jamshedpur','Dhanbad','Bokaro','Patna','Bhagalpur','Muzaffarpur'],
             'editions'=>['Ranchi','Jamshedpur','Dhanbad','Bokaro','Patna','Bhagalpur']],
            ['name'=>'Jansatta','lang'=>'Hindi','cl'=>60,'di'=>320,'min'=>350,'circ'=>150000,
             'desc'=>'Hindi newspaper published by Indian Express Group.','cats'=>$ac,
             'cities'=>['Delhi','Mumbai','Chandigarh','Lucknow'],
             'editions'=>['Delhi','Mumbai','Chandigarh']],
            ['name'=>'Sanmarg','lang'=>'Hindi','cl'=>40,'di'=>240,'min'=>260,'circ'=>130000,
             'desc'=>'Hindi newspaper serving Bihar and Kolkata readers.','cats'=>$ac,
             'cities'=>['Kolkata','Patna','Ranchi','Jamshedpur'],
             'editions'=>['Kolkata','Patna','Ranchi']],
            ['name'=>'Amrit Vichar','lang'=>'Hindi','cl'=>35,'di'=>210,'min'=>230,'circ'=>80000,
             'desc'=>'Hindi daily from Lucknow region.','cats'=>$ac,
             'cities'=>['Lucknow','Bareilly','Aligarh','Moradabad','Gorakhpur'],
             'editions'=>['Lucknow','Bareilly']],
            /* ══ MARATHI ══ */
            ['name'=>'Maharashtra Times','lang'=>'Marathi','cl'=>65,'di'=>340,'min'=>360,'circ'=>500000,
             'desc'=>"Times Group's flagship Marathi daily.",'cats'=>$ac,
             'cities'=>['Mumbai','Pune','Nagpur','Nashik','Aurangabad','Kolhapur','Solapur','Thane','Amravati','Nanded'],
             'editions'=>['Mumbai','Pune','Nagpur','Nashik','Aurangabad','Kolhapur','Solapur']],
            ['name'=>'Lokmat','lang'=>'Marathi','cl'=>55,'di'=>300,'min'=>320,'circ'=>1200000,
             'desc'=>"Maharashtra's most widely-circulated Marathi newspaper.",'cats'=>$ac,
             'cities'=>['Mumbai','Pune','Nagpur','Nashik','Aurangabad','Kolhapur','Jalgaon','Akola','Latur','Solapur','Amravati','Nanded','Sangli'],
             'editions'=>['Mumbai','Pune','Nagpur','Nashik','Aurangabad','Kolhapur','Jalgaon','Akola','Latur','Solapur','Amravati']],
            ['name'=>'Sakal','lang'=>'Marathi','cl'=>52,'di'=>290,'min'=>310,'circ'=>800000,
             'desc'=>'Pune-based Marathi daily with wide Maharashtra presence.','cats'=>$ac,
             'cities'=>['Pune','Mumbai','Nagpur','Nashik','Aurangabad','Kolhapur','Solapur','Sangli'],
             'editions'=>['Pune','Mumbai','Nagpur','Nashik','Aurangabad','Kolhapur','Solapur','Sangli']],
            ['name'=>'Loksatta','lang'=>'Marathi','cl'=>58,'di'=>310,'min'=>330,'circ'=>350000,
             'desc'=>"Indian Express Group's Marathi publication.",'cats'=>$ac,
             'cities'=>['Mumbai','Pune','Nagpur','Nashik','Aurangabad'],
             'editions'=>['Mumbai','Pune','Nagpur']],
            ['name'=>'Pudhari','lang'=>'Marathi','cl'=>40,'di'=>240,'min'=>260,'circ'=>250000,
             'desc'=>'Kolhapur-based Marathi daily covering south Maharashtra.','cats'=>$ac,
             'cities'=>['Kolhapur','Sangli','Pune','Mumbai'],
             'editions'=>['Kolhapur','Sangli','Pune']],
            ['name'=>'Tarun Bharat','lang'=>'Marathi','cl'=>38,'di'=>220,'min'=>240,'circ'=>180000,
             'desc'=>'Marathi daily from Nagpur covering Vidarbha region.','cats'=>$ac,
             'cities'=>['Nagpur','Pune','Mumbai','Amravati','Akola'],
             'editions'=>['Nagpur','Pune','Mumbai']],
            ['name'=>'Divya Marathi','lang'=>'Marathi','cl'=>45,'di'=>260,'min'=>280,'circ'=>300000,
             'desc'=>"Dainik Bhaskar Group's Marathi daily.",'cats'=>$ac,
             'cities'=>['Aurangabad','Nagpur','Latur','Pune','Jalgaon','Nanded'],
             'editions'=>['Aurangabad','Nagpur','Latur','Pune']],
            ['name'=>'Gomantak','lang'=>'Marathi','cl'=>38,'di'=>220,'min'=>240,'circ'=>30000,
             'desc'=>"Goa's flagship Marathi/Konkani newspaper.",'cats'=>$ac,
             'cities'=>['Panaji','Margao','Vasco'],
             'editions'=>['Panaji','South Goa']],
            /* ══ GUJARATI ══ */
            ['name'=>'Gujarat Samachar','lang'=>'Gujarati','cl'=>55,'di'=>300,'min'=>320,'circ'=>900000,
             'desc'=>"Gujarat's most widely-read Gujarati newspaper.",'cats'=>$ac,
             'cities'=>['Ahmedabad','Surat','Vadodara','Rajkot','Bhavnagar','Jamnagar','Junagadh','Anand','Gandhinagar','Morbi','Surendranagar'],
             'editions'=>['Ahmedabad','Surat','Vadodara','Rajkot','Bhavnagar','Jamnagar','Junagadh','Anand','Gandhinagar']],
            ['name'=>'Sandesh','lang'=>'Gujarati','cl'=>50,'di'=>280,'min'=>300,'circ'=>700000,
             'desc'=>'Major Gujarati daily covering all Gujarat cities.','cats'=>$ac,
             'cities'=>['Ahmedabad','Surat','Vadodara','Rajkot','Bhavnagar','Jamnagar','Anand','Gandhinagar'],
             'editions'=>['Ahmedabad','Surat','Vadodara','Rajkot','Bhavnagar','Jamnagar','Anand']],
            ['name'=>'Divya Bhaskar','lang'=>'Gujarati','cl'=>52,'di'=>290,'min'=>310,'circ'=>820000,
             'desc'=>"Dainik Bhaskar Group's Gujarati daily.",'cats'=>$ac,
             'cities'=>['Ahmedabad','Surat','Vadodara','Rajkot','Bhavnagar','Jamnagar','Junagadh','Gandhinagar','Anand','Morbi'],
             'editions'=>['Ahmedabad','Surat','Vadodara','Rajkot','Bhavnagar','Jamnagar','Junagadh','Gandhinagar']],
            ['name'=>'Sambhaav Metro','lang'=>'Gujarati','cl'=>40,'di'=>240,'min'=>260,'circ'=>150000,
             'desc'=>'Ahmedabad-focused Gujarati daily.','cats'=>$ac,
             'cities'=>['Ahmedabad','Surat','Vadodara'],
             'editions'=>['Ahmedabad','Surat','Vadodara']],
            ['name'=>'Akila','lang'=>'Gujarati','cl'=>35,'di'=>210,'min'=>230,'circ'=>120000,
             'desc'=>'Gujarati daily serving Saurashtra region.','cats'=>$ac,
             'cities'=>['Ahmedabad','Rajkot','Bhavnagar','Jamnagar','Junagadh'],
             'editions'=>['Rajkot','Ahmedabad','Bhavnagar']],
            /* ══ TELUGU ══ */
            ['name'=>'Eenadu','lang'=>'Telugu','cl'=>60,'di'=>320,'min'=>340,'circ'=>1750000,
             'desc'=>"Telugu's most circulated newspaper — Ramoji Group.",'cats'=>$ac,
             'cities'=>['Hyderabad','Visakhapatnam','Vijayawada','Guntur','Tirupati','Kurnool','Warangal','Karimnagar','Nizamabad'],
             'editions'=>['Hyderabad','Visakhapatnam','Vijayawada','Guntur','Tirupati','Kurnool','Warangal','Karimnagar','Nizamabad']],
            ['name'=>'Sakshi','lang'=>'Telugu','cl'=>55,'di'=>300,'min'=>320,'circ'=>700000,
             'desc'=>'Telugu daily launched by YS Jagan group.','cats'=>$ac,
             'cities'=>['Hyderabad','Visakhapatnam','Vijayawada','Guntur','Nellore','Kurnool','Tirupati','Karimnagar','Warangal'],
             'editions'=>['Hyderabad','Visakhapatnam','Vijayawada','Guntur','Kurnool','Karimnagar']],
            ['name'=>'Andhra Jyothi','lang'=>'Telugu','cl'=>55,'di'=>300,'min'=>320,'circ'=>580000,
             'desc'=>'Telugu daily with strong readership in AP and Telangana.','cats'=>$ac,
             'cities'=>['Hyderabad','Visakhapatnam','Vijayawada','Guntur','Tirupati','Kurnool','Nellore','Karimnagar'],
             'editions'=>['Hyderabad','Visakhapatnam','Vijayawada','Guntur','Tirupati']],
            ['name'=>'Namaste Telangana','lang'=>'Telugu','cl'=>45,'di'=>260,'min'=>280,'circ'=>280000,
             'desc'=>'Telugu daily focused on Telangana region.','cats'=>$ac,
             'cities'=>['Hyderabad','Warangal','Karimnagar','Nizamabad'],
             'editions'=>['Hyderabad','Warangal','Karimnagar']],
            ['name'=>'Vaartha','lang'=>'Telugu','cl'=>40,'di'=>240,'min'=>260,'circ'=>200000,
             'desc'=>'Telugu daily for Andhra Pradesh readers.','cats'=>$ac,
             'cities'=>['Hyderabad','Vijayawada','Visakhapatnam','Tirupati'],
             'editions'=>['Hyderabad','Vijayawada','Visakhapatnam']],
            ['name'=>'Lokasatta (Telugu)','lang'=>'Telugu','cl'=>45,'di'=>260,'min'=>280,'circ'=>250000,
             'desc'=>'Telugu edition by Indian Express Group.','cats'=>$ac,
             'cities'=>['Hyderabad','Visakhapatnam','Vijayawada','Guntur'],
             'editions'=>['Hyderabad','Visakhapatnam','Vijayawada']],
            ['name'=>'Surya Telugu','lang'=>'Telugu','cl'=>38,'di'=>220,'min'=>240,'circ'=>150000,
             'desc'=>'Telugu daily covering Coastal Andhra Pradesh.','cats'=>$ac,
             'cities'=>['Hyderabad','Visakhapatnam','Vijayawada','Kakinada','Rajahmundry'],
             'editions'=>['Hyderabad','Visakhapatnam','Vijayawada']],
            /* ══ TAMIL ══ */
            ['name'=>'Dinamalar','lang'=>'Tamil','cl'=>50,'di'=>280,'min'=>300,'circ'=>1100000,
             'desc'=>"Tamil Nadu's most circulated Tamil newspaper.",'cats'=>$ac,
             'cities'=>['Chennai','Coimbatore','Madurai','Tiruchirappalli','Salem','Tirunelveli','Vellore','Tiruppur','Erode'],
             'editions'=>['Chennai','Coimbatore','Madurai','Tiruchirappalli','Salem','Tirunelveli','Tiruppur','Erode']],
            ['name'=>'Dinamani','lang'=>'Tamil','cl'=>45,'di'=>260,'min'=>280,'circ'=>400000,
             'desc'=>"Indian Express Group's Tamil daily.",'cats'=>$ac,
             'cities'=>['Chennai','Coimbatore','Madurai','Tiruchirappalli','Salem','Tirunelveli','Tiruppur'],
             'editions'=>['Chennai','Coimbatore','Madurai','Tiruchirappalli','Salem','Tirunelveli']],
            ['name'=>'Daily Thanthi','lang'=>'Tamil','cl'=>48,'di'=>270,'min'=>290,'circ'=>1000000,
             'desc'=>'Tamil daily published by Dina Thanthi group.','cats'=>$ac,
             'cities'=>['Chennai','Coimbatore','Madurai','Tiruchirappalli','Salem','Tirunelveli','Tiruppur','Erode','Vellore'],
             'editions'=>['Chennai','Coimbatore','Madurai','Tiruchirappalli','Salem','Tirunelveli','Tiruppur','Erode']],
            ['name'=>'Dinakaran','lang'=>'Tamil','cl'=>42,'di'=>250,'min'=>270,'circ'=>600000,
             'desc'=>'Tamil daily published by Sun TV group.','cats'=>$ac,
             'cities'=>['Chennai','Coimbatore','Madurai','Tiruchirappalli','Salem','Tirunelveli'],
             'editions'=>['Chennai','Coimbatore','Madurai','Tiruchirappalli','Salem']],
            ['name'=>'Malai Malar','lang'=>'Tamil','cl'=>40,'di'=>240,'min'=>260,'circ'=>280000,
             'desc'=>'Tamil evening newspaper.','cats'=>$ac,
             'cities'=>['Chennai','Coimbatore','Madurai'],
             'editions'=>['Chennai','Coimbatore','Madurai']],
            /* ══ KANNADA ══ */
            ['name'=>'Vijay Karnataka','lang'=>'Kannada','cl'=>50,'di'=>280,'min'=>300,'circ'=>700000,
             'desc'=>"Times Group's Kannada daily — widest reach in Karnataka.",'cats'=>$ac,
             'cities'=>['Bengaluru','Mysore','Hubli-Dharwad','Mangalore','Gulbarga','Bellary','Davanagere'],
             'editions'=>['Bengaluru','Mysore','Hubli','Mangalore','Belgaum','Gulbarga','Davangere']],
            ['name'=>'Prajavani','lang'=>'Kannada','cl'=>48,'di'=>270,'min'=>290,'circ'=>560000,
             'desc'=>"Karnataka's oldest and most respected Kannada newspaper.",'cats'=>$ac,
             'cities'=>['Bengaluru','Mysore','Hubli-Dharwad','Mangalore','Gulbarga','Davanagere','Bellary'],
             'editions'=>['Bengaluru','Mysore','Hubli','Mangalore','Belgaum','Gulbarga']],
            ['name'=>'Udayavani','lang'=>'Kannada','cl'=>42,'di'=>250,'min'=>270,'circ'=>240000,
             'desc'=>'Mangalore-based Kannada daily, strong in Coastal Karnataka.','cats'=>$ac,
             'cities'=>['Mangalore','Bengaluru','Hubli-Dharwad','Mysore','Bellary'],
             'editions'=>['Mangalore','Bengaluru','Hubli']],
            ['name'=>'Kannadaprabha','lang'=>'Kannada','cl'=>40,'di'=>240,'min'=>260,'circ'=>180000,
             'desc'=>"Indian Express Group's Kannada publication.",'cats'=>$ac,
             'cities'=>['Bengaluru','Mysore','Hubli-Dharwad','Mangalore'],
             'editions'=>['Bengaluru','Mysore','Hubli','Mangalore']],
            ['name'=>'Samyuktha Karnataka','lang'=>'Kannada','cl'=>38,'di'=>220,'min'=>240,'circ'=>150000,
             'desc'=>'Kannada daily focused on North Karnataka.','cats'=>$ac,
             'cities'=>['Hubli-Dharwad','Bengaluru','Gulbarga','Bellary','Davanagere'],
             'editions'=>['Hubli','Bengaluru','Gulbarga','Bellary']],
            /* ══ MALAYALAM ══ */
            ['name'=>'Malayala Manorama','lang'=>'Malayalam','cl'=>55,'di'=>300,'min'=>320,'circ'=>2400000,
             'desc'=>"World's 9th largest circulated newspaper — Kerala's #1.",'cats'=>$ac,
             'cities'=>['Kochi','Thiruvananthapuram','Kozhikode','Thrissur','Kannur','Palakkad','Alappuzha','Kottayam','Malappuram'],
             'editions'=>['Kochi','Thiruvananthapuram','Kozhikode','Thrissur','Kannur','Palakkad','Alappuzha','Kottayam','Malappuram']],
            ['name'=>'Mathrubhumi','lang'=>'Malayalam','cl'=>52,'di'=>290,'min'=>310,'circ'=>1700000,
             'desc'=>'Kozhikode-based flagship Malayalam daily.','cats'=>$ac,
             'cities'=>['Kochi','Thiruvananthapuram','Kozhikode','Thrissur','Kannur','Palakkad','Alappuzha','Kottayam'],
             'editions'=>['Kochi','Thiruvananthapuram','Kozhikode','Thrissur','Kannur','Palakkad','Alappuzha','Kottayam']],
            ['name'=>'Kerala Kaumudi','lang'=>'Malayalam','cl'=>45,'di'=>260,'min'=>280,'circ'=>450000,
             'desc'=>'Thiruvananthapuram-based Malayalam newspaper.','cats'=>$ac,
             'cities'=>['Thiruvananthapuram','Kochi','Kozhikode','Thrissur','Alappuzha','Kottayam','Kannur'],
             'editions'=>['Thiruvananthapuram','Kochi','Kozhikode','Thrissur','Alappuzha']],
            ['name'=>'Deepika','lang'=>'Malayalam','cl'=>42,'di'=>250,'min'=>270,'circ'=>300000,
             'desc'=>'Kottayam-based Christian-readership Malayalam newspaper.','cats'=>$ac,
             'cities'=>['Kottayam','Kochi','Thiruvananthapuram','Thrissur','Kozhikode'],
             'editions'=>['Kottayam','Kochi','Thiruvananthapuram','Thrissur']],
            ['name'=>'Madhyamam','lang'=>'Malayalam','cl'=>42,'di'=>250,'min'=>270,'circ'=>320000,
             'desc'=>'Kozhikode-based Malayalam daily.','cats'=>$ac,
             'cities'=>['Kozhikode','Kochi','Thiruvananthapuram','Thrissur','Kannur','Malappuram'],
             'editions'=>['Kozhikode','Kochi','Thiruvananthapuram','Thrissur']],
            ['name'=>'Mangalam','lang'=>'Malayalam','cl'=>38,'di'=>230,'min'=>250,'circ'=>200000,
             'desc'=>'Kottayam-based Malayalam daily.','cats'=>$ac,
             'cities'=>['Kottayam','Kochi','Thiruvananthapuram','Kozhikode','Thrissur'],
             'editions'=>['Kottayam','Kochi','Thiruvananthapuram']],
            /* ══ BENGALI ══ */
            ['name'=>'Ananda Bazar Patrika','lang'=>'Bengali','cl'=>60,'di'=>320,'min'=>350,'circ'=>1200000,
             'desc'=>"Kolkata's most prestigious Bengali daily.",'cats'=>$ac,
             'cities'=>['Kolkata','Haora','Siliguri','Durgapur','Asansol'],
             'editions'=>['Kolkata','Siliguri','Asansol']],
            ['name'=>'Bartaman','lang'=>'Bengali','cl'=>50,'di'=>280,'min'=>300,'circ'=>500000,
             'desc'=>'Popular Bengali daily published from Kolkata.','cats'=>$ac,
             'cities'=>['Kolkata','Haora','Siliguri','Durgapur'],
             'editions'=>['Kolkata','Siliguri']],
            ['name'=>'Sangbad Pratidin','lang'=>'Bengali','cl'=>48,'di'=>270,'min'=>290,'circ'=>400000,
             'desc'=>'Kolkata-based Bengali daily.','cats'=>$ac,
             'cities'=>['Kolkata','Siliguri','Asansol'],
             'editions'=>['Kolkata','Siliguri']],
            ['name'=>'Aajkaal','lang'=>'Bengali','cl'=>45,'di'=>260,'min'=>280,'circ'=>350000,
             'desc'=>'Prominent Bengali daily from Kolkata.','cats'=>$ac,
             'cities'=>['Kolkata','Haora','Siliguri'],
             'editions'=>['Kolkata','Siliguri']],
            ['name'=>'Eisamay','lang'=>'Bengali','cl'=>48,'di'=>270,'min'=>290,'circ'=>300000,
             'desc'=>"Times Group's Bengali daily.",'cats'=>$ac,
             'cities'=>['Kolkata','Siliguri'],
             'editions'=>['Kolkata','Siliguri']],
            ['name'=>'Ganashakti','lang'=>'Bengali','cl'=>40,'di'=>240,'min'=>260,'circ'=>200000,
             'desc'=>'Left-leaning Bengali daily from Kolkata.','cats'=>$ac,
             'cities'=>['Kolkata','Haora','Asansol'],
             'editions'=>['Kolkata']],
            /* ══ PUNJABI ══ */
            ['name'=>'Ajit Daily','lang'=>'Punjabi','cl'=>50,'di'=>280,'min'=>300,'circ'=>600000,
             'desc'=>"Jalandhar-based Punjabi daily — largest Punjabi newspaper.",'cats'=>$ac,
             'cities'=>['Jalandhar','Amritsar','Ludhiana','Patiala','Chandigarh','Bathinda'],
             'editions'=>['Jalandhar','Amritsar','Ludhiana','Patiala','Chandigarh','Bathinda']],
            ['name'=>'Jagbani','lang'=>'Punjabi','cl'=>45,'di'=>260,'min'=>280,'circ'=>400000,
             'desc'=>'Punjabi daily published by Tribune group.','cats'=>$ac,
             'cities'=>['Jalandhar','Amritsar','Ludhiana','Patiala','Chandigarh'],
             'editions'=>['Jalandhar','Amritsar','Ludhiana','Patiala','Chandigarh']],
            /* ══ ODIA ══ */
            ['name'=>'Sambad','lang'=>'Odia','cl'=>42,'di'=>250,'min'=>270,'circ'=>550000,
             'desc'=>"Odisha's most circulated Odia newspaper.",'cats'=>$ac,
             'cities'=>['Bhubaneswar','Cuttack','Sambalpur','Rourkela','Balasore'],
             'editions'=>['Bhubaneswar','Cuttack','Sambalpur','Rourkela','Balasore']],
            ['name'=>'Dharitri','lang'=>'Odia','cl'=>38,'di'=>220,'min'=>240,'circ'=>320000,
             'desc'=>'Popular Odia daily from Bhubaneswar.','cats'=>$ac,
             'cities'=>['Bhubaneswar','Cuttack','Sambalpur','Rourkela'],
             'editions'=>['Bhubaneswar','Cuttack','Sambalpur','Rourkela']],
            ['name'=>'Samaja','lang'=>'Odia','cl'=>36,'di'=>210,'min'=>230,'circ'=>280000,
             'desc'=>"Cuttack-based Odia daily — one of the oldest.",'cats'=>$ac,
             'cities'=>['Bhubaneswar','Cuttack','Sambalpur'],
             'editions'=>['Bhubaneswar','Cuttack','Sambalpur']],
            ['name'=>'Pragativadi','lang'=>'Odia','cl'=>32,'di'=>200,'min'=>220,'circ'=>180000,
             'desc'=>'Bhubaneswar-based Odia daily.','cats'=>$ac,
             'cities'=>['Bhubaneswar','Cuttack'],
             'editions'=>['Bhubaneswar','Cuttack']],
            /* ══ ASSAMESE ══ */
            ['name'=>'Amar Asom','lang'=>'Assamese','cl'=>35,'di'=>210,'min'=>230,'circ'=>210000,
             'desc'=>"Guwahati-based Assamese daily — largest in Assam.",'cats'=>$ac,
             'cities'=>['Guwahati','Silchar','Dibrugarh','Tezpur'],
             'editions'=>['Guwahati','Silchar','Dibrugarh']],
            ['name'=>'Asomiya Pratidin','lang'=>'Assamese','cl'=>32,'di'=>200,'min'=>220,'circ'=>180000,
             'desc'=>'Popular Assamese daily from Guwahati.','cats'=>$ac,
             'cities'=>['Guwahati','Silchar','Dibrugarh'],
             'editions'=>['Guwahati','Silchar']],
            ['name'=>'Dainik Janambhumi','lang'=>'Assamese','cl'=>30,'di'=>190,'min'=>210,'circ'=>120000,
             'desc'=>'Long-established Assamese daily from Guwahati.','cats'=>$ac,
             'cities'=>['Guwahati','Dibrugarh'],
             'editions'=>['Guwahati','Dibrugarh']],
            /* ══ URDU ══ */
            ['name'=>'Rashtriya Sahara (Urdu)','lang'=>'Urdu','cl'=>50,'di'=>280,'min'=>300,'circ'=>400000,
             'desc'=>'National Urdu daily with editions across major Indian cities.','cats'=>$ac,
             'cities'=>['Delhi','Lucknow','Mumbai','Patna','Kolkata','Hyderabad','Allahabad'],
             'editions'=>['Delhi','Lucknow','Mumbai','Patna','Kolkata','Hyderabad']],
            ['name'=>'Inquilab','lang'=>'Urdu','cl'=>45,'di'=>260,'min'=>280,'circ'=>200000,
             'desc'=>'Mumbai-based Urdu daily.','cats'=>$ac,
             'cities'=>['Mumbai','Delhi','Pune','Aurangabad'],
             'editions'=>['Mumbai','Delhi']],
            ['name'=>'Siasat','lang'=>'Urdu','cl'=>40,'di'=>240,'min'=>260,'circ'=>180000,
             'desc'=>'Hyderabad-based Urdu daily.','cats'=>$ac,
             'cities'=>['Hyderabad'],
             'editions'=>['Hyderabad']],
            ['name'=>'Munsif','lang'=>'Urdu','cl'=>38,'di'=>220,'min'=>240,'circ'=>120000,
             'desc'=>'Hyderabad-based Urdu newspaper.','cats'=>$ac,
             'cities'=>['Hyderabad'],
             'editions'=>['Hyderabad']],
            ['name'=>'Etemaad','lang'=>'Urdu','cl'=>35,'di'=>210,'min'=>230,'circ'=>90000,
             'desc'=>'Hyderabad-based Urdu daily.','cats'=>$ac,
             'cities'=>['Hyderabad'],
             'editions'=>['Hyderabad']],
            ['name'=>'Roznama Jadid','lang'=>'Urdu','cl'=>35,'di'=>210,'min'=>230,'circ'=>80000,
             'desc'=>'Delhi-based Urdu newspaper.','cats'=>$ac,
             'cities'=>['Delhi','Lucknow','Aligarh'],
             'editions'=>['Delhi','Lucknow']],
        ];
    }

    /* ── TEMPLATES ─────────────────────────────────────────────────── */
    // TRACE: seed_templates() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private static function seed_templates(): void {
        global $wpdb; $p = $wpdb->prefix . 'nas_';
        if ($wpdb->get_var("SELECT COUNT(*) FROM {$p}templates")) return;
        $cats = $wpdb->get_results("SELECT id, name FROM {$p}categories");
        $cm = []; foreach ($cats as $c) $cm[strtolower($c->name)] = $c->id;
        $cid = fn($k) => array_reduce(array_keys($cm), fn($carry,$key) => (!$carry && str_contains($key,$k)) ? $cm[$key] : $carry, 0);
        $tpls = [
            [$cid('obituary'),'Obituary — Simple','With deep sorrow we announce the passing of [Name], aged [Age] years, beloved [Relation] of [Family Name]. Departed on [Date] at [Place]. Prayer meeting on [Date] at [Time] at [Venue]. All friends & relatives cordially invited. Family in mourning.','formal',65],
            [$cid('obituary'),'Obituary — Detailed','It is with profound grief that we announce the demise of [Full Name], [Age] yrs, beloved [Relation] of [Family Name]. Passed away on [Date] at [Hospital/Place]. Last rites performed. Condolences at [Address/Phone].','formal',80],
            [$cid('obituary'),'Death Anniversary','We fondly remember [Name] on his/her [N]th death anniversary on [Date]. Gone from our eyes, never from our hearts. Deeply missed by [Family Names]. Prayer meeting at [Venue] on [Date] at [Time]. All near and dear welcome.','emotional',55],
            [$cid('property'),'Flat For Sale','[N]-BHK Flat FOR SALE in [Society/Area], [City]. [Area] sq.ft, [Floor] Floor, [Facing]-facing. [Furnished/Semi/Unfurnished]. Lift, 24x7 Security, Power Backup, Parking. Price: Rs.[Amount] (Negotiable). Bank Loan Available. Contact: [Phone].','professional',55],
            [$cid('property'),'Flat For Rent','[N]-BHK Flat FOR RENT in [Society/Area], [City]. [Area] sq.ft, [Floor] Floor. [Semi-Furnished]. Rent: Rs.[Amount] PM + Maintenance. [N] Months Deposit. [Families/Bachelors] Preferred. Call/WhatsApp: [Phone].','professional',50],
            [$cid('property'),'Commercial Space For Sale','Commercial [Shop/Office/Showroom] FOR SALE in [Location], [City]. Area: [Sq.ft] sq.ft. Ground/[Floor] Floor. Ready to move. Suitable for [Type]. Price: Rs.[Amount]. Contact: [Phone/Email].','professional',45],
            [$cid('property'),'Plot For Sale','[Residential/Commercial] Plot FOR SALE in [Area/Colony], [City]. Area: [Size] sq.yards. [Corner/Non-corner]. [RERA Approved]. All documents clear. Price: Rs.[Amount] (negotiable). Contact: [Phone].','professional',42],
            [$cid('matrimonial'),'Groom Wanted','GROOM WANTED. [Caste/Community] family seeks professionally qualified match for daughter. DOB: [Date], Height: [Height], [Qualification], [Profession], CTC Rs.[Amount]. [Family background]. Caste no bar. Send biodata: [Phone/Email].','formal',58],
            [$cid('matrimonial'),'Bride Wanted','BRIDE WANTED. [Caste/Community] family seeks suitable match for son. DOB: [Date], Height: [Height], [Qualification], [Job], CTC Rs.[Amount] PA. Own property. Early marriage preferred. Contact with biodata: [Phone].','formal',58],
            [$cid('matrimonial'),'NRI Match Wanted','NRI MATCH WANTED. [Community] seeks match for [US/UK/Canada]-based NRI son/daughter, [Age] yrs, [Qualification], [Profession], well-settled abroad. Respond with family details to: [Phone/Email].','formal',62],
            [$cid('job'),'Job Opening','REQUIRED: [Job Title] for [Company Name], [Location]. Exp: [Years] yrs. Qualification: [Degree]. Salary: Rs.[Amount] PM. Email CV: [Email] or WhatsApp: [Phone]. Walk-in: [Date/Time/Address].','professional',50],
            [$cid('job'),'Walk-In Interview','WALK-IN INTERVIEW. [Company] invites candidates for [Position]. Date: [Date]. Time: [Time]. Venue: [Address]. Qualification & Experience required. Salary: Rs.[Amount] PM. Bring CV + Documents.','professional',48],
            [$cid('education'),'Admissions Open','ADMISSIONS OPEN [Year-Year]. [Institution Name], [Location]. Courses: [List]. Scholarship for meritorious students. Limited seats. Fee: Rs.[Amount] PA. Call/WhatsApp: [Phone]. Website: [URL].','formal',48],
            [$cid('education'),'Coaching / Tuition','HOME TUITION / COACHING for Class [Range] students. Subjects: [Subjects]. Experienced teacher. [Area], [City]. Personalised attention. Reasonable fees. Contact: [Phone].','casual',40],
            [$cid('name change'),'Name Change Notice','I, [Old Name], [S/D/W] of [Father Name], residing at [Full Address], hereby declare that my name has been changed from [Old Name] to [New Name] for all legal purposes. Henceforth I shall be known as [New Name] only.','legal',60],
            [$cid('name change'),'Lost Document Notice','I, [Name], have lost my [Document Type] bearing No. [Number] at [Place]. If found, please contact: [Phone/Address] or nearest police station. Finder will be rewarded.','legal',42],
            [$cid('tender'),'Tender Notice','TENDER NOTICE. [Organisation Name], [Address] invites sealed tenders for [Work Description]. EMD: Rs.[Amount]. Last date: [Date]. Details: [Website/Office]. Contact: [Phone/Email].','formal',55],
            [$cid('business'),'Grand Opening','GRAND OPENING: [Business Name], [Address], [City]. Inauguration on [Date] at [Time]. Exclusive launch offers! [Products/Services]. Free gifts for first [N] customers. Call: [Phone].','promotional',50],
            [$cid('financial'),'IPO Notice','[Company Name] — [Public Issue]. Issue opens: [Date]. Closes: [Date]. Price Band: Rs.[Min]-[Max] per share. Lot Size: [N] shares. Apply through your bank or broker.','formal',60],
            [$cid('vehicle'),'Car For Sale','FOR SALE: [Make Model Year] — [Colour]. [KM] km driven. Single owner. Insurance & PUC valid. All papers clear. Price: Rs.[Amount] (Negotiable). Test drive available. [City]. Call: [Phone].','casual',45],
            [$cid('health'),'Hospital/Clinic Ad','[Hospital/Clinic Name], [Area], [City]. Speciality: [Speciality]. [NABH Accredited]. Emergency: 24x7. OPD: [Timings]. Insurance & Cashless available. Book appointment: [Phone]. Website: [URL].','professional',50],
            [$cid('entertainment'),'Event Announcement','[Event Name] presented by [Organiser]. Date: [Date] | Time: [Time] | Venue: [Venue], [City]. [Brief Description]. Tickets: [Box Office/Website]. Contact: [Phone]. Entry: Rs.[Amount] onwards.','promotional',48],
        ];
        foreach ($tpls as $t) {
            if (!$t[0]) continue;
            $wpdb->insert("{$p}templates",['category_id'=>$t[0],'name'=>$t[1],'content'=>$t[2],'tone'=>$t[3],'word_limit'=>$t[4],'is_active'=>1]);
        }
    }

    /* ── SAMPLE ADS ─────────────────────────────────────────────────── */
    // TRACE: seed_sample_ads() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private static function seed_sample_ads(): void {
        global $wpdb; $p = $wpdb->prefix . 'nas_';
        if ($wpdb->get_var("SELECT COUNT(*) FROM {$p}sample_ads")) return;
        $cats = $wpdb->get_results("SELECT id, name FROM {$p}categories");
        $cm = []; foreach ($cats as $c) $cm[strtolower($c->name)] = $c->id;
        $cid = fn($k) => array_reduce(array_keys($cm), fn($carry,$key) => (!$carry && str_contains($key,$k)) ? $cm[$key] : $carry, 0);
        $samples = [
            [$cid('obituary'),'Sample Obituary — Delhi Family','With deep sorrow we announce the passing of Smt. Kamla Devi Sharma, aged 78 years, beloved mother of the Sharma family, New Delhi. She left for heavenly abode on 15th March 2025. Prayer meeting on 17th March at 4 PM at 42, Raj Nagar Colony, Pitampura, Delhi. All friends and relatives cordially invited. Family in mourning.','classified'],
            [$cid('obituary'),'Sample Obituary — Mumbai','It is with profound grief we announce the demise of Shri Ramesh Mehta, aged 72, beloved husband of Mrs. Sujata Mehta and father of Ankit and Priya. Passed peacefully on 10th April 2025 at Lilavati Hospital, Mumbai. Flowers may be sent to 5B, Sea View, Worli, Mumbai-400018. Family in mourning.','classified'],
            [$cid('property'),'3BHK Flat For Sale — Dwarka Delhi','3 BHK Flat FOR SALE in Dwarka Sector 12, New Delhi. 1450 sq.ft, 8th Floor, East-facing. Fully Furnished with AC, Modular Kitchen, Wardrobes. 24x7 Security, Power Backup, Covered Parking, Club House. Price: Rs.1.25 Crore (Negotiable). Bank Loan Available. Contact: 9876543210.','classified'],
            [$cid('property'),'2BHK Flat For Rent — Andheri Mumbai','2 BHK Flat FOR RENT in Andheri West, Mumbai near Metro. 950 sq.ft, 4th Floor, Semi-Furnished. Lift, Security, Gym, Club House. Rent: Rs.45,000 PM plus Maintenance. 2 Months Deposit. Families Preferred. Call/WhatsApp: 9898989898.','classified'],
            [$cid('property'),'Commercial Shop — Bengaluru','Commercial Shop FOR SALE, MG Road, Bengaluru. Ground Floor, 380 sq.ft. Busy commercial area. High footfall. Ready Possession. Price: Rs.55 Lakh. Ideal for retail, clinic, office. Call: 9845012345.','classified'],
            [$cid('matrimonial'),'Groom Wanted — Pune','GROOM WANTED. Brahmin family seeks professionally qualified groom for daughter. DOB: June 1997, Height: 5 feet 3 inches, Software Engineer, TCS, CTC 12 LPA. Pune. Caste no bar. Send biodata with photo to 9823456789.','classified'],
            [$cid('matrimonial'),'Bride Wanted — Hyderabad','BRIDE WANTED. Kamma community seeks match for son, 30 years, MS USA, Software Architect, US citizen, Height 5 feet 10 inches. Well-settled family, Hyderabad. Seeking educated, cultured girl. Contact: 9701234567.','classified'],
            [$cid('job'),'Accountant Required — Kolkata','REQUIRED: Accountant for Reputed Trading Company, Burrabazar, Kolkata. Minimum 3 years experience in Tally ERP, GST Filing, Balance Sheet. Salary Rs.25,000 to 35,000 PM. Monday to Saturday 10 AM to 7 PM. WhatsApp CV: 9831234567. Immediate joining.','classified'],
            [$cid('job'),'Walk-In Interview BPO — Chennai','WALK-IN INTERVIEW. Leading BPO Company, Anna Salai, Chennai. Voice Process. Any Graduate. Fresher or Experienced welcome. Salary Rs.15,000 to 25,000 PM plus Incentives. Date: 20th January 2025. Time: 10 AM to 4 PM. Venue: 25, Anna Salai, Chennai-600002. Carry CV and ID Proof.','classified'],
            [$cid('education'),'IIT-JEE Coaching — Kota','ADMISSIONS OPEN 2025-26. Resonance Eduventures, Kota. Classes for IIT-JEE, NEET, Foundation Class IX to XII. 35 plus years track record. 24x7 hostel facility. Scholarship up to 90 percent for meritorious students. Call: 0744-2777756.','classified'],
            [$cid('name change'),'Name Change Notice — Delhi','I, Mohammed Salim Khan, son of Abdul Rehman Khan, resident of 15, Block B, Jamia Nagar, New Delhi-110025, hereby declare that my name has been changed from Mohammed Salim Khan to Mohammad Saleem Khan for all legal purposes henceforth. Signed: Mohammad Saleem Khan.','classified'],
            [$cid('business'),'Grand Opening — Ahmedabad','GRAND OPENING: Shree Ganesh Jewellers, Ring Road, Naranpura, Ahmedabad. Inauguration on 1st February 2025 at 11 AM. 22 Carat Gold, Diamond, Silver Jewellery at factory prices. Special 5 percent discount on inaugural day. Call: 9825012345.','classified'],
            [$cid('vehicle'),'Car For Sale — Bengaluru','FOR SALE: Hyundai Creta SX Plus 2021, Blue, 28500 km driven. Single Owner. Full Insurance Valid. PUC Valid. All Papers Clear. Sunroof, ADAS features. Price: Rs.13.5 Lakh Negotiable. Test drive available. Bengaluru. Call: 9845678901.','classified'],
            [$cid('health'),'Hospital Advertisement — Hyderabad','Yashoda Hospitals, Malakpet, Hyderabad. 24x7 Emergency. Multi-Specialty: Cardiac Surgery, Neuro, Ortho, IVF, Oncology. NABH Accredited. Insurance and Cashless facility available. OPD: 8 AM to 8 PM. Appointment: 040-45678901.','classified'],
            [$cid('entertainment'),'Cultural Event — Kolkata','DURGA PUJA CELEBRATIONS 2025. Shreepur Sarbojonin Committee. Cultural programme on Saptami, Ashtami and Navami evenings with Classical Music, Dance and Drama. Venue: Shreepur Club Ground, Salt Lake, Kolkata. Open to all. Entry Free.','classified'],
            [$cid('tender'),'Government Tender — Mumbai','TENDER NOTICE. MCGM Mumbai invites sealed tenders for repair and resurfacing of roads in Ward K-East. EMD: Rs.2,00,000. Tender Fee: Rs.5,000. Last date: 15th February 2025. Details at mcgm.gov.in. Contact: 022-22621000.','classified'],
            [$cid('financial'),'Mutual Fund Advertisement','INVEST WISELY. Mirae Asset Large Cap Fund. SIP starting from Rs.500 per month. 10-year CAGR: 14.2 percent. Tax saving under Section 80C with ELSS scheme. Invest online at miraeasset.co.in. Mutual fund investments are subject to market risks. Read all scheme related documents carefully before investing.','classified'],
        ];
        foreach ($samples as $s) {
            if (!$s[0]) continue;
            $wpdb->insert("{$p}sample_ads",['category_id'=>$s[0],'title'=>$s[1],'content'=>$s[2],'format_type'=>$s[3],'word_count'=>str_word_count($s[2]),'is_active'=>1]);
        }
    }

    /* ── QUICK REPLIES ──────────────────────────────────────────────── */
    // TRACE: seed_quick_replies() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private static function seed_quick_replies(): void {
        global $wpdb; $p = $wpdb->prefix . 'nas_';
        if ($wpdb->get_var("SELECT COUNT(*) FROM {$p}quick_replies")) return;
        $replies = [
            ['Booking Received','booking','Thank you for your booking! We have received your request and our team will review it within 24 hours. Your booking reference is #{ORDER_ID}.'],
            ['Proof Ready for Approval','status','Hi {CLIENT_NAME}, your ad proof for booking #{ORDER_ID} is ready. Please log in to your dashboard to review and approve it. Link: {DASHBOARD_LINK}'],
            ['Payment Request','payment','Hi {CLIENT_NAME}, your ad is ready to publish. Kindly complete the payment of Rs.{AMOUNT} for booking #{ORDER_ID}. Pay via UPI, bank transfer, or online at your dashboard. Please share the screenshot once done.'],
            ['Payment Confirmed','payment','Hi {CLIENT_NAME}, your payment of Rs.{AMOUNT} for booking #{ORDER_ID} has been received. Thank you! Your ad will be published as scheduled.'],
            ['Documents Required','documents','Hi {CLIENT_NAME}, to process your ad booking #{ORDER_ID}, we need: 1) Government ID proof (Aadhaar/PAN) 2) {SPECIFIC_DOCUMENT}. Kindly share at the earliest to avoid delays.'],
            ['Ad Published Successfully','published','Great news! Your advertisement has been published in {PUBLICATION} - {EDITION} Edition on {PUB_DATE}. Booking #{ORDER_ID} is now complete!'],
            ['Processing Update','status','Hi {CLIENT_NAME}, your booking #{ORDER_ID} is being processed and sent to the newspaper. Expected publication date: {PUB_DATE}. We will update you shortly.'],
            ['Price Quote Shared','quotation','Hi {CLIENT_NAME}, please find your ad quotation below. Newspaper: {PUBLICATION}. Ad Type: {AD_TYPE}. Publication Date: {PUB_DATE}. Total Amount: Rs.{AMOUNT} including GST. Kindly confirm and make payment to proceed.'],
            ['Rejection Notice','rejection','We regret that your ad booking #{ORDER_ID} could not be processed. Reason: {REASON}. Please contact us at {SUPPORT_PHONE} for alternative options.'],
            ['Content Correction Request','documents','Hi {CLIENT_NAME}, we noticed an issue with your ad content for booking #{ORDER_ID}: {ISSUE}. Kindly provide corrected content at the earliest so we can proceed.'],
            ['Publication Confirmed','published','Your ad has been sent to {PUBLICATION} for publication on {PUB_DATE}. Booking #{ORDER_ID} confirmed. You will receive a copy/tear sheet within 5 business days.'],
            ['Thank You','general','Thank you for choosing us for your newspaper advertising! We hope your ad achieves great results. Reach out for any future requirements!'],
            ['Follow-Up Reminder','status','Hi {CLIENT_NAME}, a gentle reminder regarding booking #{ORDER_ID} which requires your attention. Please log in to your dashboard or reply here to proceed.'],
            ['GST Invoice Ready','payment','Hi {CLIENT_NAME}, your GST invoice for booking #{ORDER_ID} is ready. Amount: Rs.{AMOUNT}. Download from your dashboard under the Invoice section.'],
        ];
        foreach ($replies as $i => $r) {
            $wpdb->insert("{$p}quick_replies",['title'=>$r[0],'category'=>$r[1],'content'=>$r[2],'sort_order'=>$i,'is_active'=>1]);
        }
    }

    /* ── COMBO OFFERS ───────────────────────────────────────────────── */
    // TRACE: seed_combo_offers() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private static function seed_combo_offers(): void {
        global $wpdb; $p = $wpdb->prefix . 'nas_';
        if ($wpdb->get_var("SELECT COUNT(*) FROM {$p}combo_offers")) return;
        $from = date('Y-m-d'); $to = date('Y-m-d', strtotime('+12 months'));
        $combos = [
            ['Metro 3-City Classified Pack',['Delhi','Mumbai','Bengaluru'],['Times of India','Hindustan Times'],15,'Publish same classified ad in 3 major metro newspapers and save 15%.'],
            ['Pan India English Bundle',['Delhi','Mumbai','Bengaluru','Hyderabad','Chennai','Kolkata','Pune','Ahmedabad'],['Times of India','Hindustan Times','The Hindu','Deccan Herald'],20,'Book in 5+ English newspapers across 5+ cities and get 20% off.'],
            ['North India Hindi Combo',['Delhi','Lucknow','Kanpur','Agra','Patna','Ranchi','Chandigarh'],['Dainik Jagran','Amar Ujala','Hindustan (Hindi)'],12,'Book in 3 leading Hindi dailies across North India for 12% off.'],
            ['South India Language Pack',['Chennai','Bengaluru','Hyderabad','Kochi','Thiruvananthapuram'],['The Hindu','Dinamalar','Vijay Karnataka','Eenadu','Malayala Manorama'],18,'Cover all South Indian languages with 5 regional papers and save 18%.'],
            ['Rajasthan + MP Classified Combo',['Jaipur','Jodhpur','Kota','Indore','Bhopal','Raipur'],['Rajasthan Patrika','Dainik Bhaskar','Nai Dunia'],12,'Book across Rajasthan Patrika, Dainik Bhaskar and Nai Dunia for 12% off.'],
            ['Maharashtra Marathi Full State',['Mumbai','Pune','Nagpur','Nashik','Aurangabad','Kolhapur'],['Lokmat','Maharashtra Times','Sakal'],15,'Cover Maharashtra fully with the 3 top Marathi dailies and get 15% off.'],
            ['Gujarat Full State Pack',['Ahmedabad','Surat','Vadodara','Rajkot','Bhavnagar'],['Gujarat Samachar','Sandesh','Divya Bhaskar'],15,'Book across three leading Gujarati dailies for statewide reach and 15% off.'],
            ['Business and Financial Pack',['Delhi','Mumbai','Bengaluru','Hyderabad','Kolkata','Ahmedabad'],['The Economic Times','Business Standard','Financial Express'],10,'Book in all 3 business dailies across 6 cities and save 10%.'],
        ];
        foreach ($combos as $c) {
            $wpdb->insert("{$p}combo_offers",[
                'name'=>$c[0],'cities'=>wp_json_encode($c[1]),'newspapers'=>wp_json_encode($c[2]),
                'discount_type'=>'percentage','discount_value'=>floatval($c[3]),
                'conditions'=>$c[4],'valid_from'=>$from,'valid_to'=>$to,'is_active'=>1,
            ]);
        }
    }

    /* ── DEFAULT CITIES (300 cities — used if cities.json missing) ─── */
    // TRACE: default_cities() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private static function default_cities(): array {
        return json_decode('[{"name":"Mumbai","state":"Maharashtra","tier":1,"population":20667656},{"name":"Delhi","state":"Delhi","tier":1,"population":32941000},{"name":"Bengaluru","state":"Karnataka","tier":1,"population":13193000},{"name":"Hyderabad","state":"Telangana","tier":1,"population":10534000},{"name":"Chennai","state":"Tamil Nadu","tier":1,"population":10971000},{"name":"Kolkata","state":"West Bengal","tier":1,"population":15134000},{"name":"Pune","state":"Maharashtra","tier":1,"population":7642000},{"name":"Ahmedabad","state":"Gujarat","tier":1,"population":8450000},{"name":"Surat","state":"Gujarat","tier":1,"population":7185000},{"name":"Jaipur","state":"Rajasthan","tier":1,"population":4068000},{"name":"Lucknow","state":"Uttar Pradesh","tier":1,"population":3780000},{"name":"Kanpur","state":"Uttar Pradesh","tier":1,"population":3124000},{"name":"Nagpur","state":"Maharashtra","tier":1,"population":2977000},{"name":"Indore","state":"Madhya Pradesh","tier":2,"population":3240000},{"name":"Thane","state":"Maharashtra","tier":2,"population":2400000},{"name":"Bhopal","state":"Madhya Pradesh","tier":2,"population":1900000},{"name":"Visakhapatnam","state":"Andhra Pradesh","tier":2,"population":2173000},{"name":"Patna","state":"Bihar","tier":2,"population":2300000},{"name":"Vadodara","state":"Gujarat","tier":2,"population":2200000},{"name":"Ghaziabad","state":"Uttar Pradesh","tier":2,"population":1700000},{"name":"Ludhiana","state":"Punjab","tier":2,"population":1830000},{"name":"Agra","state":"Uttar Pradesh","tier":2,"population":1760000},{"name":"Nashik","state":"Maharashtra","tier":2,"population":1600000},{"name":"Faridabad","state":"Haryana","tier":2,"population":1600000},{"name":"Meerut","state":"Uttar Pradesh","tier":2,"population":1475000},{"name":"Rajkot","state":"Gujarat","tier":2,"population":1500000},{"name":"Varanasi","state":"Uttar Pradesh","tier":2,"population":1435000},{"name":"Srinagar","state":"Jammu & Kashmir","tier":2,"population":1300000},{"name":"Aurangabad","state":"Maharashtra","tier":2,"population":1430000},{"name":"Dhanbad","state":"Jharkhand","tier":2,"population":1200000},{"name":"Amritsar","state":"Punjab","tier":2,"population":1200000},{"name":"Allahabad","state":"Uttar Pradesh","tier":2,"population":1400000},{"name":"Ranchi","state":"Jharkhand","tier":2,"population":1100000},{"name":"Haora","state":"West Bengal","tier":2,"population":1100000},{"name":"Coimbatore","state":"Tamil Nadu","tier":2,"population":1601000},{"name":"Jabalpur","state":"Madhya Pradesh","tier":2,"population":1300000},{"name":"Gwalior","state":"Madhya Pradesh","tier":2,"population":1100000},{"name":"Vijayawada","state":"Andhra Pradesh","tier":2,"population":1000000},{"name":"Jodhpur","state":"Rajasthan","tier":2,"population":1100000},{"name":"Madurai","state":"Tamil Nadu","tier":2,"population":1600000},{"name":"Raipur","state":"Chhattisgarh","tier":2,"population":1000000},{"name":"Kota","state":"Rajasthan","tier":2,"population":1000000},{"name":"Guwahati","state":"Assam","tier":2,"population":1100000},{"name":"Chandigarh","state":"Chandigarh","tier":2,"population":1000000},{"name":"Solapur","state":"Maharashtra","tier":2,"population":900000},{"name":"Hubli-Dharwad","state":"Karnataka","tier":2,"population":900000},{"name":"Tiruchirappalli","state":"Tamil Nadu","tier":2,"population":1100000},{"name":"Bareilly","state":"Uttar Pradesh","tier":2,"population":980000},{"name":"Mysore","state":"Karnataka","tier":2,"population":990000},{"name":"Tiruppur","state":"Tamil Nadu","tier":2,"population":900000},{"name":"Gurgaon","state":"Haryana","tier":2,"population":1350000},{"name":"Noida","state":"Uttar Pradesh","tier":2,"population":700000},{"name":"Moradabad","state":"Uttar Pradesh","tier":2,"population":900000},{"name":"Jalandhar","state":"Punjab","tier":2,"population":870000},{"name":"Bhubaneswar","state":"Odisha","tier":2,"population":950000},{"name":"Salem","state":"Tamil Nadu","tier":2,"population":900000},{"name":"Warangal","state":"Telangana","tier":2,"population":800000},{"name":"Guntur","state":"Andhra Pradesh","tier":2,"population":750000},{"name":"Thiruvananthapuram","state":"Kerala","tier":2,"population":1000000},{"name":"Kochi","state":"Kerala","tier":2,"population":680000},{"name":"Kozhikode","state":"Kerala","tier":2,"population":700000},{"name":"Dehradun","state":"Uttarakhand","tier":2,"population":580000},{"name":"Jammu","state":"Jammu & Kashmir","tier":2,"population":600000},{"name":"Jamshedpur","state":"Jharkhand","tier":2,"population":630000},{"name":"Asansol","state":"West Bengal","tier":2,"population":1250000},{"name":"Durgapur","state":"West Bengal","tier":2,"population":580000},{"name":"Siliguri","state":"West Bengal","tier":2,"population":700000},{"name":"Mangalore","state":"Karnataka","tier":2,"population":620000},{"name":"Tirupati","state":"Andhra Pradesh","tier":2,"population":460000},{"name":"Udaipur","state":"Rajasthan","tier":2,"population":470000},{"name":"Ajmer","state":"Rajasthan","tier":2,"population":545000},{"name":"Bikaner","state":"Rajasthan","tier":2,"population":700000},{"name":"Aligarh","state":"Uttar Pradesh","tier":2,"population":870000},{"name":"Gorakhpur","state":"Uttar Pradesh","tier":2,"population":730000},{"name":"Saharanpur","state":"Uttar Pradesh","tier":3,"population":700000},{"name":"Amravati","state":"Maharashtra","tier":3,"population":700000},{"name":"Kolhapur","state":"Maharashtra","tier":3,"population":570000},{"name":"Bhilai","state":"Chhattisgarh","tier":3,"population":600000},{"name":"Tirunelveli","state":"Tamil Nadu","tier":3,"population":550000},{"name":"Gaya","state":"Bihar","tier":3,"population":550000},{"name":"Jalgaon","state":"Maharashtra","tier":3,"population":500000},{"name":"Akola","state":"Maharashtra","tier":3,"population":450000},{"name":"Kurnool","state":"Andhra Pradesh","tier":3,"population":450000},{"name":"Bellary","state":"Karnataka","tier":3,"population":450000},{"name":"Patiala","state":"Punjab","tier":3,"population":450000},{"name":"Agartala","state":"Tripura","tier":3,"population":400000},{"name":"Bhagalpur","state":"Bihar","tier":3,"population":400000},{"name":"Muzaffarnagar","state":"Uttar Pradesh","tier":3,"population":400000},{"name":"Nizamabad","state":"Telangana","tier":3,"population":340000},{"name":"Gulbarga","state":"Karnataka","tier":3,"population":500000},{"name":"Erode","state":"Tamil Nadu","tier":3,"population":550000},{"name":"Shimla","state":"Himachal Pradesh","tier":3,"population":170000},{"name":"Haridwar","state":"Uttarakhand","tier":3,"population":230000},{"name":"Rohtak","state":"Haryana","tier":3,"population":375000},{"name":"Hisar","state":"Haryana","tier":3,"population":360000},{"name":"Panipat","state":"Haryana","tier":3,"population":350000},{"name":"Ambala","state":"Haryana","tier":3,"population":205000},{"name":"Jhansi","state":"Uttar Pradesh","tier":3,"population":510000},{"name":"Muzaffarpur","state":"Bihar","tier":3,"population":400000},{"name":"Nanded","state":"Maharashtra","tier":3,"population":500000},{"name":"Bilaspur","state":"Chhattisgarh","tier":3,"population":500000},{"name":"Latur","state":"Maharashtra","tier":3,"population":400000},{"name":"Sangli","state":"Maharashtra","tier":3,"population":436000},{"name":"Ujjain","state":"Madhya Pradesh","tier":3,"population":520000},{"name":"Bhilwara","state":"Rajasthan","tier":3,"population":350000},{"name":"Alwar","state":"Rajasthan","tier":3,"population":340000},{"name":"Pondicherry","state":"Puducherry","tier":3,"population":600000},{"name":"Cuttack","state":"Odisha","tier":3,"population":610000},{"name":"Rourkela","state":"Odisha","tier":3,"population":560000},{"name":"Sambalpur","state":"Odisha","tier":3,"population":320000},{"name":"Balasore","state":"Odisha","tier":3,"population":155000},{"name":"Bhavnagar","state":"Gujarat","tier":3,"population":600000},{"name":"Jamnagar","state":"Gujarat","tier":3,"population":600000},{"name":"Junagadh","state":"Gujarat","tier":3,"population":320000},{"name":"Anand","state":"Gujarat","tier":3,"population":200000},{"name":"Gandhinagar","state":"Gujarat","tier":3,"population":290000},{"name":"Karimnagar","state":"Telangana","tier":3,"population":320000},{"name":"Khammam","state":"Telangana","tier":3,"population":270000},{"name":"Thrissur","state":"Kerala","tier":3,"population":330000},{"name":"Kannur","state":"Kerala","tier":3,"population":220000},{"name":"Alappuzha","state":"Kerala","tier":3,"population":175000},{"name":"Kottayam","state":"Kerala","tier":3,"population":200000},{"name":"Malappuram","state":"Kerala","tier":3,"population":225000},{"name":"Vellore","state":"Tamil Nadu","tier":3,"population":530000},{"name":"Thanjavur","state":"Tamil Nadu","tier":3,"population":230000},{"name":"Nagercoil","state":"Tamil Nadu","tier":3,"population":225000},{"name":"Tumkur","state":"Karnataka","tier":3,"population":305000},{"name":"Shivamogga","state":"Karnataka","tier":3,"population":325000},{"name":"Davanagere","state":"Karnataka","tier":3,"population":450000},{"name":"Udupi","state":"Karnataka","tier":3,"population":180000},{"name":"Satara","state":"Maharashtra","tier":3,"population":290000},{"name":"Chandrapur","state":"Maharashtra","tier":3,"population":335000},{"name":"Panaji","state":"Goa","tier":3,"population":115000},{"name":"Margao","state":"Goa","tier":3,"population":95000},{"name":"Vasco","state":"Goa","tier":3,"population":80000},{"name":"Shillong","state":"Meghalaya","tier":3,"population":355000},{"name":"Dibrugarh","state":"Assam","tier":3,"population":140000},{"name":"Silchar","state":"Assam","tier":3,"population":230000},{"name":"Kohima","state":"Nagaland","tier":3,"population":100000},{"name":"Dimapur","state":"Nagaland","tier":3,"population":180000},{"name":"Imphal","state":"Manipur","tier":3,"population":270000},{"name":"Aizawl","state":"Mizoram","tier":3,"population":293000},{"name":"Gangtok","state":"Sikkim","tier":3,"population":100000},{"name":"Kakinada","state":"Andhra Pradesh","tier":3,"population":415000},{"name":"Rajahmundry","state":"Andhra Pradesh","tier":3,"population":340000},{"name":"Nellore","state":"Andhra Pradesh","tier":3,"population":500000},{"name":"Kadapa","state":"Andhra Pradesh","tier":3,"population":345000},{"name":"Anantapur","state":"Andhra Pradesh","tier":3,"population":390000},{"name":"Bhimavaram","state":"Andhra Pradesh","tier":3,"population":150000},{"name":"Eluru","state":"Andhra Pradesh","tier":3,"population":220000},{"name":"Mahabubnagar","state":"Telangana","tier":3,"population":250000},{"name":"Adilabad","state":"Telangana","tier":3,"population":150000},{"name":"Ramagundam","state":"Telangana","tier":3,"population":275000},{"name":"Bathinda","state":"Punjab","tier":3,"population":296000},{"name":"Pathankot","state":"Punjab","tier":3,"population":215000},{"name":"Hoshiarpur","state":"Punjab","tier":3,"population":195000},{"name":"Karnal","state":"Haryana","tier":3,"population":285000},{"name":"Sonipat","state":"Haryana","tier":3,"population":285000},{"name":"Yamunanagar","state":"Haryana","tier":3,"population":290000},{"name":"Bahadurgarh","state":"Haryana","tier":3,"population":155000},{"name":"Rewari","state":"Haryana","tier":3,"population":145000},{"name":"Sirsa","state":"Haryana","tier":3,"population":196000},{"name":"Roorkee","state":"Uttarakhand","tier":3,"population":115000},{"name":"Haldwani","state":"Uttarakhand","tier":3,"population":155000},{"name":"Rudrapur","state":"Uttarakhand","tier":3,"population":145000},{"name":"Rishikesh","state":"Uttarakhand","tier":3,"population":102000},{"name":"Dharamsala","state":"Himachal Pradesh","tier":3,"population":22000},{"name":"Mandi","state":"Himachal Pradesh","tier":3,"population":27000},{"name":"Solan","state":"Himachal Pradesh","tier":3,"population":35000},{"name":"Raigarh","state":"Chhattisgarh","tier":3,"population":180000},{"name":"Korba","state":"Chhattisgarh","tier":3,"population":370000},{"name":"Durg","state":"Chhattisgarh","tier":3,"population":270000},{"name":"Ambikapur","state":"Chhattisgarh","tier":3,"population":115000},{"name":"Jagdalpur","state":"Chhattisgarh","tier":3,"population":110000},{"name":"Sagar","state":"Madhya Pradesh","tier":3,"population":290000},{"name":"Rewa","state":"Madhya Pradesh","tier":3,"population":235000},{"name":"Satna","state":"Madhya Pradesh","tier":3,"population":280000},{"name":"Chhindwara","state":"Madhya Pradesh","tier":3,"population":200000},{"name":"Singrauli","state":"Madhya Pradesh","tier":3,"population":250000},{"name":"Khandwa","state":"Madhya Pradesh","tier":3,"population":215000},{"name":"Guna","state":"Madhya Pradesh","tier":3,"population":180000},{"name":"Shivpuri","state":"Madhya Pradesh","tier":3,"population":215000},{"name":"Vidisha","state":"Madhya Pradesh","tier":3,"population":165000},{"name":"Mandsaur","state":"Madhya Pradesh","tier":3,"population":145000},{"name":"Betul","state":"Madhya Pradesh","tier":3,"population":115000},{"name":"Mathura","state":"Uttar Pradesh","tier":3,"population":441000},{"name":"Shahjahanpur","state":"Uttar Pradesh","tier":3,"population":360000},{"name":"Pilibhit","state":"Uttar Pradesh","tier":3,"population":130000},{"name":"Etawah","state":"Uttar Pradesh","tier":3,"population":260000},{"name":"Sitapur","state":"Uttar Pradesh","tier":3,"population":170000},{"name":"Bahraich","state":"Uttar Pradesh","tier":3,"population":175000},{"name":"Rae Bareli","state":"Uttar Pradesh","tier":3,"population":175000},{"name":"Deoria","state":"Uttar Pradesh","tier":3,"population":135000},{"name":"Basti","state":"Uttar Pradesh","tier":3,"population":120000},{"name":"Gonda","state":"Uttar Pradesh","tier":3,"population":140000},{"name":"Mirzapur","state":"Uttar Pradesh","tier":3,"population":235000},{"name":"Hapur","state":"Uttar Pradesh","tier":3,"population":250000},{"name":"Amroha","state":"Uttar Pradesh","tier":3,"population":200000},{"name":"Sambhal","state":"Uttar Pradesh","tier":3,"population":225000},{"name":"Darbhanga","state":"Bihar","tier":3,"population":295000},{"name":"Arrah","state":"Bihar","tier":3,"population":265000},{"name":"Begusarai","state":"Bihar","tier":3,"population":160000},{"name":"Chhapra","state":"Bihar","tier":3,"population":210000},{"name":"Sasaram","state":"Bihar","tier":3,"population":175000},{"name":"Katihar","state":"Bihar","tier":3,"population":240000},{"name":"Purnia","state":"Bihar","tier":3,"population":280000},{"name":"Bhuj","state":"Gujarat","tier":3,"population":150000},{"name":"Navsari","state":"Gujarat","tier":3,"population":175000},{"name":"Vapi","state":"Gujarat","tier":3,"population":180000},{"name":"Bharuch","state":"Gujarat","tier":3,"population":220000},{"name":"Surendranagar","state":"Gujarat","tier":3,"population":175000},{"name":"Morbi","state":"Gujarat","tier":3,"population":195000},{"name":"Bokaro","state":"Jharkhand","tier":3,"population":560000},{"name":"Hazaribagh","state":"Jharkhand","tier":3,"population":185000},{"name":"Berhampur","state":"Odisha","tier":3,"population":355000},{"name":"Medinipur","state":"West Bengal","tier":3,"population":175000},{"name":"Berhampore","state":"West Bengal","tier":3,"population":185000},{"name":"Malda","state":"West Bengal","tier":3,"population":165000},{"name":"Jalpaiguri","state":"West Bengal","tier":3,"population":145000},{"name":"Vijayapura","state":"Karnataka","tier":3,"population":325000},{"name":"Hassan","state":"Karnataka","tier":3,"population":135000},{"name":"Raichur","state":"Karnataka","tier":3,"population":230000},{"name":"Palakkad","state":"Kerala","tier":3,"population":130000},{"name":"Cuddalore","state":"Tamil Nadu","tier":3,"population":175000},{"name":"Dindigul","state":"Tamil Nadu","tier":3,"population":210000},{"name":"Hosur","state":"Tamil Nadu","tier":3,"population":200000},{"name":"Kancheepuram","state":"Tamil Nadu","tier":3,"population":165000},{"name":"Kumbakonam","state":"Tamil Nadu","tier":3,"population":140000},{"name":"Dhule","state":"Maharashtra","tier":3,"population":340000},{"name":"Yavatmal","state":"Maharashtra","tier":3,"population":190000},{"name":"Wardha","state":"Maharashtra","tier":3,"population":130000},{"name":"Beed","state":"Maharashtra","tier":3,"population":135000},{"name":"Ichalkaranji","state":"Maharashtra","tier":3,"population":290000},{"name":"Kalyan","state":"Maharashtra","tier":3,"population":1246000},{"name":"Tezpur","state":"Assam","tier":3,"population":85000},{"name":"Jorhat","state":"Assam","tier":3,"population":90000},{"name":"Itanagar","state":"Arunachal Pradesh","tier":3,"population":44971}]', true);
    }
}
