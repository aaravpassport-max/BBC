use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct AppSettings {
    pub welcome_done: bool,
    pub responsible_use_ack: bool,
    pub default_depth: String,
    pub default_coverage: String,
    pub default_rate: String,
    pub export_folder: String,
    pub email_extraction: bool,
    pub deduplication: bool,
    pub request_delay_ms: u64,
    pub concurrency: u32,
    pub timeout_secs: u64,
    pub retry_count: u32,
    pub telemetry: bool,
}

impl Default for AppSettings {
    fn default() -> Self {
        Self {
            welcome_done: false,
            responsible_use_ack: false,
            default_depth: "balanced".into(),
            default_coverage: "standard".into(),
            default_rate: "balanced".into(),
            export_folder: String::new(),
            email_extraction: false,
            deduplication: true,
            request_delay_ms: 1000,
            concurrency: 1,
            timeout_secs: 600,
            retry_count: 2,
            telemetry: false,
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SearchParams {
    pub keywords: Vec<String>,
    pub locations: Vec<String>,
    pub depth_label: String,
    pub coverage_mode: String,
    pub rate_mode: String,
    pub email_extraction: bool,
    pub fields: Vec<String>,
    pub project_name: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct JobRecord {
    pub id: String,
    pub name: String,
    pub keywords_json: String,
    pub locations_json: String,
    pub status: String,
    pub result_count: i64,
    pub duplicates_removed: i64,
    pub engine_job_ids: String,
    pub created_at: String,
    pub updated_at: String,
    pub error_message: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct BusinessRow {
    pub id: String,
    pub job_id: String,
    pub place_id: String,
    pub title: String,
    pub category: String,
    pub address: String,
    pub city: String,
    pub state: String,
    pub country: String,
    pub phone: String,
    pub website: String,
    pub email: String,
    pub review_rating: String,
    pub review_count: String,
    pub maps_url: String,
    pub latitude: String,
    pub longitude: String,
    pub open_hours: String,
    pub description: String,
    pub search_keyword: String,
    pub search_location: String,
    pub scraped_at: String,
    pub status: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct JobProgress {
    pub job_id: String,
    pub phase: String,
    pub location: String,
    pub query: String,
    pub completed_units: u32,
    pub total_units: u32,
    pub businesses_discovered: u32,
    pub businesses_processed: u32,
    pub current_business: String,
    pub elapsed_secs: u64,
    pub estimated_remaining_secs: Option<u64>,
    pub paused: bool,
    pub captcha_pause: bool,
    pub batch_items: Vec<BatchLine>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct BatchLine {
    pub location: String,
    pub status: String,
}

pub fn depth_to_int(label: &str) -> i32 {
    match label.to_lowercase().as_str() {
        "fast" => 3,
        "deep" => 10,
        "very deep" | "very_deep" | "verydeep" => 20,
        _ => 5,
    }
}

pub fn rate_to_delay(label: &str) -> u64 {
    match label.to_lowercase().as_str() {
        "conservative" => 2000,
        "faster" => 500,
        _ => 1000,
    }
}

pub fn expand_keywords(keywords: &[String], coverage: &str) -> Vec<String> {
    if coverage != "small-city coverage" && coverage != "small_city" && coverage != "small-city" {
        return keywords.to_vec();
    }
    let mut out = Vec::new();
    for k in keywords {
        out.push(k.clone());
        let base = k.trim();
        if !base.is_empty() {
            out.push(format!("{base} services"));
            out.push(format!("{base} agent"));
        }
    }
    out.sort();
    out.dedup();
    out
}

pub fn na(s: &str) -> String {
    if s.trim().is_empty() {
        "Not available".to_string()
    } else {
        s.to_string()
    }
}
