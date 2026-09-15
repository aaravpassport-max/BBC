# Auditable Controller Convention

## Why

`RTOFLOW\Services\AuditService` provides an insert-only, tamper-evident log
of significant platform actions (`wp_rto_logs`). It has existed since early
in the project, but adoption across admin controllers was inconsistent:
several controllers performed real mutations (`$wpdb->insert`, `->update`,
`->delete`, or delegated to a Service's create/update/delete method) with no
corresponding audit entry. This document defines the convention going
forward so that gap doesn't reopen.

## Rule

**Every admin-mutating action — create, update, delete, or status/state
change — reachable from `app/Controllers/Admin/**` (directly, or via a
Service method it calls) MUST write an audit log entry**, unless the
mutation is purely a housekeeping/read-side-effect with no business meaning
(e.g. marking a support ticket "viewed" the first time it's opened is
borderline and left to reviewer judgment — anything a support agent or
compliance reviewer would plausibly ask "who did this and when" about must
be logged).

This applies whether the mutation happens directly in the controller method
or the controller delegates to a Service — the log call can live in either
place, but it must exist exactly once on every code path that performs the
mutation.

## How to log

Use `AuditService::log()` (signature — see `app/Services/AuditService.php`):

```php
AuditService::log(
    string $action,          // 'entity.action' slug — see naming below
    ?int   $leadId = null,   // the rto_leads.id this action relates to, or null
    array  $newValue = [],   // state after the change / fields being set
    array  $oldValue = [],   // state before the change, when a prior row exists
    ?int   $userId = null    // defaults to get_current_user_id() — override only
                              // for system/webhook actions attributed to someone else
);
```

For lead status transitions specifically, prefer the existing helper:

```php
AuditService::logStatusChange(int $leadId, string $from, string $to, string $note = '');
```

Call the log **after** the mutation succeeds (so failed mutations aren't
logged as if they happened), but still within the same request/method —
don't defer it to a queue or hook unless the mutation itself is
transactional and the hook is guaranteed to fire.

## Event slug naming pattern

`entity.action`, both lowercase, snake_case within each segment, dot-separated:

- `service.created`, `service.updated`, `service.status_changed`
- `city.created`, `city.deleted`
- `rating.deleted`
- `email_template.updated`
- `settings.updated`
- `complaint.status_changed`
- `vendor.status_changed`, `vendor.pan_revealed` (existing examples)
- `payout.generated`, `payout.processed` (existing examples)
- `eligibility_rule.created` / `.toggled` / `.deleted` (existing examples)

Pick the entity name from the primary DB table/domain concept being
mutated, and the action from: `created`, `updated`, `deleted`,
`status_changed` (or a more specific past-tense verb when neither `updated`
nor `status_changed` fits, e.g. `pan_revealed`, `toggled`).

## Required context fields

- `newValue` should include the entity's `id` (or the lead/complaint/etc.
  id it affects) plus whatever fields were actually written — do not dump
  the entire `$_POST` superglobal.
- `oldValue` should be populated whenever a prior row is available (fetch
  it with a `SELECT ... WHERE id = %d` immediately before the mutating
  query if the controller doesn't already have it in hand) — this is what
  makes the log useful for "what changed" review, not just "something
  changed."
- Never put secrets (API keys, tokens, password fields) in either array,
  even encrypted — log that the field changed, not its value.
- `leadId` should be populated whenever the action is tied to a specific
  lead, even if the mutation's primary table isn't `rto_leads` (e.g. a
  complaint response should carry the complaint's `lead_id`).

## Checklist for new/edited admin controller methods

Before merging a change to any `app/Controllers/Admin/*Controller.php`
method, confirm:

- [ ] Does this method call `$wpdb->insert()`, `$wpdb->update()`,
      `$wpdb->delete()`, or a Service method that does, on behalf of an
      admin/staff action?
- [ ] If yes, is there an `AuditService::log()` (or `logStatusChange()`)
      call on every path that reaches that mutation, including early
      returns/duplicated logic branches?
- [ ] Does the event slug follow `entity.action` and match an existing
      entity name if one is already in use elsewhere?
- [ ] Does `newValue`/`oldValue` carry the entity id and the fields that
      changed, without leaking secrets?
- [ ] Is the log call placed *after* the mutation is confirmed to have
      succeeded (not before, and not skipped on an early-return-on-error
      path)?
- [ ] Did you run `php -l` on the file you edited?

## Known gaps intentionally NOT fixed here

Some admin-mutating code paths live in files reserved for other concurrent
work at the time this convention was written (`EligibilityController.php`,
`PayoutsController.php`, `VendorsController.php`,
`CityServiceConfigController.php`, `LeadService.php`,
`NotificationService.php`, `WorkflowService.php`). Any gap identified in
those files was reported to the relevant owner rather than fixed directly —
see the audit gap list circulated alongside this document. Apply the same
checklist above when those files are next touched.
