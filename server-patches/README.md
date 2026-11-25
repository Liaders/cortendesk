# StatusDesk access policy extension

Base: marcpope/cortendesk-server v1.5.1, commit
`a0b469eddc56b9485d85926ef27816332ab02463`, AGPL-3.0-only.

`access-blocks.patch` modifies that exact source. Docker builds the modified
hbbs and hbbr from source and runs the Rust library tests. Upstream source,
license and notices remain in the build stage. Changes: device-ID deny lists,
expiring canonical IPv4/IPv6 deny lists, checks during registration, signalling
and relay pairing, and relay policy polling. Both processes advertise
`access-blocks-v1/<role>` when fetching policy. Concurrent cache writes use
process-specific temporary files. The console requires recent successful policy
fetches from both roles before creating blocks.

Protocol additions to `/api/server/policy`:

```json
{"blocked":["123456789"],"blocked_ips":{"198.51.100.5":1795600000}}
```

IP values are absolute Unix expiration timestamps, checked at request time even
while the console is unavailable. Old snapshots omit these fields and retain
their old behavior. Existing policy cache persistence applies to both binaries.

The policy rejects application traffic; it does not install kernel firewall
rules or prevent TCP handshakes. Console web administration, administrative API
and SSH remain accessible. Trusted reverse-proxy configuration must preserve
the client IP for both HTTP and WebSocket traffic. A proxy's own address must
never be blocked as a substitute for a missing client address.

This is not hardware identity. Changing the device ID can evade the device
deny list. A known signed-in source is identified by its client token; an
anonymous source cannot be reliably distinguished from unrelated clients behind
the same IP in open mode. IP blocks affect every client at that address. They
apply to new requests and relay pairings, not existing direct sessions. Already
established relay sessions are not forcibly terminated by this patch.

Use signed-in clients and approved-only policy for strong source admission;
individual device credentials and immediate session termination require a
separate protocol change. No additional visibility or organization grants are
provided by any block or unblock operation.
