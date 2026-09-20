mod api_client;
mod commands;
mod dedup;
mod email_enrich;
mod export;
mod geocode;
mod india_locations;
mod jobs;
mod logging;
mod models;
mod scraper_engine;
mod storage;

use commands::AppState;
use jobs::JobRuntime;
use scraper_engine::EngineState;
use std::sync::{Arc, Mutex};
use storage::{default_data_root, Storage};

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    let data_root = default_data_root();
    logging::init_logs(data_root.join("logs"));
    logging::info("Application startup");

    let storage = Storage::open(data_root.clone()).expect("Failed to open local storage");
    let engine_dir = data_root.join("engine-data");

    tauri::Builder::default()
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_shell::init())
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_fs::init())
        .manage(AppState {
            storage: Arc::new(Mutex::new(storage)),
            engine: Arc::new(Mutex::new(EngineState::new(engine_dir))),
            runtime: Arc::new(JobRuntime::default()),
        })
        .invoke_handler(tauri::generate_handler![
            commands::startup_check,
            commands::ensure_search_engine,
            commands::get_settings,
            commands::save_settings,
            commands::list_job_history,
            commands::get_job_results,
            commands::get_job_progress,
            commands::start_search,
            commands::pause_search,
            commands::resume_search,
            commands::stop_search,
            commands::discover_cities,
            commands::list_states,
            commands::delete_job,
            commands::clear_all_data,
            commands::open_logs_folder,
            commands::open_exports_folder,
            commands::reveal_export_file,
            commands::export_suggested_filename,
            commands::export_job,
            commands::get_unfinished_job,
            commands::discard_unfinished_job,
        ])
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
