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

**Zip (must be ~1.9–2.0 MB):**  
https://github.com/aaravpassport-max/BBC/raw/cursor/liquidity-trap-engine-439c/fno-lab-standalone-app.zip?download=16.37.24

Inside the zip, open **`fno-lab-standalone-app/PLUGIN_BUILD.txt`** — it must say **`FNO_PLUGIN_VERSION=16.37.24`** and **`FNO_CORE_BUILD_MARKER=16.37.24-whole-lots-enforced`**. If you see **16.37.21**, the file is stale (wrong download or cached copy).

After install:

1. App header: **`v16.37.24 (16.37.24-whole-lots-enforced)`**
2. **Settings → F&O Lab Providers** → Whole-lot fix: **yes**
3. Browser view-source on `/` → search **`FNO_CORE_BUILD_MARKER`** — must be **`16.37.24-whole-lots-enforced`**
