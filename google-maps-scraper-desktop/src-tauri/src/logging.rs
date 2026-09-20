use chrono::Local;
use std::fs::{self, OpenOptions};
use std::io::Write;
use std::path::PathBuf;
use std::sync::Mutex;

static LOG_DIR: Mutex<Option<PathBuf>> = Mutex::new(None);

pub fn init_logs(dir: PathBuf) {
    let _ = fs::create_dir_all(&dir);
    *LOG_DIR.lock().unwrap() = Some(dir);
}

pub fn log_line(level: &str, message: &str) {
    let line = format!(
        "{} [{}] {}\n",
        Local::now().format("%Y-%m-%d %H:%M:%S"),
        level,
        message
    );
    if let Some(dir) = LOG_DIR.lock().unwrap().as_ref() {
        let file = dir.join(format!("{}.log", Local::now().format("%Y-%m-%d")));
        if let Ok(mut f) = OpenOptions::new().create(true).append(true).open(file) {
            let _ = f.write_all(line.as_bytes());
        }
    }
}

pub fn info(msg: &str) {
    log_line("INFO", msg);
}

pub fn warn(msg: &str) {
    log_line("WARN", msg);
}

pub fn error(msg: &str) {
    log_line("ERROR", msg);
}
