# ILRS — Multi-computer sync implementation plan

**Status:** Approved for scope — **include all application data** (business + Life modules + shared configuration).  
**Phase 0:** Implemented in v1.2.0 (device, folder, outbox, settings UI).  
**Phase 1:** Implemented in v1.2.1 — clients, inquiries, activities, payments.  
**Phase 2:** Implemented in v1.2.2 — reminders/tasks, reminder logs, enquiry follow-up reminders (+ renderer SQL hook).  
**Phase 3:** Implemented in v1.2.3 — Life modules.  
**Phase 4:** Implemented in v1.2.4 — inquiry/workflow stages, inquiry templates, Drive folder backups, conflict list UI.  
**Phase 5+:** File attachments, bootstrap snapshot, full conflict merge UI — not started.  
**Architecture:** Existing ILRS + local SQLite per PC + Google Drive for Desktop folder + record-level change files. No server, no Google API, no syncing `ilrs.db`.

---

## 1. What currently exists

- **Stack:** Electron 29, Node main process (`main.js`), renderer HTML/JS (`src/`), `better-sqlite3`.
- **Database:** `{userData}/ilrs.db`, WAL, migrations through **schema_version 19** in `main.js`.
- **IDs:** Primary keys are mostly **UUIDs** (`crypto.randomUUID`). Exception: `inquiry_number` from `settings.inquiry_counter` (`INQ-*`).
- **Users / offices:** No `users` or `offices` tables; `settings.user_name`, `assigned_to` text fields.
- **Writes:** Main-process modules (`inquiry-actions.js`, `reminder-actions.js`, `payment-manager.js`, …) plus broad renderer access via IPC **`db-query`** (`app.js` has many direct SQL calls).
- **Backup:** Local daily `.db` copy under `userData/backups/`. `cloud_backup` setting is unused.
- **Export:** Partial JSON export (life lists); enquiries/clients not in “export all”.
- **Attachments:** No file store; conversation is `inquiry_activities` rows.
- **Cross-machine sync:** None (`inquiry-activity-sync.js` is single-machine only).

See also `docs/PRODUCT-AUDIT-IA.md` for product/entity overview.

---

## 2. Scope decision (your instruction)

**Keep everything in** — synchronize **all persistent domains** in ILRS:

| Domain | Tables (representative) |
|--------|-------------------------|
| Work / enquiries | `clients`, `inquiries`, `inquiry_activities`, `inquiry_stages`, `inquiry_templates`, `work_payments` |
| Tasks / reminders | `reminders`, `reminder_logs` |
| Workflow config | `workflow_stages` |
| Life | `medicines`, `medicine_logs`, `bills`, `bill_history`, `habits`, `habit_logs`, `family_members`, `checklists`, `checklist_items` |
| Settings | **Whitelist only** — sync business-relevant keys; **never** sync `app_pin` or device-local paths |

Life data will propagate to all linked computers the same as enquiry data. Organizations that want business-only sync can use a later “sync profile” toggle; default matches your instruction.

---

## 3. What will change

1. **Sync engine** (main process): outbox, inbound scanner/watcher, apply + conflict layer.
2. **Schema migration (v20+):** `revision`, tombstone metadata, `sync_*` tables, optional `app_users` / `offices`.
3. **Hooks** on all mutations (action modules + `db-query` interceptor for renderer SQL).
4. **UI:** folder selection, device/office identity, sync status, conflicts, backup health.
5. **Drive folder layout** under user-selected `ILRS/` root (Changes, Devices, Conflicts, Attachments, Backups).
6. **Attachments** (new table + files) when document sync is implemented.
7. **`inquiry_number`:** treat UUID as authority; fix counter collisions (display numbers via synchronized counter event or derived numbering — see §8).

---

## 4. Local SQLite

- Each computer keeps its own `ilrs.db` in Electron `userData`.
- The `.db` file is **never** placed in Google Drive for live use.
- Remote changes apply via **events** inside SQLite transactions.

---

## 5. Google Drive for Desktop

- User selects a folder that Drive already syncs (e.g. `…/Google Drive/ILRS`).
- App uses only local filesystem APIs; authentication is external (Drive for Desktop).
- Tolerate delayed/missing/duplicate files; no assumption of strict ordering.

---

## 6. Synchronization mechanics

- **Outbound:** mutation → outbox row → atomic JSON event file in `Sync/Changes/…` → published.
- **Inbound:** scan/watch Changes → validate → skip if `event_id` processed → apply or defer → update local DB.
- **Idempotency:** `sync_processed_events(event_id)`.
- **Ordering:** per-record `revision`; defer if dependency missing (e.g. inquiry before activity).
- **Terminology:** automatic / background / eventually synchronized — not “real-time.”

---

## 7. Record identification

- **Global ID:** existing UUID primary keys on all synced tables.
- **Event ID:** new UUID per change file.
- **Entity:** stable string per table (`client`, `inquiry`, `reminder`, `medicine`, …).
- **Device ID:** generated once, persisted (e.g. `DEVICE-` + random hex), not hostname/IP alone.

---

## 8. `inquiry_number` (INQ-*)

- Two offline PCs must not rely on independent `inquiry_counter` increments.
- **Plan:** keep human-readable numbers where possible by syncing counter updates via a **small global lock file** or by issuing numbers only through **serialized counter events**; display duplicate risk until counter event applies. Long-term: optional per-office prefix. Record identity remains **inquiry UUID**.

---

## 9. Conflict strategy (summary)

| Data | Strategy |
|------|----------|
| `inquiry_activities`, `reminder_logs`, medicine/habit/bill **log** rows | Append-only; duplicate blocked by `event_id` + row `id` |
| `work_payments`, `bill_history` | Append-only + delete tombstones |
| `clients`, `inquiries`, `reminders`, parent life rows | Revision check; field-level merge when fields differ; UI resolution for same-field edits (stage, status, amounts) |
| `inquiry_stages`, `workflow_stages`, templates | Revision + admin resolution |
| `settings` | Whitelist merge; conflicts on same key → newer revision or UI |

Conflicts stored under `Sync/Conflicts/` and in `sync_conflicts` table.

---

## 10. Deletes

- Use existing soft deletes (`outcome_status`, `status = 'deleted'`) plus new `deleted_at` / `deleted_by_*` where helpful.
- Sync **delete** events as tombstones with higher revision so old upserts cannot resurrect rows.

---

## 11. Documents

- Phase after core entities: `attachments` table + `ILRS/Attachments/{file_id}` + metadata events; dedupe by SHA-256.

---

## 12. Offline

- Full local operation without internet; outbox durable across restarts.
- UI states: Saved locally / Pending sync / Synchronized / Conflict.

---

## 13. Multi-computer convergence

- All PCs process the same change files (eventual consistency).
- **New computer:** device registration → validate folder → replay Changes (and snapshots if added) → download attachments → normal incremental sync. **Do not** copy another PC’s `ilrs.db` as the primary bootstrap method.

---

## 14. Backup (separate from sync)

- Keep local dated `.db` backups.
- Optional versioned copies in `ILRS/Backups/` on Drive.
- Warn when backup runs with pending outbox, unprocessed inbound, or open conflicts.

---

## 15. Alarms (default until you override)

With **all reminders synced**, the same due item may fire on multiple PCs. **Default implementation plan:**

- Add optional `alarm_device_id` / “ring on this device only” for work reminders, defaulting to **creator device** for new items; life reminders can default to **all devices** or same rule — configurable in settings.

If you prefer “ring everywhere,” we can set default to all devices.

---

## 16. User identity (default)

- Introduce lightweight **`app_users`** (id, display_name) and per-session **active user**; events carry `user_id` + `device_id` + `office_id`.
- Not a Google login; not a password store.

---

## 17. Modules to add or modify

**New:** `sync-engine.js`, `sync-outbox.js`, `sync-apply.js`, `sync-conflicts.js`, `sync-device.js`, `sync-folder.js`, `sync-entities.js` (registry of tables/fields).

**Modify:** `main.js`, `preload.js`, action modules, `package.json` build files, `src/app.js` + `sync-ui.js`, tests under `scripts/test-sync-*.js`.

---

## 18. Phased delivery

| Phase | Content |
|-------|---------|
| 0 | Device, folder, outbox, processed events, settings UI |
| 1 | Clients, enquiries, activities, payments |
| 2 | Reminders, tasks, logs |
| 3 | Life modules (all tables listed in §2) |
| 4 | Stages, templates, whitelisted settings |
| 5 | Attachments + Drive backups + bootstrap snapshot if needed |

---

## 19. Risks and limitations

- Decentralized, file-transport sync — not a central database.
- Shared Drive folder = trust boundary.
- Drive delay and conflict copies (`file (1).json`) must be handled safely.
- `db-query` bypass risk until all writes are hooked or migrated.
- Large initial replay on new PC may require snapshot file (still no server).

---

## 20. Acceptance tests (from product spec)

- Multi-office enquiry + conversation (Computer A ↔ B).
- Offline create (customer, enquiry, task, reminder, note) then eventual sync.
- Same-record offline edit → conflict detected, not silent overwrite.
- New Computer C initializes from sync folder without copying `ilrs.db`.

---

## 21. Remaining optional choices

Reply if you want these different from defaults above:

1. **Alarms:** all devices vs creator device only for work reminders.
2. **Drive layout:** one company folder vs folder per office.
3. **Start implementation:** phase 0 only first, or phases 0–1 in first release.

---

*Document version: 2026-09-19 — scope “keep everything in” applied.*
