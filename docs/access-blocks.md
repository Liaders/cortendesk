# Temporary IP access blocks

The upstream hbbs/hbbr binaries and existing approval policy are unchanged.
The Console provides administrator-only, audited 24-hour IP blocks. A separate
host service applies nftables rules. Client API middleware and nginx WebSocket
admission enforce the same list for traffic through HTTP. A reason and explicit
confirmation are required; the displayed IP is locked for that editor instance
so a concurrent heartbeat cannot silently change the address being confirmed.

IP blocks affect every device behind the address. Private/reserved IPs are
rejected. Expired and manually revoked records remain in history, with actor and
reason. Duplicate blocks do not extend the deadline. Unblocking is available even
when the agent is unhealthy. New blocks require an acknowledgment from the agent
within 30 seconds. Changes normally propagate within five seconds. The UI does
not claim per-device-ID blocking: that requires a separately agreed admission
mechanism. Devices and session history are never deleted by these actions.

## Host service

Install `scripts/statusdesk/firewall-agent.py` as
`/opt/statusdesk/firewall-agent.py`, root-owned and not writable by the web user.
Install Python 3 and nftables. Create root-owned `/etc/statusdesk-firewall.json`
with mode 0600, containing `console_url` and `secret`. The secret is the existing
server-link secret, never a user password or an SSH key. Use HTTPS, or a loopback
HTTP URL with the Console's published port. Redirects and ambient HTTP proxies
are not followed. Do not expose this secret in command arguments or logs.

Install `scripts/statusdesk/statusdesk-firewall.service` into systemd. Start it
only after reviewing the full-host scope. Rules drop all incoming and forwarded
traffic from blocked IPv4/IPv6 addresses, regardless of port or protocol. This
includes SSH (22 and the temporary 2222), HTTPS, client API and Docker ports. The Console
container needs no privileged mode, Docker socket, host mounts or NET_ADMIN.

The service only replaces its own `inet statusdesk_access` table atomically.
It never flushes the host ruleset. Input hooks handle native/userland-proxy
traffic; forward hooks also cover Docker DNAT without relying on port mappings.
Kernel set timeouts expire blocks even if the agent or Console stops. Web
administration, administrative API and SSH are unavailable from the blocked IP.
Do not block the only administrator address without a verified hosting-console
or alternate-address recovery route. The agent should use the loopback Console
URL so it can continue reconciliation even during a full-host block. Direct connections
between clients are unaffected; relay traffic may stall or disconnect when a
firewall rule drops its packets. Do not promise immediate session termination.
nginx admission alone does not close existing WebSocket sessions, but the host
firewall also drops their network packets once synchronized.

Policy and acknowledgments are authenticated by the existing server secret at
`/api/server/firewall/policy` and `/api/server/firewall/applied`. Only the current
policy digest may be acknowledged. API-only updates cannot install firewall rules
until the host service is installed and running. UI changes are pending until the
next successful reconciliation. Never install the host service or network rules
as part of a routine image-only rollout without a separate review.

nginx checks all Console requests, including static files and both WebSocket
upgrades, through an internal PHP subrequest. Public callers cannot access the internal nginx location. Resolved
client IPs must come only through configured trusted proxies, as before. Direct
RustDesk/WebSocket ports are covered by host rules. The same IP list applies to
client and administrative API requests without widening any access permissions.
Authenticated infrastructure exchange remains exempt at the application layer;
this does not bypass the host-level source-IP firewall.

## Verification and rollback

Pure Python tests never touch the host network. The nftables integration test
runs only in a disposable network namespace and refuses the host namespace.
Container CI tests nginx rejection for WebSocket, web login, device pages and
static files, while unrelated addresses remain allowed.

To disable kernel enforcement, stop the agent and remove only its own table:
`nft delete table inet statusdesk_access`. Existing timeouts also clear remaining
entries automatically. Do not flush nftables or reset Docker rules. Database
block history can remain on image rollback. Removing all enforcement also needs
an image/configuration rollback or explicit revocation of active Console blocks.

The separately prepared connection report fix includes legacy target-primary
organization fallback in internal-session exclusion. It has not been deployed.
Neither this branch nor its host service is installed on the production server.
