# India location database

The app maintains a **hierarchical India location dataset** in SQLite on your machine. It powers batch research, typeahead search, and geocoding (coordinates are tried from this database before online geocoders).

## Hierarchy

```
State or Union Territory
  └── District
        └── City / town (place)
```

Each place has a stable **label** for jobs and geocoding, e.g.:

- `Bengaluru, Bengaluru Urban, Karnataka`
- `Shimla, Shimla, Himachal Pradesh`

## User-managed data (Location Manager)

| Storage | Path (typical) |
|---------|----------------|
| SQLite database | `%LOCALAPPDATA%\GoogleMapsScraper\config\app.db` |
| Bundled seed (first run only) | `src-tauri/assets/india-locations/india-locations.json` |

On first launch, the app **seeds** `location_nodes` from the bundled JSON. After that, **your database is not overwritten** on app updates. Use **Location Manager** (top navigation → Locations) to:

- Add, edit, rename, activate/deactivate, merge, or delete locations
- Import/export JSON or CSV in bulk
- Find duplicates under the same parent
- Reset to the bundled seed (destructive)

Changes apply **immediately** to batch “Load places”, search, and scraping geocoding — no reinstall or code edits.

## Developer: regenerating the bundled seed

For shipping updated default data in installers, from `google-maps-scraper-desktop/`:

```bash
npm run build:india-locations
```

Commit updated `india-locations.json` and `manifest.json`.

## Sources (bundled seed)

| Layer | Source | License |
|-------|--------|---------|
| States & UTs | [dr5hn/countries-states-cities-database](https://github.com/dr5hn/countries-states-cities-database) | ODbL |
| Districts & places | [GeoNames](https://www.geonames.org/) | CC BY 4.0 |

## Tauri commands (scraper integration)

- `list_states`, `list_districts`, `discover_cities`, `search_india_locations`, `india_location_manifest` — read **active** data from SQLite
- `location_*` — full Location Manager API (CRUD, import/export, duplicates, reset)

Geocoding order: **location DB** → Nominatim → Photon → offline city hints.
