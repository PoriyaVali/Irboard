#!/usr/bin/env python3
"""scripts/node_ports.py against simulated firewalls: no root, no firewall.

Run: python3 tests/NodePortsAgentTest.py
"""
import json
import os
import sys
import tempfile
import unittest
from unittest.mock import mock_open, patch

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
        self.default_zone = 'public'
        self.active_zones = ['public']
        self.firewalld_rules = {(self.default_zone, permanent, p, proto): False
                               for p, proto in preset for permanent in (False, True)}
        self.fail_permanent_add = False
        self.extra_ufw_lines = []
        self.ufw_ipv6_rules = {}
        self.ipv6_policy = 'ACCEPT'
        self.rules6 = {}

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
            for (port, proto), mine in sorted(self.ufw_ipv6_rules.items()):
                lines.append('%s/%s (v6)              ALLOW       Anywhere (v6)%s' % (
                    port.replace('-', ':'), proto, '  # irboard-node' if mine else ''))
            lines.extend(self.extra_ufw_lines)
            return 0, '\n'.join(lines) + '\n'
        if cmd[:3] == ['iptables', '-S', 'INPUT']:
            return 0, ('-P INPUT DROP\n' if b == 'iptables' else '-P INPUT ACCEPT\n')
        if cmd[:3] == ['ip6tables', '-S', 'INPUT']:
            return 0, '-P INPUT %s\n' % self.ipv6_policy
        if b == 'firewalld' and cmd[0] == 'firewall-cmd':
            if cmd[-1] == '--get-default-zone':
                return 0, self.default_zone + '\n'
            if cmd[-1] == '--get-active-zones':
                return 0, ''.join(z + '\n  interfaces: eth0\n' for z in self.active_zones)
            spec = cmd[-1]
            port, proto = spec.split('=', 1)[1].split('/')
            zone = next((a.split('=', 1)[1] for a in cmd if a.startswith('--zone=')), self.default_zone)
            key = (zone, '--permanent' in cmd, port, proto)
            if spec.startswith('--query-port'):
                return (0, 'yes\n') if key in self.firewalld_rules else (1, 'no\n')
            if spec.startswith('--add-port'):
                if self.fail_permanent_add and '--permanent' in cmd:
                    return 1, ''
                self.firewalld_rules[key] = True
                return 0, 'success\n'
            if spec.startswith('--remove-port'):
                if self.refuse_removal:
                    return 1, ''
                self.firewalld_rules.pop(key, None)
                return 0, 'success\n'
        if b == 'ufw' and cmd[:2] == ['ufw', 'allow']:
            port, proto = cmd[cmd.index('port') + 1], cmd[cmd.index('proto') + 1]
            rules = self.ufw_ipv6_rules if cmd[cmd.index('from') + 1] == '::/0' else self.rules
            rules[(port.replace(':', '-'), proto)] = True
            return 0, 'Rule added\n'
        if b == 'ufw' and cmd[:3] == ['ufw', 'delete', 'allow']:
            port, proto = cmd[cmd.index('port') + 1], cmd[cmd.index('proto') + 1]
            rules = self.ufw_ipv6_rules if cmd[cmd.index('from') + 1] == '::/0' else self.rules
            if self.refuse_removal:
                return 1, 'ERROR: could not delete rule\n'
            return (0, 'Rule deleted\n') if rules.pop((port.replace(':', '-'), proto), None) is not None else (1, '')
        if b == 'iptables' and cmd[0] in ('iptables', 'ip6tables'):
            proto = cmd[cmd.index('-p') + 1]
            port = cmd[cmd.index('--dport') + 1].replace(':', '-')
            tagged = '--comment' in cmd
            key = (port, proto)
            rules = self.rules6 if cmd[0] == 'ip6tables' else self.rules
            if cmd[1] == '-C':
                return (0, '') if key in rules and rules[key] == tagged else (1, '')
            if cmd[1] == '-I':
                rules[key] = True
                return 0, ''
            if cmd[1] == '-D':
                if self.refuse_removal:
                    return 4, ''
                if rules.get(key) is True and tagged:
                    del rules[key]
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
        self.assertEqual(state, [{'port': '2053', 'proto': 'tcp', 'backend': 'ufw', 'family': 4}])
        self.assertIn(['ufw', 'allow', 'proto', 'tcp', 'from', '0.0.0.0/0', 'to', 'any',
                       'port', '2053', 'comment', 'irboard-node'], fake.log)

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
        self.assertIn(['firewall-cmd', '--zone=public', '--add-port=2000-2010/udp'], fake.log)
        self.assertIn(['firewall-cmd', '--zone=public', '--permanent', '--add-port=2000-2010/udp'], fake.log)

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
                opened_state = list(state)
                fake.refuse_removal = True
                state, report = node_ports.reconcile([], state, fw)
                self.assertEqual(report['closed'], [])
                self.assertEqual(len(report['errors']), len(opened_state))
                self.assertEqual(state, opened_state)
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


class ReviewRegressionTest(unittest.TestCase):
    def test_partial_firewalld_add_keeps_runtime_ownership_and_closes_it(self):
        fake = FakeFirewall('firewalld')
        fake.fail_permanent_add = True
        fw = node_ports.Firewall(fake)
        state, report = node_ports.reconcile(want('2053/tcp'), [], fw)
        self.assertEqual(state, [{'port': '2053', 'proto': 'tcp', 'backend': 'firewalld',
                                  'zone': 'public', 'permanent': False}])
        self.assertEqual(len(report['errors']), 1)
        state, report = node_ports.reconcile(want('2053/tcp'), state, fw)
        self.assertEqual(len(state), 1)
        self.assertEqual(len(report['errors']), 1)
        state, report = node_ports.reconcile([], state, fw)
        self.assertEqual((state, report['closed'], fake.firewalld_rules), ([], ['2053/tcp'], {}))

    def test_failed_permanent_add_is_retried_after_runtime_already_succeeded(self):
        fake = FakeFirewall('firewalld')
        fake.fail_permanent_add = True
        fw = node_ports.Firewall(fake)
        state, _ = node_ports.reconcile(want('2053/tcp'), [], fw)
        fake.fail_permanent_add = False
        state, report = node_ports.reconcile(want('2053/tcp'), state, fw)
        self.assertEqual(len(state), 2)
        self.assertEqual(report['errors'], [])
        self.assertIn(('public', True, '2053', 'tcp'), fake.firewalld_rules)

    def test_existing_runtime_rule_is_preserved_when_only_permanent_is_ours(self):
        fake = FakeFirewall('firewalld')
        fake.firewalld_rules[('public', False, '2053', 'tcp')] = False
        fw = node_ports.Firewall(fake)
        state, _ = node_ports.reconcile(want('2053/tcp'), [], fw)
        self.assertEqual([s['permanent'] for s in state], [True])
        state, _ = node_ports.reconcile([], state, fw)
        self.assertEqual(fake.firewalld_rules, {('public', False, '2053', 'tcp'): False})

    def test_active_zone_is_used_and_recorded_even_when_default_differs(self):
        fake = FakeFirewall('firewalld')
        fake.active_zones = ['external']
        fw = node_ports.Firewall(fake)
        state, report = node_ports.reconcile(want('2053/tcp'), [], fw)
        self.assertEqual(report['errors'], [])
        self.assertEqual({s['zone'] for s in state}, {'external'})
        self.assertEqual({k[0] for k in fake.firewalld_rules}, {'external'})
        # A later default-zone change must not redirect removal to that zone.
        fake.default_zone = 'internal'
        state, report = node_ports.reconcile([], state, node_ports.Firewall(fake))
        self.assertEqual((state, report['errors'], fake.firewalld_rules), ([], [], {}))

    def test_all_active_zones_are_reconciled_and_old_zone_rules_are_removed(self):
        fake = FakeFirewall('firewalld')
        fake.active_zones = ['public', 'external']
        state, _ = node_ports.reconcile(want('2053/tcp'), [], node_ports.Firewall(fake))
        self.assertEqual(len(state), 4)
        fake.active_zones = ['external']
        state, report = node_ports.reconcile(want('2053/tcp'), state, node_ports.Firewall(fake))
        self.assertEqual({k[0] for k in fake.firewalld_rules}, {'external'})
        self.assertEqual(len(state), 2)

    def test_ufw_source_or_direction_restrictions_are_not_public_allows(self):
        for rule in ('2053/tcp ALLOW 198.51.100.7', '2053/tcp ALLOW 198.51.100.0/24',
                     '2053/tcp ALLOW OUT Anywhere', '2053/tcp on eth1 ALLOW Anywhere'):
            with self.subTest(rule=rule):
                fake = FakeFirewall('ufw')
                fake.extra_ufw_lines = [rule]
                state, report = node_ports.reconcile(want('2053/tcp'), [], node_ports.Firewall(fake))
                self.assertEqual(report['opened'], ['2053/tcp'])
                self.assertEqual(len(state), 1)
                state, report = node_ports.reconcile([], state, node_ports.Firewall(fake))
                self.assertEqual(report['errors'], [])
                self.assertEqual(fake.extra_ufw_lines, [rule])

    def test_ufw_existing_ipv4_allow_is_not_owned_when_only_ipv6_is_missing(self):
        fake = FakeFirewall('ufw', preset=[('2053', 'tcp')])
        fw = node_ports.Firewall(fake, ipv6_required=True)
        state, report = node_ports.reconcile(want('2053/tcp'), [], fw)
        self.assertEqual([s['family'] for s in state], [6])
        self.assertTrue(fw.allowed('2053', 'tcp'))
        state, report = node_ports.reconcile([], state, fw)
        self.assertEqual(fake.rules, {('2053', 'tcp'): False})
        self.assertEqual(fake.ufw_ipv6_rules, {})

    def test_ufw_with_ipv6_disabled_still_opens_ipv4_ports(self):
        # A dual-stack server whose ufw has IPV6=no: ufw filters IPv4 only, so
        # its IPv4 rules are needed. Refusing to run left every node closed.
        fake = FakeFirewall('ufw')
        with patch.object(node_ports.os.path, 'isfile', return_value=True), \
                patch('builtins.open', mock_open(read_data='IPV6=no\n')):
            fw = node_ports.Firewall(fake, ipv6_required=True)
        self.assertEqual(fw.scopes, [{'backend': 'ufw', 'family': 4}])
        self.assertEqual(len(fw.warnings), 1)
        state, report = node_ports.reconcile(want('2053/tcp'), [], fw)
        self.assertEqual((report['opened'], report['errors']), (['2053/tcp'], []))
        self.assertEqual([s['family'] for s in state], [4])

    def test_ipv6_iptables_has_independent_ownership_and_removal(self):
        fake = FakeFirewall('iptables', preset=[('443', 'tcp')])
        fake.ipv6_policy = 'DROP'
        fake.rules6[('443', 'tcp')] = False
        fw = node_ports.Firewall(fake, ipv6_required=True)
        state, report = node_ports.reconcile(want('2053/tcp', '443/tcp'), [], fw)
        self.assertEqual({s['backend'] for s in state}, {'iptables', 'ip6tables'})
        self.assertEqual(len(state), 2)
        self.assertTrue(fw.allowed('2053', 'tcp'))
        self.assertIn(['ip6tables', '-I', 'INPUT', '-p', 'tcp', '--dport', '2053',
                       '-j', 'ACCEPT', '-m', 'comment', '--comment', 'irboard-node'], fake.log)
        state, report = node_ports.reconcile([], state, fw)
        self.assertEqual((state, report['errors']), ([], []))
        self.assertEqual(fake.rules, {('443', 'tcp'): False})
        self.assertEqual(fake.rules6, {('443', 'tcp'): False})

    def test_only_closed_ipv6_is_managed_when_ipv4_is_open(self):
        fake = FakeFirewall('iptables')
        fake.ipv6_policy = 'DROP'
        def cmd(args):
            if args == ['iptables', '-S', 'INPUT']:
                return 0, '-P INPUT ACCEPT\n'
            return fake(args)
        state, report = node_ports.reconcile(want('2053/udp'), [], node_ports.Firewall(cmd, ipv6_required=True))
        self.assertEqual([s['backend'] for s in state], ['ip6tables'])
        self.assertEqual(report['errors'], [])

    def test_missing_ipv6_inspection_or_final_deny_is_never_reported_open(self):
        fake = FakeFirewall('open')
        for failure in ('missing-ipv6', 'terminal-drop'):
            def cmd(args):
                if failure == 'missing-ipv6' and args[0] == 'ip6tables':
                    return 127, ''
                if failure == 'terminal-drop' and args == ['iptables', '-S', 'INPUT']:
                    return 0, '-P INPUT ACCEPT\n-A INPUT -j DROP\n'
                return fake(args)
            with self.assertRaises(RuntimeError):
                node_ports.Firewall(cmd, ipv6_required=True)

    def test_legacy_ufw_and_firewalld_state_is_retained_for_removal(self):
        for backend in ('ufw', 'firewalld'):
            fake = FakeFirewall(backend)
            fw = node_ports.Firewall(fake)
            state, _ = node_ports.reconcile(want('2053/tcp'), [], fw)
            old = [{'port': '2053', 'proto': 'tcp', 'backend': backend}]
            state, report = node_ports.reconcile([], old, fw)
            self.assertEqual((state, report['closed'], report['errors']), ([], ['2053/tcp'], []))

    def test_failed_query_during_removal_keeps_owned_rule_for_retry(self):
        fake = FakeFirewall('ufw')
        fw = node_ports.Firewall(fake)
        state, _ = node_ports.reconcile(want('2053/tcp'), [], fw)
        def failing_status(args):
            if args == ['ufw', 'status']:
                return 127, ''
            return fake(args)
        fw.run = failing_status
        retained, report = node_ports.reconcile([], state, fw)
        self.assertEqual(retained, state)
        self.assertTrue(report['errors'])


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

    def test_missing_local_addresses_never_closes_existing_ports(self):
        node_ports.run = lambda *args, **kwargs: (127, '')
        self.assertEqual(node_ports.main(['--base', self.base]), 1)
        with open(self.state_path) as f:
            self.assertEqual(json.load(f), [{'port': '2053', 'proto': 'tcp', 'backend': 'ufw'}])


def run_tests():
    # The host running these simulated tests must not choose IPv6 scopes from
    # its own /etc/default/ufw; each test supplies its explicit requirement.
    with patch.object(node_ports.os.path, 'isfile', return_value=False):
        unittest.main(verbosity=2)


if __name__ == '__main__':
    run_tests()
