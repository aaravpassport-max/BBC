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
