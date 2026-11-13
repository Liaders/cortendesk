# Work groups for whole-session accounting

This is the server increment of the StatusDesk session-accounting feature. It is not deployed. The StatusDesk client picker is a separate next increment; current clients cannot make an explicit selection yet.

## Device configuration

The existing device group remains the primary group. Optional additional work groups reuse the existing group catalog. They do not participate in device visibility, strategies, group filters or access grants.

The shared Add/Edit device dialog offers a searchable, scrollable checklist of additional groups. Its primary group is always a work-group option, without a redundant pivot entry. Only groups the editor can access, or groups already assigned to that device, can be saved. Existing additional-group labels are accounting metadata visible to an authorized device editor; they do not expose the groups' devices.

## Attribution

Only a successful login audit establishes attribution. A pre-login attempt has no attribution. One available group is recorded automatically; zero groups is recorded as ungrouped. Multiple groups require an explicit client choice. Old clients, signed-out clients and failed submissions leave those sessions as **Organization not selected**, rather than charging the primary group.

The group id and name are recorded in the audit row. A successful selection applies to the entire session, including its start, and cannot be replaced with another group. Repeating the same selection succeeds without changing the recorded name. Reconfiguration, group renaming and group deletion do not redistribute recorded time. There are no intra-session intervals in this version.

Pre-existing audit rows are not backfilled. Their report behavior continues to use the current device group, with that limitation explained in the report. Operator ownership still uses current device data. Historical imports retain the legacy attribution path.

## Client contract

All new endpoints require the existing client Bearer token. No changes are made to tokenless audit payloads or the existing active-session GUID response.

1. Optionally fetch `GET /api/statusdesk/devices/{rustdesk_id}/work-groups`. The response is `{groups: [{id, name}], selection_required: boolean}`. Only visible, active target devices are exposed. These choices alone are not a session assignment.
2. Resolve the exact session GUID using the existing `GET /api/audit/conn/active?id=...&session_id=...&conn_type=...`. Keep the random u64 session id as a string. Do not match by target id alone: simultaneous sessions may choose different organizations.
3. Fetch `GET /api/statusdesk/sessions/{guid}/work-group`. The response contains `status`, the recorded `group` or null, and available `groups` only when status is `required`. `automatic`, `selected` and `ungrouped` need no prompt. A null status identifies a legacy session.
4. For `required`, select one offered group and send `PUT /api/statusdesk/sessions/{guid}/work-group` with `{group_id: integer}`. A 200 response with `{saved: true}` confirms persistence. Do not display a choice as saved before this response. Validation/stale choices return 422; replacing an existing choice returns 409. Fetch fresh state to resolve a conflict.

Session reads/writes require both visibility of the target and a token whose device id exactly matches the audit's initiating `from_peer`. Tokens without a device id cannot assign sessions. A GUID is not sufficient authorization. Selection remains possible after a quick disconnect so delayed audit delivery does not lose an otherwise valid assignment.

The client must keep selection state per connection, not globally per target. On an unsupported server it must retain its existing connection path. On a supported server, cancellation or an error must not silently choose the primary group. No client credentials or groups should be persisted in an unscoped shared cache for this feature.

## Report and exports

The existing target group filter continues to describe the physical device's current primary group. A separate **Work organization** filter describes attribution. The summary groups sessions by recorded work-group id; **Organization not selected** remains separate from ungrouped devices. CSV and XLSX add a Work group column; exported system labels remain English. Scope, date clipping, overlap calculations and the 10,000-session export cap are preserved.

## Deployment and rollback

This increment adds `device_work_groups` and three nullable attribution columns to `audit_connections`. It does not change existing group memberships or historical audit values. Rehearse migration on a database copy and take a fresh backup before deployment. Earlier application images ignore the additional table/columns; retaining them preserves new accounting data when rolling back an image. Do not run a down migration as part of an image rollback. Existing schema-equality rollback scripts require review before deploying this increment.

Regression surface: the shared device editor and its create/reset path, the successful-login audit writer, and the connection report/export paths. New device-group relations are never used by access-control or strategy-resolution queries. No client source, connection protocol, production data or production container is modified in this increment.
