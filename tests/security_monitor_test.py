import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('monitor', Path(__file__).resolve().parents[1] / 'scripts/statusdesk/security-monitor.py')
monitor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(monitor)


class MonitorTests(unittest.TestCase):
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
