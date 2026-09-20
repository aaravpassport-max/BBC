use crate::api_client::ApiClient;
use crate::dedup::deduplicate;
use crate::email_enrich::find_public_email;
use crate::logging;
use crate::models::{
    depth_to_int, expand_keywords, rate_to_delay, BusinessRow, JobProgress, JobRecord, SearchParams,
};
use chrono::Utc;
use csv::ReaderBuilder;
use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use tauri::{AppHandle, Emitter};
use uuid::Uuid;

pub struct JobRuntime {
    pub progress: Arc<Mutex<Option<JobProgress>>>,
    pub stop: Arc<AtomicBool>,
    pub pause: Arc<AtomicBool>,
}

impl Default for JobRuntime {
    fn default() -> Self {
        Self {
            progress: Arc::new(Mutex::new(None)),
            stop: Arc::new(AtomicBool::new(false)),
            pause: Arc::new(AtomicBool::new(false)),
        }
    }
}

pub async fn run_search_job(
    app: AppHandle,
    storage: Arc<Mutex<crate::storage::Storage>>,
    runtime: Arc<JobRuntime>,
    params: SearchParams,
    resume_job_id: Option<String>,
    api_base: String,
) -> Result<String, String> {
    runtime.stop.store(false, Ordering::SeqCst);
    runtime.pause.store(false, Ordering::SeqCst);

    let settings = {
        let s = storage.lock().map_err(|e| e.to_string())?;
        s.load_settings()
    };

    let job_id = resume_job_id.unwrap_or_else(|| Uuid::new_v4().to_string());
    let now = Utc::now().to_rfc3339();
    let keywords: Vec<String> = expand_keywords(
        &params
            .keywords
            .iter()
            .map(|k| k.trim())
            .filter(|k| !k.is_empty())
            .map(|s| s.to_string())
            .collect::<Vec<_>>(),
        &params.coverage_mode,
    );
    let locations: Vec<String> = params
        .locations
        .iter()
        .map(|l| l.trim())
        .filter(|l| !l.is_empty())
        .map(|s| s.to_string())
        .collect();
    let total = locations.len() as u32;

    if keywords.is_empty() || locations.is_empty() {
        return Err("Add at least one keyword and one location.".into());
    }

    let mut job = JobRecord {
        id: job_id.clone(),
        name: params
            .project_name
            .clone()
            .unwrap_or_else(|| format!("{} — {}", keywords.first().cloned().unwrap_or_default(), locations.first().cloned().unwrap_or_default())),
        keywords_json: serde_json::to_string(&keywords).unwrap_or_default(),
        locations_json: serde_json::to_string(&locations).unwrap_or_default(),
        status: "running".into(),
        result_count: 0,
        duplicates_removed: 0,
        engine_job_ids: String::new(),
        created_at: now.clone(),
        updated_at: now,
        error_message: None,
    };

    {
        let s = storage.lock().map_err(|e| e.to_string())?;
        if s.get_job(&job_id)?.is_none() {
            s.insert_job(&job)?;
        }
    }

    let depth = depth_to_int(&params.depth_label);
    let delay_ms = rate_to_delay(&params.rate_mode);
    let max_time = settings.timeout_secs as i64;
    let email = params.email_extraction;
    let fast_mode = params.depth_label.eq_ignore_ascii_case("fast");
    let api = ApiClient::new(&api_base)?;
    let mut engine_ok = false;
    for attempt in 1..=20 {
        if api.health().await.is_ok() {
            engine_ok = true;
            break;
        }
        if attempt == 1 {
            logging::warn("Search engine not ready yet; waiting before starting job");
        }
        tokio::time::sleep(std::time::Duration::from_secs(2)).await;
    }
    if !engine_ok {
        return Err(
            "Search engine is not running. Wait until the app finishes starting, then try again."
                .into(),
        );
    }

    let started = std::time::Instant::now();
    let mut all_rows: Vec<BusinessRow> = Vec::new();
    let mut engine_ids = Vec::new();
    let mut step_errors: Vec<String> = Vec::new();
    let mut batch_lines: Vec<crate::models::BatchLine> = locations
        .iter()
        .map(|l| crate::models::BatchLine {
            location: l.clone(),
            status: "Waiting".into(),
        })
        .collect();

    for (idx, location) in locations.iter().enumerate() {
        if runtime.stop.load(Ordering::SeqCst) {
            job.status = "stopped".into();
            break;
        }
        while runtime.pause.load(Ordering::SeqCst) {
            tokio::time::sleep(std::time::Duration::from_millis(400)).await;
            if runtime.stop.load(Ordering::SeqCst) {
                break;
            }
        }

        let api_keywords: Vec<String> = keywords
            .iter()
            .map(|k| format!("{k} in {location}, India"))
            .collect();
        let query_label = keywords.first().cloned().unwrap_or_default();
        update_progress(
            &app,
            &runtime,
            &job_id,
            location,
            &format!("{} (+{} more)", query_label, keywords.len().saturating_sub(1)),
            idx as u32,
            total,
            all_rows.len() as u32,
            all_rows.len() as u32,
            "",
            started.elapsed().as_secs(),
            &batch_lines,
            false,
        );

        if let Some(line) = batch_lines.iter_mut().find(|b| b.location == *location) {
            line.status = "Running".into();
        }

        let geocode_query = if location.contains(',') {
            location.clone()
        } else {
            format!("{location}, India")
        };
        let coords = {
            let db_hit = {
                let guard = storage.lock().map_err(|e| e.to_string())?;
                crate::geocode::coords_from_location_db(guard.db(), &geocode_query)
            };
            if let Some(c) = db_hit {
                logging::info(&format!("Geocoding (location database): {geocode_query}"));
                c
            } else {
                match crate::geocode::geocode_place(&geocode_query).await {
                    Ok(c) => c,
                    Err(e) => {
                        logging::warn(&e);
                        step_errors.push(format!(
                            "Could not locate \"{location}\" (check spelling and internet): {e}"
                        ));
                        if let Some(line) = batch_lines.iter_mut().find(|b| b.location == *location) {
                            line.status = "Failed".into();
                        }
                        continue;
                    }
                }
            }
        };

        tokio::time::sleep(std::time::Duration::from_millis(delay_ms)).await;

        let engine_id = match api
            .create_job(
                &job.name,
                api_keywords.clone(),
                &coords.0,
                &coords.1,
                depth,
                fast_mode,
                email,
                max_time,
            )
            .await
        {
            Ok(id) => id,
            Err(e) => {
                if e.to_lowercase().contains("captcha") || e.to_lowercase().contains("blocked") {
                    update_progress(
                        &app,
                        &runtime,
                        &job_id,
                        location,
                        &query_label,
                        idx as u32,
                        total,
                        all_rows.len() as u32,
                        all_rows.len() as u32,
                        "",
                        started.elapsed().as_secs(),
                        &batch_lines,
                        true,
                    );
                    job.status = "paused".into();
                    job.error_message = Some(
                        "Google Maps requires additional verification. Try again later.".into(),
                    );
                    break;
                }
                logging::error(&e);
                step_errors.push(format!("Could not start search for \"{location}\": {e}"));
                if let Some(line) = batch_lines.iter_mut().find(|b| b.location == *location) {
                    line.status = "Failed".into();
                }
                continue;
            }
        };
        engine_ids.push(engine_id.clone());

        let mut status = "working".to_string();
        let mut last_title = String::new();
        for poll in 0..450 {
            if runtime.stop.load(Ordering::SeqCst) {
                break;
            }
            tokio::time::sleep(std::time::Duration::from_secs(8)).await;
            status = api
                .poll_status(&engine_id)
                .await
                .unwrap_or_else(|_| "failed".into())
                .trim()
                .to_lowercase();
            if poll == 15 && status == "working" && all_rows.is_empty() {
                let _ = app.emit(
                    "job-progress-hint",
                    "Preparing the built-in browser (first search can take 5–15 minutes). Keep the app open.",
                );
            }
            update_progress(
                &app,
                &runtime,
                &job_id,
                location,
                &query_label,
                idx as u32,
                total,
                all_rows.len() as u32,
                all_rows.len() as u32,
                &last_title,
                started.elapsed().as_secs(),
                &batch_lines,
                false,
            );
            if status == "ok" {
                break;
            }
            if status == "failed" {
                job.error_message = Some(
                    "Search could not be completed. Try a broader term, another location, or lower coverage."
                        .into(),
                );
                break;
            }
        }

        if status == "working" {
            job.error_message = Some(
                "The search took too long to finish. If this is your first run, wait a few minutes and try again with Fast coverage."
                    .into(),
            );
        }

        if status == "ok" {
            match api.download_csv(&engine_id).await {
                Ok(csv) => {
                    let mut parsed = parse_csv_rows(&csv, &job_id, &query_label, location);
                    if parsed.is_empty() {
                        step_errors.push(format!(
                            "Search for \"{location}\" finished with 0 listings. Try Fast depth, a simpler keyword (e.g. \"coffee shop\"), close other Maps scraper tools, and wait 10–15 minutes on first launch while the browser prepares."
                        ));
                    }
                    if email {
                        for row in &mut parsed {
                            if row.email.is_empty() && !row.website.is_empty() {
                                if let Some(em) = find_public_email(&row.website).await {
                                    row.email = em;
                                }
                            }
                            if !row.title.is_empty() {
                                last_title = row.title.clone();
                            }
                        }
                    }
                    all_rows.extend(parsed);
                }
                Err(e) => {
                    logging::warn(&e);
                    step_errors.push(format!("Could not download results for \"{location}\": {e}"));
                }
            }
        }

        if let Some(line) = batch_lines.iter_mut().find(|b| b.location == *location) {
            line.status = if status == "ok" {
                "Complete".into()
            } else {
                "Failed".into()
            };
        }
    }

    let mut dupes = 0u32;
    if settings.deduplication {
        let (uniq, d) = deduplicate(all_rows);
        dupes = d;
        all_rows = uniq;
    }

    job.result_count = all_rows.len() as i64;
    job.duplicates_removed = dupes as i64;
    job.engine_job_ids = engine_ids.join(",");
    job.updated_at = Utc::now().to_rfc3339();
    if job.status == "running" {
        job.status = "completed".into();
    }

    {
        let s = storage.lock().map_err(|e| e.to_string())?;
        s.replace_businesses(&job_id, &all_rows)?;
        s.update_job(&job)?;
    }

    update_progress(
        &app,
        &runtime,
        &job_id,
        "",
        "",
        total,
        total,
        all_rows.len() as u32,
        all_rows.len() as u32,
        "",
        started.elapsed().as_secs(),
        &batch_lines,
        false,
    );

    if job.result_count == 0 && job.error_message.is_none() {
        job.error_message = Some(if step_errors.is_empty() {
            "No businesses were returned. Use the latest installer (with bundled browser), set depth to Fast, try a common keyword, and keep the app open 10–15 minutes on first run. Open logs from Settings if it persists."
                .into()
        } else {
            step_errors.join(" ")
        });
        let s = storage.lock().map_err(|e| e.to_string())?;
        s.update_job(&job)?;
    }

    let _ = app.emit(
        "job-completed",
        serde_json::json!({
            "jobId": job_id,
            "resultCount": job.result_count,
            "errorMessage": job.error_message,
        }),
    );
    Ok(job_id)
}

fn parse_csv_rows(csv: &str, job_id: &str, keyword: &str, location: &str) -> Vec<BusinessRow> {
    let mut rdr = ReaderBuilder::new().has_headers(true).from_reader(csv.as_bytes());
    let headers = rdr.headers().ok().cloned().unwrap_or_default();
    let idx = |name: &str| headers.iter().position(|h| h == name);
    let mut out = Vec::new();
    let ts = Utc::now().to_rfc3339();
    for rec in rdr.records().flatten() {
        let get = |name: &str| {
            idx(name)
                .and_then(|i| rec.get(i))
                .unwrap_or("")
                .to_string()
        };
        let mut city = location.to_string();
        let mut state = String::new();
        let mut country = "India".to_string();
        if let Some(ca) = idx("complete_address") {
            if let Some(raw) = rec.get(ca) {
                if let Ok(v) = serde_json::from_str::<HashMap<String, String>>(raw) {
                    city = v.get("city").cloned().unwrap_or(city);
                    state = v.get("state").cloned().unwrap_or_default();
                    country = v.get("country").cloned().unwrap_or(country);
                }
            }
        }
        let row_keyword = ["keyword", "input", "query"]
            .iter()
            .find_map(|col| {
                idx(col).and_then(|i| rec.get(i)).filter(|s| !s.is_empty())
            })
            .unwrap_or(keyword)
            .to_string();
        out.push(BusinessRow {
            id: Uuid::new_v4().to_string(),
            job_id: job_id.to_string(),
            place_id: get("place_id"),
            title: get("title"),
            category: get("category"),
            address: get("address"),
            city,
            state,
            country,
            phone: get("phone"),
            website: get("website"),
            email: get("emails"),
            review_rating: get("review_rating"),
            review_count: get("review_count"),
            maps_url: get("link"),
            latitude: get("latitude"),
            longitude: get("longitude"),
            open_hours: get("open_hours"),
            description: get("descriptions"),
            search_keyword: row_keyword,
            search_location: location.to_string(),
            scraped_at: ts.clone(),
            status: get("status"),
        });
    }
    out
}

#[allow(clippy::too_many_arguments)]
fn update_progress(
    app: &AppHandle,
    runtime: &JobRuntime,
    job_id: &str,
    location: &str,
    query: &str,
    completed: u32,
    total: u32,
    discovered: u32,
    processed: u32,
    current: &str,
    elapsed: u64,
    batch: &[crate::models::BatchLine],
    captcha: bool,
) {
    let est = if completed > 0 {
        Some(((elapsed as f64 / completed as f64) * (total - completed) as f64) as u64)
    } else {
        None
    };
    let p = JobProgress {
        job_id: job_id.to_string(),
        phase: if captcha {
            "captcha".into()
        } else {
            "searching".into()
        },
        location: location.into(),
        query: query.into(),
        completed_units: completed,
        total_units: total,
        businesses_discovered: discovered,
        businesses_processed: processed,
        current_business: current.into(),
        elapsed_secs: elapsed,
        estimated_remaining_secs: est,
        paused: runtime.pause.load(Ordering::SeqCst),
        captcha_pause: captcha,
        batch_items: batch.to_vec(),
    };
    if let Ok(mut g) = runtime.progress.lock() {
        *g = Some(p.clone());
    }
    let _ = app.emit("job-progress", p);
}
