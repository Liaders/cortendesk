#!/usr/bin/env python3
"""Read-only fail2ban collector. No commands to ban/unban or change services."""
import datetime
import importlib.util
import ipaddress
import json
import logging
from pathlib import Path
import re
import sqlite3
import subprocess
import time
import urllib.request


def read_command(*args):
    return subprocess.run(args, check=True, capture_output=True, text=True, timeout=10).stdout


def events(directory=Path('/var/log')):
    result = []
    pattern = re.compile(r'^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)(?:,\d+)?\s+.*?\[sshd\]\s+(?:Restore )?(Ban|Unban)\s+(\S+)\s*$')
    for name in ['fail2ban.log.1', 'fail2ban.log']:
        path = directory / name
        if not path.exists():
            continue
        with path.open('rb') as stream:
            stream.seek(max(0, path.stat().st_size - 262144))
            lines = stream.read(262144).decode('utf-8', errors='replace').splitlines()
        for line in lines:
            match = pattern.match(line)
            if not match:
                continue
            try:
                ip = str(ipaddress.ip_address(match[3]))
                at = int(datetime.datetime.strptime(match[1], '%Y-%m-%d %H:%M:%S').timestamp())
            except ValueError:
                continue
            result.append({'ip': ip, 'action': match[2].lower(), 'at': at})
    return sorted(result, key=lambda row: row['at'], reverse=True)[:200]


def collect(lookup=None):
    history = events()
    try:
        ips = [str(ipaddress.ip_address(value)) for value in read_command('fail2ban-client', 'get', 'sshd', 'banip').split()]
    except (subprocess.SubprocessError, ValueError, OSError):
        return {'state': 'error', 'bans': [], 'events': history}
    deadlines = {}
    try:
        with sqlite3.connect('file:/var/lib/fail2ban/fail2ban.sqlite3?mode=ro', uri=True) as db:
            for ip, start, duration, count in db.execute("SELECT ip,timeofban,bantime,bancount FROM bans WHERE jail='sshd' ORDER BY timeofban DESC LIMIT 10000"):
                if ip not in deadlines:
                    deadlines[ip] = {'since': int(start), 'until': int(start + duration) if duration and duration > 0 else None, 'repeated': count > 1}
    except sqlite3.Error:
        pass
    return {'state': 'active', 'bans': [{'ip': ip, **deadlines.get(ip, {'since': None, 'until': None, 'repeated': None}), **(lookup.get(ip) if lookup else {'country': None, 'provider': None})} for ip in ips[:1000]], 'events': history}


class GeoLookup:
    def __init__(self):
        self.path = Path('/var/lib/statusdesk-security-monitor/ip-cache.json')
        try:
            self.cache = json.loads(self.path.read_text())
        except (OSError, ValueError):
            self.cache = {}
        if not isinstance(self.cache, dict):
            self.cache = {}
        self.cache = {key: entry for key, entry in self.cache.items()
                      if key in ('_day', '_count') or (isinstance(entry, dict)
                          and isinstance(entry.get('expires'), (int, float))
                          and entry['expires'] > time.time())}
        self.budget = 0

    def get(self, ip):
        unknown = {'country': None, 'provider': None}
        entry = self.cache.get(ip, {})
        if entry.get('expires', 0) > time.time():
            return entry.get('data', unknown)
        if self.budget <= 0 or not ipaddress.ip_address(ip).is_global:
            return unknown
        day = datetime.date.today().isoformat()
        if self.cache.get('_day') != day:
            self.cache['_day'], self.cache['_count'] = day, 0
        if self.cache.get('_count', 0) >= 400:
            return unknown
        self.budget -= 1
        self.cache['_count'] += 1
        data = unknown
        ttl = 3600
        try:
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
            with opener.open('https://ipwho.is/' + ip, timeout=2) as response:
                payload = json.loads(response.read(65536))
            if payload.get('success') is True and payload.get('ip') == ip:
                country = payload.get('country_code')
                provider = payload.get('connection', {}).get('isp')
                data = {'country': country if isinstance(country, str) and re.fullmatch('[A-Z]{2}', country) else None,
                        'provider': provider[:160] if isinstance(provider, str) else None}
                ttl = 604800
        except Exception:
            pass
        self.cache[ip] = {'expires': int(time.time()) + ttl, 'data': data}
        try:
            self.path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            temporary = self.path.with_suffix('.tmp')
            temporary.write_text(json.dumps(self.cache))
            temporary.replace(self.path)
        except OSError:
            pass
        return data


def main():
    spec = importlib.util.spec_from_file_location('firewall_config', Path(__file__).with_name('firewall-agent.py'))
    shared = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(shared)
    config = shared.load_config(Path('/etc/statusdesk-firewall.json'))
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), shared.NoRedirect())
    lookup = GeoLookup()
    while True:
        lookup.budget = 2
        try:
            req = urllib.request.Request(config['console_url'].rstrip('/') + '/api/server/security/status',
                data=json.dumps(collect(lookup)).encode(), headers={'Authorization': 'Bearer ' + config['secret'], 'Content-Type': 'application/json'})
            with opener.open(req, timeout=10) as response:
                if response.status != 204:
                    raise ValueError('Unexpected acknowledgment')
        except Exception as error:
            logging.error('Security status unavailable: %s', type(error).__name__)
        time.sleep(30)


if __name__ == '__main__':
    main()
