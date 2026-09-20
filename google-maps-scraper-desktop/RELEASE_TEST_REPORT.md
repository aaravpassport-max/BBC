# Release test report — Google Maps Scraper 1.0.0

> **Note:** This cloud build environment is Linux-based and cannot execute the Windows GUI or NSIS installer. Items marked **PENDING** must be completed on a clean Windows 10/11 VM before a production release sign-off.

| Field | Value |
|-------|-------|
| Windows version tested | **PENDING** (target: Windows 11 23H2 x64) |
| Application version | 1.0.0 |
| Architecture | x64 |
| Docker installed | **PENDING — expect NO** |
| Python installed | **PENDING — expect NO** |
| Node installed | **PENDING — expect NO** |
| Chrome installed | **PENDING — expect NO** |

| Test | Status |
|------|--------|
| Application startup | **PENDING** |
| Search | **PENDING** |
| Batch search | **PENDING** |
| CSV export | **PENDING** |
| Excel export | **PENDING** |
| Deduplication | **PENDING** |
| Crash recovery | **PENDING** |
| Uninstall | **PENDING** |
| Windows Defender scan | **PENDING** |
| VirusTotal | **PENDING** |

## Automated / dev environment checks (this repository)

| Check | Status |
|-------|--------|
| Architecture audit documented | PASS |
| Docker removed from user path | PASS (sidecar binary) |
| Source ZIP located in `aaravpassport-max/BBC` | PASS |
| Windows CI workflow added | PASS |
| Bundled engine download script | PASS |

## VirusTotal / Defender procedure (run on release machine)

1. Build release via `npm run tauri build` on Windows.
2. Compute SHA-256 (`SHA256SUMS.txt` in CI).
3. `Get-MpPreference`; run `Start-MpScan -ScanType CustomScan -ScanPath <installer.exe>`.
4. Upload installer and main EXE to VirusTotal; record detection ratio and investigate any heuristic flags (common for unsigned Tauri/Rust + bundled browser automation binaries).

Do not mark Defender/VirusTotal PASS until scans are attached to the release.
