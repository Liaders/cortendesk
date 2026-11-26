import importlib.util
from pathlib import Path
import tempfile
import unittest
import sqlite3
import json
from unittest.mock import MagicMock
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('monitor', Path(__file__).resolve().parents[1] / 'scripts/statusdesk/security-monitor.py')
monitor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(monitor)


class MonitorTests(unittest.TestCase):
    def test_start_expiry_and_repeat_are_read_from_fail2ban_database(self):
        db = sqlite3.connect(':memory:')
        db.execute('CREATE TABLE bans (ip TEXT, timeofban INTEGER, bantime INTEGER, bancount INTEGER, jail TEXT)')
        db.execute("INSERT INTO bans VALUES ('8.8.8.8', 1700000000, 7200, 2, 'sshd')")
        with patch.object(monitor, 'events', return_value=[]), patch.object(monitor, 'read_command', return_value='8.8.8.8 8.8.4.4'), patch.object(monitor.sqlite3, 'connect', return_value=db):
            rows = monitor.collect()['bans']
        self.assertEqual(rows[0]['since'], 1700000000)
        self.assertEqual(rows[0]['until'], 1700007200)
        self.assertTrue(rows[0]['repeated'])
        self.assertIsNone(rows[1]['since'])
        self.assertIsNone(rows[1]['repeated'])
        db.close()

    def test_geolocation_is_cached_and_failures_do_not_break_monitoring(self):
        with tempfile.TemporaryDirectory() as directory:
            lookup = monitor.GeoLookup.__new__(monitor.GeoLookup)
            lookup.path = Path(directory) / 'cache.json'
            lookup.cache, lookup.budget = {}, 2
            response = MagicMock()
            response.__enter__.return_value.read.return_value = json.dumps({'success': True, 'ip': '8.8.8.8', 'country_code': 'US', 'connection': {'isp': 'Example ISP'}}).encode()
            opener = MagicMock()
            opener.open.return_value = response
            with patch.object(monitor.urllib.request, 'build_opener', return_value=opener):
                self.assertEqual(lookup.get('8.8.8.8')['provider'], 'Example ISP')
                self.assertEqual(lookup.get('8.8.8.8')['country'], 'US')
                self.assertEqual(opener.open.call_count, 1)
                opener.open.side_effect = TimeoutError()
                self.assertEqual(lookup.get('8.8.4.4'), {'country': None, 'provider': None})
                self.assertEqual(lookup.get('127.0.0.1'), {'country': None, 'provider': None})

    def test_history_is_bounded_validated_and_contains_no_raw_log_data(self):
        with tempfile.TemporaryDirectory() as name:
            directory = Path(name)
            (directory / 'fail2ban.log').write_text(
                '2026-11-26 05:00:00,001 fail2ban.actions [1]: NOTICE [sshd] Ban 8.8.8.8\n'
                '2026-11-26 05:01:00,001 fail2ban.actions [1]: NOTICE [sshd] Unban 8.8.8.8\n'
                '2026-11-26 05:01:00,001 fail2ban.actions [1]: NOTICE [sshd] Ban invalid;command\n'
                '2026-11-26 05:01:00,001 fail2ban.actions [1]: NOTICE [other] Ban 8.8.8.8\n')
            rows = monitor.events(directory)
            self.assertEqual([row['action'] for row in rows], ['unban', 'ban'])
            self.assertEqual(set(rows[0]), {'ip', 'action', 'at'})

    def test_reads_active_addresses_without_any_mutating_fail2ban_commands(self):
        with patch.object(monitor, 'events', return_value=[]), patch.object(monitor, 'read_command', return_value='8.8.8.8 2001:4860::1') as command:
            payload = monitor.collect()
            self.assertEqual(payload['state'], 'active')
            self.assertEqual(len(payload['bans']), 2)
            command.assert_called_once_with('fail2ban-client', 'get', 'sshd', 'banip')


if __name__ == '__main__':
    unittest.main()
