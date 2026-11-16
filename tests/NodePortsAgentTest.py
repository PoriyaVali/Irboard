#!/usr/bin/env python3
"""scripts/node_ports.py against simulated firewalls: no root, no firewall.

Run: python3 tests/NodePortsAgentTest.py
"""
import json
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'scripts'))
import node_ports  # noqa: E402


class FakeFirewall:
    """Answers the commands node_ports.py runs, for one backend."""

    def __init__(self, backend, preset=(), refuse_removal=False):
        self.backend = backend
        # (port, proto) -> tagged by us?
        self.rules = {p: False for p in preset}
        self.log = []
        # A firewall whose removals fail (busy lock, timeout): rules stay.
        self.refuse_removal = refuse_removal

    def __call__(self, cmd, timeout=30):
        self.log.append(cmd)
        b = self.backend
        if cmd[:2] == ['firewall-cmd', '--state']:
            return (0, 'running\n') if b == 'firewalld' else (127, '')
        if cmd[:2] == ['ufw', 'status']:
            if b != 'ufw':
                return (0, 'Status: inactive\n')
            lines = ['Status: active', '', 'To                         Action      From']
            for (port, proto), mine in sorted(self.rules.items()):
                lines.append('%s/%s                   ALLOW       Anywhere%s' % (port.replace('-', ':'), proto,
                                                                             '  # irboard-node' if mine else ''))
            return 0, '\n'.join(lines) + '\n'
        if cmd[:3] == ['iptables', '-S', 'INPUT']:
            return 0, ('-P INPUT DROP\n' if b == 'iptables' else '-P INPUT ACCEPT\n')
        if b == 'firewalld' and cmd[0] == 'firewall-cmd':
            spec = cmd[-1]
            port, proto = spec.split('=', 1)[1].split('/')
            if spec.startswith('--query-port'):
                return 0, 'yes\n' if (port, proto) in self.rules else 'no\n'
            if spec.startswith('--add-port'):
                self.rules[(port, proto)] = True
                return 0, 'success\n'
            if spec.startswith('--remove-port'):
                if self.refuse_removal:
                    return 1, ''
                self.rules.pop((port, proto), None)
                return 0, 'success\n'
        if b == 'ufw' and cmd[:2] == ['ufw', 'allow']:
            port, proto = cmd[2].split('/')
            self.rules[(port.replace(':', '-'), proto)] = True
            return 0, 'Rule added\n'
        if b == 'ufw' and cmd[:3] == ['ufw', 'delete', 'allow']:
            port, proto = cmd[3].split('/')
            if self.refuse_removal:
                return 1, 'ERROR: could not delete rule\n'
            return (0, 'Rule deleted\n') if self.rules.pop((port.replace(':', '-'), proto), None) is not None else (1, '')
        if b == 'iptables' and cmd[0] == 'iptables':
            proto = cmd[cmd.index('-p') + 1]
            port = cmd[cmd.index('--dport') + 1].replace(':', '-')
            tagged = '--comment' in cmd
            key = (port, proto)
            if cmd[1] == '-C':
                return (0, '') if key in self.rules and self.rules[key] == tagged else (1, '')
            if cmd[1] == '-I':
                self.rules[key] = True
                return 0, ''
            if cmd[1] == '-D':
                if self.refuse_removal:
                    return 4, ''
                if self.rules.get(key) is True and tagged:
                    del self.rules[key]
                    return 0, ''
                return 1, ''
        return 127, ''


def want(*specs):
    out = []
    for spec in specs:
        port, proto = spec.split('/')
        out.append({'port': port, 'proto': proto, 'nodes': ['n']})
    return out


class ReconcileTest(unittest.TestCase):
    def test_a_new_node_port_is_opened_and_recorded(self):
        fake = FakeFirewall('ufw')
        fw = node_ports.Firewall(fake)
        state, report = node_ports.reconcile(want('2053/tcp'), [], fw)
        self.assertEqual(report['opened'], ['2053/tcp'])
        self.assertEqual(state, [{'port': '2053', 'proto': 'tcp', 'backend': 'ufw'}])
        self.assertIn(['ufw', 'allow', '2053/tcp', 'comment', 'irboard-node'], fake.log)

    def test_a_shared_port_closes_only_when_the_last_node_goes(self):
        fake = FakeFirewall('ufw')
        fw = node_ports.Firewall(fake)
        two = [{'port': '2053', 'proto': 'tcp', 'nodes': ['vless#1', 'trojan#2']}]
        state, _ = node_ports.reconcile(two, [], fw)
        one = [{'port': '2053', 'proto': 'tcp', 'nodes': ['trojan#2']}]
        state, report = node_ports.reconcile(one, state, fw)
        self.assertEqual(report['closed'], [])
        self.assertIn(('2053', 'tcp'), fake.rules)
        state, report = node_ports.reconcile([], state, fw)
        self.assertEqual(report['closed'], ['2053/tcp'])
        self.assertNotIn(('2053', 'tcp'), fake.rules)
        self.assertEqual(state, [])

    def test_a_port_someone_else_opened_is_never_closed(self):
        fake = FakeFirewall('ufw', preset=[('443', 'tcp')])
        fw = node_ports.Firewall(fake)
        state, report = node_ports.reconcile(want('443/tcp'), [], fw)
        self.assertEqual((state, report['opened']), ([], []))
        state, report = node_ports.reconcile([], state, fw)
        self.assertEqual(report['closed'], [])
        self.assertIn(('443', 'tcp'), fake.rules)

    def test_iptables_rules_come_back_after_a_reboot_and_go_by_their_tag(self):
        fake = FakeFirewall('iptables')
        fw = node_ports.Firewall(fake)
        state, _ = node_ports.reconcile(want('2053/tcp', '2053/udp'), [], fw)
        self.assertEqual(sorted(fake.rules), [('2053', 'tcp'), ('2053', 'udp')])
        fake.rules.clear()  # rebooted: iptables rules are not persistent
        state, report = node_ports.reconcile(want('2053/tcp', '2053/udp'), state, fw)
        self.assertEqual(report['opened'], ['2053/tcp', '2053/udp'])
        state, report = node_ports.reconcile(want('2053/tcp'), state, fw)
        self.assertEqual(report['closed'], ['2053/udp'])
        self.assertEqual(sorted(fake.rules), [('2053', 'tcp')])

    def test_firewalld_opens_now_and_permanently(self):
        fake = FakeFirewall('firewalld')
        fw = node_ports.Firewall(fake)
        node_ports.reconcile(want('2000-2010/udp'), [], fw)
        self.assertIn(['firewall-cmd', '--add-port=2000-2010/udp'], fake.log)
        self.assertIn(['firewall-cmd', '--permanent', '--add-port=2000-2010/udp'], fake.log)

    def test_a_firewall_that_accepts_everything_needs_nothing(self):
        fake = FakeFirewall('open')
        fw = node_ports.Firewall(fake)
        self.assertEqual(fw.name, 'open')
        state, report = node_ports.reconcile(want('2053/tcp'), [], fw)
        self.assertEqual((state, report['opened'], report['closed']), ([], [], []))

    def test_a_port_that_fails_to_close_stays_recorded_and_is_retried(self):
        for backend in ('ufw', 'iptables', 'firewalld'):
            with self.subTest(backend=backend):
                fake = FakeFirewall(backend)
                fw = node_ports.Firewall(fake)
                state, _ = node_ports.reconcile(want('2053/tcp'), [], fw)
                fake.refuse_removal = True
                state, report = node_ports.reconcile([], state, fw)
                self.assertEqual(report['closed'], [])
                self.assertEqual(len(report['errors']), 1)
                self.assertEqual(state, [{'port': '2053', 'proto': 'tcp', 'backend': backend}])
                # The firewall recovers: the next run closes it.
                fake.refuse_removal = False
                state, report = node_ports.reconcile([], state, fw)
                self.assertEqual((report['closed'], report['errors'], state), (['2053/tcp'], [], []))

    def test_invalid_entries_are_ignored(self):
        fw = node_ports.Firewall(FakeFirewall('ufw'))
        state, report = node_ports.reconcile(
            [{'port': '0', 'proto': 'tcp'}, {'port': '70000', 'proto': 'tcp'}, {'port': '22; rm -rf /', 'proto': 'tcp'},
             {'port': '2053', 'proto': 'sctp'}], [], fw)
        self.assertEqual((state, report['opened']), ([], []))


class MainTest(unittest.TestCase):
    def setUp(self):
        self.base = tempfile.mkdtemp()
        os.makedirs(os.path.join(self.base, 'storage'))
        self.state_path = os.path.join(self.base, 'storage', 'node_ports_state.json')
        with open(self.state_path, 'w') as f:
            json.dump([{'port': '2053', 'proto': 'tcp', 'backend': 'ufw'}], f)
        self._run = node_ports.run

    def tearDown(self):
        node_ports.run = self._run

    def test_an_unanswering_panel_changes_nothing(self):
        calls = []

        def fake_run(cmd, timeout=30):
            calls.append(cmd)
            if cmd[:1] == ['hostname']:
                return 0, '203.0.113.10\n'
            if 'node:local-ports' in cmd:
                return 1, '{"ok":false,"error":"SQLSTATE[HY000] [2002] Connection refused"}\n'
            raise AssertionError('no firewall command may run: %r' % (cmd,))

        node_ports.run = fake_run
        self.assertEqual(node_ports.main(['--base', self.base]), 1)
        with open(self.state_path) as f:
            self.assertEqual(json.load(f), [{'port': '2053', 'proto': 'tcp', 'backend': 'ufw'}])
        with open(os.path.join(self.base, 'storage', 'node_ports_status.json')) as f:
            status = json.load(f)
        self.assertFalse(status['ok'])
        self.assertIn('Connection refused', status['error'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
