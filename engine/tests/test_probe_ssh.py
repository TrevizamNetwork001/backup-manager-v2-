import io
import json
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

import paramiko

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import probe_ssh
from contexts import ProbeContext
from drivers.huawei_olt_ssh_probe import probe_authentication
from results import ProbeResult


class HuaweiOltSshProbeTests(unittest.TestCase):
    def setUp(self):
        self.context = ProbeContext(
            device_id=6, host='192.0.2.26', port=22, username='olt-user',
            vendor='Huawei', platform='olt',
            ssh_host_key_algorithm='ssh-rsa', ssh_host_key_fingerprint='SHA256:' + 'a' * 43,
            metadata={'__secret': 'secret', '__observe': lambda *_: None},
        )

    def test_probe_only_authenticates_and_does_not_open_a_cli_shell(self):
        with patch('drivers.huawei_olt_ssh_probe.paramiko.SSHClient') as client_class:
            result = probe_authentication(self.context)
            self.assertTrue(result.success)
            client_class.return_value.connect.assert_called_once()
            client_class.return_value.invoke_shell.assert_not_called()
            client_class.return_value.close.assert_called_once()

    def test_authentication_failure_uses_existing_error_code(self):
        with patch('drivers.huawei_olt_ssh_probe.paramiko.SSHClient') as client_class:
            client_class.return_value.connect.side_effect = paramiko.AuthenticationException()
            self.assertEqual('SSH_AUTH_FAILED', probe_authentication(self.context).code)

    def test_credential_test_routes_huawei_olt_to_access_only_probe(self):
        payload = {
            'device_id': 6, 'host': '192.0.2.26', 'port': 22, 'username': 'olt-user',
            'vendor': 'Huawei', 'platform': 'olt', 'secret': 'secret',
            'ssh_host_key_algorithm': 'ssh-rsa',
            'ssh_host_key_fingerprint': 'SHA256:' + 'a' * 43,
        }
        output = io.StringIO()
        with patch.object(sys, 'stdin', io.StringIO(json.dumps(payload))), \
             patch.object(sys, 'stdout', output), \
             patch.object(probe_ssh, 'probe_huawei_olt_authentication', return_value=ProbeResult(success=True)) as auth:
            probe_ssh.main()
        self.assertTrue(json.loads(output.getvalue())['success'])
        auth.assert_called_once()


if __name__ == '__main__':
    unittest.main()
