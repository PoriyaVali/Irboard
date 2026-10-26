#!/usr/bin/env python3
"""Validate exported fictional profiles with the actual, pinned Mihomo core.

First run MihomoSubscriptionTest with IRBOARD_MIHOMO_CONTRACT_DIR set, then:
python3 tests/ValidateMihomoConfig.py --binary /path/to/mihomo --profiles /path/to/ClashMeta.json
Only disposable test keys/certificates and state directories are generated.
"""
import argparse
import base64
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
    with tempfile.TemporaryDirectory(prefix='irboard-mihomo-') as folder:
        root = pathlib.Path(folder)
        def openssl(*command):
            subprocess.run(['openssl', *map(str, command)], check=True, capture_output=True)
        openssl('ecparam', '-name', 'prime256v1', '-genkey', '-noout', '-out', root / 'ec.pem')
        openssl('ec', '-in', root / 'ec.pem', '-outform', 'DER', '-out', root / 'private.der')
        openssl('ec', '-in', root / 'ec.pem', '-pubout', '-outform', 'DER', '-out', root / 'public.der')
        openssl('req', '-x509', '-new', '-key', root / 'ec.pem', '-days', '1', '-subj', '/CN=fixture.invalid', '-out', root / 'ca.pem')
        for profile in profiles:
            protocol = profile['type']
            if protocol == 'masque':
                profile['private-key'] = base64.b64encode((root / 'private.der').read_bytes()).decode()
                profile['public-key'] = base64.b64encode((root / 'public.der').read_bytes()).decode()
            if protocol == 'openvpn':
                profile['ca'] = (root / 'ca.pem').read_text()
            if protocol in ('tailscale', 'zerotier', 'easytier'):
                profile['state-dir'] = str(root / protocol)
            config = {'mode': 'rule', 'log-level': 'error', 'proxies': [profile],
                      'proxy-groups': [{'name': 'TEST', 'type': 'select', 'proxies': [profile['name']]}],
                      'rules': ['MATCH,DIRECT']}
            filename = root / (protocol + '.json')  # JSON is also valid YAML.
            filename.write_text(json.dumps(config))
            result = subprocess.run([str(binary), '-t', '-d', str(root), '-f', str(filename)],
                                    capture_output=True, text=True, timeout=30)
            if result.returncode:
                raise SystemExit(protocol + ': ' + result.stdout + result.stderr)
            print('PASS ' + protocol)
    print('All 16 exported profiles accepted by Mihomo v1.19.33.')


if __name__ == '__main__':
    main()
