import { useCallback, useEffect, useMemo, useState } from "react";
import { invoke } from "@tauri-apps/api/core";
import { LocationNode, LocationSearchHit } from "./lib/locations";

function placeLabel(node: LocationNode, district: string, state: string): string {
  const d = node.districtName ?? district;
  const s = node.stateName ?? state;
  if (node.name.toLowerCase() === d.toLowerCase()) {
    return `${d}, ${s}`;
  }
  return `${node.name}, ${d}, ${s}`;
}

interface Props {
  /** Preloaded state names from dashboard startup */
  states?: string[];
  /** Called with full geocoding label (e.g. Bengaluru, Bangalore Urban, Karnataka) */
  onAdd: (labels: string[]) => void;
}

export default function LocationPicker({ states: statesProp, onAdd }: Props) {
  const [expanded, setExpanded] = useState(true);
  const [states, setStates] = useState<string[]>(statesProp ?? []);
  const [state, setState] = useState("");
  const [districts, setDistricts] = useState<LocationNode[]>([]);
  const [district, setDistrict] = useState("");
  const [places, setPlaces] = useState<{ id: string; label: string }[]>([]);
  const [filter, setFilter] = useState("");
  const [searchHits, setSearchHits] = useState<LocationSearchHit[]>([]);
  const [picked, setPicked] = useState<Record<string, boolean>>({});
  const [loading, setLoading] = useState(false);
  const [hint, setHint] = useState("");

  useEffect(() => {
    if (statesProp?.length) {
      setStates(statesProp);
      return;
    }
    invoke<string[]>("list_states")
      .then(setStates)
      .catch(() => setHint("Could not load states from location database."));
  }, [statesProp]);

  useEffect(() => {
    if (state || states.length === 0) return;
    setState(states.includes("Karnataka") ? "Karnataka" : states[0]);
  }, [states, state]);

  useEffect(() => {
    if (!state) return;
    invoke<LocationNode[]>("list_districts", { stateName: state })
      .then(setDistricts)
      .catch(() => setDistricts([]));
    setDistrict("");
    setPlaces([]);
    setPicked({});
  }, [state]);

  const loadPlaces = useCallback(async () => {
    if (!state) return;
    setLoading(true);
    setHint("");
    try {
      if (district) {
        const d = districts.find((x) => x.name === district);
        if (!d) {
          setPlaces([]);
          return;
        }
        const nodes = await invoke<LocationNode[]>("location_list_children", {
          parentId: d.id,
          includeInactive: false,
        });
        setPlaces(
          nodes.map((n) => ({
            id: n.id,
            label: placeLabel(n, district, state),
          })),
        );
      } else {
        const labels = await invoke<string[]>("discover_cities", {
          stateName: state,
          districtName: null,
        });
        setPlaces(
          labels.map((label, i) => ({
            id: `state-${state}-${i}`,
            label,
          })),
        );
        if (labels.length > 500) {
          setHint(`${labels.length} places in ${state}. Pick a district or use Search filter to narrow down.`);
        }
      }
    } catch (e) {
      setHint(String(e));
      setPlaces([]);
    } finally {
      setLoading(false);
    }
  }, [state, district, districts]);

  useEffect(() => {
    if (district) {
      loadPlaces();
    }
  }, [district, loadPlaces]);

  useEffect(() => {
    const q = filter.trim();
    if (q.length < 2) {
      setSearchHits([]);
      return;
    }
    const t = window.setTimeout(() => {
      invoke<LocationSearchHit[]>("search_india_locations", {
        query: q,
        stateName: state || null,
        districtName: district || null,
        limit: 150,
      })
        .then(setSearchHits)
        .catch(() => setSearchHits([]));
    }, 280);
    return () => window.clearTimeout(t);
  }, [filter, state, district]);

  const rows = useMemo(() => {
    if (filter.trim().length >= 2 && searchHits.length > 0) {
      return searchHits.map((h) => ({ id: h.id, label: h.label }));
    }
    const q = filter.trim().toLowerCase();
    if (!q) return places;
    return places.filter((p) => p.label.toLowerCase().includes(q));
  }, [places, searchHits, filter]);

  const addPicked = () => {
    const labels = rows.filter((r) => picked[r.id]).map((r) => r.label);
    if (labels.length) {
      onAdd(labels);
      setPicked({});
      return;
    }
    setHint("Select one or more places, or double-click a row to add it.");
  };

  const addOne = (label: string) => {
    onAdd([label]);
  };

  const pickedCount = Object.values(picked).filter(Boolean).length;

  return (
    <div className="location-picker card nested">
      <button
        type="button"
        className="location-picker-toggle"
        onClick={() => setExpanded((e) => !e)}
        aria-expanded={expanded}
      >
        <span>Browse State → District → City</span>
        <span className="muted">{expanded ? "Hide" : "Show"}</span>
      </button>
      {expanded && (
        <>
          <p className="muted" style={{ marginTop: 0 }}>
            Select from your location database (same data as Location Manager). Added locations appear
            in the list below; you can still type custom locations manually.
          </p>
          <div className="row location-picker-filters">
            <div>
              <label>State / UT</label>
              <select value={state} onChange={(e) => setState(e.target.value)}>
                {states.map((s) => (
                  <option key={s} value={s}>
                    {s}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label>District</label>
              <select value={district} onChange={(e) => setDistrict(e.target.value)}>
                <option value="">All districts (large list)</option>
                {districts.map((d) => (
                  <option key={d.id} value={d.name}>
                    {d.name}
                  </option>
                ))}
              </select>
            </div>
            <div style={{ display: "flex", alignItems: "flex-end" }}>
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                disabled={loading || !state}
                onClick={() => loadPlaces()}
              >
                {loading ? "Loading…" : district ? "Reload places" : "Load all places in state"}
              </button>
            </div>
          </div>
          <label>Search / filter places</label>
          <input
            placeholder="Type city or town name (min 2 characters searches entire database)"
            value={filter}
            onChange={(e) => setFilter(e.target.value)}
          />
          {hint && <p className="muted">{hint}</p>}
          <div className="location-picker-list">
            {rows.length === 0 && !loading && (
              <p className="muted">
                {places.length === 0
                  ? "Choose a district or click Load places, or type in the search box above."
                  : "No matches for this filter."}
              </p>
            )}
            {rows.slice(0, 400).map((r) => (
              <div
                key={r.id}
                className={`loc-row ${picked[r.id] ? "loc-row-selected" : ""}`}
                onDoubleClick={() => addOne(r.label)}
              >
                <label className="location-picker-row-label">
                  <input
                    type="checkbox"
                    checked={!!picked[r.id]}
                    onChange={(e) => setPicked({ ...picked, [r.id]: e.target.checked })}
                  />
                  <span>{r.label}</span>
                </label>
                <button
                  type="button"
                  className="btn btn-ghost btn-sm"
                  onClick={() => addOne(r.label)}
                >
                  Add
                </button>
              </div>
            ))}
            {rows.length > 400 && (
              <p className="muted">Showing first 400 of {rows.length}. Narrow district or search.</p>
            )}
          </div>
          <div className="location-picker-actions">
            <button type="button" className="btn btn-primary btn-sm" onClick={addPicked}>
              Add selected to search ({pickedCount})
            </button>
            <button type="button" className="btn btn-ghost btn-sm" onClick={() => setPicked({})}>
              Clear selection
            </button>
          </div>
        </>
      )}
    </div>
  );
}
