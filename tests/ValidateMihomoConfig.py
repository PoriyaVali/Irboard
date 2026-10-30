#!/usr/bin/env python3
"""Validate exported fictional profiles with the actual, pinned Mihomo core.

First run MihomoSubscriptionTest with IRBOARD_MIHOMO_CONTRACT_DIR set, then:
python3 tests/ValidateMihomoConfig.py --binary /path/to/mihomo --profiles /path/to/ClashMeta.json
Managed.json in the same directory, the managed profiles, is validated too.
Exported credentials are validated without substitution. State lives in a temporary directory.
"""
import argparse
import json
import pathlib
import subprocess
import tempfile


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--binary', required=True, type=pathlib.Path)
    parser.add_argument('--profiles', required=True, type=pathlib.Path)
    args = parser.parse_args()
    binary = args.binary.resolve()
    version = subprocess.run([str(binary), '-v'], check=True, capture_output=True, text=True).stdout
    if 'v1.19.33 ' not in version:
        raise SystemExit('This contract targets Mihomo v1.19.33; use the pinned binary.')
    profiles = json.loads(args.profiles.read_text())
    expected = {'ssr', 'socks5', 'http', 'snell', 'wireguard', 'shadowquic', 'gost-relay', 'ssh',
                'mieru', 'sudoku', 'masque', 'openvpn', 'tailscale', 'zerotier', 'easytier', 'trusttunnel'}
    if {p['type'] for p in profiles} != expected or len(profiles) != len(expected):
        raise SystemExit('The exported contract must contain all 15 added protocols and TrustTunnel.')
    # Managed profiles, rendered for one subscriber, sit beside the catalog export.
    managed = args.profiles.with_name('Managed.json')
    if managed.exists():
        extra = json.loads(managed.read_text())
        if {p['type'] for p in extra} != {'http', 'socks5', 'mieru', 'shadowquic', 'sudoku', 'snell'}:
            raise SystemExit('Managed.json must contain the six managed protocols.')
        profiles = profiles + extra
    with tempfile.TemporaryDirectory(prefix='irboard-mihomo-') as folder:
        root = pathlib.Path(folder)
        for index, profile in enumerate(profiles):
            protocol = profile['type']
            config = {'mode': 'rule', 'log-level': 'error', 'proxies': [profile],
                      'proxy-groups': [{'name': 'TEST', 'type': 'select', 'proxies': [profile['name']]}],
                      'rules': ['MATCH,DIRECT']}
            filename = root / ('%d-%s.json' % (index, protocol))  # JSON is also valid YAML.
            filename.write_text(json.dumps(config))
            result = subprocess.run([str(binary), '-t', '-d', str(root), '-f', str(filename)],
                                    capture_output=True, text=True, timeout=30)
            if result.returncode:
                raise SystemExit(protocol + ': ' + result.stdout + result.stderr)
            print('PASS ' + profile['name'])
    print('All %d exported profiles accepted by Mihomo v1.19.33.' % len(profiles))


if __name__ == '__main__':
    main()
