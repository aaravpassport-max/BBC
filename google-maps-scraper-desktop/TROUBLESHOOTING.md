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

## First launch is slow

The bundled search engine may prepare its browser runtime on first run. This is normal and can take one to two minutes.

## Exports

Default export folder is under `%LOCALAPPDATA%\GoogleMapsScraper\results`. Change it in Settings.

## Logs

Settings → Diagnostics → Open Logs (`logs/YYYY-MM-DD.log`).

## SmartScreen warning

New unsigned builds may show Windows SmartScreen. Prefer a signed build from your vendor, or verify `SHA256SUMS.txt` before running.
