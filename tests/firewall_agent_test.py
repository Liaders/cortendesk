"""Pure-policy tests; never modify the developer's network."""
import hashlib
import importlib.util
import ipaddress
import json
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('agent', Path(__file__).resolve().parents[1] / 'scripts/statusdesk/firewall-agent.py')
agent = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent)


def payload(blocks):
    return {'blocks': blocks, 'digest': hashlib.sha256(json.dumps(blocks, separators=(',', ':')).encode()).hexdigest()}


class FirewallTests(unittest.TestCase):
    def test_expiry_and_invalid_addresses(self):
        _, active = agent.validate(payload({'8.8.8.8': 101, '8.8.4.4': 100}), 100)
        self.assertEqual(active, {ipaddress.ip_address('8.8.8.8'): 101})
        for ip in ['127.0.0.1', '::1', '10.0.0.1', '8.8.8.8;flush ruleset', '::ffff:8.8.8.8']:
            with self.assertRaises(ValueError):
                agent.validate(payload({ip: 101}), 100)
        with self.assertRaises(ValueError):
            agent.validate(payload({'8.8.8.8': 90000}), 100)

    def test_atomic_own_table_and_all_protocols(self):
        text = agent.rules({ipaddress.ip_address('8.8.8.8'): 120, ipaddress.ip_address('2001:4860::1'): 130}, 100, True)
        self.assertTrue(text.startswith('delete table inet statusdesk_access\n'))
        self.assertNotIn('flush ruleset', text)
        self.assertIn('8.8.8.8 timeout 20s', text)
        self.assertIn('2001:4860::1 timeout 30s', text)
        self.assertIn('ip saddr @blocked4 drop', text)
        self.assertIn('ip6 saddr @blocked6 drop', text)
        self.assertNotIn('dport', text)
        self.assertNotIn('ct state', text)
        self.assertNotIn('22,', text)
        self.assertNotIn('443', text)
        self.assertNotIn('8080', text)

    def test_digest_tampering_and_unknown_types(self):
        with self.assertRaises(ValueError):
            agent.validate({'blocks': {}, 'digest': 'wrong'}, 100)
        with self.assertRaises(ValueError):
            agent.validate(payload({'8.8.8.8': True}), 100)


if __name__ == '__main__':
    unittest.main()
