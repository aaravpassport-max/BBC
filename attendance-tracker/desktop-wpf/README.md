# Native Windows admin console (WPF)

**Workforce Attendance Console** is a Microsoft Office–style native desktop app:

- **WPF** (`net8.0-windows`) with Segoe UI, neutral chrome, blue accent, left navigation rail
- **No Electron**, no embedded Chromium for the admin UI
- Talks to the proven **`core/` HTTP API** on localhost (Node sidecar started automatically)

## Build

Windows only:

```powershell
..\scripts\build-windows.ps1
```

See `docs/DESKTOP_INSTALL.md` for installer and first-run steps.
