# SECURITY.md

## External connections

| Destination | Purpose |
|-------------|---------|
| Google Maps | Business search (bundled search engine) |
| OpenStreetMap Nominatim (`nominatim.openstreetmap.org`) | Geocoding city/state names to coordinates |
| Business websites | Optional inspection of **public** contact pages when email extraction is enabled |

No telemetry is sent by default. The application does not upload search results to third-party analytics servers.

## Local security boundary

- The search engine HTTP API listens on **127.0.0.1:8080** only.
- User data is stored under `%LOCALAPPDATA%\GoogleMapsScraper\`.
- Logs do not include passwords or tokens.

## Prohibited behavior

This application does **not**:

- Bypass CAPTCHAs or authentication
- Disable antivirus or firewall
- Create hidden startup persistence
- Download and execute unknown remote binaries at runtime
- Collect unrelated user files

## CAPTCHA / rate limits

If Google presents an access challenge, the UI shows a pause message and does not attempt automated bypass.

## Reporting

Report security concerns via your repository issue tracker or vendor contact. Include steps to reproduce and application version.

## Release integrity

Release packages should ship with `SHA256SUMS.txt`. Verify hashes before installation.
