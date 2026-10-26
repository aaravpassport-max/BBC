# Windows install — if setup shows **Retry**

The installer is large (~400MB+ with WordPress, PHP, and MariaDB). Retry usually means a file could not be written.

## Do this first

1. **Close LeadCRM** — Task Manager → end **LeadCRM WA.exe** (if running).
2. **Uninstall** any old “LeadCRM WA” from Settings → Apps.
3. Run **`LeadCRM-WA-Setup-1.0.10.exe`** again (no need to run as Administrator for the default per-user install).
4. When asked for a folder, pick a **short path**, e.g. `C:\LeadCRM-WA` or accept the default under `%LOCALAPPDATA%\Programs\LeadCRM-WA`.
5. Temporarily **pause antivirus** during install if Retry keeps appearing.
6. Ensure **2 GB free disk** on the target drive.

## Portable fallback (no NSIS)

Download **`LeadCRM-WA-1.0.10-win.zip`** from the same GitHub release, unzip to e.g. `C:\LeadCRM-WA`, and run **`LeadCRM WA.exe`** inside the folder.

## After install

App data (CRM database, WordPress copy) lives in:

`%APPDATA%\leadcrm-wa-desktop\LeadCRM-WA\`

Logs: `%APPDATA%\leadcrm-wa-desktop\LeadCRM-WA\logs\`
