#!/usr/bin/env python3
"""Observe configured client TCP endpoints, separately from firewall ownership.

One connection attempt (including IPv4/IPv6 alternatives) is shared by nodes
using the same endpoint. UDP has no generic successful-connect proof and is
explicitly left unverified. Only the reachability snapshot is written.
"""
import argparse
import datetime
import errno
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


def probe_targets(targets, deadline=None, connect=connect_endpoint, workers=MAX_WORKERS):
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
                outcome = connect(*endpoint, deadline)
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
