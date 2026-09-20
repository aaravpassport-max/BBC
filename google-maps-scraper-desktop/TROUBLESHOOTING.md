# TROUBLESHOOTING.md

## Installer error: “Error opening file for writing … scraper-engine.exe”

The old app or search engine is still running and Windows will not overwrite the file.

1. Click **Abort** on the installer error.
2. Close **Google Maps Scraper** if it is open.
3. Open **Task Manager** (Ctrl+Shift+Esc) → end these if listed:
   - `Google Maps Scraper.exe`
   - `scraper-engine.exe`
4. Run the installer again (right-click → **Run as administrator** is fine).
5. If it still fails, delete the install folder (not your search data):
   - `C:\Users\<You>\AppData\Local\Google Maps Scraper\`
   - Then run the installer again.

Your saved searches stay in `%LOCALAPPDATA%\GoogleMapsScraper\` (different folder).

## Application will not start

**Message:** bundled browser/search engine is missing.

Reinstall from the official installer or portable package. Do not delete files inside the installation folder.

## Search returns no results

- Install the **latest** `GoogleMapsScraper-Setup.exe` (about **230+ MB**). Older ~28 MB builds do not include Chromium and always return empty results.
- Set **Search depth** to **Fast** and try a simple test: keyword `coffee shop`, location `Bangalore, Karnataka`.
- Close **Docker / old Maps scraper kit** on port 8080, or uninstall it. This app now uses port **8765** so it does not talk to the wrong engine.
- On first run, keep the app open on the Searching screen for **10–15 minutes** while the browser runtime copies into your user data folder.
- Check internet access and that Windows Firewall allows **Google Maps Scraper** and **scraper-engine.exe**.
- If location lookup fails, use **City, State** (e.g. `Bangalore, Karnataka`). The app tries OpenStreetMap, then Photon, then offline hints for major Indian cities.
- Open **Settings → Open logs folder** and check today’s log for `[engine]` or geocoding errors.

## “Search temporarily paused” / verification

Google Maps requested additional verification. Wait and try again later with conservative request behavior. The app does not bypass CAPTCHAs.

## First search returns no results / takes a long time

The built-in browser runtime (Playwright + Chromium) is downloaded on the **first real search**. This can take **5–15 minutes** on a normal connection.

While searching:

1. Stay on the **Searching** screen and keep the app open.
2. Use **Fast** search coverage for the first test.
3. Try a simple query: `coffee shops` and location `Bangalore, Karnataka`.

Build **1.0.1 and later** include the browser runtime inside the installer so searches start faster.

## First launch is slow

The bundled search engine may take up to a minute to start its local service. This is normal.

## Exports

Default export folder is under `%LOCALAPPDATA%\GoogleMapsScraper\results`. Change it in Settings.

## Logs

Settings → Diagnostics → Open Logs (`logs/YYYY-MM-DD.log`).

## SmartScreen warning

New unsigned builds may show Windows SmartScreen. Prefer a signed build from your vendor, or verify `SHA256SUMS.txt` before running.
