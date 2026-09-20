# Google Maps Scraper — Windows Desktop

Self-contained **Windows 10/11** desktop application for Google Maps business research: search, batch by Indian state/city, deduplication, CSV/Excel export, and local history — **without Docker, Python, Node, or a manual localhost workflow**.

Built with **Tauri 2** (Rust + React) and the bundled **[gosom/google-maps-scraper](https://github.com/gosom/google-maps-scraper)** engine as a hidden sidecar.

## Source material

Starting point: `Google-Maps-Scraper-Desktop-App-v3.zip` in [aaravpassport-max/BBC](https://github.com/aaravpassport-max/BBC) (Docker-based kit). This desktop app replaces Docker with a bundled engine. See [ARCHITECTURE.md](./ARCHITECTURE.md).

## User quick start

1. Install from `GoogleMapsScraper-Setup.exe` (build artifact) or extract `GoogleMapsScraper-Portable.zip`.
2. Double-click **Google Maps Scraper**.
3. Search → review → export.

See [README.txt](./README.txt). India states/districts/places: [DATA.md](./DATA.md).

## Developers

See [BUILD.md](./BUILD.md).

## Status

Windows installer/portable packages are produced by GitHub Actions on `windows-latest`. Clean-machine QA checklist: [RELEASE_TEST_REPORT.md](./RELEASE_TEST_REPORT.md).
