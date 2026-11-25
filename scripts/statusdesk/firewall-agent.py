#!/usr/bin/env python3
"""Host-only nftables reconciler. Never runs inside the Console PHP container."""
import argparse
import hashlib
import ipaddress
import json
import logging
import os
from pathlib import Path
import stat
import subprocess
import time
import urllib.parse
import urllib.request

TABLE = 'statusdesk_access'
PORTS = (21115, 21116, 21117, 21118, 21119)


def validate(payload, now):
    blocks = payload.get('blocks')
    if not isinstance(blocks, dict) or len(blocks) > 10000:
        raise ValueError('Invalid block list')
    digest = hashlib.sha256(json.dumps(blocks, separators=(',', ':')).encode()).hexdigest()
    if payload.get('digest') != digest:
        raise ValueError('Policy digest mismatch')
    valid = {}
    for raw, until in blocks.items():
        ip = ipaddress.ip_address(raw)
        if not ip.is_global or getattr(ip, 'ipv4_mapped', None) is not None or str(ip) != raw or isinstance(until, bool) or not isinstance(until, int):
            raise ValueError('Invalid public address or expiry')
        if until > now + 86430:
            raise ValueError('Expiry exceeds the allowed 24 hours')
        if until > now:
            valid[ip] = until
    return digest, valid


def rules(blocks, now, exists):
    # Replace only our own table in one atomic transaction. Never flush the ruleset.
    lines = [f'delete table inet {TABLE}'] if exists else []
    lines += [f'table inet {TABLE} {{']
    for version, kind in [(4, 'ipv4_addr'), (6, 'ipv6_addr')]:
        elements = ', '.join(f'{ip} timeout {max(1, until - now)}s' for ip, until in blocks.items() if ip.version == version)
        lines += [f' set blocked{version} {{ type {kind}; flags timeout;']
        if elements:
            lines += ['  elements = { ' + elements + ' }']
        lines += [' }']
    for chain in ['input', 'forward']:
        lines += [f' chain {chain} {{ type filter hook {chain} priority -10; policy accept;']
        for version, family in [(4, 'ip'), (6, 'ip6')]:
            for protocol, ports in [('tcp', PORTS), ('udp', (21116,))]:
                match = f'{protocol} dport' if chain == 'input' else f'meta l4proto {protocol} ct original proto-dst'
                port_set = ', '.join(str(port) for port in ports)
                lines += [f'  {family} saddr @blocked{version} {match} {{ {port_set} }} drop']
        lines += [' }']
    return '\n'.join(lines + ['}']) + '\n'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        raise ValueError('Policy redirects are forbidden')


def request(config, path, data=None):
    body = json.dumps(data).encode() if data is not None else None
    req = urllib.request.Request(config['console_url'].rstrip('/') + '/api/server/firewall/' + path,
                                 data=body, headers={'Authorization': 'Bearer ' + config['secret'],
                                 'Content-Type': 'application/json', 'User-Agent': 'StatusDesk-firewall/1'})
    # Do not inherit ambient HTTP proxies or forward credentials across redirects.
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    with opener.open(req, timeout=10) as response:
        return json.loads(response.read(2 * 1024 * 1024)) if data is None else None


def load_config(path):
    info = path.stat()
    if info.st_uid != 0 or stat.S_IMODE(info.st_mode) & 0o077:
        raise ValueError('Configuration must be root-owned with mode 0600')
    config = json.loads(path.read_text())
    url = urllib.parse.urlsplit(config['console_url'])
    loopback = url.hostname in ('127.0.0.1', '::1', 'localhost')
    if url.scheme != 'https' and not (url.scheme == 'http' and loopback):
        raise ValueError('Use HTTPS or an explicit loopback URL')
    if url.username or url.password or url.query or url.fragment or not config.get('secret'):
        raise ValueError('Invalid policy URL or secret')
    return config


def sync(config):
    payload = request(config, 'policy')
    now = int(time.time())
    digest, blocks = validate(payload, now)
    result = subprocess.run(['nft', '-j', 'list', 'tables'], capture_output=True, check=True, text=True)
    tables = json.loads(result.stdout).get('nftables', [])
    exists = any(row.get('table', {}).get('family') == 'inet' and row['table']['name'] == TABLE for row in tables if 'table' in row)
    subprocess.run(['nft', '-f', '-'], input=rules(blocks, now, exists), text=True, check=True, capture_output=True)
    request(config, 'applied', {'digest': digest})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', type=Path, default=Path('/etc/statusdesk-firewall.json'))
    parser.add_argument('--once', action='store_true')
    args = parser.parse_args()
    logging.basicConfig(level=logging.INFO)
    config = load_config(args.config)
    while True:
        try:
            sync(config)
        except Exception as error:
            # Log only the error class: request URLs and environment can contain secrets.
            logging.error('Firewall reconciliation failed: %s', type(error).__name__)
            if args.once:
                raise SystemExit(1)
        if args.once:
            return
        time.sleep(5)


if __name__ == '__main__':
    main()
