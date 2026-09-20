use crate::location_store::{
    self, export_json, find_duplicates, get_node, import_csv, import_json, list_districts_for_state,
    list_state_names, location_labels, manifest_stats, merge_locations, reset_from_bundle,
    delete_location, search_locations, set_active, upsert_location, LocationNodeInput,
};
use crate::storage::Storage;
use tauri::State;

fn with_storage<F, T>(state: &State<'_, crate::commands::AppState>, f: F) -> Result<T, String>
where
    F: FnOnce(&Storage) -> Result<T, String>,
{
    let g = state.storage.lock().map_err(|e| e.to_string())?;
    f(&g)
}

#[tauri::command]
pub fn location_manifest(state: State<'_, crate::commands::AppState>) -> Result<location_store::LocationManifest, String> {
    with_storage(&state, |s| manifest_stats(s.db()))
}

#[tauri::command]
pub fn location_list_states(
    state: State<'_, crate::commands::AppState>,
    include_inactive: Option<bool>,
) -> Result<Vec<String>, String> {
    with_storage(&state, |s| list_state_names(s.db(), include_inactive.unwrap_or(false)))
}

#[tauri::command]
pub fn location_list_state_nodes(
    state: State<'_, crate::commands::AppState>,
    include_inactive: Option<bool>,
) -> Result<Vec<location_store::LocationNode>, String> {
    with_storage(&state, |s| {
        location_store::list_nodes_by_level(s.db(), "state", !include_inactive.unwrap_or(false))
    })
}

#[tauri::command]
pub fn location_list_districts(
    state: State<'_, crate::commands::AppState>,
    state_name: String,
    include_inactive: Option<bool>,
) -> Result<Vec<location_store::LocationNode>, String> {
    with_storage(&state, |s| {
        list_districts_for_state(s.db(), &state_name, include_inactive.unwrap_or(false))
    })
}

#[tauri::command]
pub fn location_list_children(
    state: State<'_, crate::commands::AppState>,
    parent_id: String,
    include_inactive: Option<bool>,
) -> Result<Vec<location_store::LocationNode>, String> {
    with_storage(&state, |s| {
        location_store::list_children(s.db(), &parent_id, !include_inactive.unwrap_or(false))
    })
}

#[tauri::command]
pub fn location_get(
    state: State<'_, crate::commands::AppState>,
    id: String,
) -> Result<location_store::LocationNode, String> {
    with_storage(&state, |s| get_node(s.db(), &id))
}

#[tauri::command]
pub fn location_upsert(
    state: State<'_, crate::commands::AppState>,
    node: LocationNodeInput,
) -> Result<location_store::LocationNode, String> {
    with_storage(&state, |s| upsert_location(s.db(), &node))
}

#[tauri::command]
pub fn location_delete(
    state: State<'_, crate::commands::AppState>,
    id: String,
) -> Result<(), String> {
    with_storage(&state, |s| delete_location(s.db(), &id))
}

#[tauri::command]
pub fn location_set_active(
    state: State<'_, crate::commands::AppState>,
    id: String,
    active: bool,
) -> Result<(), String> {
    with_storage(&state, |s| set_active(s.db(), &id, active))
}

#[tauri::command]
pub fn location_merge(
    state: State<'_, crate::commands::AppState>,
    from_id: String,
    into_id: String,
) -> Result<(), String> {
    with_storage(&state, |s| merge_locations(s.db(), &from_id, &into_id))
}

#[tauri::command]
pub fn location_search(
    state: State<'_, crate::commands::AppState>,
    query: String,
    state_name: Option<String>,
    district_name: Option<String>,
    include_inactive: Option<bool>,
    limit: Option<u32>,
) -> Result<Vec<location_store::LocationSearchHit>, String> {
    with_storage(&state, |s| {
        search_locations(
            s.db(),
            &query,
            state_name.as_deref(),
            district_name.as_deref(),
            !include_inactive.unwrap_or(false),
            limit.unwrap_or(100).min(500) as usize,
        )
    })
}

#[tauri::command]
pub fn location_duplicates(
    state: State<'_, crate::commands::AppState>,
) -> Result<Vec<location_store::DuplicateGroup>, String> {
    with_storage(&state, |s| find_duplicates(s.db()))
}

#[tauri::command]
pub fn location_export_json(state: State<'_, crate::commands::AppState>) -> Result<String, String> {
    with_storage(&state, |s| export_json(s.db()))
}

#[tauri::command]
pub fn location_import_json(
    state: State<'_, crate::commands::AppState>,
    body: String,
) -> Result<u32, String> {
    with_storage(&state, |s| import_json(s.db(), &body))
}

#[tauri::command]
pub fn location_import_csv(
    state: State<'_, crate::commands::AppState>,
    body: String,
) -> Result<u32, String> {
    with_storage(&state, |s| import_csv(s.db(), &body))
}

#[tauri::command]
pub fn location_reset_from_bundle(
    state: State<'_, crate::commands::AppState>,
) -> Result<location_store::LocationManifest, String> {
    with_storage(&state, |s| reset_from_bundle(s.db()))
}

#[tauri::command]
pub fn location_labels_for_batch(
    state: State<'_, crate::commands::AppState>,
    state_name: String,
    district_name: Option<String>,
) -> Result<Vec<String>, String> {
    with_storage(&state, |s| location_labels(s.db(), &state_name, district_name.as_deref()))
}
