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


class FakeUdpServer:
    """A UDP endpoint on 127.0.0.1 answering datagrams with `reply(data)`."""

    def __init__(self, reply):
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.sock.bind(('127.0.0.1', 0))
        self.sock.settimeout(0.2)
        self.port = self.sock.getsockname()[1]
        self.reply, self.received, self.stopped = reply, [], False
        self.thread = threading.Thread(target=self.serve, daemon=True)
        self.thread.start()

    def serve(self):
        while not self.stopped:
            try:
                data, peer = self.sock.recvfrom(65535)
            except (socket.timeout, OSError):
                continue
            self.received.append(data)
            answer = self.reply(data)
            if answer:
                self.sock.sendto(answer, peer)

    def close(self):
        self.stopped = True
        self.thread.join(1)
        self.sock.close()


def quic_server(obfs=None, psk=''):
    """Answers an unknown QUIC version with Version Negotiation, as quic-go does."""
    def reply(data):
        packet = probe.deobfuscate(obfs, psk, data)
        # Strict on purpose: only a correctly decoded probe is answered. A
        # real server (checked against quic-go behind Salamander) may also
        # answer a wrongly keyed datagram that happens to decode as another
        # long-header packet; the probe then reports open/udp_reply, which is
        # true of the port. Strictness keeps the no-reply case deterministic.
        if len(packet) < 1200 or packet[1:5] != probe.QUIC_PROBE_VERSION:
            return None
        dcid = packet[6:6 + packet[5]]
        at = 6 + packet[5]
        scid = packet[at + 1:at + 1 + packet[at]]
        vn = bytes([0x80]) + b'\0\0\0\0' + bytes([len(scid)]) + scid + bytes([len(dcid)]) + dcid + b'\0\0\0\1'
        return probe.obfuscate(obfs, psk, vn)
    return reply


class UdpProbeTest(unittest.TestCase):
    def udp(self, server_reply, target_fields):
        server = FakeUdpServer(server_reply)
        self.addCleanup(server.close)
        row = dict(target('hysteria#1', port=str(server.port), transport='udp'), **target_fields)
        return probe.probe_targets([row], deadline=time.monotonic() + 5)[0], server

    def test_a_quic_server_answers_version_negotiation(self):
        result, server = self.udp(quic_server(), {'probe': 'quic'})
        self.assertEqual((result['state'], result['reason']), ('open', 'quic_version_negotiation'))
        self.assertGreaterEqual(len(server.received[0]), 1200, 'servers ignore unknown versions below 1200 bytes')

    def test_salamander_and_xplus_wrap_the_question_with_the_node_key(self):
        for obfs in ('salamander', 'xplus'):
            with self.subTest(obfs=obfs):
                result, server = self.udp(quic_server(obfs, 'node-key'),
                                          {'probe': 'quic', 'obfs': obfs, 'obfs_password': 'node-key'})
                self.assertEqual((result['state'], result['reason']), ('open', 'quic_version_negotiation'))
                # On the wire nothing reads as QUIC: no plain version field.
                self.assertNotEqual(server.received[0][1:5], probe.QUIC_PROBE_VERSION)

    def test_a_wrong_key_gets_no_answer_and_reads_as_no_reply(self):
        server = FakeUdpServer(quic_server('salamander', 'right-key'))
        self.addCleanup(server.close)
        state = probe.udp_endpoint('quic', '127.0.0.1', server.port, 'salamander', 'wrong-key',
                                   time.monotonic() + 5, timeout=1.5)
        self.assertEqual(state, ('unreachable', 'no_reply'))
        self.assertGreaterEqual(len(server.received), 2, 'a lost datagram is sent again')

    def test_the_mdns_server_answers_a_dns_query_under_its_domain(self):
        def dns(data):
            return data[:2] + b'\x81\x83' + data[4:]  # NXDOMAIN, same id and question
        result, server = self.udp(dns, {'probe': 'dns', 'domain': 't.example.test'})
        self.assertEqual((result['state'], result['reason']), ('open', 'dns_answer'))
        self.assertIn(b'\x01t\x07example\x04test\x00', server.received[0])

    def test_a_refused_udp_port_is_closed(self):
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as free:
            free.bind(('127.0.0.1', 0))
            port = free.getsockname()[1]
        state = probe.udp_endpoint('quic', '127.0.0.1', port, None, None, time.monotonic() + 5, timeout=1.5)
        self.assertEqual(state, ('closed', 'refused'))

    def test_silent_protocols_and_unknown_obfuscation_stay_unverified_without_a_packet(self):
        calls = []
        def udp(*args):
            calls.append(args)
            return 'open', 'quic_version_negotiation'
        rows = [target('wireguard#1', transport='udp'),
                dict(target('hysteria#2', transport='udp'), probe='quic', obfs='other', obfs_password='x'),
                dict(target('hysteria#3', transport='udp'), probe='quic', obfs='salamander')]
        results = probe.probe_targets(rows, udp=udp)
        self.assertEqual(calls, [])
        self.assertEqual([(r['state'], r['reason']) for r in results], [('unknown', 'udp_unverified')] * 3)

    def test_the_node_key_never_reaches_the_report(self):
        result, _ = self.udp(quic_server('salamander', 'secret-key'),
                             {'probe': 'quic', 'obfs': 'salamander', 'obfs_password': 'secret-key'})
        self.assertNotIn('secret-key', json.dumps(result))
        self.assertEqual(set(result), {'node', 'host', 'port', 'transport', 'state', 'reason', 'updated_at'})


if __name__ == '__main__':
    unittest.main(verbosity=2)
