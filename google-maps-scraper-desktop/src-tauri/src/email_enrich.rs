use regex::Regex;
use std::time::Duration;

pub async fn find_public_email(website: &str) -> Option<String> {
    let url = normalize_url(website);
    let client = reqwest::Client::builder()
        .timeout(Duration::from_secs(12))
        .user_agent("Mozilla/5.0 (compatible; GoogleMapsScraperDesktop/1.0)")
        .build()
        .ok()?;
    let html = client.get(&url).send().await.ok()?.text().await.ok()?;
    let re = Regex::new(r"[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}").ok()?;
    for cap in re.captures_iter(&html) {
        if let Some(m) = cap.get(0) {
            let email = m.as_str().to_lowercase();
            if !email.ends_with(".png")
                && !email.ends_with(".jpg")
                && !email.contains("example.com")
                && !email.contains("sentry")
            {
                return Some(email);
            }
        }
    }
    None
}

fn normalize_url(website: &str) -> String {
    let w = website.trim();
    if w.starts_with("http://") || w.starts_with("https://") {
        w.to_string()
    } else {
        format!("https://{w}")
    }
}
