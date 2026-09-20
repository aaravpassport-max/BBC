use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct StateEntry {
    pub name: String,
    pub cities: Vec<String>,
}

pub fn states() -> Vec<StateEntry> {
    serde_json::from_str(include_str!("../assets/india_states.json")).unwrap_or_default()
}

pub fn cities_for_state(name: &str) -> Vec<String> {
    states()
        .into_iter()
        .find(|s| s.name.eq_ignore_ascii_case(name))
        .map(|s| s.cities)
        .unwrap_or_default()
}
