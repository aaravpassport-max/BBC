# ILRS Product Audit & Target Information Architecture

## Executive summary

ILRS grew as a **reminder app** with **work/inquiry modules bolted on**. Navigation, status models, and screens overlap. Users must learn three parallel mental models (Focus sidebar × lifecycle queue tabs × pipeline stages) to answer one question: *what do I do next?*

**Target:** One coherent business operations app with a clear split between **operational surface** (cards, queues, top nav) and **record** (activity timeline, history).

---

## 1. Application inventory (audit)

### Navigation (before restructure)

| Area | Routes | Role |
|------|--------|------|
| Focus | today, tomorrow, upcoming, overdue, postponed, completed | Mixed reminders, tasks, inquiries |
| Work lists | reminders, tasks, calendar | Duplicate time views |
| Work business | pipeline, inquiries, inquiry-followups, work-schedule, clients, work-reports, pipeline-settings | **4 ways** to see inquiries |
| Life | medicine, bills, habits, family, checklists, rewards | Personal modules (OK separate) |
| System | settings | Config |

**Problems:** 15+ sidebar items; primary work paths duplicated; lifecycle queue tabs repeated on every work screen; no single “inquiry workspace.”

### Entity model (current)

| Concept | Storage | UI exposure |
|---------|---------|-------------|
| Enquiry | `inquiries` + `stage_key`, `outcome_status`, `lifecycle_status` | Cards, pipeline table, detail |
| Task / Reminder | `reminders` (`task_type`, `workflow_status`, `lifecycle_status`) | Focus lists, task page |
| Follow-up | `next_follow_up` on inquiry + linked `reminders` (`source_type=inquiry`) | Follow-ups page, Today |
| Activity | `inquiry_activities` | Detail bottom; weak typing; prompt-only add |
| Notes | `inquiries.notes`, `internal_notes` | Detail card; not on list cards |
| History | Activities only (no unified audit for reminders) | Partial |

### Status / stage confusion

- **Stage** (`stage_key`): pipeline position (e.g. Quotation Sent) — correct.
- **Outcome** (`outcome_status`): active / closed_won / closed_lost — legacy.
- **Lifecycle** (`lifecycle_status`): active / pending / completed / closed — queue tabs.
- **Workflow** (`workflow_status` on reminders): in_progress / postponed / done — parallel to lifecycle.

**Working:** Lifecycle queues + card dropdowns (after v1.0.56 fix). Stage changes via modal. Follow-up reminders sync from inquiry.

**Partial:** Activity log exists but no structured conversation types UI; no “latest update” on cards.

**Duplicated:** Pipeline vs Inquiries list vs Follow-ups vs Work schedule vs Today inquiry sections.

**Obsolete / low value:** Separate `dashboard` page (alias of today); redundant nav badges; “whats new” lifecycle banner (remove after adoption).

**Missing:** Operational state (waiting on client/vendor/us); tabbed inquiry workspace; top-level module nav; pinned current note vs history.

---

## 2. Target mental model

| Question | Where answered |
|----------|----------------|
| What is this item? | Type chip (Enquiry / Task / Reminder) |
| What is its current state? | **State** (lifecycle + operational hold reason) |
| Where in the process? | **Stage** (enquiries only) |
| What happened? | **Activity timeline** (detail tab) |
| What is happening now? | **Current note** + latest activity line on card |
| What next? | **Next action** + **Next follow-up** |
| Who? | **Assignee** |
| When? | Dates on card |

**Card = operate now. Detail tabs = depth. History = timeline.**

---

## 3. Target navigation (top-primary)

### Global top bar (row 2)

1. **Focus** — what needs attention by time (Today default).
2. **Inquiries** — all enquiry work (list / pipeline / follow-ups / schedule as **context tabs**, not sidebar).
3. **Tasks & reminders** — task list, all reminders, calendar.
4. **Life** — personal modules.
5. **More** — clients, analytics, workflow setup, settings.

### Sidebar (contextual only)

Shows **secondary routes for the active top section** (e.g. Focus → Today, Overdue, Completed). Admin links only under **More**.

---

## 4. Inquiry workspace (detail)

Tabs:

1. **Overview** — state, stage, next action, follow-up, contact, payments summary, current note.
2. **Tasks** — linked tasks/reminders; create complete.
3. **Follow-ups** — schedule + linked fire times.
4. **Activity** — full timeline + structured log composer.
5. **Notes** — editable current note (operational); changes log to activity.

---

## 5. Activity types (conversation system)

Structured types: client_contact, outbound_call, inbound_call, whatsapp, email, documents_requested, documents_received, vendor_contact, quotation_prepared, quotation_sent, client_response, follow_up_done, status_change, internal_note, task_created, task_completed, reminder_created, reminder_completed, stage_change, payment.

Each entry: type, title, body, timestamp, optional metadata (stage transition).

---

## 6. Consolidation & removal plan

| Action | Item |
|--------|------|
| Merge | pipeline, inquiry-followups, work-schedule → **Inquiries hub views** |
| Keep | lifecycle queue tabs (Act now / Waiting / Finished / Closed) as **work status** filter |
| Simplify | Inquiry cards — operational fields only + latest activity line |
| Remove over time | Duplicate sidebar entries; lifecycle “what’s new” banner |
| Add | `last_activity_summary`, `operational_state` on inquiries |

---

## 7. Reminder vs task vs enquiry

- **Enquiry** = container for client work; owns stage, follow-up, activity.
- **Task** = one-off action (`task_type=task`); link via `source_id`.
- **Reminder** = time-based fire; inquiry follow-ups are reminders with `source_type=inquiry`.
- UI presents tasks and follow-ups under enquiry tabs; Focus still aggregates **due today** across types.

---

## 8. Validation checklist (release)

- [x] Unified Inquiries hub (List / Pipeline / Follow-ups / Schedule inner tabs)
- [x] Task/reminder tabbed detail workspace (`work-item-detail`)
- [x] Auto activity on enquiry lifecycle, follow-up reminder create, task/reminder complete
- [ ] End-to-end: create enquiry → log → stage → complete linked task (manual regression)
- [ ] History chronological; card shows latest line only (verify after auto-sync)
- [ ] Closed items leave Act now; overdue visible on Focus
- [ ] Mobile layout: top nav scrolls; cards stack

---

*Implementation tracked on branch `cursor/work-ux-restructure-271c`.*
