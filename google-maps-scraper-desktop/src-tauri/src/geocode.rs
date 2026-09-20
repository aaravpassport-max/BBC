use crate::logging;
use serde::Deserialize;
use std::time::Duration;

const UA: &str =
    "GoogleMapsScraperDesktop/1.0 (+https://github.com/aaravpassport-max/BBC; local business research)";

#[derive(Debug, Deserialize)]
struct NominatimHit {
    lat: String,
    lon: String,
}

#[derive(Debug, Deserialize)]
struct PhotonResponse {
    features: Vec<PhotonFeature>,
}

#[derive(Debug, Deserialize)]
struct PhotonFeature {
    geometry: PhotonGeometry,
}

#[derive(Debug, Deserialize)]
struct PhotonGeometry {
    coordinates: [f64; 2],
}

pub async fn geocode_place(place: &str) -> Result<(String, String), String> {
    if let Ok(coords) = try_nominatim(place).await {
        return Ok(coords);
    }
    logging::warn(&format!("Nominatim unavailable for \"{place}\", trying Photon geocoder"));
    if let Ok(coords) = try_photon(place).await {
        return Ok(coords);
    }
    if let Some(coords) = offline_india_hint(place) {
        logging::info(&format!("Using offline city coordinates for \"{place}\""));
        return Ok(coords);
    }
    Err(format!(
        "Could not locate \"{place}\". Check spelling, internet connection, or use \"City, State\" (e.g. Bangalore, Karnataka)."
    ))
}

async fn try_nominatim(place: &str) -> Result<(String, String), String> {
    let client = reqwest::Client::builder()
        .user_agent(UA)
        .timeout(Duration::from_secs(30))
        .build()
        .map_err(|e| e.to_string())?;
    let url = format!(
        "https://nominatim.openstreetmap.org/search?{}",
        serde_urlencoded::to_string([
            ("format", "json"),
            ("limit", "1"),
            ("q", place),
            ("countrycodes", "in"),
        ])
        .map_err(|e| e.to_string())?
    );
    logging::info(&format!("Geocoding (Nominatim): {place}"));
    tokio::time::sleep(Duration::from_secs(1)).await;
    let resp = client
        .get(&url)
        .header("Accept-Language", "en")
        .send()
        .await
        .map_err(|e| format!("Geocoding network error: {e}"))?;
    if !resp.status().is_success() {
        return Err(format!("Nominatim status {}", resp.status()));
    }
    let hits: Vec<NominatimHit> = resp.json().await.map_err(|e| e.to_string())?;
    hits.first()
        .map(|h| (h.lat.clone(), h.lon.clone()))
        .ok_or_else(|| format!("Nominatim had no results for \"{place}\""))
}

async fn try_photon(place: &str) -> Result<(String, String), String> {
    let client = reqwest::Client::builder()
        .user_agent(UA)
        .timeout(Duration::from_secs(30))
        .build()
        .map_err(|e| e.to_string())?;
    let url = format!(
        "https://photon.komoot.io/api/?{}",
        serde_urlencoded::to_string([("q", place), ("limit", "1")]).map_err(|e| e.to_string())?
    );
    logging::info(&format!("Geocoding (Photon): {place}"));
    let resp = client
        .get(&url)
        .send()
        .await
        .map_err(|e| format!("Photon network error: {e}"))?;
    if !resp.status().is_success() {
        return Err(format!("Photon status {}", resp.status()));
    }
    let body: PhotonResponse = resp.json().await.map_err(|e| e.to_string())?;
    let feat = body
        .features
        .first()
        .ok_or_else(|| format!("Photon had no results for \"{place}\""))?;
    let lon = feat.geometry.coordinates[0];
    let lat = feat.geometry.coordinates[1];
    Ok((lat.to_string(), lon.to_string()))
}

/// Last-resort coordinates for common India search locations (app presets / batch).
fn offline_india_hint(place: &str) -> Option<(String, String)> {
    let p = place.to_lowercase();
    const CITIES: &[(&str, &str, &str)] = &[
        ("bangalore", "12.9716", "77.5946"),
        ("bengaluru", "12.9716", "77.5946"),
        ("mumbai", "19.0760", "72.8777"),
        ("delhi", "28.6139", "77.2090"),
        ("new delhi", "28.6139", "77.2090"),
        ("chennai", "13.0827", "80.2707"),
        ("hyderabad", "17.3850", "78.4867"),
        ("pune", "18.5204", "73.8567"),
        ("kolkata", "22.5726", "88.3639"),
        ("ahmedabad", "23.0225", "72.5714"),
        ("jaipur", "26.9124", "75.7873"),
        ("lucknow", "26.8467", "80.9462"),
        ("kochi", "9.9312", "76.2673"),
        ("mysore", "12.2958", "76.6394"),
        ("mangalore", "12.9141", "74.8560"),
    ];
    for (name, lat, lon) in CITIES {
        if p.contains(name) {
            return Some(((*lat).to_string(), (*lon).to_string()));
        }
    }
    None
}
