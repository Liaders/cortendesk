# StatusDesk Console

StatusDesk Console is the maintained distribution in [Liaders/cortendesk](https://github.com/Liaders/cortendesk), based on [CortenDesk](https://github.com/marcpope/cortendesk) by Marc Pope. The upstream license, notices, credits and sponsorship links are retained.

The maintained branch is `codex/statusdesk-main`. `main` remains an unmodified upstream reference. `statusdesk-upstream.json` records the integrated upstream revision, and `STATUSDESK_VERSION` records our distribution version; upstream's `VERSION` is retained separately.

## Included additions

- An explicit role switch for devices without any organization. Visibility remains scoped on every client API; assignment still requires device management and an eligible organization. Existing roles default to denied. See [the client contract](docs/session-work-groups.md#access-to-devices-without-an-organization).
- Optional organization INNs in the list and editor, reviewed TXT import that preserves existing bindings and grants, and server-side organization search/pagination for large catalogs. See [organization import](docs/organization-import.md).
- Connection time reports with dependent source/target device filters, saved work-organization attribution, interval clipping, time without overlaps per operator, and localized XLSX export. Only authenticated sessions count; exports stop at 10,000 sessions, with a truncation notice and totals for the exported subset. Reports use GMT+3 date boundaries and readable filter summaries, operator display names and device aliases. Invalid dates show a field error. Reports retain the current-ownership and legacy-attribution limitations explained in the UI. Connection-report CSV export has been removed; other log exports are unchanged.
- Recently registered devices (24 hours), badges on desktop and phone layouts, and a No group filter.
- Device-group creation from the shared device editor, reusing the existing dialog and preserving unsaved device fields and permission checks.
- Current device aliases beneath IDs in connection, file-transfer and alarm logs, with eager loading and viewer scoping.
- Client Bearer APIs for organization search, permitted creation, idempotent device attachment and atomic first attribution of an ungrouped session. Confirmed attribution remains immutable; additional organizations never grant device access. Client alias read/write preserves the machine hostname. See [the client contract](docs/session-work-groups.md).
- All new interface labels and connection-report workbook headings/sheet names cover all 12 supported locales. Existing unrelated log exports retain their original language behavior.

The temporary report image previously prepared separately is not the same as an image built from this branch. Adopting this distribution on an existing installation is a separate, tested migration; CI never connects to a production server.

## Checks and images

`StatusDesk checks and image` runs Pest on PHP 8.4 and 8.5, validates/audits Composer dependencies, and checks formatting of maintained PHP files. It then builds a Linux amd64 image and starts it with no network and a disposable anonymous data volume. The container check covers startup, readiness, an authenticated report, XLSX export, and the embedded ID/relay processes.

Only a successful push to the maintained branch publishes to `ghcr.io/liaders/statusdesk-console`. Pull requests, upstream review runs and manual test runs never publish. Every published build has a `sha-<commit>` tag and an `edge` convenience tag. Use the **image digest from the run summary** for deployment; `edge` is not a production release channel. There is no automatic production deployment and no production SSH key or database in Actions.

The inherited upstream Docker Hub/GHCR release workflow is restricted to the upstream repository. Our publication uses only GHCR and the repository-scoped `GITHUB_TOKEN`; Docker Hub credentials are not needed. Package visibility and registry authentication follow GitHub's package settings.

For local checks:

```sh
composer install
php vendor/bin/pest
docker build --build-arg STATUSDESK_VERSION="$(cat STATUSDESK_VERSION)" -t statusdesk-console:test .
bash scripts/statusdesk/smoke-container.sh statusdesk-console:test
```

## Reviewing upstream updates

Run **Actions → Review upstream changes**. Choose `main` for a stable update; `beta` is a deliberate opt-in, never an automatic upgrade.

If the upstream revision is already included, the workflow reports that and changes nothing. Otherwise it merges upstream into a new `codex/upstream-…` review branch, records the new baseline, provides a link to open a PR into the maintained branch manually, and calls the same test/container workflow for the candidate. It never opens or approves a PR, merges changes, or deploys. The reusable verification job checks the prepared branch directly; it does not depend on a token-generated PR event or permission to approve reviews.

If there are merge conflicts, the workflow stops and lists the files in its summary. Resolve them in a local integration branch:

```sh
git fetch origin
git fetch upstream main
git switch -c codex/review-upstream origin/codex/statusdesk-main
git merge --no-ff upstream/main
```

Keep original authorship when merging. Prefer the upstream implementation when it includes one of our additions, adapt our tests to the agreed behavior, and remove duplicate implementations. Update the baseline manifest after resolving the merge, bump `STATUSDESK_VERSION` for a distribution release, and open a PR. Never merge beta changes solely to obtain a single feature without reviewing its migrations and dependencies.

## Deployment and rollback

`compose.statusdesk.yml` and `.env.statusdesk.example` are templates for a new installation, **not a recreation of an existing server's mounts or configuration**. For a new installation, select a verified image digest and configure the public URL, then run:

```sh
docker compose --env-file .env.statusdesk -f compose.statusdesk.yml up -d
```

For an existing server:

1. Save its actual image digest, environment, ports, mounts, key hashes and configuration. Back up its database, files and sessions.
2. Rehearse the selected image on a separate copy. Review migration changes, notification settings, authentication and client connectivity.
3. Switch during an agreed service window. Preserve the old container with automatic restart disabled and preserve the latest shared data.
4. Check readiness, report/export behavior, keys and ID/relay operation. An image rollback does not automatically reverse database migrations: validate schema compatibility before reverting code.

Real databases, keys, credentials and server-specific deployment state belong outside the repository and build context. Before the first production switch to this distribution, create server-specific deployment/rollback instructions from the actual running configuration.

See [the Russian operator guide](docs/STATUSDESK_RU.md).
