# Access blocks

Administrators can block a known device ID indefinitely or its displayed last
public IP for 24 hours from the device detail page. A reason is required. IP
confirmation warns that all clients sharing the address are affected. Private,
loopback and reserved IPs are rejected. Repeating a live block does not extend
its expiry. Expired and revoked records remain for history; explicit actions
are recorded in the Console audit. Nothing deletes devices or session history.

Only super administrators may create or revoke these infrastructure-wide rules.
Delegated device permissions are insufficient. New blocks require compatible
hbbs and hbbr to have fetched policy within 60 seconds. Unblocking remains
available even when the link is unhealthy. Rules propagate on the next policy
poll. No restart is necessary to apply or expire a block.

Client API middleware checks canonical source IP, request registration ID,
login device identity and the existing Bearer token's source ID. Infrastructure
policy endpoints and administrative API are exempt; normal authorization still
applies. Web administration and SSH are unaffected. All client endpoints retain
their original visibility and permissions after the blocking check passes.

The image includes a source-built server patch, described in
`server-patches/README.md`. That document specifies enforcement coverage and
identity limitations. This is application admission control, not a kernel IP
firewall. Existing direct and relay sessions are not terminated. Full device
identity that survives ID changes requires additional client/server credentials.

The separately prepared connection report fix includes the legacy target-primary
organization fallback when excluding internal sessions; explicit unassigned and
confirmed selections are preserved. Neither that fix nor access blocking has
been deployed by preparing this branch.
