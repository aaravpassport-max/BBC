# India location database

The app ships a **hierarchical India location dataset** used for batch research, geocoding, and search.

## Hierarchy

```
State or Union Territory (36)
  └── District (~763, GeoNames admin2 / LGD-aligned)
        └── City / town (populated places, pop ≥ 5,000 or administrative seats)
```

Each place has a stable **`label`** for jobs and geocoding, e.g.:

- `Bengaluru, Bengaluru Urban, Karnataka`
- `Shimla, Shimla, Himachal Pradesh` (district HQ)

## Sources (authoritative open data)

| Layer | Source | License |
|-------|--------|---------|
| States & UTs, ISO names/types | [dr5hn/countries-states-cities-database](https://github.com/dr5hn/countries-states-cities-database) | ODbL |
| Districts & places | [GeoNames](https://www.geonames.org/) `admin2Codes.txt`, `IN.zip` | CC BY 4.0 |

Government reference: [Local Government Directory (LGD)](https://lgdirectory.gov.in/) — GeoNames admin2 districts track LGD-style divisions; regenerate when boundaries change.

## Files

| Path | Purpose |
|------|---------|
| `src-tauri/assets/india-locations/india-locations.json` | Bundled dataset (generated) |
| `src-tauri/assets/india-locations/manifest.json` | Schema version, counts, generation time |
| `src-tauri/assets/india-locations/ATTRIBUTION.md` | Credits |
| `scripts/build-india-locations.mjs` | Regeneration script |

## Updating the database

From `google-maps-scraper-desktop/`:

```bash
npm run build:india-locations
```

Commit updated `india-locations.json` and `manifest.json`. CI should run the same script and fail if output drifts (optional check).

## API (Tauri commands)

- `list_states` — all state/UT names  
- `list_districts(stateName)` — districts in a state  
- `discover_cities(stateName, districtName?)` — location labels for batch UI  
- `search_india_locations(query, stateName?, districtName?, limit?)` — typeahead search  
- `india_location_manifest()` — version and counts  

Geocoding tries **India DB coordinates first**, then Nominatim, Photon, and offline hints.
