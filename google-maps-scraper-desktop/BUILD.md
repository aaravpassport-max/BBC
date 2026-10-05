# BUILD.md — Google Maps Scraper Desktop

## Requirements (developers only)

- Windows 10/11 x64 (recommended for final packaging)
- [Node.js 20 LTS](https://nodejs.org/)
- [Rust stable](https://rustup.rs/)
- [Visual Studio Build Tools](https://visualstudio.microsoft.com/visual-cpp-build-tools/) with C++ workload (Windows)
- WebView2 (preinstalled on Windows 11; runtime installer on older systems)

End users do **not** need any of the above.

## Build steps (Windows)

```powershell
cd google-maps-scraper-desktop
npm ci
npm run download-engine:win
npm run tauri build
```

Outputs (typical paths):

- NSIS installer: `src-tauri/target/release/bundle/nsis/Google Maps Scraper_1.0.0_x64-setup.exe`
- Portable folder: `src-tauri/target/release/bundle/portable/` (zip for release)

Rename installer to **`GoogleMapsScraper-Setup.exe`** for distribution if desired.

## Bundled search engine

The app bundles **[gosom/google-maps-scraper](https://github.com/gosom/google-maps-scraper)** v1.18.1 as `scraper-engine` (Tauri sidecar). Download via:

```bash
npm run download-engine:win   # Windows
npm run download-engine -- x86_64-unknown-linux-gnu   # Linux dev smoke test
```

## Code signing (recommended)

Unsigned builds may trigger SmartScreen. Sign with Authenticode + timestamp:

```powershell
signtool sign /fd SHA256 /a /tr http://timestamp.digicert.com /td SHA256 "Google Maps Scraper.exe"
```

## CI

GitHub Actions workflow: `.github/workflows/build-google-maps-scraper-windows.yml`

## Clean-machine validation

Before marking a release PASS, test on a VM with **no** Docker, Python, Node, Git, or Chrome installed. See `RELEASE_TEST_REPORT.md`.
