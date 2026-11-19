#!/usr/bin/env python3
"""Observe configured client endpoints, separately from firewall ownership.

One attempt (including IPv4/IPv6 alternatives) is shared by nodes using the
same endpoint. TCP is a connect. UDP has no generic connect, so only a
protocol that answers a stranger is asked: a QUIC server (Hysteria, TUIC,
ShadowQUIC, MASQUE) answers an unknown version with Version Negotiation,
inside Hysteria's obfuscation when it has one, and the MDNS server answers
any DNS query; a refused UDP port (ICMP) is closed. Other UDP protocols stay
explicitly unverified. Only the reachability snapshot is written.
"""
import argparse
import datetime
import errno
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import queue
import re
import selectors
import socket
import subprocess
import tempfile
import threading
import time

MAX_TARGETS = 500
MAX_WORKERS = 16
CONNECT_TIMEOUT = 3.0
TOTAL_BUDGET = 20.0
REF = re.compile(r'^[a-z0-9][a-z0-9_-]{0,31}#[1-9][0-9]*$')
RANGE = re.compile(r'^([0-9]{1,5})-([0-9]{1,5})$')
# 0x?a?a?a?a versions are reserved to force Version Negotiation (RFC 9000 s15).
QUIC_PROBE_VERSION = b'\x1a\x2a\x3a\x4a'
# A UDP endpoint key: never confused with a TCP (host, port) key.
_UDP = object()
DNS = re.compile(r'^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?$')


def valid_host(host):
    if not isinstance(host, str) or not host or len(host) > 253:
        return False
    try:
        ipaddress.ip_address(host)
        return True
    except ValueError:
        return DNS.fullmatch(host) is not None


def classify(target):
    """Return a canonical TCP endpoint or an honest non-probe outcome."""
    port = target.get('port')
    if isinstance(port, str) and RANGE.fullmatch(port):
        return None, ('unknown', 'unsupported_range')
    if isinstance(port, bool) or not isinstance(port, (str, int)) or not re.fullmatch(r'[0-9]{1,5}', str(port)):
        return None, ('unknown', 'invalid_endpoint')
    number = int(port)
    if not 1 <= number <= 65535 or not valid_host(target.get('host')):
        return None, ('unknown', 'invalid_endpoint')
    if target.get('transport') == 'udp':
        kind, obfs = target.get('probe'), target.get('obfs')
        if kind == 'quic' and obfs in (None, 'salamander', 'xplus'):
            secret = target.get('obfs_password')
            if obfs is not None and (not isinstance(secret, str) or not secret):
                return None, ('unknown', 'udp_unverified')
            return (_UDP, 'quic', target['host'], number, obfs, secret if obfs else None), None
        if kind == 'dns':
            domain = target.get('domain')
            return (_UDP, 'dns', target['host'], number, domain if valid_host(domain) else '', None), None
        return None, ('unknown', 'udp_unverified')
    if target.get('transport') != 'tcp':
        return None, ('unknown', 'invalid_endpoint')
    return (target['host'], number), None


def connect_endpoint(host, port, deadline, timeout=CONNECT_TIMEOUT):
    """Race bounded IPv4/IPv6 alternatives, success only after SO_ERROR=0.

    DNS executes in daemon workers. A stalled system resolver cannot hold the
    command past the batch deadline; the command process exits after writing
    its bounded report. It never sends protocol/application credentials.
    """
    stop = min(deadline, time.monotonic() + timeout)
    try:
        addresses = socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)
    except socket.gaierror:
        return 'unreachable', 'dns_error'
    except OSError:
        return 'unreachable', 'timeout'
    if time.monotonic() >= stop:
        return 'unreachable', 'timeout'
    # Interleave both families. A broken IPv6 route must not hide working IPv4.
    by_family = {socket.AF_INET: [], socket.AF_INET6: []}
    for family, kind, protocol, _, address in addresses:
        if family in by_family and address not in by_family[family] and len(by_family[family]) < 4:
            by_family[family].append(address)
    candidates = []
    for index in range(4):
        for family in (socket.AF_INET6, socket.AF_INET):
            if index < len(by_family[family]):
                candidates.append((family, by_family[family][index]))
    if not candidates:
        return 'unreachable', 'dns_error'
    sockets = []
    errors = []
    refused = {errno.ECONNREFUSED, 10061}
    pending = {errno.EINPROGRESS, errno.EWOULDBLOCK, errno.EALREADY, errno.EINTR, 10035, 10036, 10037}
    with selectors.DefaultSelector() as selector:
        try:
            for family, address in candidates:
                try:
                    sock = socket.socket(family, socket.SOCK_STREAM)
                    sockets.append(sock)
                    sock.setblocking(False)
                    code = sock.connect_ex(address)
                    if code == 0:
                        return 'open', 'connected'
                    if code in pending:
                        selector.register(sock, selectors.EVENT_WRITE)
                    else:
                        errors.append(code)
                except OSError as exc:
                    errors.append(exc.errno)
            while selector.get_map() and time.monotonic() < stop:
                for key, _ in selector.select(max(0.0, stop - time.monotonic())):
                    code = key.fileobj.getsockopt(socket.SOL_SOCKET, socket.SO_ERROR)
                    selector.unregister(key.fileobj)
                    if code == 0:
                        return 'open', 'connected'
                    errors.append(code)
            if selector.get_map():
                return 'unreachable', 'timeout'
            if errors and all(code in refused for code in errors):
                return 'closed', 'refused'
            return 'unreachable', 'timeout'
        finally:
            for sock in sockets:
                sock.close()


def _xor(data, key):
    return bytes(b ^ key[i % len(key)] for i, b in enumerate(data))


def _obfs_key(kind, password, salt):
    psk = password.encode()
    if kind == 'salamander':  # Hysteria 2: BLAKE2b-256(PSK || 8-byte salt)
        return hashlib.blake2b(psk + salt, digest_size=32).digest()
    return hashlib.sha256(psk + salt).digest()  # Hysteria 1 "xplus": 16-byte salt


def obfuscate(kind, password, data):
    if kind is None:
        return data
    salt = os.urandom(8 if kind == 'salamander' else 16)
    return salt + _xor(data, _obfs_key(kind, password, salt))


def deobfuscate(kind, password, data):
    if kind is None:
        return data
    size = 8 if kind == 'salamander' else 16
    if len(data) <= size:
        return b''
    return _xor(data[size:], _obfs_key(kind, password, data[:size]))


def quic_probe_packet(dcid, scid):
    """A long-header packet in a version no server speaks. Servers ignore
    unknown versions in datagrams under 1200 bytes, so it is padded."""
    header = (bytes([0xc0 | (os.urandom(1)[0] & 0x0f)]) + QUIC_PROBE_VERSION
              + bytes([len(dcid)]) + dcid + bytes([len(scid)]) + scid)
    return header + os.urandom(1200 - len(header))


def is_version_negotiation(data, dcid, scid):
    """Version 0, our source ID as its destination and our destination as its source."""
    if len(data) < 7 or not data[0] & 0x80 or data[1:5] != b'\0\0\0\0':
        return False
    size = data[5]
    if data[6:6 + size] != scid or len(data) <= 6 + size:
        return False
    at = 6 + size
    return data[at + 1:at + 1 + data[at]] == dcid


def dns_probe_query(domain):
    """An A query for a random name under the node's domain: the MDNS server
    answers every query (NXDOMAIN or no data), and any answer proves it."""
    ident = os.urandom(2)
    name = os.urandom(6).hex() + '.' + (domain.rstrip('.') if domain else 'probe.invalid')
    if len(name) > 253:
        name = os.urandom(6).hex() + '.probe.invalid'
    qname = b''.join(bytes([len(label)]) + label.encode() for label in name.split('.') if label) + b'\0'
    return ident, ident + b'\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00' + qname + b'\x00\x01\x00\x01'


def udp_endpoint(kind, host, port, extra, secret, deadline, timeout=CONNECT_TIMEOUT):
    """Ask a UDP endpoint the question its protocol answers.

    Open: the expected answer (or any datagram back). Closed: every address
    refused the port (ICMP). Otherwise unreachable: no answer although a
    live server of this protocol always answers. Sent again each second, so
    one lost datagram is not a verdict.
    """
    stop = min(deadline, time.monotonic() + timeout)
    try:
        addresses = socket.getaddrinfo(host, port, type=socket.SOCK_DGRAM)
    except socket.gaierror:
        return 'unreachable', 'dns_error'
    except OSError:
        return 'unreachable', 'timeout'
    by_family = {socket.AF_INET: [], socket.AF_INET6: []}
    for family, _, _, _, address in addresses:
        if family in by_family and address not in by_family[family] and len(by_family[family]) < 2:
            by_family[family].append(address)
    candidates = [(f, a) for i in range(2) for f in (socket.AF_INET6, socket.AF_INET) if i < len(by_family[f])
                  for a in [by_family[f][i]]]
    if not candidates:
        return 'unreachable', 'dns_error'
    sockets, refused = [], 0
    expected = 'quic_version_negotiation' if kind == 'quic' else 'dns_answer'
    with selectors.DefaultSelector() as selector:
        try:
            for family, address in candidates:
                try:
                    sock = socket.socket(family, socket.SOCK_DGRAM)
                    sockets.append(sock)
                    sock.setblocking(False)
                    sock.connect(address)  # a connected socket reports ICMP refusals
                except OSError:
                    continue
                if kind == 'quic':
                    dcid, scid = os.urandom(8), os.urandom(8)
                    payload = obfuscate(extra, secret, quic_probe_packet(dcid, scid))
                    check = (lambda data, d=dcid, s=scid: is_version_negotiation(deobfuscate(extra, secret, data), d, s))
                else:
                    ident, payload = dns_probe_query(extra)
                    check = (lambda data, i=ident: len(data) >= 12 and data[:2] == i and bool(data[2] & 0x80))
                selector.register(sock, selectors.EVENT_READ, (payload, check))
            asked = len(selector.get_map())
            sends, next_send = 0, time.monotonic()
            while selector.get_map() and time.monotonic() < stop:
                if sends < 3 and time.monotonic() >= next_send:
                    for key in list(selector.get_map().values()):
                        try:
                            key.fileobj.send(key.data[0])
                        except ConnectionRefusedError:
                            selector.unregister(key.fileobj)
                            refused += 1
                        except OSError:
                            pass
                    sends, next_send = sends + 1, time.monotonic() + 1.0
                until = min(stop, next_send) if sends < 3 else stop
                for key, _ in selector.select(max(0.0, until - time.monotonic())):
                    try:
                        data = key.fileobj.recv(65535)
                    except ConnectionRefusedError:
                        selector.unregister(key.fileobj)
                        refused += 1
                        continue
                    except OSError:
                        continue
                    return 'open', expected if key.data[1](data) else 'udp_reply'
            if asked and refused == asked:
                return 'closed', 'refused'
            return 'unreachable', 'no_reply'
        finally:
            for sock in sockets:
                sock.close()


def probe_targets(targets, deadline=None, connect=connect_endpoint, workers=MAX_WORKERS, udp=udp_endpoint):
    """Bound the whole batch, deduplicate endpoints, preserve every node row."""
    deadline = deadline if deadline is not None else time.monotonic() + TOTAL_BUDGET
    endpoints = {}
    decisions = []
    for target in targets:
        endpoint, outcome = classify(target)
        decisions.append((target, endpoint, outcome))
        if endpoint is not None:
            # A queued/stalled task that never completed is unmeasured, not
            # evidence that the endpoint was unreachable.
            endpoints[endpoint] = ('unknown', 'budget_exceeded')
    jobs, completed = queue.Queue(), queue.Queue()
    for endpoint in endpoints:
        jobs.put(endpoint)

    def worker():
        while time.monotonic() < deadline:
            try:
                endpoint = jobs.get_nowait()
            except queue.Empty:
                return
            try:
                outcome = udp(*endpoint[1:], deadline) if endpoint[0] is _UDP else connect(*endpoint, deadline)
            except Exception:
                outcome = ('unreachable', 'timeout')
            completed.put((endpoint, outcome))

    count = min(max(1, workers), MAX_WORKERS, len(endpoints))
    for _ in range(count):
        threading.Thread(target=worker, daemon=True).start()
    waiting = set(endpoints)
    while waiting and time.monotonic() < deadline:
        try:
            endpoint, outcome = completed.get(timeout=max(0.001, deadline - time.monotonic()))
        except queue.Empty:
            break
        endpoints[endpoint] = outcome
        waiting.discard(endpoint)
    results = []
    for target, endpoint, outcome in decisions:
        state, reason = outcome or endpoints[endpoint]
        results.append(dict(node=target['node'], host=target.get('host', ''), port=target.get('port'),
                            transport=target.get('transport', 'unknown'), state=state, reason=reason,
                            updated_at=target.get('updated_at', 0)))
    return results


def run(cmd, timeout):
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        return p.returncode, p.stdout
    except (OSError, subprocess.TimeoutExpired):
        return 127, ''


def panel_command(base, cmd):
    if os.name == 'nt':
        return cmd
    from node_ports import as_panel_user
    return as_panel_user(base, cmd)


def write_report(path, report):
    fd, name = tempfile.mkstemp(prefix='.node-port-reachability-', dir=str(path.parent))
    staged = Path(name)
    try:
        with os.fdopen(fd, 'w') as f:
            json.dump(report, f, indent=2)
            f.flush()
            os.fsync(f.fileno())
        staged.chmod(0o644)
        os.replace(staged, path)
    finally:
        staged.unlink(missing_ok=True)


def main(argv=None, run_cmd=run, prepare=panel_command):
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', required=True)
    args = ap.parse_args(argv)
    base = Path(args.base)
    deadline = time.monotonic() + TOTAL_BUDGET
    code, output = run_cmd(prepare(str(base), ['php', str(base / 'artisan'), 'node:local-ports', '--endpoints']),
                           timeout=min(10.0, TOTAL_BUDGET))
    answer = None
    for line in reversed(output.strip().splitlines()):
        try:
            answer = json.loads(line)
            break
        except ValueError:
            continue
    valid = code == 0 and isinstance(answer, dict) and answer.get('ok') is True and isinstance(answer.get('targets'), list)
    targets = answer['targets'] if valid else []
    valid = valid and len(targets) <= MAX_TARGETS and all(isinstance(t, dict)
        and isinstance(t.get('node'), str) and REF.fullmatch(t['node']) for t in targets)
    report = dict(ok=bool(valid), updated_at='', results=[], errors=[])
    if valid:
        report['results'] = probe_targets(targets, deadline)
    else:
        report['errors'] = ['The panel did not provide valid endpoint targets; no endpoints checked.']
    report['updated_at'] = datetime.datetime.now().astimezone().strftime('%Y-%m-%d %H:%M:%S%z')
    write_report(base / 'storage/node_port_reachability.json', report)
    return 0 if valid else 1


if __name__ == '__main__':
    raise SystemExit(main())
