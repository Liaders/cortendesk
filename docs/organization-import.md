# Organization INN and TXT import

Organizations retain their existing `device_groups` IDs. An optional, unique INN is stored as a string, preserving leading zeros. Empty values become NULL. Nonempty values must contain exactly 10 or 12 ASCII digits and pass the corresponding checksum; all-zero placeholders and spreadsheet scientific notation are rejected. The field appears in the organization list, detail view and editor. User groups do not have an INN.

## Import workflow

On **Organizations and groups → Organizations**, users with `group: rw` can open **Import organizations**. The only accepted upload extension is `.txt`; CSV import is not supported.

Each record has two tab-separated fields: organization name and INN. An optional first header is `Наименование<TAB>ИНН` (English `Name<TAB>INN` is also supported). Empty INNs are allowed, but the separator is still required. Standard quoted fields and doubled quotation marks are supported. Input may be UTF-8 (with or without BOM), Windows-1251, or BOM-marked UTF-16LE/BE. Limits are 2 MiB and 10,000 records. Blank lines are ignored. Values are trimmed but never numerically converted or padded.

Uploading and previewing do not change organizations. The preview shows create/update/skip/error counts, physical starting line numbers, and 25 rows per page. Filter to errors and correct a row inline, or explicitly choose to exclude erroneous rows. Updates to existing names require a separate opt-in. **Apply checked changes** is the only step that writes the catalog.

Matching rules:

- Match a nonempty INN first. A different normalized name requires explicit rename consent.
- Otherwise match a unique normalized exact name. Normalization collapses whitespace and ignores Unicode case; it does not remove legal forms or punctuation and does not use fuzzy matching.
- A name match can fill an empty existing INN. A different existing INN or several matching names requires manual correction.
- Empty imported INNs never erase an existing INN. Repeated identical records are skipped; conflicting INNs/names are flagged. A complete valid record takes precedence over its blank-INN duplicate.
- Existing IDs, device bindings, access grants, notes and session organization snapshots are retained. Import never deletes or merges organizations. Optional user-group access choices apply only to newly created empty organizations. Delegates can select only their own user groups; administrators can select any user group.

Preview state is stored server-side, scoped to its author, with a one-hour expiry. Applying rechecks permissions, ownership, expiry, selected grants and the current matching plan. Catalog changes since preview require refreshing the preview. Catalog updates, audit entries and the import receipt commit in one transaction. A repeated request for the same applied batch returns its receipt without applying twice.

The scheduled `statusdesk:prune-organization-imports` command removes expired unused drafts and applied receipts older than 30 days. Successful imports discard uploaded rows and previews immediately. The command does not delete organizations.

## Large catalogs

The organization list queries and paginates on the server: 25/50/100 rows, default 50. Search is a literal Unicode case-insensitive substring of name or INN. Device counts are SQL counts scoped to the viewer and the current page. Search and page position survive editing. The device editor fetches organization search results in pages of 50, retaining selected labels; user-group access editing shows the first 50 search matches while retaining already checked IDs.

## Compatibility and migration

The additive migration adds nullable `inn`, indexed derived `name_key`, private `organization_import_batches`, and a reverse additional-binding index. It backfills only the derived name key; existing organization fields and timestamps are preserved. Client creation accepts optional INN, and organization options can search it. Older clients may omit INN; their existing idempotency payload hashes remain valid. Session assignment and additional-binding access rules are unchanged. See [the client contract](session-work-groups.md).

Deployment is separate from implementation: back up first and run the forward migration with the reviewed image. Keep the added schema when rolling back an image; do not run the down migration on production because it drops INNs and receipts. If an older image changes organization names while rolled back, recompute `name_key` before returning to this version so search uses current names. Direct SQL catalog writers must maintain that derived key too.

Feature tests cover parsing, checksums, matching conflicts, preserving bindings/history/grants, stale plans, atomic rollback, permissions, receipt replay, old-client compatibility, cleanup, 1,200-row pagination, editor scoping and all 12 translations. JavaScript tests cover remote search races, pagination, keyboard selection and failure states.
