# Google Maps Scraper Desktop — Architecture

## Source audit (Google-Maps-Scraper-Desktop-App-v3.zip)

The supplied kit (`google-maps-scraper-kit-master`) is a **Docker orchestration wrapper** around the upstream **[gosom/google-maps-scraper](https://github.com/gosom/google-maps-scraper)** Go application (MIT). It is **not** a native desktop product.

| Area | Finding |
|------|---------|
| **Entry points** | `docker compose up -d`; `scripts/scrape.py` / `scrape.sh`; `START_ONE_CLICK.vbs` (starts Docker, opens browser to `http://127.0.0.1:8080`); Claude slash commands |
| **Scraping engine** | `gosom/google-maps-scraper:v1.15.0` container, `-web -data-folder /gmapsdata` |
| **Browser automation** | Playwright/Chromium **inside the Go binary** (cached in Docker volume `/opt`) |
| **Network requests** | Google Maps (via engine); optional business websites for email/social enrichment in `scrape.py` |
| **Dependencies (user-facing today)** | **Docker Desktop** — hard requirement |
| **External services** | Google Maps; OpenStreetMap Nominatim for geocoding (`scrape.py`); business websites (optional) |
| **Database/storage** | Docker volumes `gmaps_data`, `gmaps_cache`; CSV job downloads via REST |
| **API endpoints** | `POST/GET/DELETE /api/v1/jobs`, `GET /api/v1/jobs/{id}/download`, OpenAPI at `/api/docs` |
| **Export** | CSV from API; `scrape.py` trims to lead fields or `--full` for ~34 columns |
| **Configuration** | `.env.example`; job JSON body (`depth`, `keywords`, `lat`/`lon`, `email`, `max_time`, optional `proxies`) |
| **Authentication** | None on localhost API (127.0.0.1 binding is the security boundary) |
| **Anti-bot / CAPTCHA** | No bypass; upstream warns about IP rate limits; job `failed` or empty results |
| **Docker-specific** | Entire runtime: `docker-compose.yml`, `CHECK_DOCKER.bat`, VBS launcher |
| **Python/Node** | Python stdlib client only (dev/scripting); no Node in kit |
| **Browser/runtime (user)** | Implicit via Docker image — not installed on host |

## Target architecture (this repository)

```text
Google Maps Scraper.exe  (Tauri shell — no console)
    ├── WebView UI (React)
    ├── Rust application layer
    │     ├── Job orchestration, SQLite history, dedup, export
    │     ├── Geocoding (Nominatim)
    │     └── Optional public email fetch from business websites
    ├── scraper-engine (bundled gosom/google-maps-scraper binary, hidden child)
    │     └── Embedded Playwright/browser runtime
    └── User data: %LOCALAPPDATA%\GoogleMapsScraper\
          config, logs, jobs, results, cache, engine-data
```

**Docker is not used in production.** The same upstream engine runs as a **bundled sidecar** on `127.0.0.1:8080`.

## Module layout

```text
google-maps-scraper-desktop/
├── src/                    # React UI
├── src-tauri/src/
│   ├── commands.rs         # Tauri IPC
│   ├── scraper_engine.rs   # Sidecar lifecycle
│   ├── api_client.rs       # REST to local engine
│   ├── jobs.rs             # Search/batch workers
│   ├── models.rs
│   ├── storage.rs          # SQLite + paths
│   ├── geocode.rs
│   ├── dedup.rs
│   ├── export.rs
│   ├── email_enrich.rs
│   ├── india_locations.rs
│   └── logging.rs
├── scripts/download-scraper-engine.js
└── installer/setup.iss       # Inno Setup (Windows CI)
```

## External connections (shipped app)

1. **Google Maps** — business search (via bundled engine)
2. **OpenStreetMap Nominatim** — geocoding (`nominatim.openstreetmap.org`)
3. **Business websites** — optional public contact/email inspection when enabled

No telemetry. No other domains by default.

## Security model

- Local REST API bound to loopback only
- No CAPTCHA bypass, no credential harvesting
- Logs exclude secrets
- Child scraper process started with hidden console on Windows

See [SECURITY.md](./SECURITY.md) for details.
