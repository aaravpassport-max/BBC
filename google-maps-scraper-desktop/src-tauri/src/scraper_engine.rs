use crate::logging;
use std::path::{Path, PathBuf};
use std::sync::Mutex;
use tauri::{AppHandle, Emitter, Manager};
use tauri::path::BaseDirectory;
use tauri_plugin_shell::process::CommandEvent;
use tauri_plugin_shell::ShellExt;

/// Avoid clashing with Docker/old kit scrapers on :8080.
const ENGINE_PORT: u16 = 8765;

pub struct EngineState {
    pub data_dir: PathBuf,
    pub ready: bool,
    pub api_base: String,
}

impl EngineState {
    pub fn new(data_dir: PathBuf) -> Self {
        Self {
            data_dir,
            ready: false,
            api_base: format!("http://127.0.0.1:{ENGINE_PORT}"),
        }
    }
}

fn playwright_has_chromium(dir: &Path) -> bool {
    fs_read_dir(dir).map(|entries| {
        entries.flatten().any(|e| {
            e.file_name()
                .to_string_lossy()
                .starts_with("chromium")
        })
    }).unwrap_or(false)
}

fn fs_read_dir(dir: &Path) -> std::io::Result<std::fs::ReadDir> {
    std::fs::read_dir(dir)
}

fn copy_dir_all(src: &Path, dst: &Path) -> Result<(), String> {
    std::fs::create_dir_all(dst).map_err(|e| e.to_string())?;
    for entry in std::fs::read_dir(src).map_err(|e| e.to_string())? {
        let entry = entry.map_err(|e| e.to_string())?;
        let target = dst.join(entry.file_name());
        if entry.file_type().map_err(|e| e.to_string())?.is_dir() {
            copy_dir_all(&entry.path(), &target)?;
        } else {
            std::fs::copy(entry.path(), target).map_err(|e| e.to_string())?;
        }
    }
    Ok(())
}

/// Playwright must be on a writable path (install dir can be read-only on Windows).
fn prepare_playwright_browsers(app: &AppHandle, data_dir: &Path) -> Option<PathBuf> {
    let bundled = app.path().resolve("ms-playwright", BaseDirectory::Resource).ok()?;
    if !playwright_has_chromium(&bundled) {
        logging::warn(&format!(
            "Bundled browser runtime missing or incomplete at {}",
            bundled.display()
        ));
        return None;
    }
    let local = data_dir.join("ms-playwright");
    let marker = local.join(".copied-from-bundle");
    if playwright_has_chromium(&local) && marker.exists() {
        return Some(local);
    }
    logging::info("Preparing built-in browser runtime (one-time copy, may take a minute)");
    let _ = std::fs::remove_dir_all(&local);
    if copy_dir_all(&bundled, &local).is_err() {
        logging::warn("Could not copy browser runtime to user data; using install folder");
        return Some(bundled);
    }
    let _ = std::fs::write(marker, "1");
    logging::info(&format!("Browser runtime ready at {}", local.display()));
    Some(local)
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

    #[cfg(target_os = "windows")]
    {
        let _ = std::process::Command::new("taskkill")
            .args(["/F", "/IM", "scraper-engine.exe", "/T"])
            .output();
    }

    logging::info("Starting bundled search engine");
    let mut sidecar = app
        .shell()
        .sidecar("scraper-engine")
        .map_err(|e| format!("Bundled search engine is missing. Please reinstall the application. ({e})"))?;

    if let Some(pw) = prepare_playwright_browsers(app, &data_dir) {
        sidecar = sidecar
            .env("PLAYWRIGHT_BROWSERS_PATH", pw.to_string_lossy().to_string())
            .env("PLAYWRIGHT_SKIP_VALIDATE_HOST_REQUIREMENTS", "1");
        logging::info(&format!("PLAYWRIGHT_BROWSERS_PATH={}", pw.display()));
    } else {
        logging::warn(
            "Built-in Chromium was not found in this install. Reinstall from the latest GoogleMapsScraper-Setup.exe (build 8+).",
        );
    }

    let listen_addr = format!("127.0.0.1:{ENGINE_PORT}");
    let jobs_url = format!("http://{listen_addr}/api/v1/jobs");

    let (mut rx, _child) = sidecar
        .args(["-web", "-addr", &listen_addr, "-data-folder"])
        .args([data_dir.to_string_lossy().to_string()])
        .spawn()
        .map_err(|e| format!("Could not start search engine: {e}"))?;

    tauri::async_runtime::spawn(async move {
        while let Some(event) = rx.recv().await {
            match event {
                CommandEvent::Error(e) => logging::error(&format!("Engine process error: {e}")),
                CommandEvent::Stdout(line) => {
                    logging::info(&format!("[engine] {}", String::from_utf8_lossy(&line)));
                }
                CommandEvent::Stderr(line) => {
                    logging::warn(&format!("[engine] {}", String::from_utf8_lossy(&line)));
                }
                _ => {}
            }
        }
    });

    let client = reqwest::Client::new();
    for attempt in 1..=120 {
        tokio::time::sleep(std::time::Duration::from_secs(1)).await;
        if client
            .get(&jobs_url)
            .send()
            .await
            .map(|r| r.status().is_success())
            .unwrap_or(false)
        {
            let mut s = state.lock().map_err(|e| e.to_string())?;
            s.ready = true;
            s.api_base = format!("http://{listen_addr}");
            logging::info(&format!("Search engine ready at {}", s.api_base));
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
            "The bundled browser/search engine is missing from this installation.".to_string()
        })?;
    if let Ok(pw) = app.path().resolve("ms-playwright", BaseDirectory::Resource) {
        if !playwright_has_chromium(&pw) {
            return Err(
                "This install is missing the bundled Chromium browser (~200 MB). Download the latest GoogleMapsScraper-Setup.exe (not an older ~28 MB build) and reinstall."
                    .to_string(),
            );
        }
    }
    Ok(())
}
