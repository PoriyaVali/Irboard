# Node ports on the panel's own server

A node can run on the same server as the panel (its V2bX next to it). Its
listening port then has to be open in that server's firewall, and closed again
once no node needs it. The server-config agent does both, by itself.

## How it decides

`php artisan node:local-ports` lists the ports the local nodes need
(`app/Services/LocalNodePorts.php`). A node is **local** when either

- the V2bX serving it last reported from one of this server's own addresses
  (UniProxy remembers the address for a day), or
- its address is one of this server's addresses, or a name resolving to one.

The first covers a node behind a CDN or a relay, whose address does not point
here although its listener does. The second opens the port before V2bX has
reported for the first time. Loopback never counts: a V2bX reaching the panel
through an SSH tunnel reports from `127.0.0.1` whichever server it runs on.

For each local node the **service port** (`server_port`) is needed, with the
protocols that node type listens on: TCP for VLESS, VMess, Trojan, AnyTLS,
Sudoku, SSH and HTTP (UDP for an mKCP/QUIC transport); UDP for Hysteria2, TUIC,
ShadowQUIC, WireGuard and MASQUE; Mieru by its transport and port range; both
for the rest. Child nodes share their parent's listener, and an unmanaged
protocol node is a server the panel only lists, so neither adds a port.

## How it is applied

`scripts/node_ports.py` (run as root by `server_config_agent.sh` every minute,
and within seconds after a node is saved or deleted) makes the firewall match:

- A needed port the firewall does not already allow is opened and recorded in
  `storage/node_ports_state.json` as opened by the agent.
- A recorded port no node needs any more is closed. Several nodes may share a
  port: it stays open until the last of them is gone.
- Nothing else is ever closed. A port allowed before (SSH, the web server, one
  the operator opened) is not in the record, so it stays as it was.
- When the panel cannot answer (database down, PHP error), nothing changes: an
  unanswered question must not read as "no nodes" and close everything.

Supported firewalls: firewalld, ufw, and iptables/ip6tables:

- firewalld: each active zone (or the default when none is active), runtime
  and permanent separately. The exact zone and configuration are recorded,
  so a default-zone change cannot redirect cleanup. If only one addition
  succeeds, that rule stays recorded and the other is retried.
- ufw: public inbound rules, separately for IPv4 and enabled IPv6, commented
  `irboard-node`. An allow limited to a source, destination or interface is
  not mistaken for public access. An existing IPv4 rule is not adopted when
  only IPv6 needs adding (and vice versa). With `IPV6=no` in
  `/etc/default/ufw` on a server that has IPv6 addresses, ufw filters IPv4
  only: its IPv4 ports are still opened, and the status carries a warning
  that IPv6 clients are not covered.
- iptables and ip6tables: each family with a DROP/REJECT INPUT policy gets
  its own tagged rule and ownership record; rules return after a reboot on
  the next run. An ACCEPT policy with an unconditional final deny is
  reported as unsupported rather than incorrectly reported open.

A family with an accept-by-default policy needs no extra rule. Existing
source-specific restrictions are left intact. Failure to discover local
addresses or inspect a required firewall family/zone stops reconciliation;
it is never interpreted as an empty node list or an open firewall. Changes
to cloud security groups and arbitrary nftables/custom chains are outside
the agent's scope. Rules must still be reviewed against local firewall policy.

artisan is run as the owner of the panel directory, never as root, so it
cannot leave root-owned files in `storage/` that the web server then cannot
write.

## What the admin sees

The column now prefers a separate **endpoint observation**, generated in the
background by `scripts/node_port_probe.py`. The previous local-firewall-only
view could show Unknown for every node when none ran on the panel server.
`node:local-ports --endpoints` exports configured client addresses only, with
no credentials. Each row, including a relay/child, is checked at its **own
host and client port**, not its service port or its parent's endpoint.

TCP connect success is green **Open (TCP)**; explicit connection refusal is
red **Closed (TCP)**. A timeout or unavailable route is **Unreachable**, not
proof that a firewall closed the port. The tooltip names the vantage point
(the panel server) and the tested transport. A CDN edge accepting TCP does
not prove a working VPN handshake. A UDP node is asked the question its
protocol answers: a QUIC server (Hysteria, TUIC, ShadowQUIC, MASQUE) gets a
datagram in an unknown QUIC version and answers with Version Negotiation
(RFC 9000); for Hysteria the datagram is wrapped in the node's obfuscation
(Salamander for Hysteria 2, xplus for Hysteria 1) with the node's key, and a
relay uses its root's. The MDNS server (a UDP DNS tunnel) gets a DNS query
under its domain and answers every query. An answer is green **Open (UDP)**,
an ICMP refusal red **Closed (UDP)**, and no answer to three tries red **No
reply (UDP)** (node down, port dropped by a firewall, or UDP blocked on the
way). Protocols that never answer strangers (WireGuard, mKCP, Mieru,
Shadowsocks UDP, ...) and unsupported ranges stay **UDP unverified** instead
of being guessed from a TCP check or heartbeat. Checked against a real
quic-go listener (the library Hysteria uses), plain and behind Salamander.
The obfuscation key goes only to the probe, never into its report.
Host/port changes, edits and stale snapshots invalidate the previous result.

The observation worker deduplicates endpoints and uses bounded concurrency
and a global deadline. Opening the admin list only reads its cached result;
it never launches a scan. Its status file `node_port_reachability.json` is
separate from firewall ownership/desired state, and observation failures
can never open or close a firewall rule. Work that did not complete within
the batch budget remains Unknown rather than being reported unreachable.
The agent starts it in the background under a lock, so a slow batch never
holds the server-config queue and two batches never overlap.

In **Nodes / Manage**, the **Port status** column follows **Address** in both
admin bundles. Text accompanies the color; the tooltip identifies the tested
endpoint, transport and last check. The same badge is next to the address on
mobile. Older API responses without an observation field retain the legacy
local-firewall display; an explicit failed or stale endpoint observation
never falls back to an unrelated parent or local-firewall success.

A gray "Unknown" badge means there is no applicable, current report: for
example, a UDP-only service has not been verified, a node was just edited, or
the observer/report is unavailable. A report older than three minutes is not
displayed as a current open/closed result. No network check is started by
opening the node list.

The visible rows share one authenticated read every 20 seconds. Requests
stop when the list unmounts; logout or navigation discards previous reports
and in-flight responses.

`GET /{admin}/system/getNodePorts` returns `storage/node_ports_status.json`,
with the separate endpoint snapshot under `reachability`:
the firewall found, each needed port with the nodes needing it, whether it is
allowed and which process listens on it (a port held by another program, say
the web server's 8443, shows here), and the ports opened or closed on the last
run. The agent log (`storage/logs/server_config.log`) records every change.

The hosting provider's own firewall (a cloud security group) cannot be seen
from the server: open the port there as well.

## Node addresses as IPs in subscriptions

Setting `subscribe_serve_ips` (subscribe group, off by default). A client
has to look a node's host name up before its tunnel exists, and that lookup
is plain DNS: the filter sees the node's name and the address it resolves
to, and for REALITY it ties the cover site to the operator's own domain a
moment before the handshake. With the setting on, subscriptions carry the
node's IPv4 address instead (resolved on the panel, cached for 10 minutes),
so clients make no lookup at all.

A node is swapped only when nothing else depends on its name: the TLS name
is pinned to the old host when the node had none; an HTTP transport (ws,
httpupgrade, xhttp, h2) must carry its own Host header or the node keeps its
name; nodes on Cloudflare addresses keep their name (a CDN edge must be found
by name, near the client); a node tagged `keep-domain` is never touched (the
tag is not served). MDNS, TrustTunnel, v2node and the Mihomo-native types are
not touched. Code: `app/Services/NodeAddressResolver.php`, applied in
`ClientController::subscribe` (which the relay mirror also builds from) and
the legacy `AppController::getConfig`. If a node's IP changes, clients pick it up on
their next subscription refresh.

