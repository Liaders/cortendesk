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


def collect():
    history = events()
    try:
        ips = [str(ipaddress.ip_address(value)) for value in read_command('fail2ban-client', 'get', 'sshd', 'banip').split()]
    except (subprocess.SubprocessError, ValueError, OSError):
        return {'state': 'error', 'bans': [], 'events': history}
    deadlines = {}
    try:
        with sqlite3.connect('file:/var/lib/fail2ban/fail2ban.sqlite3?mode=ro', uri=True) as db:
            for ip, start, duration in db.execute("SELECT ip,timeofban,bantime FROM bans WHERE jail='sshd' ORDER BY timeofban DESC LIMIT 10000"):
                if ip not in deadlines:
                    deadlines[ip] = int(start + duration) if duration and duration > 0 else None
    except sqlite3.Error:
        pass
    return {'state': 'active', 'bans': [{'ip': ip, 'until': deadlines.get(ip)} for ip in ips[:1000]], 'events': history}


def main():
    spec = importlib.util.spec_from_file_location('firewall_config', Path(__file__).with_name('firewall-agent.py'))
    shared = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(shared)
    config = shared.load_config(Path('/etc/statusdesk-firewall.json'))
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), shared.NoRedirect())
    while True:
        try:
            req = urllib.request.Request(config['console_url'].rstrip('/') + '/api/server/security/status',
                data=json.dumps(collect()).encode(), headers={'Authorization': 'Bearer ' + config['secret'], 'Content-Type': 'application/json'})
            with opener.open(req, timeout=10) as response:
                if response.status != 204:
                    raise ValueError('Unexpected acknowledgment')
        except Exception as error:
            logging.error('Security status unavailable: %s', type(error).__name__)
        time.sleep(30)


if __name__ == '__main__':
    main()
