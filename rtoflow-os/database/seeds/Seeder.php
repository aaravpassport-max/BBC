<?php
if (!defined('ABSPATH')) exit;

class RTOFLOW_Seeder {
    public function run(): void {
        // FIX (root cause of the duplicate/legacy "12 categories on the
        // /rto-service/all/ page" report): this legacy services() below
        // seeds its OWN older, differently-worded 30-service catalog
        // ('RC & Ownership', 'NOC Services', 'Hypothecation', 'Vehicle
        // Registration', 'Commercial & Permits' — none of which match the
        // current 7 canonical categories apply.php's dropdowns and
        // RealFormSchemaSeeder actually use). It guards on
        // `COUNT(*) >= 10`, which passed and let it seed its 30 rows back
        // when IndiaDataSeeder's inserts were silently failing (see the
        // slug bug fixed in IndiaDataSeeder.php) and the live table had far
        // fewer than 10 rows. IndiaDataSeeder is the sole source of truth
        // for services now — this legacy list stays here only for its
        // historical $svcs data (harmless if never called) but must never
        // run again, or every future activation reintroduces the same
        // duplicate catalog.
        $this->states(); $this->cities(); $this->doc_types();
        $this->automation_rules(); $this->notification_templates(); $this->holidays();
    }

    private function states(): void {
        global $wpdb; $t=$wpdb->prefix.'rto_states';
        if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $t")>0) return;
        $data=[['Andhra Pradesh','AP'],['Arunachal Pradesh','AR'],['Assam','AS'],['Bihar','BR'],['Chhattisgarh','CG'],['Goa','GA'],['Gujarat','GJ'],['Haryana','HR'],['Himachal Pradesh','HP'],['Jharkhand','JH'],['Karnataka','KA'],['Kerala','KL'],['Madhya Pradesh','MP'],['Maharashtra','MH'],['Manipur','MN'],['Meghalaya','ML'],['Mizoram','MZ'],['Nagaland','NL'],['Odisha','OD'],['Punjab','PB'],['Rajasthan','RJ'],['Sikkim','SK'],['Tamil Nadu','TN'],['Telangana','TS'],['Tripura','TR'],['Uttar Pradesh','UP'],['Uttarakhand','UK'],['West Bengal','WB'],['Delhi','DL'],['Chandigarh','CH'],['Puducherry','PY'],['Ladakh','LA'],['Jammu & Kashmir','JK'],['Gujarat','GJ']];
        foreach ($data as [$n,$c]) { if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE code=%s",$c))) continue; $wpdb->insert($t,['name'=>$n,'code'=>$c]); }
    }

    private function cities(): void {
        global $wpdb; $t=$wpdb->prefix.'rto_cities';
        if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $t")>=30) return;
        $sm=array_column($wpdb->get_results("SELECT id,code FROM {$wpdb->prefix}rto_states",ARRAY_A),'id','code');
        $cities=[['Mumbai','MH'],['Pune','MH'],['Nagpur','MH'],['Thane','MH'],['Delhi','DL'],['New Delhi','DL'],['Bengaluru','KA'],['Mysuru','KA'],['Hubballi','KA'],['Mangaluru','KA'],['Chennai','TN'],['Coimbatore','TN'],['Madurai','TN'],['Hyderabad','TS'],['Warangal','TS'],['Ahmedabad','GJ'],['Surat','GJ'],['Vadodara','GJ'],['Jaipur','RJ'],['Jodhpur','RJ'],['Lucknow','UP'],['Kanpur','UP'],['Agra','UP'],['Kolkata','WB'],['Vijayawada','AP'],['Bhopal','MP'],['Indore','MP'],['Ludhiana','PB'],['Amritsar','PB'],['Gurgaon','HR'],['Thiruvananthapuram','KL'],['Kochi','KL'],['Ranchi','JH'],['Bhubaneswar','OD'],['Chandigarh','CH'],['Srinagar','JK'],['Jammu','JK'],['Raipur','CG'],['Dehradun','UK'],['Guwahati','AS'],['Panaji','GA']];
        foreach ($cities as [$n,$s]) { if (!isset($sm[$s])) continue; $wpdb->insert($t,['name'=>$n,'state_id'=>$sm[$s],'is_active'=>1]); }
    }

    private function services(): void {
        global $wpdb; $t=$wpdb->prefix.'rto_services';
        if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $t")>=10) return;
        // [category, name, slug, price, govt_fee, sla_days, sla_urgent_days]
        $svcs=[
            ['RC & Ownership','Ownership Transfer','ownership_transfer',2500,500,15,7],
            ['RC & Ownership','Ownership Transfer (Used Car)','ownership_transfer_used',2000,300,15,7],
            ['RC & Ownership','Transfer within Family','ownership_family',1500,200,12,5],
            ['RC & Ownership','RC Renewal','rc_renewal',1000,200,10,5],
            ['RC & Ownership','Duplicate RC','duplicate_rc',800,150,10,5],
            ['RC & Ownership','Change of Address in RC','rc_address_change',600,100,7,3],
            ['RC & Ownership','RC Name Change','rc_name_change',600,100,7,3],
            ['RC & Ownership','Change of State','change_state',3000,700,20,10],
            ['NOC Services','NOC for Vehicle','noc_vehicle',1200,100,10,5],
            ['NOC Services','NOC for Two Wheeler','noc_two_wheeler',800,100,10,5],
            ['NOC Services','Export Vehicle NOC','export_noc',2000,500,15,7],
            ['Driving License','Driving License - Fresh','dl_fresh',2500,200,20,10],
            ['Driving License','Driving License Renewal','dl_renewal',1500,150,15,7],
            ['Driving License','Duplicate Driving License','dl_duplicate',1000,150,12,6],
            ['Driving License','International Driving Permit','idp',3000,1000,20,10],
            ['Driving License','Change of Address in DL','dl_address_change',600,100,10,5],
            ['Hypothecation','Hypothecation Addition','hypothecation_add',1000,200,10,5],
            ['Hypothecation','Hypothecation Removal','hypothecation_remove',1000,200,10,5],
            ['Vehicle Registration','Vehicle Fitness Certificate','fitness_certificate',1500,300,7,3],
            ['Vehicle Registration','CNG/LPG Conversion','cng_conversion',2000,500,12,6],
            ['Vehicle Registration','Temporary Registration','temp_reg',800,100,5,3],
            ['Vehicle Registration','Fancy Number Plate','fancy_number',5000,2000,15,7],
            ['Vehicle Registration','Import Vehicle Registration','import_reg',8000,2000,30,15],
            ['Vehicle Registration','Vehicle Scrapping Certificate','scrapping',500,100,5,3],
            ['Commercial & Permits','Trade Certificate','trade_certificate',3000,500,20,10],
            ['Commercial & Permits','Commercial Vehicle Permit','commercial_permit',4000,1000,20,10],
            ['Commercial & Permits','Route Permit','route_permit',3500,800,20,10],
            ['Other Services','RTO Liaison & Consultancy','consultancy',1000,0,7,3],
            ['Other Services','Legal Clearance','legal_clearance',2000,300,15,7],
            ['Other Services','Accident Report Filing','accident_report',800,100,5,2],
        ];
        $i=0;
        foreach ($svcs as [$cat,$name,$slug,$price,$govt,$sla,$urgent]) {
            $wpdb->insert($t,['category'=>$cat,'name'=>$name,'slug'=>$slug,'base_price'=>$price,'govt_fee'=>$govt,'vendor_share'=>45,'sla_days'=>$sla,'sla_urgent_days'=>$urgent,'is_active'=>1,'display_order'=>$i]);
            $i++;
        }
    }

    private function doc_types(): void {
        global $wpdb; $t=$wpdb->prefix.'rto_doc_types';
        if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $t")>=5) return;
        $types=[['RC Book / Smart Card',1],['Valid Insurance Certificate',1],['Valid PUC Certificate',1],['Aadhar Card',1],['PAN Card',0],['Passport Photo',1],['Driving License',0],['Form 29 (Notice of Transfer)',0],['Form 30 (Application for Transfer)',0],['Address Proof',1],['Sale Deed / Agreement',0],['NOC from Financier',0],['Bank Statement / Cancelled Cheque',0]];
        foreach ($types as [$name,$mandatory]) $wpdb->insert($t,['name'=>$name,'is_mandatory'=>$mandatory,'formats'=>'pdf,jpg,png','max_size_mb'=>5,'is_active'=>1]);
    }

    private function automation_rules(): void {
        global $wpdb; $t=$wpdb->prefix.'rto_automation_rules';
        if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $t")>=3) return;
        $rules=[['Notify Client on Lead Created','lead.created','[]','[{"action":"notify","template":"lead_confirmed","channel":"email","recipients":["client"]}]',1],['Notify on Payment','payment.received','[]','[{"action":"notify","template":"payment_received","channel":"email","recipients":["client"]}]',1],['SLA Breach Alert','lead.sla_breach','[]','[{"action":"set_priority","value":"4"},{"action":"notify","template":"sla_warning","channel":"email","recipients":["admin"]}]',1],['Move to Feasibility on Create','lead.created','[]','[{"action":"set_status","value":"feasibility_check"}]',5]];
        foreach ($rules as [$name,$trigger,$cond,$actions,$priority]) $wpdb->insert($t,['name'=>$name,'trigger_event'=>$trigger,'conditions_json'=>$cond,'actions_json'=>$actions,'priority'=>$priority,'is_active'=>1]);
    }

    private function notification_templates(): void {
        global $wpdb; $t=$wpdb->prefix.'rto_notification_templates';
        if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $t")>=3) return;
        $tpls=[['lead_confirmed','email','Request Received — {lead_number}','<p>Dear {client_name}, your request <strong>{lead_number}</strong> for <strong>{service_name}</strong> has been received.</p>'],['payment_received','email','Payment Confirmed — {lead_number}','<p>Payment of {amount} received for {lead_number}. Transaction ID: {txn_id}</p>'],['sla_warning','email','SLA Warning — {lead_number}','<p>Request {lead_number} SLA deadline: {sla_deadline}. Immediate action required.</p>'],['lead_confirmed','whatsapp',null,'Your request {lead_number} for {service_name} has been received. We will update you within 24 hours.'],['payment_received','whatsapp',null,'Payment confirmed for {lead_number}. Amount: {amount}. TXN: {txn_id}']];
        foreach ($tpls as [$slug,$channel,$subject,$body]) $wpdb->insert($t,['slug'=>$slug,'channel'=>$channel,'subject'=>$subject,'body'=>$body,'is_active'=>1]);
    }

    private function holidays(): void {
        global $wpdb; $t=$wpdb->prefix.'rto_holidays';
        if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $t")>0) return;
        $y=date('Y');
        foreach ([["$y-01-26","Republic Day"],["$y-08-15","Independence Day"],["$y-10-02","Gandhi Jayanti"],["$y-12-25","Christmas"]] as [$d,$n]) $wpdb->insert($t,['date'=>$d,'name'=>$n]);
    }
}
