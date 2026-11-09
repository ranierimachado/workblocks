# Workblocks plan feeding contract — version 1 reset

> For ongoing external scheduling, use the [version 2 exchange guide](feeding-contract-v2.md). This document describes the legacy fresh-start reset.

Version: **1**  
Format: **UTF-8 JSON**  
Purpose: supply the priorities, standing weekly plan, and exact dated work blocks that Workblocks should load.

This document describes the implemented import contract. The machine-readable definition is [plan-v1.schema.json](plan-v1.schema.json). A complete example based on Rani’s plan is [examples/rani-plan.json](../examples/rani-plan.json).

## 1. What the external service supplies

The scheduling service decides **which priority receives each block, on which day, and in which position**. Workblocks stores that plan and lets the user move, split, and complete the work afterward.

Supply one self-contained document with:

1. Plan metadata and a default block duration.
2. The ranked priorities referenced by the plan.
3. A standing weekly template using weekday names.
4. Zero or more specific weeks using calendar dates.

Priorities are supporting data inside the plan. There is no separate priorities-only import, name matching, or merge operation. For automatic file pickup and pending approvals, see [automated feeding](automated-feeding.md).

The service can deliver a `.json` file for the user to upload. Generating a file does not itself trigger an import. The current app does not poll the external service or expose a separate service-token integration.

## 2. Replacement behavior — important

Every successful import is a **complete replacement of the active plan**, not an incremental update.

Workblocks:

1. Validates the entire incoming document before deleting anything.
2. Creates a physical SQLite database backup. If the backup fails, replacement stops.
3. Records the previous planner state in import history.
4. Deletes **all unfinished blocks**, including carryover and unfinished work in weeks omitted from the new document.
5. Replaces the priorities, plan settings, standing template, and week metadata.
6. Preserves completed blocks with their original priority names, but detaches them from the new priority IDs.
7. Inserts the dated blocks supplied in the new document as new, unfinished work.

Steps 3–7 run in one database transaction. If a write fails, the database changes in that transaction are rolled back. A physical backup created before the transaction may still remain.

Priority IDs identify priorities within the incoming plan. Reusing an ID from a previous import does not merge the old and new plans.

**Repeated imports are destructive too.** Importing the same document again discards subsequent unfinished-work adjustments and recreates its supplied blocks. Completed history is retained; the importer does not deduplicate new work against completed blocks. A producer should omit work already completed when preparing a replacement plan.

Import history supports restoring the complete planner state from just before a selected import. Restore also replaces completed-work state and first creates a physical database backup.

## 3. Document fields

All fields in this table are required.

| Field | Type | Meaning and constraints |
| --- | --- | --- |
| `format_version` | Integer | Must be `1`. |
| `plan_name` | String | Nonempty, human-readable plan title. |
| `timezone` | String | Valid IANA timezone recognized by the server, such as `America/Sao_Paulo`. |
| `effective_from` | Date string | Earliest allowed date for supplied work. Format: `YYYY-MM-DD`. |
| `default_block_minutes` | Integer | Default duration for blocks without an explicit duration. Range: `1`–`1440`. Use `180` for the standard three-hour plan. |
| `priorities` | Array | At least one priority. See section 4. |
| `standing_plan` | Object | Recurring weekly template. See section 5. |
| `weeks` | Array | Complete dated week snapshots. May be empty. See section 6. |

Use JSON numbers for integer fields: `180`, not `"180"`. Do not supply comments, Markdown fences, trailing commas, or extra explanatory text in the actual JSON file.

Unknown fields are rejected at every object level. Optional fields should be omitted when not needed; `null` is not valid for any field in this contract.

## 4. Priorities

Each priority requires:

| Field | Type | Meaning and constraints |
| --- | --- | --- |
| `id` | String | Unique within the document. Must match `^[a-z][a-z0-9-]{0,63}$`: 1–64 characters, starting with a lowercase ASCII letter, followed only by lowercase letters, digits, or hyphens. |
| `rank` | Integer | Unique positive integer. Lower numbers indicate higher priority. |
| `name` | String | Nonempty display name. |

Example:

```json
{
  "id": "learn-ai",
  "rank": 2,
  "name": "Learn AI"
}
```

The app uses `rank`, not the priority’s array position, to determine the displayed order. Consecutive ranks beginning at 1 are recommended; gaps are accepted.

Every block’s `priority_id` must reference a priority in this document. A priority may have no allocated blocks. Relationships and dependencies between priorities are not part of this format.

## 5. Standing plan

`standing_plan` requires a `blocks` array. The array may be empty. Its optional `notes` field accepts a string of up to 5,000 characters.

Each standing-plan block requires:

| Field | Type | Meaning |
| --- | --- | --- |
| `day` | String | One of `monday`, `tuesday`, `wednesday`, `thursday`, `friday`, `saturday`, `sunday`. |
| `slot` | String | One of `morning`, `middle`, `evening`, `extra`. |
| `priority_id` | String | ID of a priority in the document. |

Standing-plan blocks also accept the optional fields in section 7.

Example:

```json
{
  "day": "monday",
  "slot": "morning",
  "priority_id": "learn-ai",
  "notes": "Study Temporal."
}
```

A standing block has a `day`, never a `date`. The standing plan is a template: importing it does not automatically create dated weeks. The user can explicitly create a missing week from this template. When generating a week, dates before `effective_from` are skipped.

Moving or splitting a dated block does not change the standing plan.

## 6. Specific weeks

Each object in `weeks` requires:

| Field | Type | Meaning and constraints |
| --- | --- | --- |
| `week_start` | Date string | A Monday in `YYYY-MM-DD` format. Each week start must be unique. |
| `blocks` | Array | The complete list of supplied work for that week. May be empty. |

Optional week fields:

| Field | Type | Meaning |
| --- | --- | --- |
| `notes` | String | Description of the week, up to 5,000 characters. |
| `changes` | Array of strings | Explanations of changes from the standing template. Each string may contain up to 5,000 characters. |

Each dated block requires:

| Field | Type | Meaning and constraints |
| --- | --- | --- |
| `date` | Date string | A real date within that week’s Monday–Sunday range, on or after `effective_from`. |
| `slot` | String | `morning`, `middle`, `evening`, or `extra`. |
| `priority_id` | String | ID of a priority in the document. |

Dated blocks also accept the optional fields in section 7. A dated block has a `date`, never a `day`.

**Every supplied week is a complete snapshot.** Missing slots remain empty, even when the standing plan assigns work to those slots. The importer does not fill gaps from the template.

For a partial week beginning Wednesday midday, simply omit Monday, Tuesday, and Wednesday morning blocks. Do not send placeholder blocks, zero-minute blocks, or a fake priority such as `before-the-plan`.

A week’s Monday may be earlier than `effective_from`; only its supplied block dates must be on or after that date. For example, a week beginning `2026-11-02` may contain work starting on `2026-11-04`.

## 7. Shared optional block fields

These fields are accepted in both standing-plan and dated blocks.

| Field | Type | Default | Constraints |
| --- | --- | --- | --- |
| `duration_minutes` | Integer | `default_block_minutes` | `1`–`1440`. A half of a standard three-hour block is `90`. |
| `position` | Integer | `1` | Positive integer ordering blocks within the same day and slot. |
| `notes` | String | Empty string | Up to 5,000 characters. |

Positions must be unique for each combination:

- Standing plan: `(day, slot, position)`.
- Dated week: `(date, slot, position)`.

If two blocks share the same day and slot, specify distinct positions. Omitting `position` on both would assign both position 1 and cause validation to fail. Consecutive positions are recommended; gaps are accepted.

Blocks in different slots may each use position 1. Array order does not replace the explicit position field.

There is no enforced limit of three blocks per day or 45 hours per week. Weekend work and extra blocks are supported. These are work positions, not appointments: **do not supply clock start/end times**. Use the literal slot value `middle`, not `midday` or `afternoon`.

Notes and `changes` are descriptive only. Text such as “move this to AI when Operations is stable” does not execute a rule; the service should send a revised plan when that condition occurs.

## 8. Complete example

This small example deliberately contains only two priorities and three dated block portions. A production plan can contain more priorities and weeks using the same structure.

```json
{
  "format_version": 1,
  "plan_name": "My weekly work plan",
  "timezone": "America/Sao_Paulo",
  "effective_from": "2026-11-04",
  "default_block_minutes": 180,
  "priorities": [
    { "id": "us-job", "rank": 1, "name": "Remote job in the US" },
    { "id": "learn-ai", "rank": 2, "name": "Learn AI" }
  ],
  "standing_plan": {
    "notes": "No fixed clock times.",
    "blocks": [
      {
        "day": "wednesday",
        "slot": "middle",
        "priority_id": "us-job"
      },
      {
        "day": "thursday",
        "slot": "morning",
        "priority_id": "learn-ai"
      }
    ]
  },
  "weeks": [
    {
      "week_start": "2026-11-02",
      "notes": "Partial week, starting Wednesday.",
      "changes": [
        "Split Thursday’s AI session between Thursday and Saturday."
      ],
      "blocks": [
        {
          "date": "2026-11-04",
          "slot": "middle",
          "priority_id": "us-job",
          "notes": "Prepare for the technical interview."
        },
        {
          "date": "2026-11-05",
          "slot": "morning",
          "priority_id": "learn-ai",
          "duration_minutes": 90
        },
        {
          "date": "2026-11-07",
          "slot": "extra",
          "priority_id": "learn-ai",
          "duration_minutes": 90,
          "notes": "Continue the study session."
        }
      ]
    }
  ]
}
```

This imports 360 minutes (6 hours) of dated work: 180 + 90 + 90. Template hours are separate and are not added to the dated week’s total.

The two 90-minute entries arrive as independent blocks. Version 1 has no field for importing their shared split lineage. Workblocks tracks lineage when the user splits a block inside the app.

## 9. Validation and common failures

The server enforces both the JSON Schema and additional semantic checks. A producer should validate against the schema before delivery, but server validation remains authoritative.

| Rejected input | Reason |
| --- | --- |
| Unknown `format_version` or extra object fields | Unsupported contract. |
| Duplicate priority IDs or ranks | Ambiguous references or order. |
| A block referencing a priority absent from `priorities` | Incomplete plan. |
| `week_start` on a Tuesday | Weeks must start on Monday. |
| Duplicate `week_start` values | Each week is supplied once. |
| A block dated outside its parent week | Incorrect week membership. |
| A supplied block before `effective_from` | Work precedes the plan. |
| Two blocks with the same day/date, slot, and position | Conflicting position. |
| `"duration_minutes": 1.5`, `0`, or `"90"` | Duration must be a positive integer in minutes. |
| `"date": null` | Importing unscheduled carryover is not supported in v1. |
| `"slot": "midday"` | The supported value is `middle`. |
| Unknown timezone | Use an IANA timezone recognized by the PHP server. |

Do not include calculated weekly totals, completion flags, database block IDs, origin IDs, revisions, relationships, or start/end timestamps. They are not accepted fields. New imported blocks are unfinished; the app generates its own IDs and calculates totals.

The browser upload interface accepts files up to **2,000,000 bytes**. This is a frontend limit, not a JSON Schema constraint; PHP/web-server request limits may also apply.

## 10. How to deliver and import

### Through the app

1. The external service produces a UTF-8 `.json` file containing the document itself.
2. Open **Plan imports → Choose JSON file**.
3. Workblocks validates the document and shows a replacement summary.
4. Review the counts of priorities, weeks, incoming blocks, unfinished blocks to delete, and completed blocks to keep.
5. Confirm **Replace plan and unfinished work**.

### Through the local command line

From the project root, using DDEV:

```bash
ddev exec php artisan plan:import /var/www/html/examples/rani-plan.json --replace
```

Substitute the path to the supplied JSON file as seen inside DDEV. `--replace` explicitly confirms destructive replacement. The command validates the file and uses the same backup/import behavior as the app.

### Through the existing HTTP API

This API currently uses the app’s password-authenticated session and CSRF protection. It is not an unauthenticated feed endpoint or a bearer-token service API.

Use `Accept: application/json` and `Content-Type: application/json`.

| Method and path | Purpose |
| --- | --- |
| `GET /api/session` | Obtain the current authentication state and `csrf_token`. |
| `POST /api/login` | Sign in with `{ "password": "YOUR_WORKSPACE_PASSWORD" }`, keeping session cookies. Use the returned CSRF token for subsequent writes. |
| `POST /api/imports/preview` | Validate and preview `{ "document": YOUR_PLAN_OBJECT }` without replacing the plan. |
| `POST /api/imports` | Replace using `{ "document": YOUR_PLAN_OBJECT, "confirm_replace": true }`. |
| `GET /api/schema` | Download the JSON Schema after signing in. |

Include the session cookies and `X-CSRF-TOKEN` on POST requests, including login using the token obtained from `/api/session`. The `document` value must be a JSON object, not a JSON-encoded string or multipart file field. The uploaded file itself must not contain this API wrapper.

Preview and import are separate requests. Preview does not reserve or freeze the workspace; import validates again and replaces the current unfinished state at execution time. Only submit the replacement request after the intended approval.

Expected responses:

- Preview success: `200` with the replacement summary.
- Import success: `201` with `{ "id": "IMPORT_UUID" }`.
- Contract validation failure: `422` with errors, without changing the plan.
- Missing authenticated session on protected endpoints: `401`.
- A missing/invalid CSRF token or an expired session can be rejected before contract validation.

Workspace backup exports have a different format (`backup_version` and `tables`). They are not valid plan documents and cannot be fed through the plan importer.

## 11. Instructions to give the scheduling service

> Produce one valid UTF-8 JSON document matching Workblocks format version 1 and the supplied JSON Schema. Include all current priorities, the full standing template, and every dated week of unfinished work that should exist after replacement. Use explicit dates for weeks, lowercase weekday/slot values, integer minutes, and unique priority IDs, ranks, and block positions. A supplied week is complete: omitted positions stay empty. The import deletes existing unfinished work, including omitted weeks and carryover; do not send a patch. Do not resend completed work or include unsupported fields. Return the JSON file without Markdown fences or commentary.

## Version 2 and automatic feeding

For ongoing changes, use [plan-v2.schema.json](plan-v2.schema.json) and the [version 2 guide](feeding-contract-v2.md). The [automated feeding guide](automated-feeding.md) describes the running file worker and pending approvals.

The service reads `documents/outbox/current.json`, keeps `workspace_id` and `base_revision` unchanged, and writes a complete version 2 document to `documents/inbox/<timestamp>-<description>.json`. The app keeps the outbox current after committed changes. Safe updates apply automatically; deletions, removed priorities, and every version 1 reset wait for user approval in Plan imports. Invalid or outdated feeds are rejected with a reason file.

Every version 2 dated or carryover block requires an `id` UUID. Keep IDs when moving/editing existing work; generate a UUID for new work. New blocks without IDs are rejected. Version 2 requires an explicit `carryover` array, even when empty. It can change the assigned priority or duration of an unfinished block in place. Completed history is never editable through a version 2 feed. See the linked guides for split lineage, file-stability checks, result sidecars, and retry rules.
