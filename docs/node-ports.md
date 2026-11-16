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

Supported firewalls: firewalld (runtime and permanent), ufw (rules commented
`irboard-node`), and iptables with a DROP or REJECT INPUT policy (rules tagged
`irboard-node`, re-added after a reboot by the next run). A firewall that
accepts by default needs nothing and is reported as `open`.

artisan is run as the owner of the panel directory, never as root, so it
cannot leave root-owned files in `storage/` that the web server then cannot
write.

## What the admin sees

`GET /{admin}/system/getNodePorts` returns `storage/node_ports_status.json`:
the firewall found, each needed port with the nodes needing it, whether it is
allowed and which process listens on it (a port held by another program, say
the web server's 8443, shows here), and the ports opened or closed on the last
run. The agent log (`storage/logs/server_config.log`) records every change.

The hosting provider's own firewall (a cloud security group) cannot be seen
from the server: open the port there as well.
