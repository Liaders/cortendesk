# Work groups for whole-session accounting

This contract covers Console's session-accounting API and the additive Organizations API for StatusDesk. Client versions without the picker remain supported. Availability of the Organizations endpoint must be checked separately from session-accounting support; an older Console can support session attribution without supporting this listing endpoint.

## Device configuration

The existing device group remains the primary group. Optional additional work groups reuse the existing group catalog. They do not participate in device visibility, strategies, group filters or access grants.

The shared Add/Edit device dialog shows compact organization tags and a searchable, scrollable add picker. The primary organization is shown with a Primary badge and no removal button. Extra tags can be removed; selected organizations are excluded from search results. The Create organization action reuses the existing group dialog and automatically adds its result as an additional tag, without changing the primary organization or unsaved device fields. Cancelling that dialog preserves the selection. Device assignments are committed when the device form is saved. Pointer interaction inside either picker, including dragging its scrollbar, keeps the query/list open; Tab and outside clicks still close it.

Only groups the editor can access, groups already assigned to that device, or groups created in the current editor can be saved as additional assignments. Existing assignment labels are accounting metadata visible to an authorized device editor; they do not expose the organizations' other devices or create permanent access grants for the editor.

The Console section is titled **Organizations and groups**, with **Organizations** and **User groups** tabs. Existing group routes, database identifiers, API fields and permission resource codes are retained. User groups and the separate Users section keep their roles. Primary organization labels are used for physical-device filters and membership; work organization labels refer to accounting attribution.

Organization rows show separate primary-device and additional-accounting-device counts, scoped to the viewer's existing device visibility. Clicking an organization opens a read-only member dialog with separate lists. A device redundantly assigned through both paths appears only in the primary list/count. Pending, deleted and inaccessible devices are excluded. A group used only for accounting can therefore show zero primary devices and a nonzero additional count. Viewing member details requires both group-read and device-read Console permissions; no new device visibility is granted.

## Attribution

Only a successful login audit establishes attribution. A pre-login attempt has no attribution. One available group is recorded automatically; zero groups is recorded as ungrouped. Multiple groups require an explicit client choice. Old clients, signed-out clients and failed submissions leave those sessions as **Organization not selected**, rather than charging the primary group.

The group id and name are recorded in the audit row. A successful selection applies to the entire session, including its start, and cannot be replaced with another group. Repeating the same selection succeeds without changing the recorded name. Reconfiguration, group renaming and group deletion do not redistribute recorded time. There are no intra-session intervals in this version.

Pre-existing audit rows are not backfilled. Their report behavior continues to use the current device group, with that limitation explained in the report. Operator ownership still uses current device data. Historical imports retain the legacy attribution path.

## Client contract

All new endpoints require the existing client Bearer token. No changes are made to tokenless audit payloads or the existing active-session GUID response.

1. Optionally fetch `GET /api/statusdesk/devices/{rustdesk_id}/work-groups`. The response is `{groups: [{id, name}], selection_required: boolean}`. Only visible, active target devices are exposed. These choices alone are not a session assignment.
2. Resolve the exact session GUID using the existing `GET /api/audit/conn/active?id=...&session_id=...&conn_type=...`. Keep the random u64 session id as a string. Do not match by target id alone: simultaneous sessions may choose different organizations.
3. Fetch `GET /api/statusdesk/sessions/{guid}/work-group`. The response contains `status`, the recorded `group` or null, and available `groups` only when status is `required`. `automatic` and `selected` are confirmed. Older clients do not prompt for `ungrouped`; v2 clients may offer first assignment using the capabilities below. A null status identifies a legacy session.
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
- This endpoint is read-only. It does not assign any organization to a session. Existing `/api/device-group/accessible`, `/api/peers` and `/api/users` access scopes and primary-group behavior are unchanged; Accessible devices and Colleagues keep their existing behavior. `/api/peers` additionally exposes the nullable registration date documented below.
- On servers without this endpoint (404/405/501), retain the client's existing Organizations behavior rather than inferring additional memberships. An authentication error must not reuse another account's cached result.

### Suggesting an organization for a new connection

When opening a connection from a specific organization, capture its numeric id as a **per-connection suggestion**. New clients show the picker **before connecting**, using the device work-groups endpoint. Preselect the suggestion only when it is currently allowed. After successful connection, resolve the exact GUID, read its session work-group state, and revalidate the choice against that state before submitting it. Keep all other allowed organizations available in the picker. A stale suggestion is discarded; the listing is not authoritative for session assignment. A failed connection creates no session assignment. Older clients may continue showing their picker after connecting.

From other tabs, pass no suggestion: with multiple choices the picker starts without a preselected organization. When only one choice is available it is selected automatically (the normal login audit already records `automatic`; a remaining single choice on a `required` session can be confirmed through the same PUT endpoint).

Choosing **Do not choose** on a required session sends no PUT and leaves it with `status: required`, shown as **Organization not selected**. A suggested or locally highlighted choice is not saved until explicitly confirmed and acknowledged by `{saved: true}`. This action is not a way to clear an already confirmed or automatically recorded assignment. No null group, new skip endpoint, or intra-session group switching is introduced.

The existing session checks remain authoritative: the token's initiating device must match `from_peer`, the target must remain visible, the chosen group must be allowed, and a confirmed assignment is immutable. A repeated confirmation of the same group remains idempotent; a different confirmed choice returns 409. Merely fetching the Organizations list never changes session state.

## Client organization management (API v2)

This is an additive contract, separate from the read-only Organizations tab. It requires the same **client Bearer**, never a Console cookie or an admin-API token. The target must satisfy `Device::visibleTo(actor)`, including active/non-deleted status, on every request. A role does not widen device visibility. New capabilities use the actor's current Console permission matrix; no new role or implicit permission is introduced.

### Search and bindings

`GET /api/statusdesk/devices/{rustdesk_id}/organization-options?q=...&current=1&pageSize=100`

```json
{
  "api_version": 2,
  "device_has_organization": false,
  "bindings": [],
  "permissions": {"can_attach": true, "can_create": true},
  "total": 1,
  "data": [{"id": 10, "name": "Organization A", "inn": "7707083893"}]
}
```

- `bindings` is the complete deduplicated current membership list, independent of search and pagination: `[{id: 10, name: "Organization A", kind: "primary"}]`. `kind` is `primary` or `additional`; a redundant binding is reported as primary. These are accounting labels, not a grant to view other devices.
- `device_has_organization` means any primary/additional binding exists. It does **not** mean the current session has a confirmed organization.
- `can_attach` requires `device: rw`; `can_create` additionally requires `group: rw`. Both are hints; mutation endpoints recheck authorization.
- Without `can_attach`, `data` is empty and `total` is zero; use the unchanged device work-groups endpoint for ordinary session choices. Bindings remain available for the visible device.
- Administrators may attach any organization. Other device managers may attach an organization they can access, one already bound to this device, or one they created through this API. Knowing an arbitrary group ID is insufficient.
- Search is a literal substring match of organization name or INN, Unicode case-insensitive (including Cyrillic), maximum 255 characters. `data` includes additive nullable `inn`; older clients may ignore it. Pagination bounds/defaults/order match the Organizations listing: name then ID; `current` 1..2147483647, `pageSize` 1..500, default 100. Pagination counts organizations, never bindings. No device IDs from other organizations are exposed here.

### Creation form

`GET /api/statusdesk/organization-create-options?device_id={rustdesk_id}`

```json
{
  "api_version": 2,
  "can_create": true,
  "fields": {
    "name": {"required": true, "max_length": 255},
    "inn": {"required": false, "max_length": 12},
    "note": {"required": false, "max_length": 500}
  },
  "user_groups": [{"id": 7, "name": "Support"}],
  "default_user_group_ids": [7]
}
```

These are the existing device-group form fields. `user_groups` is the set this actor may grant access to **on the new, empty organization**: all groups for a super-admin; only the actor's own groups for a delegate. This deliberately does not expose unrelated user groups to delegated client management. If exactly one own group is available, it is the default; multiple own groups require a choice. No own groups means no default. If creation is not allowed, return `can_create: false`, empty groups and defaults. Clients translate their own explanatory label: members of the selected user groups get access to this organization's primary devices. Extra accounting bindings never confer that access.

`POST /api/statusdesk/organizations`

```json
{
  "request_id": "d7599187-df38-45aa-86ca-aa070bf9b244",
  "device_id": "000123",
  "name": "Organization A",
  "note": null,
  "user_group_ids": [7]
}
```

200 response:

```json
{"organization": {"id": 10, "name": "Organization A"}, "user_group_ids": [7]}
```

- `request_id` is a required UUID. `device_id` is a required string (max 255); peer IDs must never be converted to numbers. `name` is required, maximum 255; `note` is optional/nullable, maximum 500. `user_group_ids` is a required array, may be empty, and must contain distinct positive integer IDs from the currently permitted list. Every grant is checked; a forbidden/nonexistent user-group grant returns 403 rather than silently dropping it.
- Creation requires both management permissions and a visible device. It creates an empty organization and its confirmed access grants, **not** a device binding or a session assignment. The client selects the returned ID, then submits the applicable attachment/session operation. If that operation fails, retry it with this ID instead of creating another organization.
- Optional `inn` is a nullable string: empty becomes null; nonempty values must have 10 or 12 ASCII digits, a valid checksum, and be unique. Preserve leading zeros. Invalid/duplicate values return 422. Omission remains compatible with existing client request receipts; omitted INN is not added to the old payload hash. When supplied, INN participates in the request hash and must be repeated on retry. The creation response remains unchanged. Console TXT import is a separate administrator workflow described in [organization import](organization-import.md).
- The creator can subsequently attach the new organization even when no user group was selected; this special eligibility comes from the creation receipt and does not reveal any other devices.
- Mutation and durable receipt commit together. `request_id` is scoped to actor + operation (`create` or `attach`), survives token replacement for the same actor, and has no automatic expiration. Repeat identical validated data to recover a lost response. Group ID order is canonicalized; missing/null note are equivalent. Reuse with different data, including a different target device, returns 409. A deleted result returns 409; it is never recreated by replay. Authorization and target visibility are rechecked on retries. Organization names need not be unique; different request IDs are separate operations.

### Device attachment

`POST /api/statusdesk/devices/{rustdesk_id}/work-groups`

```json
{"group_id": 10, "request_id": "36e9cd4b-f1b2-4efe-85f2-b9d53387382d"}
```

200 response:

```json
{
  "organization": {"id": 10, "name": "Organization A"},
  "bindings": [{"id": 10, "name": "Organization A", "kind": "primary"}]
}
```

The server decides binding kind. If **no organization at all** is bound, the first binding becomes primary and uses the existing primary-group access/strategy behavior. Otherwise a new binding is additional and accounting-only, even if the device has additional bindings but no primary. Existing bindings are a no-op. This endpoint never changes an audit's organization. It requires device management plus attachment eligibility as described above. A forbidden organization returns 422. Idempotency uses the same durable mechanism as creation. The returned `bindings` are current, not the cached list from the original attempt; replay does not restore a binding removed later.

### Atomic first assignment

Session GET responses add these fields without changing the old fields:

```json
{
  "api_version": 2,
  "device_has_organization": false,
  "bindings": [],
  "permissions": {
    "can_attach": true,
    "can_create": true,
    "can_assign": true,
    "can_attach_and_assign": true
  },
  "status": "ungrouped",
  "group": null,
  "groups": []
}
```

`can_assign` is true for `required`, or for `ungrouped` with device-management permission. `can_attach_and_assign` additionally requires an unconfirmed (`required`/`ungrouped`) state and device management. Both are false for legacy/confirmed rows as appropriate. `groups` remains populated **only** for `required`; v2 organization search provides attachment candidates for `ungrouped`. `bindings` describes the current device, while `group` is the immutable historical session snapshot; their names/memberships may differ.

The existing PUT accepts an optional flag:

```json
{"group_id": 10, "attach_device_if_needed": true}
```

- Without the flag (or with false), the original contract is unchanged: only `required` may be selected from current device choices; an `ungrouped` or legacy row returns 409. No extra management permission is imposed on the existing selection path.
- With true, an unconfirmed `required` or `ungrouped` row may be assigned after the same device-management and organization-eligibility checks used for attachment. The device binding, if needed, and audit assignment commit **atomically**. A later failure rolls back the binding too. Already-bound organizations still require device management on this explicit path.
- The whole exact session, including time before assignment, is attributed to the saved group ID/name. Closed short sessions remain eligible; no timing heuristics are used. Other sessions on the same device are not modified. Legacy rows are not backfilled.
- Confirmed `automatic`/`selected` rows cannot change; same-group retries return `{saved: true}` without attaching anything or renaming the historical snapshot. Different groups return 409 without device side effects. First assignment is serialized with attachment/competing assignment; no intervals or switching are introduced.
- Success remains 200 `{saved: true}`; reread session GET for its confirmed snapshot. A separate `request_id` is unnecessary: the exact GUID plus immutable assignment is the idempotency boundary.
- A device token must still exactly match `from_peer`, and the target must still be visible. A missing device ID in the token returns 403; an unknown/mismatched GUID or invisible target returns 404.

Before intentional disconnect, a new client may offer the same organization picker/create flow for an unassigned device/session. Save and await success before disconnecting; alternatively allow explicit disconnect without assignment or return to the session. Network/process failure cannot guarantee this prompt or persistence. The XLSX export and web report read the same saved snapshot via `SessionWorkGroups::label`; the v2 path introduces no separate report attribution source.

### Errors and rollout

Use `Accept: application/json` and `Content-Type: application/json` for JSON writes. Standard Laravel JSON errors use `message`; validation errors additionally use `errors`. Error prose is diagnostic, not a stable translation key; branch on status and current server state.

- 401: invalid, expired, missing client token, or inactive user (`{"message":"Unauthenticated."}`).
- 403: insufficient management permission, forbidden user-group grant, or session token without initiating device ID. Never retry through admin credentials.
- 404: device unavailable/pending/deleted/invisible, or session missing/not owned by the initiating token. Does not disclose which check failed.
- 409: immutable/conflicting session assignment, incompatible session state, reused request UUID with different data, or deleted creation result.
- 422: invalid parameters (for example `{"message":"...","errors":{"request_id":["..."]}}`), or an organization not eligible for attachment/selection. Refresh choices; do not substitute another organization silently.
- 429: organization creation is limited to 30 requests/minute per source IP; attachment to 60/minute per source IP, with separate limiter prefixes. Respect `Retry-After`; reuse the same request UUID. Existing endpoint throttles are unchanged.
- 5xx/network error: persistence is not confirmed. Repeat the same mutation UUID, or reread/retry the exact session GUID, to recover; never create a new organization just because its response was lost.

Detect v2 using the new options endpoint or additive `api_version` in session GET. Absence of a new route on an older server (404/405/501) must preserve old connection/picker behavior; a single 404 can also mean an invisible device, so do not infer an account-wide capability change from it alone. Do not downgrade after 401/403 to bypass authorization. Preserve successful old read-only listing and device work-groups behavior. Roll out the new migration before enabling new client mutations; production is not changed by preparing this code.

### Starting another session for a different organization

A confirmed current session is never reassigned. If the user explicitly wants to work for another organization, the client may open a **new connection with its own GUID** and pass that organization as a per-connection choice. Do not retain the old GUID or report a locally changed label as a saved switch.

For an organization **not yet bound to the device**, the reliable order is:

1. Check organization options and attachment permission. Create the organization first if needed, retaining its returned ID and mutation UUID on retry.
2. **Before starting the new connection**, submit the device attachment POST and await success. It changes only device configuration, not the old session's immutable attribution. If attachment fails, do not silently connect for another organization.
3. Start the new connection. Resolve its exact new GUID; read its authoritative session state.
4. If `required`, confirm the chosen ID from the allowed list through the normal PUT. If `automatic`/`selected` already confirms that same ID, no assignment change is needed. If a different ID is already confirmed, do not overwrite it; show the conflict. Do not silently charge the unwanted organization as if the requested choice succeeded.

The pre-attachment is necessary in the common case where the device has **one other organization**: without it, login automatically confirms that sole existing organization and even atomic attach+assign must return 409. When the new session is still `required` or `ungrouped` and `can_attach_and_assign` is true, the atomic PUT remains available after connection; it is **not** a way to override `automatic` or `selected`. A pre-attachment that succeeds remains a device binding even if the subsequent connection fails; it does not create or reassign a session. Ordinary pre-connection selection of already bound organizations needs no attachment POST.

## Server device alias from the desktop client

These additive endpoints use the existing client Bearer and the same active/non-deleted `Device::visibleTo(actor)` scope. They edit the global Console device alias, not a private address-book alias or the machine hostname. Existing peer payloads and address-book operations keep their semantics. Probe the alias GET separately: organization API v2 alone does not establish that an older deployment supports alias editing.

`GET /api/statusdesk/devices/{rustdesk_id}/alias`

200 response:

```json
{
  "api_version": 2,
  "device_id": "000123",
  "alias": "Accounting workstation",
  "can_set_alias": true,
  "max_length": 255
}
```

`device_id` is always a string; `alias` is always a string, empty when no alias exists. Read access requires existing visibility, not device-management permission. `can_set_alias` reflects the current `device: rw` Console permission; admin passes, delegated roles use their matrix, and users without roles retain the existing legacy device-management baseline. Roles and additional accounting bindings never grant device visibility.

`PUT /api/statusdesk/devices/{rustdesk_id}/alias`

```json
{"alias": "Accounting workstation"}
```

200 returns the same state fields plus `saved: true`. The `alias` key is required; its value is a string up to 255 Unicode characters or null. Null/empty string clears it; normal request trimming applies. Numeric `0` is invalid, while string `"0"` is a valid alias. Unrelated input fields are ignored, never mass-assigned. The server only updates `devices.alias`; `devices.hostname`, `/api/peers`'s `info.device_name`, ownership, organizations and address-book entries are unchanged. The ordinary `/api/peers` top-level `alias` then reflects the saved value.

Writes recheck visibility and device management, serialize on the device row, and log a changed alias through Console audit. Repeating the same value succeeds without another change/audit event; no request UUID or migration is needed. Concurrent different edits use last committed write semantics, not a 409 revision protocol. After an uncertain response, reread this GET before deciding whether to retry, so a newer edit is not needlessly overwritten. Do not display an alias as saved before acknowledgement; on failure retain the confirmed server value.

Errors: 401 for missing/invalid/expired client token or inactive account; 403 for a visible device without write permission; 404 for an unavailable/invisible/pending/deleted device (even for admin); 422 for missing/invalid/overlong alias; 429 for rate limits; 5xx/network error means unconfirmed success. Standard JSON `message`/validation `errors` apply as above. GET is limited to 120 requests/minute per source IP, PUT to 60/minute per source IP, with separate limiter prefixes; respect `Retry-After`. Old-server 404/405/501 must leave the original toolbar usable and disable unsupported alias editing, not trigger a web/admin fallback. No Console cookie or admin token is accepted as a substitute for the client Bearer.

## Access to devices without an organization

The separate role capability `device_unassigned: r` (Console switch **Access to devices without an organization**) extends the shared server-side device visibility scope only to approved, non-deleted devices with neither a primary organization nor any additional accounting organization. Missing/disabled permissions remain denied. It does not grant access to all devices, any organization, another organization's devices, or another operator's session. Ordinary ownership and primary-organization grants still apply independently. An additional-only organization binding is not an unorganized device and never becomes a visibility grant.

Client Bearer endpoints use the same scope, including `/api/peers`, device `work-groups`, `alias`, `organization-options`, creation options and session `work-group` reads/writes. Mutations recheck visibility after locking the target. Revoking the role capability or assigning a formerly unorganized device to an inaccessible organization affects subsequent requests using existing tokens. The session endpoint additionally requires the exact GUID and the token's matching source device; confirmed attribution remains immutable.

Reading an unorganized device requires the visibility capability (or an existing ownership grant). Attaching an organization and editing an alias additionally require `device: rw`. Organization eligibility is unchanged: only accessible, already bound, or client-created eligible organizations may be attached. Creating a new organization still requires `group: rw`. The capability does not populate organization memberships for unorganized devices or expand the list of selectable organizations. Automation-token scope vocabulary and address-book sharing rules are unchanged.

Existing roles are not automatically granted this capability based on their names. Enable it deliberately for the intended role, preserving the rest of its permission matrix; role editing remains super-admin-only. No schema migration is required. Older images ignore the extra permission key during an image rollback, temporarily restoring their former visibility behavior.

## Report and exports

The Devices toolbar organization selector accepts keyboard search, fetching authorized options in pages of 50 while retaining All organizations and No organization actions. Its existing primary-group filtering meaning is unchanged. General device search additionally matches primary and additional organization names/INNs as literal case-insensitive substrings, including Cyrillic on SQLite. It remains inside the existing device visibility scope; additional membership never makes another organization's devices visible.

The overview's Active sessions widget displays the saved accounting organization for that exact session. Required/ungrouped states remain explicit. Legacy sessions without attribution display an unknown-organization label rather than inferring the device's primary organization. Current organization names are displayed, with the session's saved name retained as fallback if the organization was deleted. The same naming rule applies to report summaries, historical organization choices, session rows and XLSX. Saved IDs/names and assignment statuses are never rewritten by presentation.

Operator grouping uses user IDs, not display-name equality. Registered source devices retain current-ownership attribution. The built-in web client's server-generated `web-<user ID>` identity resolves to the same user, but only within identities already visible to the report viewer. Unknown/native unowned sources remain grouped by peer ID and display that ID alongside a recorded name. No lookup by a client-reported name/login and no assignment-history backfill occurs. Owner/user-group source filters include eligible web-client identities; an explicit source device or primary organization filter does not. Time without overlaps merges web and native intervals only for a resolved common account. Equal display names belonging to different user IDs remain separate.

Date inputs retain human D.M.YYYY text and add an accessible native-calendar button. Calendar selection updates the report immediately; month presets, server validation, inclusive date boundaries and GMT+3 conversion remain unchanged. The native calendar falls back to a visible date input where the picker API is unavailable.

The **Connected to** filter block contains one **Work organization** selector; there is no duplicate standalone organization control or target-primary-group selector. The **Who connected** block retains the source device's primary organization. Device dropdowns follow the selected organization and owner filters; changing a filter clears an incompatible device choice. Target candidates include primary/additional membership and visible devices with recorded historical sessions for the selected organization, so later reassignment or deletion of a group does not make historical records impossible to select. These candidates never grant visibility.

Session filtering uses the audit's saved work-group ID, **not** just device membership. Current membership in organization A does not charge sessions saved for B to A. Legacy rows retain their documented current-primary-group fallback; no attribution is invented or backfilled. The organization summary uses saved group IDs/names. **Organization not selected** remains separate from ungrouped devices. Unknown/unavailable filter IDs are never resolved through unscoped catalog queries.

The report uses fixed **GMT+3 / UTC+03:00** for displayed timestamps, month presets and inclusive selected-date boundaries. The query converts boundaries to the existing application/storage timezone; database storage and other pages' timezones are unchanged. Human date input/output is `1.11.2026`; older ISO component state is accepted for compatibility. Time is `09:05:07`. Durations remain elapsed time, including hours beyond 24.

Connection-report export is **XLSX only**; its CSV action and label have been removed. Other logs' exports are unchanged. Workbook sheets, headings, status/type labels and filter summaries use the active Console locale, including all 12 supported languages. Summaries show names instead of JSON and explicit All organizations/owners/devices when unrestricted. User labels prefer the resolved account's display name, then username; unknown/unowned sources show the recorded client name with source ID, or ID alone. Identical names do not merge employees. Native-client attribution still reflects current ownership, not historical proof of the signed-in human operator.

Destination labels are `Alias (RustDesk ID)`, or the ID alone. IDs stay text. Workbook dates/counts/durations remain typed with `d.m.yyyy`, `d.m.yyyy hh:mm:ss` and `[h]:mm:ss` formats; summary values are left-aligned. Client-provided text is literal and cannot create formulas or hyperlinks. Scope, clipping, overlap calculations, active-session exclusion and the 10,000-session cap are preserved; the workbook explains truncation and totals describe the exported subset.

## Deployment and rollback

This increment adds `device_work_groups` and three nullable attribution columns to `audit_connections`. It does not change existing group memberships or historical audit values. Rehearse migration on a database copy and take a fresh backup before deployment. Earlier application images ignore the additional table/columns; retaining them preserves new accounting data when rolling back an image. Do not run a down migration as part of an image rollback. Existing schema-equality rollback scripts require review before deploying this increment.

Regression surface: the shared device editor and its create/reset path, the successful-login audit writer, and the connection report/export paths. New device-group relations are never used by access-control or strategy-resolution queries. No client source, connection protocol, production data or production container is modified in this increment.

The v2 management increment additionally creates `client_organization_requests`, an additive receipt table for idempotent creation/attachment. Existing device/audit records are not rewritten by the migration. Keep this table when rolling back an image; do not run its down migration in production, because deleting receipts loses retry guarantees. Retain backups and review schema-equality deployment helpers before any later deployment. New first primary bindings intentionally use existing primary-group strategy/access resolution; additional bindings remain excluded from it.

## Device registration dates and report preferences

`GET /api/peers` now includes `created_at` for every returned peer on every page.
It is the original `devices.created_at`, serialized as UTC ISO 8601, for example
`"2026-11-25T09:15:00Z"`, or JSON `null` when unknown. Heartbeat, alias edits,
and organization attachment do not replace it. Existing Bearer authentication,
visibility, ordering, pagination and all other peer fields retain their behavior.
The client may use this nullable value to build its own last-24-hours view.

The Devices table exposes **Registered** immediately after **Last seen**, sorted
by `devices.created_at` in either direction. The column is enabled by default.
The migration also enables it once in existing custom column selections; users
may subsequently hide it again. Unknown dates display a dash.

Connection report filters below the date controls are automatically stored in
`users.connection_report_filters` for the authenticated account only. Source,
target, accounting organization and `excludeInternal` are saved. Dates are never
saved: each new visit starts with the entire current month in GMT+3, including
when the month has changed since the preceding visit. Date edits and month
presets remain usable during the current visit. Restored filters still run
through existing authorization and device visibility scopes.

**Exclude connections within the same organization** removes a session only
when its confirmed accounting organization (`selected` or `automatic`) equals
the currently visible source device's non-null primary organization ID. The
target device's primary or additional memberships are not used for this
comparison. User groups are not involved. Missing source devices, hidden source
devices, browser-only sources, missing organizations and legacy sessions without
confirmed attribution remain in the report; the server does not guess their
organization. Changing a source device's primary organization can therefore
change this optional filter's result for past sessions. No session history is
rewritten. The shared query applies to displayed sessions, active counts,
duration and overlap totals, organization/operator summaries, XLSX rows and the
export-limit count. XLSX summary records whether the option was enabled.

The Connections log displays the session accounting organization below the
controlled device alias on desktop and mobile, using the same labeling as Active
sessions. Renamed organizations display their current name. Unselected and
unknown legacy attribution are explicit; primary-device membership is not used
as a fallback.

Deployment requires the new nullable user-preference migration. It does not
change device or session records. An image rollback can leave the added nullable
column in place; it is ignored by the previous image. Do not run migration down
as part of an ordinary rollback. These changes are prepared locally and require
separate deployment authorization.
