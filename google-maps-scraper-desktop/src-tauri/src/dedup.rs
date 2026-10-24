use crate::models::BusinessRow;
use std::collections::HashSet;

pub fn deduplicate(rows: Vec<BusinessRow>) -> (Vec<BusinessRow>, u32) {
    let mut seen_place = HashSet::new();
    let mut seen_url = HashSet::new();
    let mut seen_name_addr = HashSet::new();
    let mut seen_name_phone = HashSet::new();
    let mut unique = Vec::new();
    let mut dupes = 0u32;

    for r in rows {
        let place = r.place_id.trim().to_lowercase();
        if !place.is_empty() && seen_place.contains(&place) {
            dupes += 1;
            continue;
        }
        let url = normalize(&r.maps_url);
        if !url.is_empty() && seen_url.contains(&url) {
            dupes += 1;
            continue;
        }
        let na = format!("{}|{}", normalize(&r.title), normalize(&r.address));
        if !r.title.is_empty()
            && !r.address.is_empty()
            && seen_name_addr.contains(&na)
        {
            dupes += 1;
            continue;
        }
        let np = format!("{}|{}", normalize(&r.title), normalize_phone(&r.phone));
        if !r.title.is_empty()
            && !r.phone.is_empty()
            && seen_name_phone.contains(&np)
        {
            dupes += 1;
            continue;
        }

        if !place.is_empty() {
            seen_place.insert(place);
        }
        if !url.is_empty() {
            seen_url.insert(url);
        }
        if !r.title.is_empty() && !r.address.is_empty() {
            seen_name_addr.insert(na);
        }
        if !r.title.is_empty() && !r.phone.is_empty() {
            seen_name_phone.insert(np);
        }
        unique.push(r);
    }
    (unique, dupes)
}

fn normalize(s: &str) -> String {
    s.trim()
        .to_lowercase()
        .chars()
        .filter(|c| c.is_alphanumeric() || c.is_whitespace())
        .collect::<String>()
        .split_whitespace()
        .collect::<Vec<_>>()
        .join(" ")
}

fn normalize_phone(s: &str) -> String {
    s.chars().filter(|c| c.is_ascii_digit()).collect()
}
