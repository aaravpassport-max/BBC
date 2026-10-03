# Upgrading F&O Lab (replace, do not add a new plugin)

Every release uses the **same zip name** and **same folder name** so updates replace your existing install instead of creating a second plugin.

| Item | Value |
|------|-------|
| Zip file | `fno-lab-standalone-app.zip` |
| Folder after extract | `wp-content/plugins/fno-lab-standalone-app/` |
| Version | shown in WordPress Plugins list and in the app header (not in the folder name) |

## Upgrade steps

1. WordPress **Plugins** → deactivate **F&O Lab - Standalone App**.
2. Delete **every** old copy under `wp-content/plugins/` whose name starts with `fno-lab-standalone-app` (including versioned folders like `fno-lab-standalone-app-v16.22.0`).
3. Upload `fno-lab-standalone-app.zip` to `wp-content/plugins/` and extract it there.
   - Correct result: `wp-content/plugins/fno-lab-standalone-app/fno-lab.php`
4. Activate **one** F&O Lab plugin.
5. Open the app and confirm the header shows the new version.

## Common mistake (creates duplicate plugins)

- Extracting a zip whose **filename** includes a version (for example `fno-lab-standalone-app-v16.22.0.zip`) into a **new** folder each time.
- WordPress then lists multiple F&O Lab plugins, which can cause fatal PHP errors (`Cannot redeclare …`).

Always use **`fno-lab-standalone-app.zip`** and keep a **single** folder: **`fno-lab-standalone-app`**.

## v16.22.2 full zip (exit pricing fix)

| Check | Expected |
|-------|----------|
| File size | **~1.87 MB** (1,868,278 bytes) — **not** ~500 KB or ~270 KB |
| Files inside | **~170** (includes `autonomous-driver/`, `fno-data-layer.php`) |
| SHA256 | `4f17091751255f6cf19fcbcdd64c132327419945990c853bf866c592de4bac81` |

Download (use **Release** link first — avoids browser cache on the old ~537 KB raw file):

- **Release asset (recommended):** https://github.com/aaravpassport-max/BBC/releases/download/fno-lab-v16.22.2/fno-lab-standalone-app-v16.22.2.zip
- Branch raw: https://github.com/aaravpassport-max/BBC/raw/cursor/fix-paper-trade-execution-439c/fno-lab-standalone-app.zip

See `DOWNLOAD-LINKS.txt` in this folder and repo root `DOWNLOAD-16.22.2.txt`. **Do not** use an old commit zip (~537 KB) or `main` (~268 KB).
