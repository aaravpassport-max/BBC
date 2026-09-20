use crate::models::{AppSettings, BusinessRow, JobRecord};
use rusqlite::{params, Connection};
use std::path::{Path, PathBuf};

pub struct Storage {
    pub root: PathBuf,
    db: Connection,
}

impl Storage {
    pub fn open(root: PathBuf) -> Result<Self, String> {
        for sub in ["config", "logs", "jobs", "results", "cache", "engine-data"] {
            std::fs::create_dir_all(root.join(sub)).map_err(|e| e.to_string())?;
        }
        let db_path = root.join("config").join("app.db");
        let db = Connection::open(&db_path).map_err(|e| e.to_string())?;
        let s = Self { root, db };
        s.migrate()?;
        Ok(s)
    }

    fn migrate(&self) -> Result<(), String> {
        self.db
            .execute_batch(
                "
            CREATE TABLE IF NOT EXISTS settings (
              key TEXT PRIMARY KEY,
              value TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS jobs (
              id TEXT PRIMARY KEY,
              name TEXT NOT NULL,
              keywords_json TEXT NOT NULL,
              locations_json TEXT NOT NULL,
              status TEXT NOT NULL,
              result_count INTEGER NOT NULL DEFAULT 0,
              duplicates_removed INTEGER NOT NULL DEFAULT 0,
              engine_job_ids TEXT NOT NULL DEFAULT '',
              created_at TEXT NOT NULL,
              updated_at TEXT NOT NULL,
              error_message TEXT
            );
            CREATE TABLE IF NOT EXISTS businesses (
              id TEXT PRIMARY KEY,
              job_id TEXT NOT NULL,
              place_id TEXT,
              title TEXT,
              category TEXT,
              address TEXT,
              city TEXT,
              state TEXT,
              country TEXT,
              phone TEXT,
              website TEXT,
              email TEXT,
              review_rating TEXT,
              review_count TEXT,
              maps_url TEXT,
              latitude TEXT,
              longitude TEXT,
              open_hours TEXT,
              description TEXT,
              search_keyword TEXT,
              search_location TEXT,
              scraped_at TEXT,
              status TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_businesses_job ON businesses(job_id);
            ",
            )
            .map_err(|e| e.to_string())?;
        Ok(())
    }

    pub fn logs_dir(&self) -> PathBuf {
        self.root.join("logs")
    }

    pub fn load_settings(&self) -> AppSettings {
        let mut settings = AppSettings::default();
        if settings.export_folder.is_empty() {
            settings.export_folder = self.root.join("results").to_string_lossy().to_string();
        }
        let Ok(mut stmt) = self.db.prepare("SELECT key, value FROM settings") else {
            return settings;
        };
        let rows = stmt
            .query_map([], |row| Ok((row.get::<_, String>(0)?, row.get::<_, String>(1)?)))
            .ok();
        if let Some(rows) = rows {
            for row in rows.flatten() {
                apply_setting(&mut settings, &row.0, &row.1);
            }
        }
        settings
    }

    pub fn save_settings(&self, settings: &AppSettings) -> Result<(), String> {
        let pairs = settings_to_pairs(settings);
        for (k, v) in pairs {
            self.db
                .execute(
                    "INSERT INTO settings(key,value) VALUES(?1,?2) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
                    params![k, v],
                )
                .map_err(|e| e.to_string())?;
        }
        Ok(())
    }

    pub fn insert_job(&self, job: &JobRecord) -> Result<(), String> {
        self.db
            .execute(
                "INSERT INTO jobs(id,name,keywords_json,locations_json,status,result_count,duplicates_removed,engine_job_ids,created_at,updated_at,error_message)
                 VALUES(?1,?2,?3,?4,?5,?6,?7,?8,?9,?10,?11)",
                params![
                    job.id,
                    job.name,
                    job.keywords_json,
                    job.locations_json,
                    job.status,
                    job.result_count,
                    job.duplicates_removed,
                    job.engine_job_ids,
                    job.created_at,
                    job.updated_at,
                    job.error_message
                ],
            )
            .map_err(|e| e.to_string())?;
        Ok(())
    }

    pub fn update_job(&self, job: &JobRecord) -> Result<(), String> {
        self.db
            .execute(
                "UPDATE jobs SET status=?2, result_count=?3, duplicates_removed=?4, engine_job_ids=?5, updated_at=?6, error_message=?7 WHERE id=?1",
                params![
                    job.id,
                    job.status,
                    job.result_count,
                    job.duplicates_removed,
                    job.engine_job_ids,
                    job.updated_at,
                    job.error_message
                ],
            )
            .map_err(|e| e.to_string())?;
        Ok(())
    }

    pub fn list_jobs(&self) -> Result<Vec<JobRecord>, String> {
        let mut stmt = self
            .db
            .prepare("SELECT id,name,keywords_json,locations_json,status,result_count,duplicates_removed,engine_job_ids,created_at,updated_at,error_message FROM jobs ORDER BY created_at DESC")
            .map_err(|e| e.to_string())?;
        let rows = stmt
            .query_map([], map_job)
            .map_err(|e| e.to_string())?;
        rows.collect::<Result<Vec<_>, _>>().map_err(|e| e.to_string())
    }

    pub fn get_job(&self, id: &str) -> Result<Option<JobRecord>, String> {
        let mut stmt = self
            .db
            .prepare("SELECT id,name,keywords_json,locations_json,status,result_count,duplicates_removed,engine_job_ids,created_at,updated_at,error_message FROM jobs WHERE id=?1")
            .map_err(|e| e.to_string())?;
        let mut rows = stmt.query(params![id]).map_err(|e| e.to_string())?;
        if let Some(row) = rows.next().map_err(|e| e.to_string())? {
            return Ok(Some(map_job(row)?));
        }
        Ok(None)
    }

    pub fn delete_job(&self, id: &str) -> Result<(), String> {
        self.db
            .execute("DELETE FROM businesses WHERE job_id=?1", params![id])
            .map_err(|e| e.to_string())?;
        self.db
            .execute("DELETE FROM jobs WHERE id=?1", params![id])
            .map_err(|e| e.to_string())?;
        Ok(())
    }

    pub fn replace_businesses(&self, job_id: &str, rows: &[BusinessRow]) -> Result<(), String> {
        let tx = self.db.unchecked_transaction().map_err(|e| e.to_string())?;
        tx.execute("DELETE FROM businesses WHERE job_id=?1", params![job_id])
            .map_err(|e| e.to_string())?;
        for r in rows {
            tx.execute(
                "INSERT INTO businesses(id,job_id,place_id,title,category,address,city,state,country,phone,website,email,review_rating,review_count,maps_url,latitude,longitude,open_hours,description,search_keyword,search_location,scraped_at,status)
                 VALUES(?1,?2,?3,?4,?5,?6,?7,?8,?9,?10,?11,?12,?13,?14,?15,?16,?17,?18,?19,?20,?21,?22,?23)",
                params![
                    r.id, r.job_id, r.place_id, r.title, r.category, r.address, r.city, r.state,
                    r.country, r.phone, r.website, r.email, r.review_rating, r.review_count,
                    r.maps_url, r.latitude, r.longitude, r.open_hours, r.description,
                    r.search_keyword, r.search_location, r.scraped_at, r.status
                ],
            )
            .map_err(|e| e.to_string())?;
        }
        tx.commit().map_err(|e| e.to_string())?;
        Ok(())
    }

    pub fn list_businesses(&self, job_id: &str) -> Result<Vec<BusinessRow>, String> {
        let mut stmt = self
            .db
            .prepare("SELECT id,job_id,place_id,title,category,address,city,state,country,phone,website,email,review_rating,review_count,maps_url,latitude,longitude,open_hours,description,search_keyword,search_location,scraped_at,status FROM businesses WHERE job_id=?1")
            .map_err(|e| e.to_string())?;
        let rows = stmt
            .query_map(params![job_id], map_business)
            .map_err(|e| e.to_string())?;
        rows.collect::<Result<Vec<_>, _>>().map_err(|e| e.to_string())
    }

    pub fn clear_all_results(&self) -> Result<(), String> {
        self.db.execute("DELETE FROM businesses", []).map_err(|e| e.to_string())?;
        self.db.execute("DELETE FROM jobs", []).map_err(|e| e.to_string())?;
        Ok(())
    }
}

fn map_job(row: &rusqlite::Row) -> Result<JobRecord, rusqlite::Error> {
    Ok(JobRecord {
        id: row.get(0)?,
        name: row.get(1)?,
        keywords_json: row.get(2)?,
        locations_json: row.get(3)?,
        status: row.get(4)?,
        result_count: row.get(5)?,
        duplicates_removed: row.get(6)?,
        engine_job_ids: row.get(7)?,
        created_at: row.get(8)?,
        updated_at: row.get(9)?,
        error_message: row.get(10)?,
    })
}

fn map_business(row: &rusqlite::Row) -> Result<BusinessRow, rusqlite::Error> {
    Ok(BusinessRow {
        id: row.get(0)?,
        job_id: row.get(1)?,
        place_id: row.get(2)?,
        category: row.get(4)?,
        title: row.get(3)?,
        address: row.get(5)?,
        city: row.get(6)?,
        state: row.get(7)?,
        country: row.get(8)?,
        phone: row.get(9)?,
        website: row.get(10)?,
        email: row.get(11)?,
        review_rating: row.get(12)?,
        review_count: row.get(13)?,
        maps_url: row.get(14)?,
        latitude: row.get(15)?,
        longitude: row.get(16)?,
        open_hours: row.get(17)?,
        description: row.get(18)?,
        search_keyword: row.get(19)?,
        search_location: row.get(20)?,
        scraped_at: row.get(21)?,
        status: row.get(22)?,
    })
}

fn apply_setting(s: &mut AppSettings, key: &str, value: &str) {
    match key {
        "welcome_done" => s.welcome_done = value == "1",
        "responsible_use_ack" => s.responsible_use_ack = value == "1",
        "default_depth" => s.default_depth = value.into(),
        "default_coverage" => s.default_coverage = value.into(),
        "default_rate" => s.default_rate = value.into(),
        "export_folder" => s.export_folder = value.into(),
        "email_extraction" => s.email_extraction = value == "1",
        "deduplication" => s.deduplication = value == "1",
        "request_delay_ms" => s.request_delay_ms = value.parse().unwrap_or(1000),
        "concurrency" => s.concurrency = value.parse().unwrap_or(1),
        "timeout_secs" => s.timeout_secs = value.parse().unwrap_or(600),
        "retry_count" => s.retry_count = value.parse().unwrap_or(2),
        "telemetry" => s.telemetry = value == "1",
        _ => {}
    }
}

fn settings_to_pairs(s: &AppSettings) -> Vec<(&'static str, String)> {
    vec![
        ("welcome_done", if s.welcome_done { "1" } else { "0" }.into()),
        ("responsible_use_ack", if s.responsible_use_ack { "1" } else { "0" }.into()),
        ("default_depth", s.default_depth.clone()),
        ("default_coverage", s.default_coverage.clone()),
        ("default_rate", s.default_rate.clone()),
        ("export_folder", s.export_folder.clone()),
        ("email_extraction", if s.email_extraction { "1" } else { "0" }.into()),
        ("deduplication", if s.deduplication { "1" } else { "0" }.into()),
        ("request_delay_ms", s.request_delay_ms.to_string()),
        ("concurrency", s.concurrency.to_string()),
        ("timeout_secs", s.timeout_secs.to_string()),
        ("retry_count", s.retry_count.to_string()),
        ("telemetry", if s.telemetry { "1" } else { "0" }.into()),
    ]
}

pub fn default_data_root() -> PathBuf {
    if let Some(base) = dirs::data_local_dir() {
        return base.join("GoogleMapsScraper");
    }
    PathBuf::from(".google-maps-scraper")
}

pub fn export_path(folder: &str, filename: &str) -> PathBuf {
    let dir = Path::new(folder);
    std::fs::create_dir_all(dir).ok();
    dir.join(filename)
}
