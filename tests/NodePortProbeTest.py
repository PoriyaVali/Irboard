#!/usr/bin/env python3
"""Read-only endpoint observations; no root or real firewall required."""
import importlib.util
import json
from pathlib import Path
import shutil
import socket
import sys
import tempfile
import threading
import time
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'scripts'))
import node_port_probe as probe


def target(node='vless#1', host='127.0.0.1', port='443', transport='tcp', updated_at=123):
    return dict(node=node, host=host, port=port, transport=transport, updated_at=updated_at)


class ProbeTest(unittest.TestCase):
    def test_real_local_tcp_listener_is_open_and_a_non_listener_is_refused(self):
        with socket.socket() as listener, socket.socket() as refused:
            listener.bind(('127.0.0.1', 0))
            listener.listen(1)
            refused.bind(('127.0.0.1', 0))  # keep the port reserved, but never listen
            rows = [target(port=str(listener.getsockname()[1])),
                    target('trojan#1', port=str(refused.getsockname()[1]))]
            results = probe.probe_targets(rows)
        self.assertEqual((results[0]['state'], results[0]['reason']), ('open', 'connected'))
        self.assertEqual((results[1]['state'], results[1]['reason']), ('closed', 'refused'))
        self.assertEqual(results[0]['updated_at'], 123)

    def test_shared_endpoint_is_probed_once_but_each_node_keeps_its_identity(self):
        calls = []
        def connect(host, port, deadline):
            calls.append((host, port))
            return 'open', 'connected'
        results = probe.probe_targets([target(), target('trojan#1'), target('vless#2')], connect=connect)
        self.assertEqual(calls, [('127.0.0.1', 443)])
        self.assertEqual([r['node'] for r in results], ['vless#1', 'trojan#1', 'vless#2'])
        self.assertTrue(all(r['state'] == 'open' for r in results))

    def test_udp_ranges_and_missing_client_ports_are_never_probed_or_invented_open(self):
        calls = []
        def connect(*args):
            calls.append(args)
            return 'open', 'connected'
        rows = [target(transport='udp'), target('mieru#1', port='2000-2010', transport='unknown'),
                dict(target('vless#3', port=None), server_port=2053), target('easytier#4', transport='unknown'),
                target('vless#5', host='https://name:secret@example.test')]
        results = probe.probe_targets(rows, connect=connect)
        self.assertEqual(calls, [])
        self.assertEqual([r['reason'] for r in results], [
            'udp_unverified', 'unsupported_range', 'invalid_endpoint', 'invalid_endpoint', 'invalid_endpoint'])
        self.assertTrue(all(r['state'] == 'unknown' for r in results))
        self.assertNotIn('server_port', results[2])

    def test_global_budget_returns_without_waiting_for_stalled_resolver_worker(self):
        def slow(*args):
            time.sleep(0.25)
            return 'open', 'connected'
        start = time.monotonic()
        results = probe.probe_targets([target('vless#%d' % i, host='node%d.example.test' % i)
                                       for i in range(1, 40)], deadline=start + 0.04, connect=slow)
        self.assertLess(time.monotonic() - start, 0.2)
        self.assertEqual(len(results), 39)
        self.assertTrue(all(r['state'] == 'unknown' and r['reason'] == 'budget_exceeded' for r in results))

    def test_concurrency_is_bounded_and_multiple_endpoints_progress_in_parallel(self):
        active = peak = 0
        lock = threading.Lock()
        def connect(*args):
            nonlocal active, peak
            with lock:
                active += 1
                peak = max(peak, active)
            time.sleep(0.015)
            with lock:
                active -= 1
            return 'closed', 'refused'
        results = probe.probe_targets([target('vless#%d' % i, host='node%d.example.test' % i)
                                       for i in range(1, 40)], connect=connect, workers=1000)
        self.assertGreater(peak, 1)
        self.assertLessEqual(peak, 16)
        self.assertTrue(all(r['state'] == 'closed' for r in results))

    def test_dns_errors_are_unreachable_not_closed(self):
        with patch.object(probe.socket, 'getaddrinfo', side_effect=socket.gaierror('not resolved')):
            self.assertEqual(probe.connect_endpoint('missing.example.test', 443, time.monotonic() + 2),
                             ('unreachable', 'dns_error'))

    def test_working_ipv4_is_not_hidden_by_a_broken_ipv6_alternative(self):
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0)); listener.listen(1)
            port = listener.getsockname()[1]
            addresses = [(socket.AF_INET6, socket.SOCK_STREAM, 6, '', ('::1', port, 0, 0)),
                         (socket.AF_INET, socket.SOCK_STREAM, 6, '', ('127.0.0.1', port))]
            with patch.object(probe.socket, 'getaddrinfo', return_value=addresses):
                self.assertEqual(probe.connect_endpoint('dual.example.test', port, time.monotonic() + 3),
                                 ('open', 'connected'))


class ReportTest(unittest.TestCase):
    def setUp(self):
        self.base = Path(tempfile.mkdtemp(prefix='irboard-probe-')).resolve()
        self.addCleanup(self.cleanup)
        (self.base / 'storage').mkdir()
        self.state = self.base / 'storage/node_ports_state.json'
        self.status = self.base / 'storage/node_ports_status.json'
        self.state.write_text('operator/firewall state')
        self.status.write_text('operator/firewall status')

    def cleanup(self):
        resolved = self.base.resolve()
        if resolved.parent != Path(tempfile.gettempdir()).resolve() or not resolved.name.startswith('irboard-probe-'):
            raise RuntimeError('Refuse cleanup outside the owned temporary root')
        shutil.rmtree(resolved)

    def test_export_failure_publishes_unknown_report_and_never_touches_firewall_files(self):
        calls = []
        def command(args, timeout):
            calls.append(args)
            return 1, '{"ok":false,"error":"private database credentials"}'
        code = probe.main(['--base', str(self.base)], run_cmd=command, prepare=lambda base, args: args)
        self.assertEqual(code, 1)
        report = json.loads((self.base / 'storage/node_port_reachability.json').read_text())
        self.assertFalse(report['ok'])
        self.assertEqual(report['results'], [])
        self.assertNotIn('credentials', json.dumps(report))
        self.assertEqual(self.state.read_text(), 'operator/firewall state')
        self.assertEqual(self.status.read_text(), 'operator/firewall status')
        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0][-2:], ['node:local-ports', '--endpoints'])

    def test_success_writes_only_a_readable_atomic_reachability_snapshot(self):
        output = json.dumps(dict(ok=True, targets=[target('tuic#1', transport='udp')]))
        code = probe.main(['--base', str(self.base)], run_cmd=lambda args, timeout: (0, output),
                          prepare=lambda base, args: args)
        self.assertEqual(code, 0)
        report_path = self.base / 'storage/node_port_reachability.json'
        report = json.loads(report_path.read_text())
        self.assertTrue(report['ok'])
        self.assertEqual(report['results'][0]['reason'], 'udp_unverified')
        self.assertEqual(report['errors'], [])
        self.assertTrue(report_path.stat().st_mode & 0o004)
        self.assertEqual(list((self.base / 'storage').glob('.node-port-reachability-*')), [])
        self.assertEqual(self.state.read_text(), 'operator/firewall state')

    def test_malformed_or_excessive_target_lists_are_not_scanned(self):
        for targets in ([{'node': 'bad'}], [target()] * (probe.MAX_TARGETS + 1)):
            with patch.object(probe, 'probe_targets') as work:
                code = probe.main(['--base', str(self.base)],
                    run_cmd=lambda args, timeout: (0, json.dumps(dict(ok=True, targets=targets))),
                    prepare=lambda base, args: args)
                self.assertEqual(code, 1)
                work.assert_not_called()


if __name__ == '__main__':
    unittest.main(verbosity=2)
