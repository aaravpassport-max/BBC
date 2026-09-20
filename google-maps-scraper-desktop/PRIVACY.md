# PRIVACY.md

## Data stored locally

The application stores configuration, search history, results, logs, and engine job data under:

`%LOCALAPPDATA%\GoogleMapsScraper\`

Subfolders: `config`, `logs`, `jobs`, `results`, `cache`, `engine-data`.

## Data sent over the network

- Search queries and coordinates are sent to Google Maps through the bundled search engine.
- Place names may be sent to OpenStreetMap Nominatim for geocoding.
- If email extraction is enabled, the app may request public business website pages.

## Telemetry

**Default: OFF.** The application does not send searches, results, or URLs to the developer by default.

## User control

Settings → Privacy:

- Clear history / cached data
- Delete all local results

Uninstaller can keep or remove user data (NSIS prompt when configured).
