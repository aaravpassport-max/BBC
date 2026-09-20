import { useCallback, useEffect, useMemo, useState } from "react";
import { invoke } from "@tauri-apps/api/core";
import { open, save } from "@tauri-apps/plugin-dialog";
import { readTextFile, writeTextFile } from "@tauri-apps/plugin-fs";
import {
  DuplicateGroup,
  hierarchyLine,
  levelLabel,
  LocationManifest,
  LocationNode,
  LocationNodeInput,
  LocationSearchHit,
} from "./lib/locations";

type Tab = "browse" | "search" | "duplicates";

const EMPTY_FORM: LocationNodeInput = {
  level: "place",
  name: "",
  active: true,
  aliases: [],
  population: 0,
};

interface Props {
  onManifestChange?: (info: string) => void;
  onStatesChange?: (states: string[]) => void;
}

export default function LocationManager({ onManifestChange, onStatesChange }: Props) {
  const [tab, setTab] = useState<Tab>("browse");
  const [manifest, setManifest] = useState<LocationManifest | null>(null);
  const [status, setStatus] = useState("");
  const [includeInactive, setIncludeInactive] = useState(false);

  const [states, setStates] = useState<LocationNode[]>([]);
  const [selectedStateId, setSelectedStateId] = useState<string>("");
  const [districts, setDistricts] = useState<LocationNode[]>([]);
  const [selectedDistrictId, setSelectedDistrictId] = useState<string>("");
  const [places, setPlaces] = useState<LocationNode[]>([]);
  const [selectedNode, setSelectedNode] = useState<LocationNode | null>(null);

  const [searchQuery, setSearchQuery] = useState("");
  const [searchState, setSearchState] = useState("");
  const [searchDistrict, setSearchDistrict] = useState("");
  const [searchHits, setSearchHits] = useState<LocationSearchHit[]>([]);

  const [duplicates, setDuplicates] = useState<DuplicateGroup[]>([]);

  const [editorOpen, setEditorOpen] = useState(false);
  const [form, setForm] = useState<LocationNodeInput>(EMPTY_FORM);
  const refreshManifest = useCallback(async () => {
    const m = await invoke<LocationManifest>("location_manifest");
    setManifest(m);
    const info = `${m.activeStates}/${m.statesAndUnionTerritories} active states/UTs · ${m.activeDistricts}/${m.districts} districts · ${m.activePlaces}/${m.places} places (seed ${m.seedVersion})`;
    onManifestChange?.(info);
    const names = await invoke<string[]>("location_list_states", { includeInactive: false });
    onStatesChange?.(names);
  }, [onManifestChange, onStatesChange]);

  const loadStates = useCallback(async () => {
    const rows = await invoke<LocationNode[]>("location_list_state_nodes", { includeInactive });
    setStates(rows);
    if (!selectedStateId && rows[0]) setSelectedStateId(rows[0].id);
    else if (selectedStateId && !rows.some((r) => r.id === selectedStateId)) {
      setSelectedStateId(rows[0]?.id ?? "");
    }
  }, [includeInactive, selectedStateId]);

  const loadDistricts = useCallback(
    async (stateId: string) => {
      if (!stateId) {
        setDistricts([]);
        return;
      }
      const st = states.find((s) => s.id === stateId);
      if (!st) return;
      const rows = await invoke<LocationNode[]>("location_list_districts", {
        stateName: st.name,
        includeInactive,
      });
      setDistricts(rows);
      if (!rows.some((d) => d.id === selectedDistrictId)) {
        setSelectedDistrictId(rows[0]?.id ?? "");
      }
    },
    [includeInactive, selectedDistrictId, states],
  );

  const loadPlaces = useCallback(
    async (districtId: string) => {
      if (!districtId) {
        setPlaces([]);
        return;
      }
      const rows = await invoke<LocationNode[]>("location_list_children", {
        parentId: districtId,
        includeInactive,
      });
      setPlaces(rows);
    },
    [includeInactive],
  );

  useEffect(() => {
    refreshManifest().catch((e) => setStatus(String(e)));
  }, [refreshManifest]);

  useEffect(() => {
    loadStates().catch((e) => setStatus(String(e)));
  }, [loadStates]);

  useEffect(() => {
    if (selectedStateId) loadDistricts(selectedStateId).catch((e) => setStatus(String(e)));
  }, [selectedStateId, loadDistricts]);

  useEffect(() => {
    if (selectedDistrictId) loadPlaces(selectedDistrictId).catch((e) => setStatus(String(e)));
  }, [selectedDistrictId, loadPlaces]);

  const runSearch = async () => {
    const hits = await invoke<LocationSearchHit[]>("location_search", {
      query: searchQuery,
      stateName: searchState || null,
      districtName: searchDistrict || null,
      includeInactive,
      limit: 200,
    });
    setSearchHits(hits);
    setTab("search");
  };

  const loadDuplicates = async () => {
    const groups = await invoke<DuplicateGroup[]>("location_duplicates");
    setDuplicates(groups);
    setTab("duplicates");
  };

  const openCreate = (level: string, parentId: string | null) => {
    setForm({
      ...EMPTY_FORM,
      level,
      parentId,
      active: true,
    });
    setEditorOpen(true);
  };

  const openEdit = (node: LocationNode) => {
    setForm({
      id: node.id,
      parentId: node.parentId,
      level: node.level,
      name: node.name,
      active: node.active,
      regionType: node.regionType,
      placeType: node.placeType,
      latitude: node.latitude,
      longitude: node.longitude,
      population: node.population,
      iso3166_2: node.iso3166_2,
      iso2: node.iso2,
      aliases: [...node.aliases],
    });
    setSelectedNode(node);
    setEditorOpen(true);
  };

  const saveForm = async () => {
    try {
      const saved = await invoke<LocationNode>("location_upsert", { node: form });
      setStatus(`Saved ${saved.name}`);
      setEditorOpen(false);
      await refreshManifest();
      await loadStates();
      if (saved.level === "district" && saved.parentId) {
        setSelectedStateId(saved.parentId);
      }
      if (saved.level === "place" && saved.parentId) {
        setSelectedDistrictId(saved.parentId);
      }
      setSelectedNode(saved);
    } catch (e) {
      setStatus(String(e));
    }
  };

  const toggleActive = async (node: LocationNode) => {
    try {
      await invoke("location_set_active", { id: node.id, active: !node.active });
      setStatus(node.active ? `Deactivated ${node.name}` : `Activated ${node.name}`);
      await refreshManifest();
      await loadStates();
      if (selectedStateId) await loadDistricts(selectedStateId);
      if (selectedDistrictId) await loadPlaces(selectedDistrictId);
    } catch (e) {
      setStatus(String(e));
    }
  };

  const removeNode = async (node: LocationNode) => {
    if (!confirm(`Permanently delete "${node.name}"? This only works if it has no children.`)) return;
    try {
      await invoke("location_delete", { id: node.id });
      setStatus(`Deleted ${node.name}`);
      setSelectedNode(null);
      await refreshManifest();
      await loadStates();
      if (selectedStateId) await loadDistricts(selectedStateId);
      if (selectedDistrictId) await loadPlaces(selectedDistrictId);
    } catch (e) {
      setStatus(String(e));
    }
  };

  const doMerge = async (fromId: string, intoId: string) => {
    try {
      await invoke("location_merge", { fromId, intoId });
      setStatus("Merged duplicate into selected entry.");
      await refreshManifest();
      await loadDuplicates();
      await loadStates();
    } catch (e) {
      setStatus(String(e));
    }
  };

  const exportJson = async () => {
    try {
      const body = await invoke<string>("location_export_json");
      const path = await save({
        title: "Export location database (JSON)",
        defaultPath: "india-locations-export.json",
        filters: [{ name: "JSON", extensions: ["json"] }],
      });
      if (!path) return;
      await writeTextFile(path, body);
      setStatus(`Exported to ${path}`);
    } catch (e) {
      setStatus(String(e));
    }
  };

  const importFile = async (kind: "json" | "csv") => {
    try {
      const path = await open({
        title: kind === "json" ? "Import JSON locations" : "Import CSV locations",
        filters: [
          kind === "json"
            ? { name: "JSON", extensions: ["json"] }
            : { name: "CSV", extensions: ["csv"] },
        ],
      });
      if (!path || typeof path !== "string") return;
      const body = await readTextFile(path);
      const count =
        kind === "json"
          ? await invoke<number>("location_import_json", { body })
          : await invoke<number>("location_import_csv", { body });
      setStatus(`Imported ${count} records from ${path}`);
      await refreshManifest();
      await loadStates();
    } catch (e) {
      setStatus(String(e));
    }
  };

  const resetFromBundle = async () => {
    if (
      !confirm(
        "Replace your entire location database with the bundled seed? Custom entries will be lost.",
      )
    ) {
      return;
    }
    try {
      await invoke("location_reset_from_bundle");
      setStatus("Reset from bundled seed.");
      await refreshManifest();
      await loadStates();
    } catch (e) {
      setStatus(String(e));
    }
  };

  const stateNames = useMemo(() => states.map((s) => s.name), [states]);

  const renderNodeRow = (node: LocationNode, onSelect?: () => void) => (
    <div
      key={node.id}
      className={`loc-row ${selectedNode?.id === node.id ? "loc-row-selected" : ""}`}
      onClick={() => {
        setSelectedNode(node);
        onSelect?.();
      }}
    >
      <div>
        <strong>{node.name}</strong>
        {!node.active && <span className="pill pill-warn">Inactive</span>}
        {node.mergedIntoId && <span className="pill">Merged</span>}
        <div className="muted loc-sub">{hierarchyLine(node)}</div>
      </div>
      <div className="loc-actions" onClick={(e) => e.stopPropagation()}>
        <button type="button" className="btn btn-ghost btn-sm" onClick={() => openEdit(node)}>
          Edit
        </button>
        <button type="button" className="btn btn-ghost btn-sm" onClick={() => toggleActive(node)}>
          {node.active ? "Deactivate" : "Activate"}
        </button>
        <button type="button" className="btn btn-ghost btn-sm" onClick={() => removeNode(node)}>
          Delete
        </button>
      </div>
    </div>
  );

  return (
    <div className="location-manager">
      <div className="card">
        <h2>Location Manager</h2>
        <p className="muted">
          Maintain the full India location hierarchy (State/UT → District → City/Town). Active
          locations are used immediately by batch research, search, and geocoding — no reinstall or
          code changes.
        </p>
        {manifest && (
          <p className="muted">
            {manifest.activeStates} active states/UTs · {manifest.activeDistricts} districts ·{" "}
            {manifest.activePlaces} places · seed <code>{manifest.seedVersion}</code>
          </p>
        )}
        <div className="loc-toolbar">
          <label className="inline-check">
            <input
              type="checkbox"
              checked={includeInactive}
              onChange={(e) => setIncludeInactive(e.target.checked)}
            />{" "}
            Show inactive / merged
          </label>
          <button type="button" className="btn btn-secondary btn-sm" onClick={() => importFile("json")}>
            Import JSON
          </button>
          <button type="button" className="btn btn-secondary btn-sm" onClick={() => importFile("csv")}>
            Import CSV
          </button>
          <span className="muted" title="CSV columns: state, district, place, latitude, longitude, place_type, population, active, aliases (semicolon-separated)">
            CSV: state, district, place, …
          </span>
          <button type="button" className="btn btn-secondary btn-sm" onClick={exportJson}>
            Export JSON
          </button>
          <button type="button" className="btn btn-secondary btn-sm" onClick={loadDuplicates}>
            Find duplicates
          </button>
          <button type="button" className="btn btn-ghost btn-sm" onClick={resetFromBundle}>
            Reset from bundled seed
          </button>
          <button
            type="button"
            className="btn btn-primary btn-sm"
            onClick={() => openCreate("state", null)}
          >
            Add state/UT
          </button>
        </div>
        {status && <div className="hint-banner">{status}</div>}
      </div>

      <div className="loc-tabs">
        <button
          type="button"
          className={tab === "browse" ? "loc-tab active" : "loc-tab"}
          onClick={() => setTab("browse")}
        >
          Browse hierarchy
        </button>
        <button
          type="button"
          className={tab === "search" ? "loc-tab active" : "loc-tab"}
          onClick={() => setTab("search")}
        >
          Search
        </button>
        <button
          type="button"
          className={tab === "duplicates" ? "loc-tab active" : "loc-tab"}
          onClick={() => setTab("duplicates")}
        >
          Duplicates {duplicates.length ? `(${duplicates.length})` : ""}
        </button>
      </div>

      {tab === "browse" && (
        <div className="loc-columns">
          <div className="card loc-pane">
            <div className="loc-pane-head">
              <h3>States & UTs</h3>
              <button
                type="button"
                className="btn btn-ghost btn-sm"
                onClick={() => openCreate("state", null)}
              >
                +
              </button>
            </div>
            {states.map((s) =>
              renderNodeRow(s, () => {
                setSelectedStateId(s.id);
              }),
            )}
          </div>
          <div className="card loc-pane">
            <div className="loc-pane-head">
              <h3>Districts</h3>
              {selectedStateId && (
                <button
                  type="button"
                  className="btn btn-ghost btn-sm"
                  onClick={() => openCreate("district", selectedStateId)}
                >
                  +
                </button>
              )}
            </div>
            {districts.map((d) =>
              renderNodeRow(d, () => {
                setSelectedDistrictId(d.id);
              }),
            )}
          </div>
          <div className="card loc-pane">
            <div className="loc-pane-head">
              <h3>Cities / towns</h3>
              {selectedDistrictId && (
                <button
                  type="button"
                  className="btn btn-ghost btn-sm"
                  onClick={() => openCreate("place", selectedDistrictId)}
                >
                  +
                </button>
              )}
            </div>
            {places.map((p) => renderNodeRow(p))}
          </div>
        </div>
      )}

      {tab === "search" && (
        <div className="card">
          <div className="row">
            <div>
              <label>Search</label>
              <input
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="City, district, or state name"
                onKeyDown={(e) => e.key === "Enter" && runSearch()}
              />
            </div>
            <div>
              <label>Filter state</label>
              <select value={searchState} onChange={(e) => setSearchState(e.target.value)}>
                <option value="">Any</option>
                {stateNames.map((n) => (
                  <option key={n} value={n}>
                    {n}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label>Filter district</label>
              <input
                value={searchDistrict}
                onChange={(e) => setSearchDistrict(e.target.value)}
                placeholder="Optional"
              />
            </div>
            <div style={{ display: "flex", alignItems: "flex-end" }}>
              <button type="button" className="btn btn-primary" onClick={runSearch}>
                Search
              </button>
            </div>
          </div>
          <p className="muted">{searchHits.length} results (active-only unless “Show inactive” is on)</p>
          <div className="loc-search-list">
            {searchHits.map((h) => (
              <div key={h.id} className="loc-row">
                <div>
                  <strong>{h.label}</strong>
                  {!h.active && <span className="pill pill-warn">Inactive</span>}
                  <div className="muted loc-sub">
                    {h.placeType} · pop {h.population.toLocaleString()}
                  </div>
                </div>
                <button
                  type="button"
                  className="btn btn-ghost btn-sm"
                  onClick={async () => {
                    const node = await invoke<LocationNode>("location_get", { id: h.id });
                    openEdit(node);
                  }}
                >
                  Edit
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {tab === "duplicates" && (
        <div className="card">
          {duplicates.length === 0 && (
            <p className="muted">No duplicate names under the same parent. Run “Find duplicates” to scan.</p>
          )}
          {duplicates.map((g) => (
            <div key={`${g.parentId}-${g.normalizedName}`} className="loc-dup-group">
              <h4>
                {levelLabel(g.level)} duplicates under {g.parentName} — “{g.normalizedName}”
              </h4>
              {g.nodes.map((n) => (
                <div key={n.id} className="loc-row">
                  <div>
                    <strong>{n.name}</strong> {!n.active && <span className="pill pill-warn">Inactive</span>}
                    <div className="muted loc-sub">{hierarchyLine(n)}</div>
                  </div>
                  <div className="loc-actions">
                    <button type="button" className="btn btn-ghost btn-sm" onClick={() => openEdit(n)}>
                      Edit
                    </button>
                    {g.nodes.length > 1 && (
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        onClick={() => {
                          const other = g.nodes.find((x) => x.id !== n.id);
                          if (other) doMerge(n.id, other.id);
                        }}
                      >
                        Merge into sibling
                      </button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          ))}
        </div>
      )}

      {selectedNode && tab === "browse" && (
        <div className="card">
          <h3>Selected: {selectedNode.name}</h3>
          <p className="muted">{hierarchyLine(selectedNode)}</p>
          <p>
            Level: {levelLabel(selectedNode.level)} · Source: {selectedNode.source} ·{" "}
            {selectedNode.active ? "Active" : "Inactive"}
          </p>
          {selectedNode.latitude && (
            <p className="muted">
              Coordinates: {selectedNode.latitude}, {selectedNode.longitude}
            </p>
          )}
        </div>
      )}

      {editorOpen && (
        <div className="loc-modal-backdrop" onClick={() => setEditorOpen(false)}>
          <div className="loc-modal card" onClick={(e) => e.stopPropagation()}>
            <h3>{form.id ? "Edit location" : "Add location"}</h3>
            <label>Level</label>
            <select
              value={form.level}
              disabled={!!form.id}
              onChange={(e) => setForm({ ...form, level: e.target.value })}
            >
              <option value="state">State / UT</option>
              <option value="district">District</option>
              <option value="place">City / town</option>
            </select>
            <label>Name</label>
            <input
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
            />
            {form.level === "state" && (
              <>
                <label>Region type</label>
                <input
                  value={form.regionType ?? ""}
                  onChange={(e) => setForm({ ...form, regionType: e.target.value || null })}
                  placeholder="state or union territory"
                />
              </>
            )}
            {form.level === "place" && (
              <>
                <label>Place type</label>
                <input
                  value={form.placeType ?? ""}
                  onChange={(e) => setForm({ ...form, placeType: e.target.value || null })}
                  placeholder="city, town, …"
                />
                <label>Latitude / longitude</label>
                <div className="row">
                  <input
                    value={form.latitude ?? ""}
                    onChange={(e) => setForm({ ...form, latitude: e.target.value || null })}
                    placeholder="12.9716"
                  />
                  <input
                    value={form.longitude ?? ""}
                    onChange={(e) => setForm({ ...form, longitude: e.target.value || null })}
                    placeholder="77.5946"
                  />
                </div>
                <label>Population</label>
                <input
                  type="number"
                  value={form.population ?? 0}
                  onChange={(e) => setForm({ ...form, population: Number(e.target.value) })}
                />
              </>
            )}
            <label>Aliases (comma-separated)</label>
            <input
              value={(form.aliases ?? []).join(", ")}
              onChange={(e) =>
                setForm({
                  ...form,
                  aliases: e.target.value
                    .split(",")
                    .map((s) => s.trim())
                    .filter(Boolean),
                })
              }
            />
            <label className="inline-check">
              <input
                type="checkbox"
                checked={form.active !== false}
                onChange={(e) => setForm({ ...form, active: e.target.checked })}
              />{" "}
              Active (included in scraper)
            </label>
            <div style={{ marginTop: 16 }}>
              <button type="button" className="btn btn-primary" onClick={saveForm}>
                Save
              </button>
              <button
                type="button"
                className="btn btn-ghost"
                style={{ marginLeft: 8 }}
                onClick={() => setEditorOpen(false)}
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
