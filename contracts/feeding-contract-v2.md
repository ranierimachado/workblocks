# Workblocks scheduler exchange — version 2

Use version 2 to adjust an existing schedule without losing carryover, identities, or completed history. Use [version 1](feeding-contract.md) only for a deliberate fresh-start reset.

The formal contract is [plan-v2.schema.json](plan-v2.schema.json). Version 2 is a full snapshot of **all unfinished work**, including unscheduled carryover. It is not a patch.

## The working loop

1. In **Plan imports**, download **Export current schedule (v2)**.
2. Give that file to your scheduling service. It must start from the export, not reconstruct the plan from an older conversation or read internal SQLite tables.
3. The service changes dates/slots/notes or explicitly adds/removes/splits work. It preserves everything else, including carryover and overdue work.
4. Upload the returned JSON. The app validates it and shows additions, removals, moves, edits, splits, changed priorities/template, and total unfinished minutes before/after.
5. Review and approve **Apply scheduler update**.
6. If the app reports an outdated feed, export again and have the service reapply the changes to that fresh state.

For manual exchange, follow the steps above. For hands-off updates, the file worker maintains `outbox/current.json` and processes `inbox/`; see [automated feeding](automated-feeding.md).

The server does not contact the scheduling service. File exchange works without exposing localhost to a cloud service. Automatic inbox pickup is supported; see [automated feeding](automated-feeding.md). Service-token authentication is not part of this release.

## Fields

Version 2 retains the version 1 fields and constraints for `plan_name`, `timezone`, `effective_from`, `default_block_minutes`, `priorities`, `standing_plan`, and `weeks`. Unknown fields are rejected. See the version 1 guide for those field definitions, with the following version 2 differences.

| Field | Required | Meaning |
| --- | --- | --- |
| `format_version` | Yes | Integer `2`. |
| `workspace_id` | Yes | UUID copied verbatim from the export. Identifies this workspace/reset generation. |
| `base_revision` | Yes | Nonnegative integer copied verbatim from the export. Identifies the state used by the service. |
| `carryover` | Yes | Array containing all unfinished unscheduled work. Use `[]` when empty. |
| Block `id` | Yes, in every dated or carryover block | UUID identifying that piece of work. Keep existing IDs. Generate a new UUID for new work or a new split child. Standing-template blocks do not have IDs. |
| Block `split_from` | Optional, only on new split children | UUID of an existing unfinished block retained in this same incoming document. See splitting below. |

Do not include `origin_id`, `original_date`, block `revision`, or `completed`. The app maintains those fields itself.

A dated block uses `date`, `slot`, `priority_id`, `id`, and optional `position`, `duration_minutes`, `notes`, `split_from`. A carryover block has exactly the same fields **except it must not contain `date`**. Missing optional durations use `default_block_minutes`; missing positions default to 1.

Carryover positions must be unique within each slot. Number multiple carryover blocks `1`, `2`, `3`, and so on. The export already supplies valid positions.

## Identity and history

- A priority ID must continue to mean the same priority across version 2 updates. Renaming the priority changes its display name, not its identity. Allocate a different ID for a genuinely different priority.
- Keep an existing block’s UUID even when moving it between days, slots, weeks, and carryover. Its assigned priority can change in place to another priority in the document; this appears as an update. The priority IDs themselves must retain their meanings across feeds.
- Existing block IDs preserve split lineage, original date, and creation timestamp. The app advances their edit revisions during an accepted update.
- Completed blocks are excluded from exports. Do not resend them. Resubmitting a completed UUID as unfinished is rejected.
- Completed work keeps its historical priority ID and name snapshot. A removed priority may therefore remain referenced by completed history even though it is absent from the active list. A rename does not rewrite past names.
- Imported positions that conflict with completed work are offset to make room for the completed positions. The database enforces unique dated positions. Subsequent exports contain the resolved positions; an unchanged round trip does not keep shifting them.

New UUIDs cannot prove that a producer has not recreated completed work under another ID. The scheduling service must work from the current export rather than inventing a replacement from memory.

## Stale updates

Both preview and apply check `workspace_id` and `base_revision`. Moving, splitting, completing/reopening work, generating a week, importing a plan, or restoring a snapshot invalidates earlier exports. A reset or restore also changes the workspace UUID. A successful version 2 update advances the revision, so replaying its old file is rejected.

The check happens again when applying, inside the transaction. A preview does not freeze the workspace. If anything changed after the preview, apply returns HTTP `409` without replacing the plan.

Do not edit the revision or workspace ID to force an old feed through. Regenerate it from a fresh export.

## Carryover and overdue work

The `carryover` array is authoritative. A missing carryover array is invalid; an empty array is valid and removes existing carryover unless it was rescheduled into a dated block. Removed work appears explicitly in the preview.

`effective_from` is the start of the plan, **not the date the JSON was generated**. Keep the original value when retaining overdue blocks. A block from yesterday is valid if its date is on or after that original plan start.

Nothing automatically turns overdue work into carryover. To postpone it without choosing a new date, move the same block ID from its week’s `blocks` array to `carryover` and omit its `date` field. The server preserves its original date internally.

Dated weeks remain complete snapshots. Missing slots stay empty. Existing unfinished work omitted from all supplied weeks and carryover is removed, and listed as removed in the preview. Completed history remains, including in weeks omitted by the service.

## Splitting

Keep the parent’s existing UUID for one portion. Add a new UUID for each additional portion and set `split_from` to the original parent UUID. All portions must keep the parent’s priority. The parent must still be present in the returned document and must be unfinished.

The retained portion plus all new children of that parent must total exactly the parent’s current minutes. The app rejects splits that create or lose minutes. It derives the children’s lineage and original date from the parent.

For example, change this exported 180-minute block:

```json
{
  "id": "139d8e53-eeba-4ce9-bb53-465851bf42a7",
  "date": "2026-11-06",
  "slot": "morning",
  "priority_id": "learn-ai",
  "duration_minutes": 180,
  "position": 1
}
```

Keep this portion in its week:

```json
{
  "id": "139d8e53-eeba-4ce9-bb53-465851bf42a7",
  "date": "2026-11-06",
  "slot": "morning",
  "priority_id": "learn-ai",
  "duration_minutes": 90,
  "position": 1
}
```

And put this new portion into `carryover`:

```json
{
  "id": "da1c1720-a1b9-4797-95a4-33f3e673eec5",
  "split_from": "139d8e53-eeba-4ce9-bb53-465851bf42a7",
  "slot": "extra",
  "priority_id": "learn-ai",
  "duration_minutes": 90,
  "position": 1
}
```

`split_from` is a creation instruction, not a permanent field to repeat. After import, export again: both portions are existing blocks with their own IDs and no `split_from`. Their shared lineage remains stored internally.

A standalone duration edit is allowed and appears under Updated with the change in total minutes. Use `split_from` when dividing existing work; do not simulate a split by inventing an unrelated block.

## API and CLI

All HTTP endpoints still use the existing password session and CSRF protection. Version 2 does not introduce a public or bearer-token feed endpoint.

| Endpoint | Purpose |
| --- | --- |
| `GET /api/scheduler/export` | Download the current document, ready for a scheduler to edit. |
| `GET /api/schema?version=2` | Download the version 2 JSON Schema. |
| `GET /api/state` | Read the UI-oriented database state, including the current workspace metadata. Prefer the scheduler export for feeding. |
| `POST /api/imports/preview` | Validate `{ "document": PLAN_OBJECT }` and return a change breakdown. |
| `POST /api/imports` | Apply `{ "document": PLAN_OBJECT, "confirm_replace": true }` after review. |

The POST envelope is the same as version 1. The JSON file itself contains just the plan object, without `document` or `confirm_replace` wrappers. Authentication, file-size limits, backup requirements, and transaction rollback behavior remain as documented in the version 1 guide.

CLI examples from the project root:

```bash
# documents/ is ignored by Git; create an export there for the scheduling service.
ddev exec php artisan plan:export /var/www/html/documents/scheduler-export.json

# After reviewing the service’s returned file:
ddev exec php artisan plan:import /var/www/html/documents/scheduler-update.json --replace
```

A workspace export is consistent: its revision and planner data are read in one database transaction. A backup export (`backup_version` and `tables`) is a different format; it is not a scheduler input.

## Reset and recovery

Version 1 is intentionally still destructive: it deletes all unfinished blocks, including carryover, replaces the template and priorities, and detaches preserved completed blocks from the new priority IDs. Its preview and confirmation are labeled **Fresh-start reset**.

Both versions create a physical SQLite backup before importing and record the previous planner state. A failure rolls back planner changes and the revision. Restore returns the whole previous planner state, including completed-work state, and invalidates existing feeds. Workspace revision metadata is never rolled backward by restore.

## Instructions for the external scheduling service

> Start from the latest Workblocks version 2 scheduler export. Keep workspace_id and base_revision unchanged. Preserve priority identities and all existing unfinished block IDs. Return the complete standing plan, all dated unfinished work, and every carryover block. Keep effective_from as the original plan start. Move existing IDs instead of deleting/recreating work. For a split, retain the parent ID, create new child UUIDs with split_from, and conserve minutes. Do not resend completed work. List scheduling changes in the affected week’s changes. Validate against plan-v2.schema.json. Return a JSON file for the user to preview and approve. If Workblocks rejects it as outdated, request a fresh export; never override the revision.
