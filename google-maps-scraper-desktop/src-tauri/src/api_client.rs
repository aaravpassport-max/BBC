use crate::logging;
use serde::{Deserialize, Serialize};
use std::time::Duration;


#[derive(Debug, Serialize)]
struct CreateJobBody {
    name: String,
    keywords: Vec<String>,
    lang: String,
    zoom: i32,
    lat: String,
    lon: String,
    fast_mode: bool,
    radius: i32,
    depth: i32,
    email: bool,
    max_time: i64,
}

#[derive(Debug, Deserialize)]
pub struct CreateJobResp {
    #[serde(alias = "ID")]
    pub id: String,
}

#[derive(Debug, Deserialize)]
pub struct JobStatusResp {
    #[serde(rename = "Status")]
    pub status: String,
}

pub struct ApiClient {
    client: reqwest::Client,
    base: String,
}

impl ApiClient {
    pub fn new(base: &str) -> Result<Self, String> {
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(120))
            .build()
            .map_err(|e| e.to_string())?;
        Ok(Self {
            client,
            base: base.trim_end_matches('/').to_string(),
        })
    }

    pub async fn health(&self) -> Result<(), String> {
        self.client
            .get(format!("{}/api/v1/jobs", self.base))
            .send()
            .await
            .map_err(|e| format!("Search engine not reachable: {e}"))?;
        Ok(())
    }

    pub async fn create_job(
        &self,
        name: &str,
        keywords: Vec<String>,
        lat: &str,
        lon: &str,
        depth: i32,
        fast_mode: bool,
        email: bool,
        max_time: i64,
    ) -> Result<String, String> {
        let body = CreateJobBody {
            name: name.into(),
            keywords,
            lang: "en".into(),
            zoom: 15,
            lat: lat.into(),
            lon: lon.into(),
            fast_mode,
            radius: 10000,
            depth,
            email,
            max_time,
        };
        let resp = self
            .client
            .post(format!("{}/api/v1/jobs", self.base))
            .json(&body)
            .send()
            .await
            .map_err(|e| e.to_string())?;
        let status = resp.status();
        if !status.is_success() {
            let t = resp.text().await.unwrap_or_default();
            return Err(format!("Could not start search (HTTP {status}): {t}"));
        }
        let body = resp.text().await.map_err(|e| e.to_string())?;
        let id = serde_json::from_str::<CreateJobResp>(&body)
            .map(|p| p.id)
            .ok()
            .filter(|s| !s.is_empty())
            .or_else(|| {
                serde_json::from_str::<serde_json::Value>(&body)
                    .ok()
                    .and_then(|v| {
                        v.get("id")
                            .or_else(|| v.get("ID"))
                            .and_then(|x| x.as_str())
                            .map(|s| s.to_string())
                    })
            })
            .ok_or_else(|| format!("Engine did not return a job id: {}", &body[..body.len().min(200)]))?;
        logging::info(&format!("Engine job created: {id}"));
        Ok(id)
    }

    pub async fn poll_status(&self, id: &str) -> Result<String, String> {
        let resp = self
            .client
            .get(format!("{}/api/v1/jobs/{id}", self.base))
            .send()
            .await
            .map_err(|e| e.to_string())?;
        let body = resp.text().await.map_err(|e| e.to_string())?;
        if let Ok(parsed) = serde_json::from_str::<JobStatusResp>(&body) {
            return Ok(parsed.status);
        }
        if let Ok(v) = serde_json::from_str::<serde_json::Value>(&body) {
            if let Some(s) = v.get("Status").and_then(|x| x.as_str()) {
                return Ok(s.to_string());
            }
            if let Some(s) = v.get("status").and_then(|x| x.as_str()) {
                return Ok(s.to_string());
            }
        }
        Err(format!("Unexpected job status response: {}", &body[..body.len().min(200)]))
    }

    pub async fn download_csv(&self, id: &str) -> Result<String, String> {
        let resp = self
            .client
            .get(format!("{}/api/v1/jobs/{id}/download", self.base))
            .send()
            .await
            .map_err(|e| e.to_string())?;
        if !resp.status().is_success() {
            return Err("Download failed — the search may have been blocked or empty.".into());
        }
        resp.text().await.map_err(|e| e.to_string())
    }
}
