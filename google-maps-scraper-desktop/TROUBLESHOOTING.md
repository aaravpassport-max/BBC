# TROUBLESHOOTING.md

## Application will not start

**Message:** bundled browser/search engine is missing.

Reinstall from the official installer or portable package. Do not delete files inside the installation folder.

## Search returns no results

- Try a broader keyword or larger city name.
- Lower search coverage from Very Deep to Balanced.
- Check your internet connection.
- Wait if you recently saw a verification pause (rate limiting).

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
