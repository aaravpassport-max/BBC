use chrono::Utc;
use rusqlite::{params, Connection};
use serde::{Deserialize, Serialize};
use uuid::Uuid;

pub const BUNDLE_SEED_KEY: &str = "location_db_seed_version";

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct LocationManifest {
    pub schema_version: u32,
    pub generated_at: String,
    pub states_and_union_territories: u32,
    pub districts: u32,
    pub places: u32,
    pub active_states: u32,
    pub active_districts: u32,
    pub active_places: u32,
    pub seed_version: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct LocationNode {
    pub id: String,
    pub parent_id: Option<String>,
    pub level: String,
    pub name: String,
    pub active: bool,
    pub region_type: Option<String>,
    pub place_type: Option<String>,
    pub latitude: Option<String>,
    pub longitude: Option<String>,
    pub population: i64,
    pub iso3166_2: Option<String>,
    pub iso2: Option<String>,
    pub aliases: Vec<String>,
    pub source: String,
    pub merged_into_id: Option<String>,
    pub state_name: Option<String>,
    pub district_name: Option<String>,
    pub created_at: String,
    pub updated_at: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct LocationSearchHit {
    pub id: String,
    pub label: String,
    pub place_name: String,
    pub district_name: String,
    pub state_name: String,
    pub place_type: String,
    pub latitude: String,
    pub longitude: String,
    pub population: i64,
    pub active: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct DuplicateGroup {
    pub parent_id: Option<String>,
    pub parent_name: String,
    pub level: String,
    pub normalized_name: String,
    pub nodes: Vec<LocationNode>,
}

#[derive(Debug, Deserialize)]
struct BundledRoot {
    states: Vec<BundledState>,
}

#[derive(Debug, Deserialize)]
struct BundledState {
    id: String,
    name: String,
    #[serde(rename = "type")]
    region_type: String,
    iso3166_2: String,
    iso2: String,
    districts: Vec<BundledDistrict>,
}

#[derive(Debug, Deserialize)]
struct BundledDistrict {
    id: String,
    name: String,
    #[serde(default)]
    places: Vec<BundledPlace>,
}

#[derive(Debug, Deserialize)]
struct BundledPlace {
    id: String,
    name: String,
    #[serde(rename = "type")]
    place_type: String,
    population: u64,
    latitude: String,
    longitude: String,
    #[serde(default)]
    aliases: Vec<String>,
}

pub fn migrate_locations(db: &Connection) -> Result<(), String> {
    db.execute_batch(
        "
        CREATE TABLE IF NOT EXISTS location_meta (
          key TEXT PRIMARY KEY,
          value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS location_nodes (
          id TEXT PRIMARY KEY,
          parent_id TEXT,
          level TEXT NOT NULL,
          name TEXT NOT NULL,
          normalized_name TEXT NOT NULL,
          active INTEGER NOT NULL DEFAULT 1,
          region_type TEXT,
          place_type TEXT,
          latitude TEXT,
          longitude TEXT,
          population INTEGER NOT NULL DEFAULT 0,
          iso3166_2 TEXT,
          iso2 TEXT,
          aliases_json TEXT NOT NULL DEFAULT '[]',
          source TEXT NOT NULL DEFAULT 'bundled',
          merged_into_id TEXT,
          external_id TEXT,
          created_at TEXT NOT NULL,
          updated_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_loc_parent ON location_nodes(parent_id);
        CREATE INDEX IF NOT EXISTS idx_loc_level ON location_nodes(level);
        CREATE INDEX IF NOT EXISTS idx_loc_active ON location_nodes(active);
        CREATE INDEX IF NOT EXISTS idx_loc_norm ON location_nodes(normalized_name);
        ",
    )
    .map_err(|e| e.to_string())
}

pub fn ensure_seeded(db: &Connection) -> Result<(), String> {
    migrate_locations(db)?;
    let manifest: serde_json::Value =
        serde_json::from_str(include_str!("../assets/india-locations/manifest.json"))
            .map_err(|e| e.to_string())?;
    let seed_version = format!(
        "v{}:{}",
        manifest["schemaVersion"].as_u64().unwrap_or(0),
        manifest["generatedAt"].as_str().unwrap_or("")
    );
    let current: Option<String> = db
        .query_row(
            "SELECT value FROM location_meta WHERE key = ?1",
            params![BUNDLE_SEED_KEY],
            |r| r.get(0),
        )
        .ok();
    if current.as_deref() == Some(seed_version.as_str()) {
        return Ok(());
    }
    let count: i64 = db
        .query_row("SELECT COUNT(*) FROM location_nodes", [], |r| r.get(0))
        .unwrap_or(0);
    if count > 0 && current.is_some() {
        // User DB exists; do not overwrite on app update unless they reset.
        return Ok(());
    }
    seed_from_bundle(db, &seed_version)
}

pub fn reset_from_bundle(db: &Connection) -> Result<LocationManifest, String> {
    migrate_locations(db)?;
    db.execute("DELETE FROM location_nodes", [])
        .map_err(|e| e.to_string())?;
    let manifest: serde_json::Value =
        serde_json::from_str(include_str!("../assets/india-locations/manifest.json"))
            .map_err(|e| e.to_string())?;
    let seed_version = format!(
        "v{}:{}",
        manifest["schemaVersion"].as_u64().unwrap_or(0),
        manifest["generatedAt"].as_str().unwrap_or("")
    );
    seed_from_bundle(db, &seed_version)?;
    manifest_stats(db)
}

fn seed_from_bundle(db: &Connection, seed_version: &str) -> Result<(), String> {
    let raw = include_str!("../assets/india-locations/india-locations.json");
    let data: BundledRoot = serde_json::from_str(raw).map_err(|e| e.to_string())?;
    let now = Utc::now().to_rfc3339();
    let tx = db.unchecked_transaction().map_err(|e| e.to_string())?;
    for st in data.states {
        insert_node(
            &tx,
            &st.id,
            None,
            "state",
            &st.name,
            true,
            Some(&st.region_type),
            None,
            None,
            None,
            0,
            Some(&st.iso3166_2),
            Some(&st.iso2),
            &[],
            "bundled",
            None,
            Some(&st.id),
            &now,
        )?;
        for dist in st.districts {
            insert_node(
                &tx,
                &dist.id,
                Some(&st.id),
                "district",
                &dist.name,
                true,
                None,
                None,
                None,
                None,
                0,
                None,
                None,
                &[],
                "bundled",
                None,
                Some(&dist.id),
                &now,
            )?;
            for pl in dist.places {
                insert_node(
                    &tx,
                    &pl.id,
                    Some(&dist.id),
                    "place",
                    &pl.name,
                    true,
                    None,
                    Some(&pl.place_type),
                    Some(&pl.latitude),
                    Some(&pl.longitude),
                    pl.population as i64,
                    None,
                    None,
                    &pl.aliases,
                    "bundled",
                    None,
                    Some(&pl.id),
                    &now,
                )?;
            }
        }
    }
    tx.commit().map_err(|e| e.to_string())?;
    db.execute(
        "INSERT INTO location_meta(key,value) VALUES(?1,?2)
         ON CONFLICT(key) DO UPDATE SET value=excluded.value",
        params![BUNDLE_SEED_KEY, seed_version],
    )
    .map_err(|e| e.to_string())?;
    Ok(())
}

#[allow(clippy::too_many_arguments)]
fn insert_node(
    db: &Connection,
    id: &str,
    parent_id: Option<&str>,
    level: &str,
    name: &str,
    active: bool,
    region_type: Option<&str>,
    place_type: Option<&str>,
    lat: Option<&str>,
    lon: Option<&str>,
    population: i64,
    iso3166_2: Option<&str>,
    iso2: Option<&str>,
    aliases: &[String],
    source: &str,
    merged_into: Option<&str>,
    external_id: Option<&str>,
    now: &str,
) -> Result<(), String> {
    let norm = normalize(name);
    let aliases_json = serde_json::to_string(aliases).map_err(|e| e.to_string())?;
    db.execute(
        "INSERT OR REPLACE INTO location_nodes
        (id,parent_id,level,name,normalized_name,active,region_type,place_type,latitude,longitude,population,iso3166_2,iso2,aliases_json,source,merged_into_id,external_id,created_at,updated_at)
        VALUES(?1,?2,?3,?4,?5,?6,?7,?8,?9,?10,?11,?12,?13,?14,?15,?16,?17,?18,?19)",
        params![
            id,
            parent_id,
            level,
            name,
            norm,
            if active { 1 } else { 0 },
            region_type,
            place_type,
            lat,
            lon,
            population,
            iso3166_2,
            iso2,
            aliases_json,
            source,
            merged_into,
            external_id,
            now,
            now,
        ],
    )
    .map_err(|e| e.to_string())?;
    Ok(())
}

pub fn manifest_stats(db: &Connection) -> Result<LocationManifest, String> {
    let manifest: serde_json::Value =
        serde_json::from_str(include_str!("../assets/india-locations/manifest.json"))
            .map_err(|e| e.to_string())?;
    let seed_version: String = db
        .query_row(
            "SELECT value FROM location_meta WHERE key = ?1",
            params![BUNDLE_SEED_KEY],
            |r| r.get(0),
        )
        .unwrap_or_else(|_| "unseeded".into());
    let count = |level: &str, active_only: bool| -> Result<u32, String> {
        let sql = if active_only {
            "SELECT COUNT(*) FROM location_nodes WHERE level=?1 AND active=1 AND merged_into_id IS NULL"
        } else {
            "SELECT COUNT(*) FROM location_nodes WHERE level=?1"
        };
        db.query_row(sql, params![level], |r| r.get::<_, i64>(0))
            .map(|n| n as u32)
            .map_err(|e| e.to_string())
    };
    Ok(LocationManifest {
        schema_version: manifest["schemaVersion"].as_u64().unwrap_or(1) as u32,
        generated_at: manifest["generatedAt"]
            .as_str()
            .unwrap_or("")
            .to_string(),
        states_and_union_territories: count("state", false)?,
        districts: count("district", false)?,
        places: count("place", false)?,
        active_states: count("state", true)?,
        active_districts: count("district", true)?,
        active_places: count("place", true)?,
        seed_version,
    })
}

pub fn list_state_names(db: &Connection, include_inactive: bool) -> Result<Vec<String>, String> {
    let sql = if include_inactive {
        "SELECT name FROM location_nodes WHERE level='state' ORDER BY name"
    } else {
        "SELECT name FROM location_nodes WHERE level='state' AND active=1 AND merged_into_id IS NULL ORDER BY name"
    };
    let mut stmt = db.prepare(sql).map_err(|e| e.to_string())?;
    let rows = stmt
        .query_map([], |r| r.get(0))
        .map_err(|e| e.to_string())?;
    Ok(rows.filter_map(|r| r.ok()).collect())
}

pub fn list_districts_for_state(
    db: &Connection,
    state_name: &str,
    include_inactive: bool,
) -> Result<Vec<LocationNode>, String> {
    let state_id = resolve_state_id(db, state_name)?;
    let sql = if include_inactive {
        "SELECT id,parent_id,level,name,active,region_type,place_type,latitude,longitude,population,iso3166_2,iso2,aliases_json,source,merged_into_id,created_at,updated_at
         FROM location_nodes WHERE level='district' AND parent_id=?1 ORDER BY name"
    } else {
        "SELECT id,parent_id,level,name,active,region_type,place_type,latitude,longitude,population,iso3166_2,iso2,aliases_json,source,merged_into_id,created_at,updated_at
         FROM location_nodes WHERE level='district' AND parent_id=?1 AND active=1 AND merged_into_id IS NULL ORDER BY name"
    };
    map_nodes(db, sql, params![state_id])
}

pub fn location_labels(
    db: &Connection,
    state_name: &str,
    district_name: Option<&str>,
) -> Result<Vec<String>, String> {
    let hits = search_locations(
        db,
        "",
        Some(state_name),
        district_name,
        true,
        50_000,
    )?;
    let mut labels: Vec<String> = hits.into_iter().map(|h| h.label).collect();
    labels.sort();
    labels.dedup();
    Ok(labels)
}

pub fn search_locations(
    db: &Connection,
    query: &str,
    state_name: Option<&str>,
    district_name: Option<&str>,
    active_only: bool,
    limit: usize,
) -> Result<Vec<LocationSearchHit>, String> {
    let q = normalize(query);
    let mut sql = String::from(
        "SELECT p.id,p.name,p.active,p.place_type,p.latitude,p.longitude,p.population,
                d.name,s.name
         FROM location_nodes p
         JOIN location_nodes d ON p.parent_id = d.id
         JOIN location_nodes s ON d.parent_id = s.id
         WHERE p.level='place'",
    );
    if active_only {
        sql.push_str(" AND p.active=1 AND p.merged_into_id IS NULL AND d.active=1 AND s.active=1");
    }
    if let Some(sn) = state_name {
        sql.push_str(&format!(
            " AND lower(s.name)=lower('{}')",
            sn.replace('\'', "''")
        ));
    }
    if let Some(dn) = district_name {
        sql.push_str(&format!(
            " AND lower(d.name)=lower('{}')",
            dn.replace('\'', "''")
        ));
    }
    sql.push_str(" ORDER BY p.population DESC, p.name LIMIT ");
    sql.push_str(&limit.to_string());

    let mut stmt = db.prepare(&sql).map_err(|e| e.to_string())?;
    let rows = stmt
        .query_map([], |row| {
            Ok((
                row.get::<_, String>(0)?,
                row.get::<_, String>(1)?,
                row.get::<_, i64>(2)?,
                row.get::<_, Option<String>>(3)?,
                row.get::<_, Option<String>>(4)?,
                row.get::<_, Option<String>>(5)?,
                row.get::<_, i64>(6)?,
                row.get::<_, String>(7)?,
                row.get::<_, String>(8)?,
            ))
        })
        .map_err(|e| e.to_string())?;

    let mut out = Vec::new();
    for row in rows.flatten() {
        let (id, pname, active, ptype, lat, lon, pop, dname, sname) = row;
        if q.len() >= 2 {
            let blob = normalize(&format!("{pname}{dname}{sname}"));
            if !blob.contains(&q) {
                continue;
            }
        }
        let label = format_label(&pname, &dname, &sname);
        out.push(LocationSearchHit {
            id,
            label,
            place_name: pname,
            district_name: dname,
            state_name: sname,
            place_type: ptype.unwrap_or_else(|| "place".into()),
            latitude: lat.unwrap_or_default(),
            longitude: lon.unwrap_or_default(),
            population: pop,
            active: active == 1,
        });
    }
    Ok(out)
}

pub fn coords_for_label(db: &Connection, label: &str) -> Option<(String, String)> {
    let parts: Vec<_> = label.split(',').map(|s| s.trim()).collect();
    if parts.len() < 2 {
        return None;
    }
    let state = parts.last()?;
    let place = parts.first()?;
    let district = if parts.len() >= 3 { parts[1] } else { parts[0] };
    let mut stmt = db
        .prepare(
            "SELECT p.latitude,p.longitude FROM location_nodes p
             JOIN location_nodes d ON p.parent_id=d.id
             JOIN location_nodes s ON d.parent_id=s.id
             WHERE p.level='place' AND p.active=1 AND p.merged_into_id IS NULL
             AND lower(p.name)=lower(?1) AND lower(d.name)=lower(?2) AND lower(s.name)=lower(?3)
             LIMIT 1",
        )
        .ok()?;
    stmt.query_row(params![place, district, state], |r| {
        Ok((r.get::<_, Option<String>>(0)?, r.get::<_, Option<String>>(1)?))
    })
    .ok()
    .and_then(|(la, lo)| {
        let lat = la?;
        let lon = lo?;
        if lat.is_empty() || lon.is_empty() {
            None
        } else {
            Some((lat, lon))
        }
    })
}

pub fn upsert_location(
    db: &Connection,
    input: &LocationNodeInput,
) -> Result<LocationNode, String> {
    let now = Utc::now().to_rfc3339();
    let id = input
        .id
        .clone()
        .unwrap_or_else(|| Uuid::new_v4().to_string());
    if let Some(pid) = &input.parent_id {
        parent_must_exist(db, pid, expected_parent_level(&input.level))?;
    } else if input.level != "state" {
        return Err("Only state/UT nodes may have no parent.".into());
    }
    check_duplicate(db, &id, input.parent_id.as_deref(), &input.name)?;
    insert_node(
        db,
        &id,
        input.parent_id.as_deref(),
        &input.level,
        &input.name,
        input.active,
        input.region_type.as_deref(),
        input.place_type.as_deref(),
        input.latitude.as_deref(),
        input.longitude.as_deref(),
        input.population,
        input.iso3166_2.as_deref(),
        input.iso2.as_deref(),
        &input.aliases,
        "user",
        None,
        None,
        &now,
    )?;
    get_node(db, &id)
}

#[derive(Debug, Clone, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct LocationNodeInput {
    pub id: Option<String>,
    pub parent_id: Option<String>,
    pub level: String,
    pub name: String,
    #[serde(default = "default_true")]
    pub active: bool,
    pub region_type: Option<String>,
    pub place_type: Option<String>,
    pub latitude: Option<String>,
    pub longitude: Option<String>,
    #[serde(default)]
    pub population: i64,
    pub iso3166_2: Option<String>,
    pub iso2: Option<String>,
    #[serde(default)]
    pub aliases: Vec<String>,
}

fn default_true() -> bool {
    true
}

fn expected_parent_level(level: &str) -> &'static str {
    match level {
        "district" => "state",
        "place" => "district",
        _ => "none",
    }
}

fn parent_must_exist(db: &Connection, pid: &str, want_level: &str) -> Result<(), String> {
    let (level, active): (String, i64) = db
        .query_row(
            "SELECT level,active FROM location_nodes WHERE id=?1",
            params![pid],
            |r| Ok((r.get(0)?, r.get(1)?)),
        )
        .map_err(|_| "Parent location not found.".to_string())?;
    if level != want_level {
        return Err(format!("Parent must be a {want_level}."));
    }
    if active == 0 {
        return Err("Parent location is inactive.".into());
    }
    Ok(())
}

fn check_duplicate(
    db: &Connection,
    id: &str,
    parent_id: Option<&str>,
    name: &str,
) -> Result<(), String> {
    let norm = normalize(name);
    let exists: Option<String> = db
        .query_row(
            "SELECT id FROM location_nodes WHERE parent_id IS ?1 AND normalized_name=?2 AND id<>?3 AND merged_into_id IS NULL",
            params![parent_id, norm, id],
            |r| r.get(0),
        )
        .ok();
    if exists.is_some() {
        return Err(format!(
            "Duplicate name \"{name}\" under the same parent. Rename, merge, or deactivate the existing entry."
        ));
    }
    Ok(())
}

pub fn delete_location(db: &Connection, id: &str) -> Result<(), String> {
    let children: i64 = db
        .query_row(
            "SELECT COUNT(*) FROM location_nodes WHERE parent_id=?1",
            params![id],
            |r| r.get(0),
        )
        .map_err(|e| e.to_string())?;
    if children > 0 {
        return Err(
            "This location has child entries. Remove or deactivate children first, or deactivate this location instead."
                .into(),
        );
    }
    let n = db
        .execute("DELETE FROM location_nodes WHERE id=?1", params![id])
        .map_err(|e| e.to_string())?;
    if n == 0 {
        return Err("Location not found.".into());
    }
    Ok(())
}

pub fn set_active(db: &Connection, id: &str, active: bool) -> Result<(), String> {
    let now = Utc::now().to_rfc3339();
    let n = db
        .execute(
            "UPDATE location_nodes SET active=?1, updated_at=?2 WHERE id=?3",
            params![if active { 1 } else { 0 }, now, id],
        )
        .map_err(|e| e.to_string())?;
    if n == 0 {
        return Err("Location not found.".into());
    }
    Ok(())
}

pub fn merge_locations(db: &Connection, from_id: &str, into_id: &str) -> Result<(), String> {
    if from_id == into_id {
        return Err("Cannot merge a location into itself.".into());
    }
    let (from_level, from_parent): (String, Option<String>) = db
        .query_row(
            "SELECT level,parent_id FROM location_nodes WHERE id=?1",
            params![from_id],
            |r| Ok((r.get(0)?, r.get(1)?)),
        )
        .map_err(|_| "Source location not found.".to_string())?;
    let (into_level, into_parent): (String, Option<String>) = db
        .query_row(
            "SELECT level,parent_id FROM location_nodes WHERE id=?1",
            params![into_id],
            |r| Ok((r.get(0)?, r.get(1)?)),
        )
        .map_err(|_| "Target location not found.".to_string())?;
    if from_level != into_level || from_parent != into_parent {
        return Err("Merge requires same level and same parent.".into());
    }
    let now = Utc::now().to_rfc3339();
    db.execute(
        "UPDATE location_nodes SET active=0, merged_into_id=?1, updated_at=?2 WHERE id=?3",
        params![into_id, now, from_id],
    )
    .map_err(|e| e.to_string())?;
    Ok(())
}

pub fn find_duplicates(db: &Connection) -> Result<Vec<DuplicateGroup>, String> {
    let mut stmt = db
        .prepare(
            "SELECT parent_id, level, normalized_name, COUNT(*) c FROM location_nodes
             WHERE merged_into_id IS NULL GROUP BY parent_id, level, normalized_name HAVING c > 1",
        )
        .map_err(|e| e.to_string())?;
    let groups = stmt
        .query_map([], |r| {
            Ok((
                r.get::<_, Option<String>>(0)?,
                r.get::<_, String>(1)?,
                r.get::<_, String>(2)?,
            ))
        })
        .map_err(|e| e.to_string())?;
    let mut out = Vec::new();
    for g in groups.flatten() {
        let (parent_id, level, norm) = g;
        let parent_name = if let Some(pid) = &parent_id {
            db.query_row(
                "SELECT name FROM location_nodes WHERE id=?1",
                params![pid],
                |r| r.get(0),
            )
            .unwrap_or_else(|_| "?".into())
        } else {
            "(root)".into()
        };
        let nodes = map_nodes(
            db,
            "SELECT id,parent_id,level,name,active,region_type,place_type,latitude,longitude,population,iso3166_2,iso2,aliases_json,source,merged_into_id,created_at,updated_at
             FROM location_nodes WHERE parent_id IS ?1 AND level=?2 AND normalized_name=?3",
            params![parent_id, level, norm],
        )?;
        out.push(DuplicateGroup {
            parent_id,
            parent_name,
            level,
            normalized_name: norm,
            nodes,
        });
    }
    Ok(out)
}

pub fn export_json(db: &Connection) -> Result<String, String> {
    let states = list_nodes_by_level(db, "state", true)?;
    let mut tree = Vec::new();
    for st in states {
        let districts = list_children(db, &st.id, true)?;
        let mut dvec = Vec::new();
        for d in districts {
            let places = list_children(db, &d.id, true)?;
            dvec.push(serde_json::json!({
                "id": d.id,
                "name": d.name,
                "active": d.active,
                "places": places,
            }));
        }
        tree.push(serde_json::json!({
            "id": st.id,
            "name": st.name,
            "active": st.active,
            "regionType": st.region_type,
            "iso3166_2": st.iso3166_2,
            "iso2": st.iso2,
            "districts": dvec,
        }));
    }
    serde_json::to_string_pretty(&serde_json::json!({
        "schemaVersion": 1,
        "exportedAt": Utc::now().to_rfc3339(),
        "states": tree,
    }))
    .map_err(|e| e.to_string())
}

pub fn import_json(db: &Connection, body: &str) -> Result<u32, String> {
    let v: serde_json::Value = serde_json::from_str(body).map_err(|e| e.to_string())?;
    let states = v
        .get("states")
        .and_then(|x| x.as_array())
        .ok_or("JSON must contain a states array.")?;
    let mut count = 0u32;
    for st in states {
        let input = LocationNodeInput {
            id: st.get("id").and_then(|x| x.as_str()).map(str::to_string),
            parent_id: None,
            level: "state".into(),
            name: st["name"].as_str().unwrap_or("").to_string(),
            active: st.get("active").and_then(|x| x.as_bool()).unwrap_or(true),
            region_type: st
                .get("regionType")
                .and_then(|x| x.as_str())
                .map(str::to_string),
            place_type: None,
            latitude: None,
            longitude: None,
            population: 0,
            iso3166_2: st.get("iso3166_2").and_then(|x| x.as_str()).map(str::to_string),
            iso2: st.get("iso2").and_then(|x| x.as_str()).map(str::to_string),
            aliases: vec![],
        };
        if input.name.is_empty() {
            continue;
        }
        let snode = upsert_location(db, &input)?;
        count += 1;
        if let Some(districts) = st.get("districts").and_then(|x| x.as_array()) {
            for d in districts {
                let dinput = LocationNodeInput {
                    id: d.get("id").and_then(|x| x.as_str()).map(str::to_string),
                    parent_id: Some(snode.id.clone()),
                    level: "district".into(),
                    name: d["name"].as_str().unwrap_or("").to_string(),
                    active: d.get("active").and_then(|x| x.as_bool()).unwrap_or(true),
                    region_type: None,
                    place_type: None,
                    latitude: None,
                    longitude: None,
                    population: 0,
                    iso3166_2: None,
                    iso2: None,
                    aliases: vec![],
                };
                if dinput.name.is_empty() {
                    continue;
                }
                let dnode = upsert_location(db, &dinput)?;
                count += 1;
                if let Some(places) = d.get("places").and_then(|x| x.as_array()) {
                    for p in places {
                        let pinput = parse_place_input(&dnode.id, p);
                        if pinput.name.is_empty() {
                            continue;
                        }
                        upsert_location(db, &pinput)?;
                        count += 1;
                    }
                }
            }
        }
    }
    Ok(count)
}

fn parse_place_input(parent_id: &str, p: &serde_json::Value) -> LocationNodeInput {
    LocationNodeInput {
        id: p.get("id").and_then(|x| x.as_str()).map(str::to_string),
        parent_id: Some(parent_id.to_string()),
        level: "place".into(),
        name: p.get("name").and_then(|x| x.as_str()).unwrap_or("").to_string(),
        active: p.get("active").and_then(|x| x.as_bool()).unwrap_or(true),
        region_type: None,
        place_type: p
            .get("placeType")
            .or_else(|| p.get("place_type"))
            .and_then(|x| x.as_str())
            .map(str::to_string),
        latitude: p.get("latitude").and_then(|x| x.as_str()).map(str::to_string),
        longitude: p.get("longitude").and_then(|x| x.as_str()).map(str::to_string),
        population: p.get("population").and_then(|x| x.as_i64()).unwrap_or(0),
        iso3166_2: None,
        iso2: None,
        aliases: p
            .get("aliases")
            .and_then(|x| x.as_array())
            .map(|a| {
                a.iter()
                    .filter_map(|v| v.as_str().map(str::to_string))
                    .collect()
            })
            .unwrap_or_default(),
    }
}

pub fn import_csv(db: &Connection, csv_text: &str) -> Result<u32, String> {
    let mut rdr = csv::ReaderBuilder::new()
        .has_headers(true)
        .from_reader(csv_text.as_bytes());
    let headers = rdr.headers().map_err(|e| e.to_string())?.clone();
    let idx = |h: &str| headers.iter().position(|x| x.eq_ignore_ascii_case(h));
    let mut count = 0u32;
    for rec in rdr.records() {
        let rec = rec.map_err(|e| e.to_string())?;
        let get = |h: &str| {
            idx(h)
                .and_then(|i| rec.get(i))
                .unwrap_or("")
                .trim()
                .to_string()
        };
        let state = get("state");
        if state.is_empty() {
            continue;
        }
        let state_id = ensure_state_by_name(db, &state)?;
        let district = get("district");
        let district_id = if district.is_empty() {
            ensure_district_by_name(db, &state_id, &state)?
        } else {
            ensure_district_by_name(db, &state_id, &district)?
        };
        let place = get("place");
        if place.is_empty() {
            continue;
        }
        let input = LocationNodeInput {
            id: None,
            parent_id: Some(district_id),
            level: "place".into(),
            name: place,
            active: get("active").to_lowercase() != "false",
            region_type: None,
            place_type: Some(get("place_type").if_empty_then("locality")),
            latitude: opt(get("latitude")),
            longitude: opt(get("longitude")),
            population: get("population").parse().unwrap_or(0),
            iso3166_2: None,
            iso2: None,
            aliases: get("aliases")
                .split(';')
                .map(|s| s.trim().to_string())
                .filter(|s| !s.is_empty())
                .collect(),
        };
        upsert_location(db, &input)?;
        count += 1;
    }
    Ok(count)
}

trait IfEmpty {
    fn if_empty_then(self, alt: &str) -> String;
}
impl IfEmpty for String {
    fn if_empty_then(self, alt: &str) -> String {
        if self.is_empty() {
            alt.to_string()
        } else {
            self
        }
    }
}

fn opt(s: String) -> Option<String> {
    if s.is_empty() {
        None
    } else {
        Some(s)
    }
}

fn ensure_state_by_name(db: &Connection, name: &str) -> Result<String, String> {
    if let Ok(id) = resolve_state_id(db, name) {
        return Ok(id);
    }
    let node = upsert_location(
        db,
        &LocationNodeInput {
            id: None,
            parent_id: None,
            level: "state".into(),
            name: name.to_string(),
            active: true,
            region_type: Some("state".into()),
            place_type: None,
            latitude: None,
            longitude: None,
            population: 0,
            iso3166_2: None,
            iso2: None,
            aliases: vec![],
        },
    )?;
    Ok(node.id)
}

fn ensure_district_by_name(db: &Connection, state_id: &str, name: &str) -> Result<String, String> {
    let norm = normalize(name);
    if let Ok(id) = db.query_row(
        "SELECT id FROM location_nodes WHERE parent_id=?1 AND level='district' AND normalized_name=?2",
        params![state_id, norm],
        |r| r.get(0),
    ) {
        return Ok(id);
    }
    let node = upsert_location(
        db,
        &LocationNodeInput {
            id: None,
            parent_id: Some(state_id.to_string()),
            level: "district".into(),
            name: name.to_string(),
            active: true,
            region_type: None,
            place_type: None,
            latitude: None,
            longitude: None,
            population: 0,
            iso3166_2: None,
            iso2: None,
            aliases: vec![],
        },
    )?;
    Ok(node.id)
}

fn resolve_state_id(db: &Connection, state_name: &str) -> Result<String, String> {
    db.query_row(
        "SELECT id FROM location_nodes WHERE level='state' AND lower(name)=lower(?1) LIMIT 1",
        params![state_name],
        |r| r.get(0),
    )
    .map_err(|_| format!("State/UT \"{state_name}\" not found."))
}

pub fn get_node(db: &Connection, id: &str) -> Result<LocationNode, String> {
    let sql = "SELECT id,parent_id,level,name,active,region_type,place_type,latitude,longitude,population,iso3166_2,iso2,aliases_json,source,merged_into_id,created_at,updated_at FROM location_nodes WHERE id=?1";
    let node = db
        .query_row(sql, params![id], map_node_row)
        .map_err(|_| "Location not found.".to_string())?;
    enrich_hierarchy(db, node)
}

pub fn list_nodes_by_level(
    db: &Connection,
    level: &str,
    active_only: bool,
) -> Result<Vec<LocationNode>, String> {
    let sql = if active_only {
        "SELECT id,parent_id,level,name,active,region_type,place_type,latitude,longitude,population,iso3166_2,iso2,aliases_json,source,merged_into_id,created_at,updated_at FROM location_nodes WHERE level=?1 AND active=1 AND merged_into_id IS NULL ORDER BY name"
    } else {
        "SELECT id,parent_id,level,name,active,region_type,place_type,latitude,longitude,population,iso3166_2,iso2,aliases_json,source,merged_into_id,created_at,updated_at FROM location_nodes WHERE level=?1 ORDER BY name"
    };
    map_nodes(db, sql, params![level])
}

pub fn list_children(db: &Connection, parent_id: &str, active_only: bool) -> Result<Vec<LocationNode>, String> {
    let sql = if active_only {
        "SELECT id,parent_id,level,name,active,region_type,place_type,latitude,longitude,population,iso3166_2,iso2,aliases_json,source,merged_into_id,created_at,updated_at FROM location_nodes WHERE parent_id=?1 AND active=1 AND merged_into_id IS NULL ORDER BY name"
    } else {
        "SELECT id,parent_id,level,name,active,region_type,place_type,latitude,longitude,population,iso3166_2,iso2,aliases_json,source,merged_into_id,created_at,updated_at FROM location_nodes WHERE parent_id=?1 ORDER BY name"
    };
    map_nodes(db, sql, params![parent_id])
}

fn map_nodes(
    db: &Connection,
    sql: &str,
    bind: impl rusqlite::Params,
) -> Result<Vec<LocationNode>, String> {
    let mut stmt = db.prepare(sql).map_err(|e| e.to_string())?;
    let rows = stmt
        .query_map(bind, map_node_row)
        .map_err(|e| e.to_string())?;
    let mut out = Vec::new();
    for row in rows {
        let node = row.map_err(|e| e.to_string())?;
        out.push(enrich_hierarchy(db, node)?);
    }
    Ok(out)
}

fn map_node_row(row: &rusqlite::Row<'_>) -> rusqlite::Result<LocationNode> {
    let aliases_json: String = row.get(12)?;
    let aliases: Vec<String> = serde_json::from_str(&aliases_json).unwrap_or_default();
    Ok(LocationNode {
        id: row.get(0)?,
        parent_id: row.get(1)?,
        level: row.get(2)?,
        name: row.get(3)?,
        active: row.get::<_, i64>(4)? == 1,
        region_type: row.get(5)?,
        place_type: row.get(6)?,
        latitude: row.get(7)?,
        longitude: row.get(8)?,
        population: row.get(9)?,
        iso3166_2: row.get(10)?,
        iso2: row.get(11)?,
        aliases,
        source: row.get(13)?,
        merged_into_id: row.get(14)?,
        state_name: None,
        district_name: None,
        created_at: row.get(15)?,
        updated_at: row.get(16)?,
    })
}

fn enrich_hierarchy(db: &Connection, mut node: LocationNode) -> Result<LocationNode, String> {
    match node.level.as_str() {
        "place" => {
            if let Some(did) = &node.parent_id {
                if let Ok(dname) = db.query_row(
                    "SELECT name,parent_id FROM location_nodes WHERE id=?1",
                    params![did],
                    |r| Ok((r.get::<_, String>(0)?, r.get::<_, Option<String>>(1)?)),
                ) {
                    node.district_name = Some(dname.0);
                    if let Some(sid) = dname.1 {
                        node.state_name = db
                            .query_row(
                                "SELECT name FROM location_nodes WHERE id=?1",
                                params![sid],
                                |r| r.get(0),
                            )
                            .ok();
                    }
                }
            }
        }
        "district" => {
            if let Some(sid) = &node.parent_id {
                node.state_name = db
                    .query_row(
                        "SELECT name FROM location_nodes WHERE id=?1",
                        params![sid],
                        |r| r.get(0),
                    )
                    .ok();
            }
        }
        _ => {}
    }
    Ok(node)
}

pub fn format_label(place: &str, district: &str, state: &str) -> String {
    if normalize(place) == normalize(district) {
        format!("{district}, {state}")
    } else {
        format!("{place}, {district}, {state}")
    }
}

pub fn normalize(s: &str) -> String {
    s.to_lowercase()
        .chars()
        .filter(|c| c.is_ascii_alphanumeric())
        .collect()
}
