#!/usr/bin/env python3
"""Open the ports of the nodes running on this server; close them when unused.

Run by server_config_agent.sh (root) every minute, and at once when the panel
leaves storage/node_ports_dirty after a node was saved or deleted.

The panel decides which ports are needed (`php artisan node:local-ports`: the
union over every node whose V2bX runs here, with the nodes needing each one).
This script makes the firewall match:

  * A needed port the firewall does not already allow is opened, and
    recorded in storage/node_ports_state.json as opened by us.
  * A port in that record that no node needs any more is closed.
  * Nothing else is ever closed: a port allowed before (SSH, the web server,
    one the operator opened) is not in the record, so it stays as it was.
  * When the panel cannot answer (database down, PHP error), nothing changes:
    an unanswered question must not read as "no nodes" and close everything.

Supported firewalls: firewalld, ufw, and iptables with a DROP/REJECT INPUT
policy. A firewall that accepts by default needs nothing, and is reported so.
storage/node_ports_status.json tells the admin what was decided and done.
"""
import argparse
import datetime
import json
import os
import pwd
import re
import shutil
import subprocess
import sys

TAG = 'irboard-node'
MAX_ENTRIES = 500
PROTOS = ('tcp', 'udp')
SPEC = re.compile(r'^(\d{1,5})(?:-(\d{1,5}))?$')


def run(cmd, timeout=30):
    """(exit code, stdout) of a command; (127, '') when it cannot run."""
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        return p.returncode, p.stdout
    except (OSError, subprocess.TimeoutExpired):
        return 127, ''


def valid(entry):
    """A {'port': '2053' or '2000-2010', 'proto': 'tcp'|'udp'} we may touch."""
    if not isinstance(entry, dict) or entry.get('proto') not in PROTOS:
        return False
    m = SPEC.match(str(entry.get('port', '')))
    if not m:
        return False
    lo = int(m.group(1))
    hi = int(m.group(2) or lo)
    return 1 <= lo <= hi <= 65535


class Firewall:
    """One firewall backend. `run` is injectable so tests need no firewall."""

    def __init__(self, run_cmd=run):
        self.run = run_cmd
        self.name = self.detect()

    def detect(self):
        code, out = self.run(['firewall-cmd', '--state'])
        if code == 0 and out.strip() == 'running':
            return 'firewalld'
        code, out = self.run(['ufw', 'status'])
        if code == 0 and re.search(r'^Status:\s*active', out, re.M):
            return 'ufw'
        code, out = self.run(['iptables', '-S', 'INPUT'])
        if code == 0 and re.search(r'^-P INPUT (DROP|REJECT)', out, re.M):
            return 'iptables'
        return 'open'

    @staticmethod
    def _range(port, sep):
        return port.replace('-', sep)

    def allowed(self, port, proto, backend=None):
        backend = backend or self.name
        if backend == 'firewalld':
            code, out = self.run(['firewall-cmd', '--query-port=%s/%s' % (port, proto)])
            return code == 0 and out.strip() == 'yes'
        if backend == 'ufw':
            code, out = self.run(['ufw', 'status'])
            want = {'%s/%s' % (self._range(port, ':'), proto), self._range(port, ':')}
            for line in out.splitlines():
                cols = line.split()
                if len(cols) >= 2 and cols[0] in want and cols[1] == 'ALLOW':
                    return True
            return False
        if backend == 'iptables':
            dport = self._range(port, ':')
            for extra in ([], ['-m', 'comment', '--comment', TAG]):
                code, _ = self.run(['iptables', '-C', 'INPUT', '-p', proto, '--dport', dport, '-j', 'ACCEPT'] + extra)
                if code == 0:
                    return True
            return False
        return True  # 'open': everything is allowed already

    def add(self, port, proto):
        if self.name == 'firewalld':
            spec = '--add-port=%s/%s' % (port, proto)
            a = self.run(['firewall-cmd', spec])[0]
            b = self.run(['firewall-cmd', '--permanent', spec])[0]
            return a == 0 and b == 0
        if self.name == 'ufw':
            return self.run(['ufw', 'allow', '%s/%s' % (self._range(port, ':'), proto), 'comment', TAG])[0] == 0
        if self.name == 'iptables':
            return self.run(['iptables', '-I', 'INPUT', '-p', proto, '--dport', self._range(port, ':'),
                             '-j', 'ACCEPT', '-m', 'comment', '--comment', TAG])[0] == 0
        return True

    def remove(self, port, proto, backend):
        """Remove a rule we added, with the tool that added it."""
        if backend == 'firewalld':
            spec = '--remove-port=%s/%s' % (port, proto)
            self.run(['firewall-cmd', spec])
            self.run(['firewall-cmd', '--permanent', spec])
            return True
        if backend == 'ufw':
            return self.run(['ufw', 'delete', 'allow', '%s/%s' % (self._range(port, ':'), proto)])[0] == 0
        if backend == 'iptables':
            # Only our tagged rule; delete every copy of it.
            for _ in range(10):
                code, _ = self.run(['iptables', '-D', 'INPUT', '-p', proto, '--dport', self._range(port, ':'),
                                    '-j', 'ACCEPT', '-m', 'comment', '--comment', TAG])
                if code != 0:
                    break
            return True
        return True


def as_panel_user(base, cmd):
    """`cmd` run as the owner of the panel directory when we are root.

    artisan run as root can leave root-owned files in storage/ (a fresh log
    file, a cache entry) that the web server then cannot write, breaking the
    panel. The owner of the directory is who the panel itself runs as.
    """
    if os.geteuid() != 0:
        return cmd
    try:
        owner = pwd.getpwuid(os.stat(base).st_uid).pw_name
    except (KeyError, OSError):
        return cmd
    if owner == 'root':
        return cmd
    if shutil.which('runuser'):
        return ['runuser', '-u', owner, '--'] + cmd
    if shutil.which('sudo'):
        return ['sudo', '-n', '-u', owner] + cmd
    return cmd


def local_ips(run_cmd=run):
    code, out = run_cmd(['hostname', '-I'])
    return out.split() if code == 0 else []


def holders(run_cmd=run):
    """'port/proto' -> the process listening on it, for the admin's report."""
    found = {}
    for proto, flag in (('tcp', '-ltnpH'), ('udp', '-lunpH')):
        code, out = run_cmd(['ss', flag])
        if code != 0:
            continue
        for line in out.splitlines():
            cols = line.split()
            if len(cols) < 5:
                continue
            port = cols[3].rsplit(':', 1)[-1]
            m = re.search(r'\(\("([^"]+)"', line)
            found.setdefault('%s/%s' % (port, proto), m.group(1) if m else '?')
    return found


def load_json(path, default):
    try:
        with open(path) as f:
            return json.load(f)
    except (OSError, ValueError):
        return default


def write_json(path, data):
    tmp = path + '.tmp'
    with open(tmp, 'w') as f:
        json.dump(data, f, indent=2)
    os.chmod(tmp, 0o644)
    os.replace(tmp, path)


def reconcile(desired, state, fw):
    """Make the firewall match `desired`. Returns (new state, report).

    desired: [{'port', 'proto', 'nodes'}] from the panel.
    state:   [{'port', 'proto', 'backend'}] that we opened earlier.
    """
    wanted = {}
    for d in desired:
        if valid(d):
            wanted[(str(d['port']), d['proto'])] = d.get('nodes', [])
    mine = {(str(s['port']), s['proto']): s.get('backend', fw.name) for s in state if valid(s)}
    report = {'opened': [], 'closed': [], 'errors': []}

    for (port, proto) in sorted(wanted):
        if fw.name == 'open':
            continue
        if fw.allowed(port, proto):
            # Already allowed: by us earlier (recorded), or by someone else
            # (not recorded, so we will never close it).
            continue
        if fw.add(port, proto):
            mine[(port, proto)] = fw.name
            report['opened'].append('%s/%s' % (port, proto))
        else:
            report['errors'].append('could not open %s/%s with %s' % (port, proto, fw.name))

    for (port, proto), backend in sorted(mine.items()):
        if (port, proto) in wanted:
            continue
        fw.remove(port, proto, backend)
        report['closed'].append('%s/%s' % (port, proto))

    new_state = [{'port': p, 'proto': pr, 'backend': b}
                 for (p, pr), b in sorted(mine.items()) if (p, pr) in wanted]
    return new_state, report


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', required=True, help='the panel directory')
    args = ap.parse_args(argv)
    base = args.base
    state_path = os.path.join(base, 'storage', 'node_ports_state.json')
    status_path = os.path.join(base, 'storage', 'node_ports_status.json')
    now = datetime.datetime.now().astimezone().strftime('%Y-%m-%d %H:%M:%S%z')

    ips = local_ips()
    code, out = run(as_panel_user(base, ['php', os.path.join(base, 'artisan'), 'node:local-ports',
                                         '--ips=' + ','.join(ips)]), timeout=90)
    answer = None
    for line in reversed(out.strip().splitlines()):
        try:
            answer = json.loads(line)
            break
        except ValueError:
            continue
    if code != 0 or not isinstance(answer, dict) or answer.get('ok') is not True \
            or not isinstance(answer.get('ports'), list) or len(answer['ports']) > MAX_ENTRIES:
        error = (answer or {}).get('error') if isinstance(answer, dict) else None
        write_json(status_path, {'ok': False, 'error': error or 'the panel did not answer; nothing changed',
                                 'updated_at': now})
        return 1

    fw = Firewall()
    state = load_json(state_path, [])
    new_state, report = reconcile(answer['ports'], state if isinstance(state, list) else [], fw)
    write_json(state_path, new_state)

    for item in report['opened']:
        print('[%s] node ports: opened %s (%s)' % (now, item, fw.name))
    for item in report['closed']:
        print('[%s] node ports: closed %s (no node uses it)' % (now, item))
    for item in report['errors']:
        print('[%s] node ports: %s' % (now, item))

    listening = holders()
    write_json(status_path, {
        'ok': not report['errors'],
        'updated_at': now,
        'firewall': fw.name,
        'local_ips': ips,
        'ports': [dict(p, allowed=fw.allowed(str(p['port']), p['proto']),
                       listener=listening.get('%s/%s' % (p['port'], p['proto'])))
                  for p in answer['ports'] if valid(p)],
        'managed': new_state,
        'opened': report['opened'],
        'closed': report['closed'],
        'errors': report['errors'],
    })
    return 0


if __name__ == '__main__':
    sys.exit(main())
