use serde::{Deserialize, Serialize};
use std::sync::OnceLock;

#[derive(Debug, Clone, Deserialize, Serialize)]
pub struct Place {
    pub id: String,
    pub name: String,
    #[serde(rename = "type")]
    pub place_type: String,
    pub population: u64,
    pub latitude: String,
    pub longitude: String,
    #[serde(rename = "geonameId")]
    pub geoname_id: String,
    #[serde(default)]
    pub aliases: Vec<String>,
}

#[derive(Debug, Clone, Deserialize, Serialize)]
pub struct District {
    pub id: String,
    pub name: String,
    #[serde(rename = "geonamesAdmin2")]
    pub geonames_admin2: String,
    #[serde(default)]
    pub places: Vec<Place>,
}

#[derive(Debug, Clone, Deserialize, Serialize)]
pub struct StateUt {
    pub id: String,
    pub name: String,
    #[serde(rename = "type")]
    pub region_type: String,
    pub iso3166_2: String,
    pub iso2: String,
    #[serde(rename = "geonamesAdmin1")]
    pub geonames_admin1: String,
    pub districts: Vec<District>,
}

#[derive(Debug, Deserialize)]
struct Dataset {
    states: Vec<StateUt>,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct StateSummary {
    pub id: String,
    pub name: String,
    pub region_type: String,
    pub district_count: u32,
    pub place_count: u32,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct DistrictSummary {
    pub id: String,
    pub name: String,
    pub place_count: u32,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct LocationSearchHit {
    pub place_id: String,
    pub place_name: String,
    pub place_type: String,
    pub district_id: String,
    pub district_name: String,
    pub state_id: String,
    pub state_name: String,
    pub label: String,
    pub latitude: String,
    pub longitude: String,
    pub population: u64,
}

#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct LocationManifest {
    pub schema_version: u32,
    pub generated_at: String,
    pub states_and_union_territories: u32,
    pub districts: u32,
    pub places: u32,
}

pub struct IndiaLocations {
    states: Vec<StateUt>,
}

static DB: OnceLock<IndiaLocations> = OnceLock::new();

impl IndiaLocations {
    pub fn global() -> &'static IndiaLocations {
        DB.get_or_init(|| {
            let raw = include_str!("../assets/india-locations/india-locations.json");
            let data: Dataset = serde_json::from_str(raw).expect("india-locations.json must parse");
            IndiaLocations { states: data.states }
        })
    }

    pub fn manifest() -> LocationManifest {
        let m: serde_json::Value =
            serde_json::from_str(include_str!("../assets/india-locations/manifest.json"))
                .expect("manifest");
        LocationManifest {
            schema_version: m["schemaVersion"].as_u64().unwrap_or(0) as u32,
            generated_at: m["generatedAt"].as_str().unwrap_or("").to_string(),
            states_and_union_territories: m["counts"]["statesAndUnionTerritories"].as_u64().unwrap_or(0) as u32,
            districts: m["counts"]["districts"].as_u64().unwrap_or(0) as u32,
            places: m["counts"]["places"].as_u64().unwrap_or(0) as u32,
        }
    }

    pub fn state_summaries(&self) -> Vec<StateSummary> {
        self.states
            .iter()
            .map(|s| StateSummary {
                id: s.id.clone(),
                name: s.name.clone(),
                region_type: s.region_type.clone(),
                district_count: s.districts.len() as u32,
                place_count: s.districts.iter().map(|d| d.places.len() as u32).sum(),
            })
            .collect()
    }

    pub fn state_names(&self) -> Vec<String> {
        self.states.iter().map(|s| s.name.clone()).collect()
    }

    fn find_state(&self, name: &str) -> Option<&StateUt> {
        let n = normalize(name);
        self.states.iter().find(|s| {
            normalize(&s.name) == n
                || s.iso2.eq_ignore_ascii_case(name)
                || s.id.eq_ignore_ascii_case(name)
        })
    }

    pub fn districts_for_state(&self, state_name: &str) -> Vec<DistrictSummary> {
        self.find_state(state_name)
            .map(|s| {
                s.districts
                    .iter()
                    .map(|d| DistrictSummary {
                        id: d.id.clone(),
                        name: d.name.clone(),
                        place_count: d.places.len() as u32,
                    })
                    .collect()
            })
            .unwrap_or_default()
    }

    pub fn location_labels(&self, state_name: &str, district_name: Option<&str>) -> Vec<String> {
        let Some(st) = self.find_state(state_name) else {
            return Vec::new();
        };
        let mut out = Vec::new();
        for d in &st.districts {
            if let Some(dn) = district_name {
                if !name_matches(&d.name, dn) {
                    continue;
                }
            }
            for p in &d.places {
                out.push(format_label(&p.name, &d.name, &st.name));
            }
            if d.places.is_empty() {
                out.push(format_label(&d.name, &d.name, &st.name));
            }
        }
        out.sort();
        out.dedup();
        out
    }

    pub fn search(
        &self,
        query: &str,
        state_name: Option<&str>,
        district_name: Option<&str>,
        limit: usize,
    ) -> Vec<LocationSearchHit> {
        let q = normalize(query);
        if q.len() < 2 {
            return Vec::new();
        }
        let mut hits = Vec::new();
        for st in &self.states {
            if let Some(sn) = state_name {
                if !name_matches(&st.name, sn) {
                    continue;
                }
            }
            for d in &st.districts {
                if let Some(dn) = district_name {
                    if !name_matches(&d.name, dn) {
                        continue;
                    }
                }
                for p in &d.places {
                    if !place_matches_query(p, &q) && !normalize(&d.name).contains(&q) {
                        continue;
                    }
                    hits.push(LocationSearchHit {
                        place_id: p.id.clone(),
                        place_name: p.name.clone(),
                        place_type: p.place_type.clone(),
                        district_id: d.id.clone(),
                        district_name: d.name.clone(),
                        state_id: st.id.clone(),
                        state_name: st.name.clone(),
                        label: format_label(&p.name, &d.name, &st.name),
                        latitude: p.latitude.clone(),
                        longitude: p.longitude.clone(),
                        population: p.population,
                    });
                }
            }
        }
        hits.sort_by(|a, b| b.population.cmp(&a.population));
        hits.truncate(limit);
        hits
    }

    pub fn coords_for_label(&self, label: &str) -> Option<(String, String)> {
        let parts: Vec<_> = label.split(',').map(|s| s.trim()).collect();
        if parts.len() < 2 {
            return None;
        }
        let state = parts.last()?.to_string();
        let place = parts.first()?.to_string();
        let district = if parts.len() >= 3 {
            parts[1].to_string()
        } else {
            String::new()
        };
        let st = self.find_state(&state)?;
        for d in &st.districts {
            if !district.is_empty() && !name_matches(&d.name, &district) {
                continue;
            }
            for p in &d.places {
                if name_matches(&p.name, &place) {
                    return Some((p.latitude.clone(), p.longitude.clone()));
                }
            }
        }
        None
    }
}

pub fn cities_for_state(name: &str) -> Vec<String> {
    IndiaLocations::global().location_labels(name, None)
}

fn format_label(place: &str, district: &str, state: &str) -> String {
    if normalize(place) == normalize(district) {
        format!("{district}, {state}")
    } else {
        format!("{place}, {district}, {state}")
    }
}

fn place_matches_query(p: &Place, q: &str) -> bool {
    if normalize(&p.name).contains(q) {
        return true;
    }
    p.aliases.iter().any(|a| normalize(a).contains(q))
}

fn name_matches(a: &str, b: &str) -> bool {
    a.eq_ignore_ascii_case(b) || normalize(a) == normalize(b)
}

fn normalize(s: &str) -> String {
    s.to_lowercase()
        .chars()
        .filter(|c| c.is_ascii_alphanumeric())
        .collect()
}
