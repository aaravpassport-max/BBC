import { useCallback, useEffect, useMemo, useState } from "react";
import { invoke } from "@tauri-apps/api/core";
import { listen } from "@tauri-apps/api/event";
import { open, save } from "@tauri-apps/plugin-dialog";
import { openUrl } from "@tauri-apps/plugin-opener";
import {
  AppSettings,
  BusinessRow,
  JobProgress,
  JobRecord,
  SearchParams,
  View,
} from "./lib/types";
import {
  COVERAGE_OPTIONS,
  DEPTH_OPTIONS,
  FIELD_OPTIONS,
  RATE_OPTIONS,
  SEARCH_PRESETS,
} from "./lib/presets";
import "./styles.css";

function fmtTime(secs: number) {
  const m = Math.floor(secs / 60)
    .toString()
    .padStart(2, "0");
  const s = (secs % 60).toString().padStart(2, "0");
  return `${m}:${s}`;
}

export default function App() {
  const [view, setView] = useState<View>("welcome");
  const [settings, setSettings] = useState<AppSettings | null>(null);
  const [startupError, setStartupError] = useState("");
  const [keywords, setKeywords] = useState<string[]>(["transcript providers"]);
  const [locations, setLocations] = useState<string[]>(["Bangalore, Karnataka"]);
  const [depth, setDepth] = useState("balanced");
  const [coverage, setCoverage] = useState("Standard");
  const [rate, setRate] = useState("balanced");
  const [emailExtract, setEmailExtract] = useState(false);
  const [selectedFields, setSelectedFields] = useState<string[]>(FIELD_OPTIONS);
  const [history, setHistory] = useState<JobRecord[]>([]);
  const [activeJobId, setActiveJobId] = useState<string | null>(null);
  const [progress, setProgress] = useState<JobProgress | null>(null);
  const [results, setResults] = useState<BusinessRow[]>([]);
  const [filter, setFilter] = useState("");
  const [selected, setSelected] = useState<BusinessRow | null>(null);
  const [states, setStates] = useState<string[]>([]);
  const [batchState, setBatchState] = useState("Karnataka");
  const [batchDistrict, setBatchDistrict] = useState("");
  const [batchDistricts, setBatchDistricts] = useState<{ id: string; name: string; placeCount: number }[]>([]);
  const [batchCities, setBatchCities] = useState<string[]>([]);
  const [batchPlaceFilter, setBatchPlaceFilter] = useState("");
  const [batchSelected, setBatchSelected] = useState<Record<string, boolean>>({});
  const [locationDbInfo, setLocationDbInfo] = useState<string>("");
  const [confirmBatch, setConfirmBatch] = useState(false);
  const [statusMsg, setStatusMsg] = useState("");
  const [engineStarting, setEngineStarting] = useState(true);
  const [engineReady, setEngineReady] = useState(false);
  const [unfinished, setUnfinished] = useState<JobRecord | null>(null);
  const [lastExportPath, setLastExportPath] = useState("");

  const runExport = async (format: "csv" | "xlsx") => {
    if (!activeJobId || !settings) return;
    try {
      const suggested = await invoke<string>("export_suggested_filename", {
        jobId: activeJobId,
        format,
      });
      const folder = settings.exportFolder.replace(/\\/g, "/").replace(/\/$/, "");
      const defaultPath = `${folder}/${suggested}`;
      const chosen = await save({
        title: format === "csv" ? "Save CSV as" : "Save Excel as",
        defaultPath,
        filters: [
          format === "csv"
            ? { name: "CSV file", extensions: ["csv"] }
            : { name: "Excel workbook", extensions: ["xlsx"] },
        ],
      });
      if (!chosen) {
        setStatusMsg("Export cancelled.");
        return;
      }
      const path = await invoke<string>("export_job", {
        jobId: activeJobId,
        format,
        savePath: chosen,
      });
      setLastExportPath(path);
      setStatusMsg(`Saved: ${path}`);
      await invoke("reveal_export_file", { path });
    } catch (e) {
      setStatusMsg(`Export failed: ${String(e)}`);
    }
  };

  const refreshHistory = useCallback(async () => {
    const rows = await invoke<JobRecord[]>("list_job_history");
    setHistory(rows);
  }, []);

  const loadSettings = useCallback(async () => {
    const s = await invoke<AppSettings>("get_settings");
    setSettings(s);
    setDepth(s.defaultDepth);
    setCoverage(s.defaultCoverage);
    setRate(s.defaultRate);
    setEmailExtract(s.emailExtraction);
    if (s.welcomeDone && s.responsibleUseAck) setView("dashboard");
    else if (s.welcomeDone) setView("responsible");
  }, []);

  useEffect(() => {
    (async () => {
      const report = await invoke<{ ok: boolean; message: string }>("startup_check");
      if (!report.ok) {
        setStartupError(report.message);
        setEngineStarting(false);
        return;
      }
      await invoke("ensure_search_engine")
        .then(() => {
          setEngineReady(true);
          setEngineStarting(false);
        })
        .catch((e) => {
          setStartupError(String(e));
          setEngineStarting(false);
        });
      await loadSettings();
      await refreshHistory();
      const st = await invoke<string[]>("list_states");
      setStates(st);
      try {
        const m = await invoke<{
          statesAndUnionTerritories: number;
          districts: number;
          places: number;
          generatedAt: string;
        }>("india_location_manifest");
        setLocationDbInfo(
          `${m.statesAndUnionTerritories} states/UTs · ${m.districts} districts · ${m.places} places (updated ${m.generatedAt.slice(0, 10)})`,
        );
      } catch {
        /* optional */
      }
      const open = await invoke<JobRecord | null>("get_unfinished_job");
      if (open) setUnfinished(open);
    })();
  }, [loadSettings, refreshHistory]);

  useEffect(() => {
    const un = listen("engine-ready", () => {
      setEngineReady(true);
      setEngineStarting(false);
    });
    return () => {
      un.then((f) => f());
    };
  }, []);

  useEffect(() => {
    const unsubs = [
      listen<JobProgress>("job-progress", (e) => {
        setProgress(e.payload);
        setView("progress");
      }),
      listen<{ jobId: string; resultCount?: number; errorMessage?: string }>(
        "job-completed",
        async (e) => {
          const jobId = e.payload.jobId;
          setActiveJobId(jobId);
          const rows = await invoke<BusinessRow[]>("get_job_results", { jobId });
          setResults(rows);
          await refreshHistory();
          setView("results");
          if (rows.length === 0 && e.payload.errorMessage) {
            setStatusMsg(e.payload.errorMessage);
          } else if (rows.length === 0) {
            setStatusMsg(
              "No businesses were returned. Try Fast coverage, a broader keyword, or wait a few minutes on first run while the browser runtime prepares.",
            );
          } else {
            setStatusMsg("");
          }
        },
      ),
      listen<string>("job-progress-hint", (e) => {
        setStatusMsg(e.payload);
      }),
      listen<string>("job-error", (e) => {
        setStatusMsg(e.payload);
      }),
    ];
    return () => {
      unsubs.forEach((p) => p.then((u) => u()));
    };
  }, [refreshHistory]);

  const filteredResults = useMemo(() => {
    const q = filter.trim().toLowerCase();
    if (!q) return results;
    return results.filter(
      (r) =>
        r.title.toLowerCase().includes(q) ||
        r.city.toLowerCase().includes(q) ||
        r.phone.toLowerCase().includes(q),
    );
  }, [results, filter]);

  const saveSettings = async (patch: Partial<AppSettings>) => {
    if (!settings) return;
    const next = { ...settings, ...patch };
    await invoke("save_settings", { settings: next });
    setSettings(next);
  };

  const startSearch = async (override?: Partial<SearchParams>) => {
    if (!engineReady) {
      setStatusMsg("The search engine is still starting. Please wait a moment.");
      return;
    }
    setStatusMsg("");
    const params: SearchParams = {
      keywords,
      locations,
      depthLabel: depth,
      coverageMode: coverage,
      rateMode: rate,
      emailExtraction: emailExtract,
      fields: selectedFields,
      ...override,
    };
    await invoke("start_search", { params });
    setView("progress");
  };

  useEffect(() => {
    if (!batchState) return;
    invoke<{ id: string; name: string; placeCount: number }[]>("list_districts", {
      stateName: batchState,
    }).then(setBatchDistricts);
  }, [batchState]);

  const discoverCities = async () => {
    const cities = await invoke<string[]>("discover_cities", {
      stateName: batchState,
      districtName: batchDistrict || null,
    });
    setBatchCities(cities);
    setBatchPlaceFilter("");
    const sel: Record<string, boolean> = {};
    cities.forEach((c) => {
      sel[c] = true;
    });
    setBatchSelected(sel);
    setView("batch");
  };

  const filteredBatchCities = useMemo(() => {
    const q = batchPlaceFilter.trim().toLowerCase();
    if (!q) return batchCities;
    return batchCities.filter((c) => c.toLowerCase().includes(q));
  }, [batchCities, batchPlaceFilter]);

  const renderWelcome = () => (
    <div className="welcome card">
      <h2>Welcome to Google Maps Scraper</h2>
      <p className="muted">
        Find businesses and service providers from Google Maps and organize the
        results for your research.
      </p>
      <p className="muted">Internet connection required for searches.</p>
      <button
        className="btn btn-primary"
        onClick={async () => {
          await saveSettings({ welcomeDone: true });
          setView("responsible");
        }}
      >
        Get Started
      </button>
    </div>
  );

  const renderResponsible = () => (
    <div className="welcome card">
      <h2>Responsible Use</h2>
      <p className="muted">
        Use this application only for legitimate research and business purposes.
        Respect applicable laws, website terms, privacy requirements and rate
        limits.
      </p>
      <button
        className="btn btn-primary"
        onClick={async () => {
          await saveSettings({ responsibleUseAck: true });
          setView("dashboard");
        }}
      >
        I Understand
      </button>
    </div>
  );

  const renderDashboard = () => (
    <>
      <div className="card">
        <h2>New Search</h2>
        <label>What are you looking for?</label>
        {keywords.map((k, i) => (
          <input
            key={i}
            value={k}
            onChange={(e) => {
              const copy = [...keywords];
              copy[i] = e.target.value;
              setKeywords(copy);
            }}
          />
        ))}
        <button
          className="btn btn-ghost"
          onClick={() => setKeywords([...keywords, ""])}
        >
          + Add keyword
        </button>
        <div style={{ marginTop: 8 }}>
          {SEARCH_PRESETS.map((p) => (
            <button
              key={p}
              className="btn btn-secondary"
              style={{ marginRight: 6, marginBottom: 6 }}
              onClick={() => setKeywords([p.toLowerCase()])}
            >
              {p}
            </button>
          ))}
        </div>
        <label>Location</label>
        {locations.map((l, i) => (
          <input
            key={i}
            value={l}
            onChange={(e) => {
              const copy = [...locations];
              copy[i] = e.target.value;
              setLocations(copy);
            }}
          />
        ))}
        <button
          className="btn btn-ghost"
          onClick={() => setLocations([...locations, ""])}
        >
          + Add location
        </button>
        <div className="row">
          <div>
            <label>Search coverage</label>
            <select value={depth} onChange={(e) => setDepth(e.target.value)}>
              {DEPTH_OPTIONS.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.label}
                </option>
              ))}
            </select>
            <span className="muted" title="Higher coverage may take longer and may generate more requests.">
              Higher coverage may take longer.
            </span>
          </div>
          <div>
            <label>Coverage mode</label>
            <select value={coverage} onChange={(e) => setCoverage(e.target.value)}>
              {COVERAGE_OPTIONS.map((c) => (
                <option key={c}>{c}</option>
              ))}
            </select>
            <span className="muted">
              Coverage depends on Google Maps results and available public business information.
            </span>
          </div>
        </div>
        <label>Request behavior</label>
        <div>
          {RATE_OPTIONS.map((r) => (
            <label key={r.id} style={{ display: "inline-flex", marginRight: 16 }}>
              <input
                type="radio"
                name="rate"
                checked={rate === r.id}
                onChange={() => setRate(r.id)}
              />{" "}
              {r.label}
            </label>
          ))}
        </div>
        <label>Data to collect</label>
        <div className="field-grid">
          {FIELD_OPTIONS.map((f) => (
            <label key={f}>
              <input
                type="checkbox"
                checked={selectedFields.includes(f)}
                onChange={(e) => {
                  if (e.target.checked) setSelectedFields([...selectedFields, f]);
                  else setSelectedFields(selectedFields.filter((x) => x !== f));
                }}
              />{" "}
              {f}
            </label>
          ))}
        </div>
        <label title="If a business website is available, the app may inspect public contact pages for publicly listed email addresses.">
          <input
            type="checkbox"
            checked={emailExtract}
            onChange={(e) => setEmailExtract(e.target.checked)}
          />{" "}
          Find publicly listed email addresses
        </label>
        <div style={{ marginTop: 16 }}>
          <button className="btn btn-primary" onClick={() => startSearch()}>
            START SEARCH
          </button>
        </div>
      </div>
      <div className="card">
        <h2>State batch research</h2>
        {locationDbInfo && <p className="muted">India location database: {locationDbInfo}</p>}
        <div className="row">
          <div>
            <label>State / UT</label>
            <select value={batchState} onChange={(e) => setBatchState(e.target.value)}>
              {states.map((s) => (
                <option key={s}>{s}</option>
              ))}
            </select>
          </div>
          <div>
            <label>District (optional)</label>
            <select value={batchDistrict} onChange={(e) => setBatchDistrict(e.target.value)}>
              <option value="">All districts in state</option>
              {batchDistricts.map((d) => (
                <option key={d.id} value={d.name}>
                  {d.name} ({d.placeCount} places)
                </option>
              ))}
            </select>
          </div>
          <div style={{ display: "flex", alignItems: "flex-end" }}>
            <button className="btn btn-secondary" onClick={discoverCities}>
              Load places
            </button>
          </div>
        </div>
      </div>
      <div className="card">
        <h2>Recent Searches</h2>
        {history.length === 0 && <p className="muted">No searches yet.</p>}
        {history.slice(0, 8).map((h) => (
          <div
            key={h.id}
            style={{
              display: "flex",
              justifyContent: "space-between",
              padding: "8px 0",
              borderBottom: "1px solid #f1f5f9",
            }}
          >
            <span>
              {h.name} — <span className="pill">{h.status}</span>
            </span>
            <span>
              {h.resultCount} results
              {h.duplicatesRemoved > 0 && ` (${h.duplicatesRemoved} duplicates removed)`}
            </span>
            <button
              className="btn btn-ghost"
              onClick={async () => {
                setActiveJobId(h.id);
                setResults(await invoke("get_job_results", { jobId: h.id }));
                setView("results");
              }}
            >
              Open
            </button>
          </div>
        ))}
      </div>
    </>
  );

  const renderProgress = () => (
    <div className="card">
      <h2>{progress?.captchaPause ? "Search temporarily paused" : "Searching"}</h2>
      {progress?.captchaPause ? (
        <p>Google Maps requires additional verification. Try again later.</p>
      ) : (
        <>
          <p>
            <strong>Location:</strong> {progress?.location || "—"}
          </p>
          <p>
            <strong>Query:</strong> {progress?.query || "—"}
          </p>
          <p>
            <strong>Progress:</strong> {progress?.completedUnits ?? 0} /{" "}
            {progress?.totalUnits ?? 0}
          </p>
          <p>
            <strong>Businesses discovered:</strong> {progress?.businessesDiscovered ?? 0}
          </p>
          <p>
            <strong>Elapsed:</strong> {fmtTime(progress?.elapsedSecs ?? 0)}
            {progress?.estimatedRemainingSecs != null &&
              ` · Est. remaining: ${fmtTime(progress.estimatedRemainingSecs)}`}
          </p>
          {progress?.batchItems?.length ? (
            <div className="table-wrap" style={{ marginTop: 12 }}>
              <table>
                <tbody>
                  {progress.batchItems.map((b) => (
                    <tr key={b.location}>
                      <td>{b.location}</td>
                      <td>{b.status}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}
        </>
      )}
      <div style={{ marginTop: 16, display: "flex", gap: 8 }}>
        <button className="btn btn-secondary" onClick={() => invoke("pause_search")}>
          Pause
        </button>
        <button className="btn btn-secondary" onClick={() => invoke("resume_search")}>
          Resume
        </button>
        <button className="btn btn-secondary" onClick={() => invoke("stop_search")}>
          Stop
        </button>
      </div>
    </div>
  );

  const renderResults = () => (
    <>
      <div className="card">
        <h2>
          Results: {results.length} businesses
          {history.find((h) => h.id === activeJobId)?.duplicatesRemoved
            ? ` · ${history.find((h) => h.id === activeJobId)?.duplicatesRemoved} duplicates removed`
            : ""}
        </h2>
        <input
          placeholder="Search results"
          value={filter}
          onChange={(e) => setFilter(e.target.value)}
        />
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Business</th>
                <th>Category</th>
                <th>City</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Rating</th>
                <th>Reviews</th>
              </tr>
            </thead>
            <tbody>
              {filteredResults.map((r) => (
                <tr key={r.id} onClick={() => setSelected(r)} style={{ cursor: "pointer" }}>
                  <td>{r.title}</td>
                  <td>{r.category || "Not available"}</td>
                  <td>{r.city}</td>
                  <td>{r.phone || "Not available"}</td>
                  <td>{r.email || "Not available"}</td>
                  <td>{r.reviewRating || "Not available"}</td>
                  <td>{r.reviewCount || "Not available"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {settings && (
          <p className="muted" style={{ marginTop: 8 }}>
            Choose where to save with the buttons below. Default folder:{" "}
            <code>{settings.exportFolder}</code>
          </p>
        )}
        {lastExportPath && (
          <p className="muted">
            Last saved file: <code>{lastExportPath}</code>
          </p>
        )}
        <div style={{ marginTop: 12, display: "flex", gap: 8, flexWrap: "wrap" }}>
          <button className="btn btn-primary" onClick={() => runExport("csv")}>
            Save CSV as…
          </button>
          <button className="btn btn-primary" onClick={() => runExport("xlsx")}>
            Save Excel as…
          </button>
          <button
            className="btn btn-secondary"
            onClick={async () => {
              const dir = await invoke<string>("open_exports_folder");
              setStatusMsg(`Opened folder: ${dir}`);
            }}
          >
            Open exports folder
          </button>
          {lastExportPath && (
            <button
              className="btn btn-secondary"
              onClick={() => invoke("reveal_export_file", { path: lastExportPath })}
            >
              Show last file in Explorer
            </button>
          )}
          <button className="btn btn-secondary" onClick={() => setView("dashboard")}>
            Back
          </button>
        </div>
      </div>
      {selected && (
        <div className="card detail-panel">
          <h2>{selected.title}</h2>
          <p>
            <strong>Category:</strong> {selected.category || "Not available"}
          </p>
          <p>
            <strong>Address:</strong> {selected.address || "Not available"}
          </p>
          <p>
            <strong>Phone:</strong> {selected.phone || "Not available"}
          </p>
          <p>
            <strong>Website:</strong> {selected.website || "Not available"}
          </p>
          <p>
            <strong>Email:</strong> {selected.email || "Not available"}
          </p>
          <p>
            <strong>Rating:</strong> {selected.reviewRating || "Not available"}
          </p>
          {selected.mapsUrl && (
            <button className="btn btn-secondary" onClick={() => openUrl(selected.mapsUrl)}>
              Open in Browser
            </button>
          )}
        </div>
      )}
    </>
  );

  const renderBatch = () => (
    <div className="card">
      <h2>
        {batchState}
        {batchDistrict ? ` · ${batchDistrict}` : ""}
      </h2>
      <p className="muted">{batchCities.length} places loaded</p>
      {!confirmBatch ? (
        <>
          <input
            placeholder="Filter places…"
            value={batchPlaceFilter}
            onChange={(e) => setBatchPlaceFilter(e.target.value)}
          />
          <div className="field-grid" style={{ maxHeight: 360, overflow: "auto" }}>
            {filteredBatchCities.map((c) => (
              <label key={c}>
                <input
                  type="checkbox"
                  checked={!!batchSelected[c]}
                  onChange={(e) =>
                    setBatchSelected({ ...batchSelected, [c]: e.target.checked })
                  }
                />{" "}
                {c}
              </label>
            ))}
          </div>
          <button
            className="btn btn-ghost"
            onClick={() => {
              const all: Record<string, boolean> = { ...batchSelected };
              filteredBatchCities.forEach((c) => {
                all[c] = true;
              });
              setBatchSelected(all);
            }}
          >
            Select all (filtered)
          </button>
          <button
            className="btn btn-ghost"
            onClick={() => setBatchSelected({})}
          >
            Clear
          </button>
          <div style={{ marginTop: 16 }}>
            <button className="btn btn-primary" onClick={() => setConfirmBatch(true)}>
              Review batch
            </button>
          </div>
        </>
      ) : (
        <>
          <p>
            You are about to search:{" "}
            {Object.values(batchSelected).filter(Boolean).length} locations ·{" "}
            {keywords.length} keywords · estimated searches:{" "}
            {Object.values(batchSelected).filter(Boolean).length * keywords.length}
          </p>
          <p className="muted">This may take several minutes.</p>
          <button
            className="btn btn-primary"
            onClick={async () => {
              setConfirmBatch(false);
              const locs = batchCities.filter((c) => batchSelected[c]);
              setLocations(locs);
              await startSearch({
                locations: locs,
                projectName: `${batchState} ${keywords[0] ?? "Research"}`,
              });
            }}
          >
            Start Batch
          </button>
          <button className="btn btn-secondary" onClick={() => setConfirmBatch(false)}>
            Cancel
          </button>
        </>
      )}
    </div>
  );

  const renderSettings = () =>
    settings && (
      <div className="card">
        <h2>Settings</h2>
        <label>Default search coverage</label>
        <select
          value={settings.defaultDepth}
          onChange={(e) => saveSettings({ defaultDepth: e.target.value })}
        >
          {DEPTH_OPTIONS.map((d) => (
            <option key={d.id} value={d.id}>
              {d.label}
            </option>
          ))}
        </select>
        <label>Default export folder (used as the starting location in Save dialogs)</label>
        <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
          <input
            style={{ flex: 1 }}
            value={settings.exportFolder}
            onChange={(e) => saveSettings({ exportFolder: e.target.value })}
          />
          <button
            type="button"
            className="btn btn-secondary"
            onClick={async () => {
              const picked = await open({
                directory: true,
                multiple: false,
                defaultPath: settings.exportFolder,
                title: "Choose default export folder",
              });
              if (typeof picked === "string" && picked) {
                await saveSettings({ exportFolder: picked });
              }
            }}
          >
            Browse…
          </button>
          <button
            type="button"
            className="btn btn-secondary"
            onClick={async () => {
              const dir = await invoke<string>("open_exports_folder");
              setStatusMsg(`Opened folder: ${dir}`);
            }}
          >
            Open
          </button>
        </div>
        <label>
          <input
            type="checkbox"
            checked={settings.deduplication}
            onChange={(e) => saveSettings({ deduplication: e.target.checked })}
          />{" "}
          Deduplication
        </label>
        <label>
          <input
            type="checkbox"
            checked={settings.emailExtraction}
            onChange={(e) => saveSettings({ emailExtraction: e.target.checked })}
          />{" "}
          Email extraction default
        </label>
        <p className="muted">Telemetry: OFF (default)</p>
        <button
          className="btn btn-secondary"
          onClick={async () => {
            const dir = await invoke<string>("open_logs_folder");
            setStatusMsg(`Logs folder: ${dir}`);
          }}
        >
          Diagnostics → Open Logs
        </button>
        <button
          className="btn btn-secondary"
          style={{ marginLeft: 8 }}
          onClick={async () => {
            await invoke("clear_all_data");
            await refreshHistory();
            setResults([]);
          }}
        >
          Delete all local results
        </button>
      </div>
    );

  if (engineStarting && !startupError) {
    return (
      <div className="splash">
        <div className="splash-card">
          <h2>Google Maps Scraper</h2>
          <p className="muted">Preparing the built-in search engine…</p>
          <p className="muted">First launch can take up to a minute.</p>
          <div className="spinner" aria-hidden />
        </div>
      </div>
    );
  }

  return (
    <div className="app-shell">
      {unfinished && (
        <div className="card" style={{ margin: "16px 22px 0" }}>
          <h2>An unfinished search was found</h2>
          <p>{unfinished.name}</p>
          <button
            className="btn btn-primary"
            onClick={async () => {
              setActiveJobId(unfinished.id);
              setResults(await invoke("get_job_results", { jobId: unfinished.id }));
              setUnfinished(null);
              setView("results");
            }}
          >
            View partial results
          </button>
          <button
            className="btn btn-secondary"
            style={{ marginLeft: 8 }}
            onClick={async () => {
              await invoke("discard_unfinished_job", { jobId: unfinished.id });
              setUnfinished(null);
            }}
          >
            Discard
          </button>
        </div>
      )}
      <header className="topbar">
        <h1>Google Maps Scraper</h1>
        <div>
          <button className="btn btn-ghost" onClick={() => setView("dashboard")}>
            Dashboard
          </button>
          <button className="btn btn-ghost" onClick={() => setView("settings")}>
            Settings
          </button>
        </div>
      </header>
      <main className="content">
        {(startupError || statusMsg) && (
          <div className="error-banner">{startupError || statusMsg}</div>
        )}
        {view === "welcome" && renderWelcome()}
        {view === "responsible" && renderResponsible()}
        {view === "dashboard" && renderDashboard()}
        {view === "progress" && renderProgress()}
        {view === "results" && renderResults()}
        {view === "batch" && renderBatch()}
        {view === "settings" && renderSettings()}
      </main>
    </div>
  );
}
