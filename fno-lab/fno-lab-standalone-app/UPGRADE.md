# Upgrading F&O Lab (replace, do not add a new plugin)

Every release uses the **same zip name** and **same folder name** so updates replace your existing install instead of creating a second plugin.

| Item | Value |
|------|-------|
| Zip file | `fno-lab-standalone-app.zip` |
| Folder after extract | `wp-content/plugins/fno-lab-standalone-app/` |
| Version | shown in WordPress Plugins list and in the app header (not in the folder name) |
| **Expected zip size (v16.31.3+)** | **~1.9 MB (1,927,000+ bytes)** — if you see ~610 KB, the download is incomplete; use the release link below |

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

Always use **`fno-lab-standalone-app.zip`** at the **repository root** (not a copy under `fno-lab/`) and keep a **single** folder: **`fno-lab-standalone-app`**.

## Download (latest on PR branch — **not** the old v16.37.0 GitHub Release)

The release asset **v16.37.0** is an older snapshot (**16.37.0**). Current work (Modes 7–8, whole-lot qty, zip sync) is on branch **`cursor/liquidity-trap-engine-439c`**.

**Use this zip (must be ~1.9–2.0 MB):**  
https://github.com/aaravpassport-max/BBC/raw/cursor/liquidity-trap-engine-439c/fno-lab-standalone-app.zip

If your browser reuses an old download, add a cache-buster query string, e.g.  
`.../fno-lab-standalone-app.zip?download=16.37.23`

Before installing, unzip locally and confirm `fno-lab-standalone-app/fno-lab.php` contains **`16.37.23`** and **`16.37.23-whole-lots`**.

After install:

1. WordPress **Plugins** → version **16.37.23**, Install ID **16.37.23-whole-lots** (under the plugin row).
2. App header shows **`v16.37.23 (16.37.23-whole-lots)`**.
3. **Settings → F&O Lab Providers** → “Whole-lot paper qty fix present: **yes**”.

If you still see **16.37.21**, an old plugin folder is still active or the zip was not replaced — delete **all** `fno-lab-standalone-app*` directories, reinstall, **deactivate → activate** once, hard-refresh (Ctrl+Shift+R). Check diagnostics on the settings page for the real path to `fno-lab.php` on disk.
