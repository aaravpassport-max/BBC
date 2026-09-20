use crate::logging;
use serde::Deserialize;
use std::time::Duration;

const UA: &str = "GoogleMapsScraperDesktop/1.0 (local business research; contact: support@example.invalid)";

#[derive(Debug, Deserialize)]
struct Hit {
    lat: String,
    lon: String,
}

pub async fn geocode_place(place: &str) -> Result<(String, String), String> {
    let client = reqwest::Client::builder()
        .user_agent(UA)
        .timeout(Duration::from_secs(30))
        .build()
        .map_err(|e| e.to_string())?;
    let url = format!(
        "https://nominatim.openstreetmap.org/search?{}",
        serde_urlencoded::to_string([("format", "json"), ("limit", "1"), ("q", place)])
            .map_err(|e| e.to_string())?
    );
    logging::info(&format!("Geocoding: {place}"));
    tokio::time::sleep(Duration::from_secs(1)).await;
    let resp = client
        .get(&url)
        .send()
        .await
        .map_err(|e| format!("Geocoding network error: {e}"))?;
    if !resp.status().is_success() {
        return Err(format!("Geocoding failed with status {}", resp.status()));
    }
    let hits: Vec<Hit> = resp.json().await.map_err(|e| e.to_string())?;
    hits.first()
        .map(|h| (h.lat.clone(), h.lon.clone()))
        .ok_or_else(|| format!("Could not find coordinates for \"{place}\""))
}
