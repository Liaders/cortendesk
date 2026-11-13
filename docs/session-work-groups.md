# Work groups for whole-session accounting

This contract covers Console's session-accounting API and the additive Organizations API for StatusDesk. Client versions without the picker remain supported. Availability of the Organizations endpoint must be checked separately from session-accounting support; an older Console can support session attribution without supporting this listing endpoint.

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

## Organizations tab

`GET /api/statusdesk/organizations?current=1&pageSize=100` uses the same client Bearer token. No new permissions, credentials or device grants are introduced.

```json
{
  "total": 2,
  "data": [
    {"id": 10, "name": "Organization A", "peer_ids": ["123456789", "987654321"]},
    {"id": 20, "name": "Organization B", "peer_ids": ["123456789"]}
  ]
}
```

- `total` counts organizations with at least one currently visible device, before pagination. Organizations are ordered by name, then id to break ties.
- `current` is a positive integer page number, default 1, maximum 2147483647. `pageSize` is a positive integer, default 100, maximum 500. Invalid parameters return 422. A page beyond the result set returns the same `total` with `data: []`.
- Pagination applies only to organizations. Every returned organization includes its **complete** visible `peer_ids` list, even when that list is larger than `pageSize`. Peer ids are sorted lexically, deduplicated within each organization and always JSON strings, preserving leading zeros and large numeric ids. Organization ids are JSON integers; names are display labels, not identifiers.
- Membership is the union of a device's primary group and its additional work groups. A device can appear in multiple organizations. A redundant primary/additional binding produces one entry in that organization. A visible device without a primary group still appears in its additional organizations. Devices without any group do not appear in this listing.
- Both membership branches start from the existing `Device::visibleTo(user)` scope. The current owner and existing primary-group/user-group grants determine visibility; additional work-group membership does not. Pending and soft-deleted devices are excluded, including for administrators. An organization with no visible devices is omitted, even if it exists or was explicitly granted. The name of an additional organization is accounting metadata for its visible devices; other devices in that organization remain hidden.
- Fetch each organization's devices by matching these ids against the existing authorized peer data. Load all required pages of the existing peer list independently. Do not derive membership from `device_group_name`, do not merge organizations by name, and do not treat membership as an access grant. Refresh or clear account-scoped organization data on login changes and permission changes.
- This endpoint is read-only. It does not assign any organization to a session. Existing `/api/device-group/accessible`, `/api/peers` and `/api/users` response shapes and primary-group behavior are unchanged; Accessible devices and Colleagues keep their existing contracts.
- On servers without this endpoint (404/405/501), retain the client's existing Organizations behavior rather than inferring additional memberships. An authentication error must not reuse another account's cached result.

### Suggesting an organization for a new connection

When opening a connection from a specific organization, capture its numeric id as a **per-connection suggestion**. After successful connection, resolve the exact GUID and read its session work-group state using the existing steps above. Preselect the suggestion in the picker only when its id occurs in the server's current `groups` list. Keep all other allowed organizations available below it. A stale suggestion is discarded; the listing is not authoritative for session assignment.

From other tabs, pass no suggestion: with multiple choices the picker starts without a preselected organization. When only one choice is available it is selected automatically (the normal login audit already records `automatic`; a remaining single choice on a `required` session can be confirmed through the same PUT endpoint).

Choosing **Do not choose** on a required session sends no PUT and leaves it with `status: required`, shown as **Organization not selected**. A suggested or locally highlighted choice is not saved until explicitly confirmed and acknowledged by `{saved: true}`. This action is not a way to clear an already confirmed or automatically recorded assignment. No null group, new skip endpoint, or intra-session group switching is introduced.

The existing session checks remain authoritative: the token's initiating device must match `from_peer`, the target must remain visible, the chosen group must be allowed, and a confirmed assignment is immutable. A repeated confirmation of the same group remains idempotent; a different confirmed choice returns 409. Merely fetching the Organizations list never changes session state.

## Report and exports

The existing target group filter continues to describe the physical device's current primary group. A separate **Work organization** filter describes attribution. The summary groups sessions by recorded work-group id; **Organization not selected** remains separate from ungrouped devices. CSV and XLSX add a Work group column; exported system labels remain English. Scope, date clipping, overlap calculations and the 10,000-session export cap are preserved.

## Deployment and rollback

This increment adds `device_work_groups` and three nullable attribution columns to `audit_connections`. It does not change existing group memberships or historical audit values. Rehearse migration on a database copy and take a fresh backup before deployment. Earlier application images ignore the additional table/columns; retaining them preserves new accounting data when rolling back an image. Do not run a down migration as part of an image rollback. Existing schema-equality rollback scripts require review before deploying this increment.

Regression surface: the shared device editor and its create/reset path, the successful-login audit writer, and the connection report/export paths. New device-group relations are never used by access-control or strategy-resolution queries. No client source, connection protocol, production data or production container is modified in this increment.
