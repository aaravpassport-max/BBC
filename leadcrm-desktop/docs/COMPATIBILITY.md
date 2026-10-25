# LeadCRM — Original vs Desktop Compatibility

## Upstream source

| Item | Detail |
| --- | --- |
| Repository | [reymarkjpanes/LeadCRM](https://github.com/reymarkjpanes/LeadCRM) |
| Product | Internal CRM for Camxian Technologies (Next.js 15 + Express + PostgreSQL/Prisma) |
| **Not a WordPress plugin** | No `wp-content/plugins` structure, hooks, or PHP/WordPress runtime. Public “LeadCRM” Chrome extension ([leadcrm.io](https://www.leadcrm.io)) is a separate product. |

This desktop port uses the upstream monorepo as the functional baseline, not a WordPress CRM plugin.

## Module parity

| Module | Upstream route | Desktop | Notes |
| --- | --- | --- | --- |
| Authentication / RBAC | `/login`, roles, groups | **Verified** | Local admin + sample Sales users seeded on first run |
| Dashboard & reporting | `/dashboard`, `/reporting` | **Verified** | Same UI; reporting streams need live API |
| Leads | `/crm/leads` | **Verified** | CRUD, filters, sample records |
| Contacts | `/crm/contacts` | **Verified** | List and navigation tested |
| Accounts | `/crm/accounts` | **Supported** | Same codebase; not all flows re-tested in desktop QA |
| Deals & pipeline | `/crm/deals`, `/crm/pipeline` | **Verified** (list) | Stage transitions not fully regression-tested |
| Tasks | `/operations/taskboard` | **Verified** (navigation) | |
| Workflows | `/automation/workflows` | **Supported** | Requires DB; workers run in bundled API |
| Forms | `/marketing/forms`, public `/forms/:id` | **Supported** | Public forms need reachable `APP_URL` |
| Campaigns | `/marketing/campaigns` | **Partial** | UI present; **Brevo** API key required to send |
| Inbox (Gmail) | `/inbox` | **Partial** | **Google OAuth** + `ENCRYPTION_KEY` required |
| Settings / admin | `/settings` | **Verified** | Users, groups, products, custom fields |
| Notifications | `/notifications` | **Supported** | Local notifications work; email/SMS need providers |

## Architecture changes

| Area | Upstream (hosted) | Desktop |
| --- | --- | --- |
| Database | Managed PostgreSQL | Embedded PostgreSQL per user data directory |
| Frontend | Hosted Next.js | Next.js **standalone** on loopback |
| API | Express on server | Express on `127.0.0.1:13101` |
| Config | `.env` / hosting secrets | Launcher-generated `settings.json` + env |
| WordPress | N/A | N/A |

## Code adaptations (minimal)

1. `frontend/src/lib/server/backend-url.ts` — allow loopback HTTP when `LEADCRM_DESKTOP=1`.
2. `frontend/app/api/proxy/[...path]/route.ts` — same-origin POST CSRF check for desktop HTTP.
3. `backend/src/config/validate-env.ts` — skip hosted-only provider validation when `LEADCRM_DESKTOP=1`.
4. `scripts/desktop-launcher.cjs`, `scripts/prepare-standalone.cjs` — production desktop runtime.
5. `scripts/local-development-bootstrap.cjs` — accept `LEADCRM_LOCAL_SETTINGS` for desktop data dir.
6. `leadcrm-desktop/electron/*` — shell, shortcuts, bundled PostgreSQL (Windows).

## Features requiring internet / credentials

- **Campaign email** — Brevo API key and verified sender
- **Campaign SMS** — TextBee API key / device
- **Gmail inbox** — Google Cloud OAuth client + encryption key for stored tokens
- **External webhooks** — Not exposed; inbound provider webhooks are not routed to localhost installs

## Known limitations

- **Windows NSIS installer** in this environment: full `LeadCRM-Setup-*.exe` should be built on Windows (or Linux with a working Wine/NSIS toolchain). A **portable 7z/zip** of `win-unpacked` is provided as the primary artifact from cloud build.
- **Linux/macOS desktop installers** are not in scope; Linux AppImage was built for smoke testing only.
- **Multi-user server** mode is unchanged from upstream single-tenant desktop DB; not intended as a network CRM server.
