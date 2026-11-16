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

Supported firewalls: active firewalld zones (runtime and permanent), ufw
public inbound rules, and iptables/ip6tables with a DROP/REJECT INPUT policy.
A family that accepts by default needs no additional rule.
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
    """Firewall rules scoped by zone/configuration or IP family.

    Each scope is recorded independently. A successful runtime change must
    remain ours even when the following permanent change fails.
    """

    def __init__(self, run_cmd=None, ipv6_required=False):
        self.run = run_cmd or run
        self.ipv6_required = ipv6_required
        self.scopes = []
        self.default_zone = None
        self.name = self.detect()

    def detect(self):
        code, out = self.run(['firewall-cmd', '--state'])
        if code == 0 and out.strip() == 'running':
            code, out = self.run(['firewall-cmd', '--get-default-zone'])
            if code != 0 or not re.fullmatch(r'[A-Za-z0-9_-]+', out.strip()):
                raise RuntimeError('cannot determine the firewalld default zone')
            self.default_zone = out.strip()
            code, out = self.run(['firewall-cmd', '--get-active-zones'])
            if code != 0:
                raise RuntimeError('cannot determine the active firewalld zones')
            zones = {line.split()[0] for line in out.splitlines()
                     if line and not line[0].isspace()}
            if not all(re.fullmatch(r'[A-Za-z0-9_-]+', z) for z in zones):
                raise RuntimeError('invalid active firewalld zone')
            for zone in sorted(zones or {self.default_zone}):
                for permanent in (False, True):
                    self.scopes.append(dict(backend='firewalld', zone=zone, permanent=permanent))
            return 'firewalld'
        code, out = self.run(['ufw', 'status'])
        if code == 0 and re.search(r'^Status:\s*active', out, re.M):
            ipv6 = self.ipv6_required
            config = '/etc/default/ufw'
            if os.path.isfile(config):
                with open(config) as f:
                    match = re.search(r'^\s*IPV6\s*=\s*(yes|no)\s*$', f.read(), re.M | re.I)
                if match:
                    ipv6 = match.group(1).lower() == 'yes'
                    if self.ipv6_required and not ipv6:
                        raise RuntimeError('ufw IPv6 is disabled but this server has IPv6 addresses')
            self.scopes = [dict(backend='ufw', family=family) for family in ((4, 6) if ipv6 else (4,))]
            return 'ufw'
        for backend in ('iptables', 'ip6tables'):
            code, out = self.run([backend, '-S', 'INPUT'])
            if code != 0:
                if backend == 'iptables' or self.ipv6_required:
                    raise RuntimeError('cannot inspect %s INPUT; nothing changed' % backend)
                continue
            if re.search(r'^-P INPUT (DROP|REJECT)', out, re.M):
                self.scopes.append(dict(backend=backend))
            elif re.search(r'^-A INPUT -j (DROP|REJECT)(?:\s|$)', out, re.M):
                raise RuntimeError('%s has an explicit final deny; cannot treat it as open' % backend)
        if self.scopes:
            return 'iptables'
        return 'open'

    @staticmethod
    def _range(port, sep):
        return port.replace('-', sep)

    def _firewalld(self, option, port, proto, zone, permanent):
        return ['firewall-cmd', '--zone=' + zone] + (['--permanent'] if permanent else []) + [
            '%s=%s/%s' % (option, port, proto)]

    def _ufw_public_families(self, port, proto):
        code, out = self.run(['ufw', 'status'])
        if code != 0 or not re.search(r'^Status:\s*active', out, re.M):
            raise RuntimeError('cannot inspect active ufw rules')
        want = {'%s/%s' % (self._range(port, ':'), proto), self._range(port, ':')}
        families = set()
        for line in out.splitlines():
            # A source IP/CIDR, interface or destination restriction is not
            # public access. ALLOW OUT / ALLOW FWD are not inbound allows.
            line = line.split('#', 1)[0].strip()
            m = re.fullmatch(r'(\S+)(\s+\(v6\))?\s+ALLOW(?:\s+IN)?\s+'
                             r'(Anywhere(?: \(v6\))?|0\.0\.0\.0/0|::/0)', line)
            if m and m.group(1) in want:
                families.add(6 if m.group(2) or m.group(3) == '::/0' else 4)
        return families

    def allowed(self, port, proto, backend=None, zone=None, permanent=False, family=None):
        if backend is None:
            return all(self.allowed(port, proto, **scope) for scope in self.scopes)
        if backend == 'firewalld':
            code, out = self.run(self._firewalld('--query-port', port, proto, zone, permanent))
            if code == 0 and out.strip() == 'yes':
                return True
            if code == 1 and out.strip() == 'no':
                return False
            raise RuntimeError('cannot query firewalld %s/%s' % (zone, 'permanent' if permanent else 'runtime'))
        if backend == 'ufw':
            return family in self._ufw_public_families(port, proto)
        if backend in ('iptables', 'ip6tables'):
            dport = self._range(port, ':')
            for extra in ([], ['-m', 'comment', '--comment', TAG]):
                code, _ = self.run([backend, '-C', 'INPUT', '-p', proto, '--dport', dport, '-j', 'ACCEPT'] + extra)
                if code == 0:
                    return True
                if code != 1:
                    raise RuntimeError('cannot query %s rules' % backend)
            return False
        raise RuntimeError('unsupported firewall backend: ' + str(backend))

    def _ufw_rule(self, action, port, proto, family):
        # An explicit address family prevents adding/removing an operator's
        # existing IPv4 rule when only the missing IPv6 rule belongs to us.
        source = '::/0' if family == 6 else '0.0.0.0/0'
        return ['ufw'] + action + ['proto', proto, 'from', source, 'to', 'any',
                                  'port', self._range(port, ':')]

    def add(self, port, proto, backend, zone=None, permanent=False, family=None):
        if backend == 'firewalld':
            return self.run(self._firewalld('--add-port', port, proto, zone, permanent))[0] == 0
        if backend == 'ufw':
            return self.run(self._ufw_rule(['allow'], port, proto, family) + ['comment', TAG])[0] == 0
        if backend in ('iptables', 'ip6tables'):
            return self.run([backend, '-I', 'INPUT', '-p', proto, '--dport', self._range(port, ':'),
                             '-j', 'ACCEPT', '-m', 'comment', '--comment', TAG])[0] == 0
        raise RuntimeError('unsupported firewall backend: ' + str(backend))

    def remove(self, port, proto, backend, zone=None, permanent=False, family=None):
        """Remove a rule we added, with the tool that added it.

        True only once the rule is really gone: a removal that fails or times
        out must keep the port in the record, or no later run would retry it
        and the port would stay open for good while the status read success.
        """
        if backend == 'firewalld':
            self.run(self._firewalld('--remove-port', port, proto, zone, permanent))
            return not self.allowed(port, proto, backend=backend, zone=zone, permanent=permanent)
        if backend == 'ufw':
            self.run(self._ufw_rule(['delete', 'allow'], port, proto, family))
            return family not in self._ufw_public_families(port, proto)
        if backend in ('iptables', 'ip6tables'):
            # Only our tagged rule; delete every copy of it.
            rule = ['-p', proto, '--dport', self._range(port, ':'), '-j', 'ACCEPT', '-m', 'comment', '--comment', TAG]
            for _ in range(10):
                if self.run([backend, '-D', 'INPUT'] + rule)[0] != 0:
                    break
            return self.run([backend, '-C', 'INPUT'] + rule)[0] == 1
        raise RuntimeError('unsupported firewall backend: ' + str(backend))


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


def local_ips(run_cmd=None):
    code, out = (run_cmd or run)(['hostname', '-I'])
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
    def key(entry):
        return (str(entry['port']), entry['proto'], entry['backend'],
                entry.get('zone', ''), entry.get('permanent', False), entry.get('family', 0))

    wanted = {}
    for d in desired:
        if valid(d):
            for scope in fw.scopes:
                entry = dict(port=str(d['port']), proto=d['proto'], **scope)
                wanted[key(entry)] = entry
    mine = {}
    for s in state:
        if not valid(s):
            continue
        entry = dict(s, port=str(s['port']))
        # Before scoped ownership, firewalld always changed the default zone
        # in both configurations. Keep that ownership during an upgrade.
        if entry.get('backend') == 'ufw' and 'family' not in entry:
            if fw.name != 'ufw':
                raise RuntimeError('legacy ufw ownership needs active ufw; nothing changed')
            for scope in fw.scopes:
                scoped = dict(entry, **scope)
                mine[key(scoped)] = scoped
        elif entry.get('backend') == 'firewalld' and 'zone' not in entry:
            if fw.default_zone is None:
                raise RuntimeError('legacy firewalld ownership needs its default zone; nothing changed')
            for permanent in (False, True):
                scoped = dict(entry, zone=fw.default_zone, permanent=permanent)
                mine[key(scoped)] = scoped
        else:
            mine[key(entry)] = entry
    report = {'opened': [], 'closed': [], 'errors': []}

    for k, entry in sorted(wanted.items()):
        port, proto, backend, zone, permanent, family = k
        scope = {name: value for name, value in entry.items() if name not in ('port', 'proto')}
        label = '%s/%s with %s%s' % (port, proto, backend,
                ' %s/%s' % (zone, 'permanent' if permanent else 'runtime') if zone else
                (' IPv%s' % family if family else ''))
        try:
            if fw.allowed(port, proto, **scope):
                continue  # Pre-existing rules are never adopted as ours.
            added = fw.add(port, proto, **scope)
            # A command may time out after applying its change. A successful
            # post-query still lets us retain ownership of that new rule.
            if added or fw.allowed(port, proto, **scope):
                mine[k] = entry
                item = '%s/%s' % (port, proto)
                if item not in report['opened']:
                    report['opened'].append(item)
            if not added:
                report['errors'].append('could not fully open ' + label + '; will retry')
        except RuntimeError as exc:
            report['errors'].append('could not open %s: %s' % (label, exc))

    for k, entry in sorted(list(mine.items())):
        if k in wanted:
            continue
        port, proto, backend, zone, permanent, family = k
        scope = {name: value for name, value in entry.items() if name not in ('port', 'proto')}
        try:
            removed = fw.remove(port, proto, **scope)
        except RuntimeError:
            removed = False
        if removed:
            del mine[k]
            item = '%s/%s' % (port, proto)
            if item not in report['closed']:
                report['closed'].append(item)
        else:
            report['errors'].append('could not close %s/%s with %s%s; will retry' % (
                port, proto, backend, ' ' + zone if zone else ''))

    return [entry for _, entry in sorted(mine.items())], report


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', required=True, help='the panel directory')
    args = ap.parse_args(argv)
    base = args.base
    state_path = os.path.join(base, 'storage', 'node_ports_state.json')
    status_path = os.path.join(base, 'storage', 'node_ports_status.json')
    now = datetime.datetime.now().astimezone().strftime('%Y-%m-%d %H:%M:%S%z')

    ips = local_ips()
    if not ips:
        write_json(status_path, {'ok': False, 'error': 'cannot determine local addresses; nothing changed',
                                 'updated_at': now})
        return 1
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

    try:
        fw = Firewall(ipv6_required=any(':' in ip for ip in ips))
        state = load_json(state_path, [])
        new_state, report = reconcile(answer['ports'], state if isinstance(state, list) else [], fw)
    except RuntimeError as exc:
        write_json(status_path, {'ok': False, 'error': str(exc), 'updated_at': now})
        return 1
    write_json(state_path, new_state)

    for item in report['opened']:
        print('[%s] node ports: opened %s (%s)' % (now, item, fw.name))
    for item in report['closed']:
        print('[%s] node ports: closed %s (no node uses it)' % (now, item))
    for item in report['errors']:
        print('[%s] node ports: %s' % (now, item))

    listening = holders()
    ports = []
    for p in answer['ports']:
        if not valid(p):
            continue
        try:
            allowed = fw.allowed(str(p['port']), p['proto'])
        except RuntimeError as exc:
            allowed = False
            report['errors'].append(str(exc))
        ports.append(dict(p, allowed=allowed, listener=listening.get('%s/%s' % (p['port'], p['proto']))))
    write_json(status_path, {
        'ok': not report['errors'],
        'updated_at': now,
        'firewall': fw.name,
        'local_ips': ips,
        'ports': ports,
        'managed': new_state,
        'opened': report['opened'],
        'closed': report['closed'],
        'errors': report['errors'],
    })
    return 1 if report['errors'] else 0


if __name__ == '__main__':
    sys.exit(main())
