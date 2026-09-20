use crate::logging;
use std::path::PathBuf;
use std::sync::Mutex;
use tauri::{AppHandle, Emitter, Manager};
use tauri::path::BaseDirectory;
use tauri_plugin_shell::process::CommandEvent;
use tauri_plugin_shell::ShellExt;

pub struct EngineState {
    pub data_dir: PathBuf,
    pub ready: bool,
}

impl EngineState {
    pub fn new(data_dir: PathBuf) -> Self {
        Self {
            data_dir,
            ready: false,
        }
    }
}

pub async fn ensure_engine(app: &AppHandle, state: &Mutex<EngineState>) -> Result<(), String> {
    {
        let s = state.lock().map_err(|e| e.to_string())?;
        if s.ready {
            return Ok(());
        }
    }

    let data_dir = {
        let s = state.lock().map_err(|e| e.to_string())?;
        s.data_dir.clone()
    };
    std::fs::create_dir_all(&data_dir).map_err(|e| e.to_string())?;

    logging::info("Starting bundled search engine");
    let mut sidecar = app
        .shell()
        .sidecar("scraper-engine")
        .map_err(|e| format!("Bundled search engine is missing. Please reinstall the application. ({e})"))?;

    if let Ok(pw) = app.path().resolve("ms-playwright", BaseDirectory::Resource) {
        if pw.exists() {
            logging::info(&format!("Using bundled browser runtime at {}", pw.display()));
            sidecar = sidecar.env("PLAYWRIGHT_BROWSERS_PATH", pw.to_string_lossy().to_string());
        }
    }

    let (mut rx, _child) = sidecar
        .args(["-web", "-data-folder"])
        .args([data_dir.to_string_lossy().to_string()])
        .spawn()
        .map_err(|e| format!("Could not start search engine: {e}"))?;

    tauri::async_runtime::spawn(async move {
        while let Some(event) = rx.recv().await {
            if let CommandEvent::Error(e) = event {
                logging::error(&format!("Engine process error: {e}"));
            }
        }
    });

    let client = reqwest::Client::new();
    for attempt in 1..=90 {
        tokio::time::sleep(std::time::Duration::from_secs(1)).await;
        if client
            .get("http://127.0.0.1:8080/api/v1/jobs")
            .send()
            .await
            .map(|r| r.status().is_success())
            .unwrap_or(false)
        {
            let mut s = state.lock().map_err(|e| e.to_string())?;
            s.ready = true;
            logging::info("Search engine ready");
            let _ = app.emit("engine-ready", ());
            return Ok(());
        }
        if attempt == 60 {
            logging::warn("Search engine still starting (browser runtime may take a minute on first launch)");
        }
    }
    Err("The search engine did not become ready in time. Please wait and try again, or reinstall.".into())
}

pub fn validate_bundled_engine(app: &AppHandle) -> Result<(), String> {
    app.shell()
        .sidecar("scraper-engine")
        .map(|_| ())
        .map_err(|_| {
            "The bundled browser/search engine is missing from this installation.".into()
        })
}
