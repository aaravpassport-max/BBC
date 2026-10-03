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
   - `C:\Users\<You>\AppData\Local\Programs\Google Maps Scraper\` (per-user install), or
   - `C:\Program Files\Google Maps Scraper\` (if you chose all users)
   - Then run the installer again.

Your saved searches stay in `%LOCALAPPDATA%\GoogleMapsScraper\` (different folder).

## Installer error: “Extract: error writing to file … chrome-headless-shell.exe”

Same root cause: a **previous install** or a **running search** left Playwright/Chromium files locked. Windows Defender can also block large `.exe` writes during extract.

1. Click **OK**, then **Cancel** on the installer.
2. In Task Manager, end **Google Maps Scraper.exe**, **scraper-engine.exe**, **chrome-headless-shell.exe**, and any **Chrome** process started from your install folder.
3. Delete only the browser folder inside the install directory (keeps the app shortcut path clear for reinstall):
   - `%LOCALAPPDATA%\Programs\Google Maps Scraper\ms-playwright\`  
   - or `C:\Program Files\Google Maps Scraper\ms-playwright\`
4. Run **GoogleMapsScraper-Setup.exe** again. Prefer **Install for me only** (current user) if Program Files keeps failing.
5. Temporarily allow the installer in Windows Security if SmartScreen/Defender blocked the write.

**Build 17+** installers omit the optional headless-shell bundle and remove old `ms-playwright` before copying, which avoids this error on upgrades.

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

## Where did my CSV / Excel go?

- Use **Save CSV as…** or **Save Excel as…** on the Results screen — Windows will ask where to save (Desktop, Downloads, etc.).
- If you used an older build, files may have been saved silently to:
  `C:\Users\<You>\AppData\Local\GoogleMapsScraper\results\`
- Click **Open exports folder** on Results or **Settings → Open** next to the export folder path.
- After saving, File Explorer should open with the new file selected.

## Exports

Default export folder is under `%LOCALAPPDATA%\GoogleMapsScraper\results`. Change it in Settings.

## Logs

Settings → Diagnostics → Open Logs (`logs/YYYY-MM-DD.log`).

## SmartScreen warning

New unsigned builds may show Windows SmartScreen. Prefer a signed build from your vendor, or verify `SHA256SUMS.txt` before running.
