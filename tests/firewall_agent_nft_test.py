"""Run only in a disposable Linux network namespace, never the host namespace."""
import os
import subprocess
import time
import unittest
from firewall_agent_test import agent, ipaddress


class KernelFirewallTests(unittest.TestCase):
    def test_rules_are_valid_atomic_and_expire_without_an_agent(self):
        self.assertNotEqual(os.readlink('/proc/self/ns/net'), os.readlink('/proc/1/ns/net'))
        now = int(time.time())
        blocks = {ipaddress.ip_address('8.8.8.8'): now + 2, ipaddress.ip_address('2001:4860::1'): now + 2}
        subprocess.run(['nft', '-f', '-'], input=agent.rules(blocks, now, False), text=True, check=True)
        listing = subprocess.check_output(['nft', 'list', 'table', 'inet', agent.TABLE], text=True)
        self.assertIn('8.8.8.8', listing)
        self.assertIn('ct original proto-dst', listing)
        self.assertNotIn('dport 22', listing)
        # A refresh atomically replaces only our table, without extending expiry.
        subprocess.run(['nft', '-f', '-'], input=agent.rules(blocks, now, True), text=True, check=True)
        time.sleep(3)
        listing = subprocess.check_output(['nft', 'list', 'table', 'inet', agent.TABLE], text=True)
        self.assertNotIn('8.8.8.8', listing)
        self.assertNotIn('2001:4860::1', listing)


if __name__ == '__main__':
    unittest.main()
