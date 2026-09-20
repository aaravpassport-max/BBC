use crate::export::{export_csv, export_json, export_xlsx, safe_filename};
use crate::india_locations;
use crate::jobs::{run_search_job, JobRuntime};
use crate::models::{AppSettings, BusinessRow, JobProgress, JobRecord, SearchParams};
use crate::scraper_engine::{ensure_engine, validate_bundled_engine, EngineState};
use crate::storage::Storage;
use chrono::Local;
use std::sync::{Arc, Mutex};
use tauri::{AppHandle, Emitter, State};

pub struct AppState {
    pub storage: Arc<Mutex<Storage>>,
    pub engine: Arc<Mutex<EngineState>>,
    pub runtime: Arc<JobRuntime>,
}

#[derive(serde::Serialize)]
#[serde(rename_all = "camelCase")]
pub struct StartupReport {
    pub ok: bool,
    pub message: String,
    pub data_root: String,
}

#[tauri::command]
pub fn startup_check(app: AppHandle, state: State<'_, AppState>) -> StartupReport {
    let root = state.storage.lock().map(|s| s.root.clone()).unwrap_or_default();
    match validate_bundled_engine(&app) {
        Ok(()) => StartupReport {
            ok: true,
            message: "Ready".into(),
            data_root: root.to_string_lossy().to_string(),
        },
        Err(msg) => StartupReport {
            ok: false,
            message: msg,
            data_root: root.to_string_lossy().to_string(),
        },
    }
}

#[tauri::command]
pub async fn ensure_search_engine(app: AppHandle, state: State<'_, AppState>) -> Result<(), String> {
    ensure_engine(&app, &state.engine).await
}

#[tauri::command]
pub fn get_settings(state: State<'_, AppState>) -> Result<AppSettings, String> {
    state
        .storage
        .lock()
        .map(|s| s.load_settings())
        .map_err(|e| e.to_string())
}

#[tauri::command]
pub fn save_settings(settings: AppSettings, state: State<'_, AppState>) -> Result<(), String> {
    state
        .storage
        .lock()
        .map_err(|e| e.to_string())?
        .save_settings(&settings)
}

#[tauri::command]
pub fn list_job_history(state: State<'_, AppState>) -> Result<Vec<JobRecord>, String> {
    state
        .storage
        .lock()
        .map_err(|e| e.to_string())?
        .list_jobs()
}

#[tauri::command]
pub fn get_job_results(job_id: String, state: State<'_, AppState>) -> Result<Vec<BusinessRow>, String> {
    state
        .storage
        .lock()
        .map_err(|e| e.to_string())?
        .list_businesses(&job_id)
}

#[tauri::command]
pub fn get_job_progress(state: State<'_, AppState>) -> Result<Option<JobProgress>, String> {
    state
        .runtime
        .progress
        .lock()
        .map(|p| p.clone())
        .map_err(|e| e.to_string())
}

#[tauri::command]
pub async fn start_search(
    app: AppHandle,
    state: State<'_, AppState>,
    params: SearchParams,
) -> Result<String, String> {
    ensure_engine(&app, &state.engine).await?;
    let storage = state.storage.clone();
    let runtime = state.runtime.clone();
    tauri::async_runtime::spawn(async move {
        if let Err(e) = run_search_job(app.clone(), storage, runtime, params, None).await {
            let _ = app.emit("job-error", e);
        }
    });
    Ok("started".into())
}

#[tauri::command]
pub fn pause_search(state: State<'_, AppState>) -> Result<(), String> {
    state.runtime.pause.store(true, std::sync::atomic::Ordering::SeqCst);
    Ok(())
}

#[tauri::command]
pub fn resume_search(state: State<'_, AppState>) -> Result<(), String> {
    state.runtime.pause.store(false, std::sync::atomic::Ordering::SeqCst);
    Ok(())
}

#[tauri::command]
pub fn stop_search(state: State<'_, AppState>) -> Result<(), String> {
    state.runtime.stop.store(true, std::sync::atomic::Ordering::SeqCst);
    Ok(())
}

#[tauri::command]
pub fn discover_cities(state_name: String) -> Vec<String> {
    india_locations::cities_for_state(&state_name)
}

#[tauri::command]
pub fn list_states() -> Vec<String> {
    india_locations::states()
        .into_iter()
        .map(|s| s.name)
        .collect()
}

#[tauri::command]
pub fn delete_job(job_id: String, state: State<'_, AppState>) -> Result<(), String> {
    state
        .storage
        .lock()
        .map_err(|e| e.to_string())?
        .delete_job(&job_id)
}

#[tauri::command]
pub fn clear_all_data(state: State<'_, AppState>) -> Result<(), String> {
    state
        .storage
        .lock()
        .map_err(|e| e.to_string())?
        .clear_all_results()
}

#[tauri::command]
pub fn get_unfinished_job(state: State<'_, AppState>) -> Result<Option<JobRecord>, String> {
    state
        .storage
        .lock()
        .map_err(|e| e.to_string())?
        .find_unfinished_job()
}

#[tauri::command]
pub async fn discard_unfinished_job(
    job_id: String,
    state: State<'_, AppState>,
) -> Result<(), String> {
    let mut job = state
        .storage
        .lock()
        .map_err(|e| e.to_string())?
        .get_job(&job_id)?
        .ok_or_else(|| "Job not found".to_string())?;
    job.status = "stopped".into();
    job.updated_at = chrono::Utc::now().to_rfc3339();
    state
        .storage
        .lock()
        .map_err(|e| e.to_string())?
        .update_job(&job)
}

#[tauri::command]
pub fn open_logs_folder(state: State<'_, AppState>) -> Result<String, String> {
    let dir = state
        .storage
        .lock()
        .map_err(|e| e.to_string())?
        .logs_dir();
    Ok(dir.to_string_lossy().to_string())
}

#[tauri::command]
pub fn export_job(
    job_id: String,
    format: String,
    state: State<'_, AppState>,
) -> Result<String, String> {
    let storage = state.storage.lock().map_err(|e| e.to_string())?;
    let settings = storage.load_settings();
    let job = storage
        .get_job(&job_id)?
        .ok_or_else(|| "Search not found.".to_string())?;
    let rows = storage.list_businesses(&job_id)?;
    let date = Local::now().format("%Y-%m-%d").to_string();
    let base = safe_filename(&format!("{}_{}", job.name, date));
    let path = match format.as_str() {
        "xlsx" => export_xlsx(&settings.export_folder, &format!("{base}.xlsx"), &rows)?,
        "json" => export_json(&settings.export_folder, &format!("{base}.json"), &rows)?,
        _ => export_csv(&settings.export_folder, &format!("{base}.csv"), &rows)?,
    };
    Ok(path.to_string_lossy().to_string())
}
