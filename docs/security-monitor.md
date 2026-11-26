# Read-only fail2ban overview

`/security` is available only to super administrators. Livewire checks access
again on every render. No ban, unban, settings, shell or service-control methods
are exposed. The page keeps SSH fail2ban bans separate from full-server manual
IP blocks. All twelve supported locales are included. Dates use GMT+3.

The standalone host collector runs `fail2ban-client get sshd banip`, reads the
fail2ban SQLite database in read-only mode for expiry metadata, and parses only
Ban/Unban records for sshd from the current and previous uncompressed log files.
It reads at most 256 KiB per file, sends at most 200 events and 1000 active
addresses. This is recent available history, not a complete historical archive.
The active list is authoritative from fail2ban, never reconstructed from logs.
Unknown expiry remains unknown rather than being guessed. No raw log messages,
usernames, passwords or tokens are sent to the browser.

Every 30 seconds the collector posts a bounded, validated snapshot to
`/api/server/security/status`, authenticated by the existing server-link secret.
The Console timestamps receipt itself and retains the latest snapshot for one
day. After 90 seconds without an update it is explicitly stale. No data and
collector errors are distinct from a healthy empty ban list.

Install `security-monitor.py` alongside `/opt/statusdesk/firewall-agent.py`.
The collector reuses the existing root-only `/etc/statusdesk-firewall.json` for
its loopback URL and secret; it does not modify the firewall agent or its rules.
The supplied separate systemd unit needs no Linux capabilities and cannot write
system files. Root access is used only for the fail2ban socket and logs.

The Console container gets neither the fail2ban socket nor host privileges.
The authenticated push endpoint accepts only state, validated IP addresses,
timestamps and ban/unban enums. It does not execute any host operations.

Deployment requires an updated Console image and a new collector service.
Do not restart the live container or install/start the collector without the
user's explicit go-ahead when active RustDesk sessions may be present.
