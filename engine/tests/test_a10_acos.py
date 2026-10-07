import io
import sys
import tarfile
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from drivers.a10_acos import (A10AcosDriver, analyze_system_archive, backup_system_command,
                              expected_filename, parse_acos_version, trigger_system_backup)
from contexts import BackupContext
from errors import BackupError
from registry_setup import registry


class A10AcosTests(unittest.TestCase):
    def test_trigger_checks_version_and_password_prompt_before_sending_secret(self):
        class FakeChannel:
            closed = False

            def __init__(self, version, transfer_prompt=b'Password:', initial_prompt=b'ACOS>', extra_prompts=False):
                self.version = version
                self.transfer_prompt = transfer_prompt
                self.output = [initial_prompt]
                self.sent = []
                self.extra_prompts = extra_prompts
                self.waiting_filename = False

            def settimeout(self, _):
                pass

            def recv_ready(self):
                return bool(self.output)

            def recv(self, _):
                return self.output.pop(0)

            def exit_status_ready(self):
                return False

            def sendall(self, value):
                self.sent.append(value)
                if value == 'enable\n':
                    self.output.append(b'Password:')
                elif value == '\n':
                    if self.waiting_filename:
                        self.waiting_filename = False
                        self.output.append(b'Do you want to save the remote host information to a profile for later use?[yes/no]')
                    else:
                        self.output.append(b'ACOS#')
                elif value == 'show version | inc ACOS\n':
                    self.output.append(f'64-bit Advanced Core OS (ACOS) version {self.version}\nACOS#'.encode())
                elif value == 'configure\n':
                    self.output.append(b'ACOS(config)#')
                elif value.startswith('backup system '):
                    self.output.append(self.transfer_prompt)
                elif value == 'transfer-secret\n':
                    if self.extra_prompts:
                        self.waiting_filename = True
                        self.output.append(b'File name [/incoming/CGNAT-A10_20261002110000-exec-177.tar.gz]?')
                    else:
                        self.output.append(b'Backup completed\nACOS#')
                elif value == 'no\n':
                    self.output.append(b'System files backup succeeded\nACOS(config)#')

            def close(self):
                pass

        class FakeClient:
            def __init__(self, channel):
                self.channel = channel

            def set_missing_host_key_policy(self, _):
                pass

            def connect(self, *_, **__):
                pass

            def invoke_shell(self, **_):
                return self.channel

            def close(self):
                pass

        context = BackupContext(execution_id=177, device_id=1, host='192.0.2.20', port=22,
                                username='admin', vendor='a10 networks', platform='network')
        options = dict(method='scp', host='192.0.2.10', username='bmexec177',
                       filename='CGNAT-A10_20261002110000-exec-177.tar.gz', interface='management')
        channel = FakeChannel('4.1.4-GR1-P14, build 42 (*)')
        with patch('drivers.a10_acos.paramiko.SSHClient', return_value=FakeClient(channel)):
            trigger_system_backup(context, 'device-secret', options, 'transfer-secret', lambda *args: None)
        self.assertEqual(['enable\n', '\n', 'show version | inc ACOS\n', 'configure\n'], channel.sent[:4])
        self.assertTrue(channel.sent[4].startswith('backup system use-mgmt-port scp://'))
        self.assertEqual('transfer-secret\n', channel.sent[5])

        sftp_channel = FakeChannel('4.1.4-GR1-P14, build 42')
        sftp_options = dict(options, method='sftp', username='a10backup', directory='incoming')
        with patch('drivers.a10_acos.paramiko.SSHClient', return_value=FakeClient(sftp_channel)):
            trigger_system_backup(context, 'device-secret', sftp_options, 'transfer-secret', lambda *args: None)
        self.assertIn('/incoming/CGNAT-A10_', sftp_channel.sent[4])
        self.assertEqual('transfer-secret\n', sftp_channel.sent[5])

        with_prompts = FakeChannel('4.1.4-GR1-P14, build 42', extra_prompts=True)
        with patch('drivers.a10_acos.paramiko.SSHClient', return_value=FakeClient(with_prompts)):
            trigger_system_backup(context, 'device-secret', sftp_options, 'transfer-secret', lambda *args: None)
        self.assertEqual(['transfer-secret\n', '\n', 'no\n'], with_prompts.sent[-3:])

        privileged = FakeChannel('4.1.4-GR1-P14, build 42', initial_prompt=b'ACOS#')
        with patch('drivers.a10_acos.paramiko.SSHClient', return_value=FakeClient(privileged)):
            trigger_system_backup(context, 'device-secret', options, 'transfer-secret', lambda *args: None)
        self.assertEqual('show version | inc ACOS\n', privileged.sent[0])

        channel = FakeChannel('7.0.2, build 1')
        with patch('drivers.a10_acos.paramiko.SSHClient', return_value=FakeClient(channel)):
            with self.assertRaises(BackupError) as failure:
                trigger_system_backup(context, 'device-secret', options, 'transfer-secret', lambda *args: None)
        self.assertEqual('A10_VERSION_UNSUPPORTED', failure.exception.code)
        self.assertEqual(['enable\n', '\n', 'show version | inc ACOS\n'], channel.sent)

        channel = FakeChannel('4.1.4-GR1-P14, build 42', b'Unexpected response\nACOS#')
        with patch('drivers.a10_acos.paramiko.SSHClient', return_value=FakeClient(channel)):
            with self.assertRaises(BackupError) as failure:
                trigger_system_backup(context, 'device-secret', options, 'transfer-secret', lambda *args: None)
        self.assertEqual('A10_TRANSFER_FAILED', failure.exception.code)
        self.assertEqual(5, len(channel.sent))

    def test_version_is_specific_to_observed_primary_acos_release(self):
        version = 'ACOS: 4.1.4-GR1-P14, build 42 (*)\nGUI: 4.1.4, build 11\n'
        self.assertEqual('4.1.4-GR1-P14, build 42', parse_acos_version(version))
        self.assertEqual('4.1.4-GR1-P14, build 42', parse_acos_version(
            '64-bit Advanced Core OS (ACOS) version 4.1.4-GR1-P14, build 42 (May-22-2024,16:56)'))
        self.assertIsNone(parse_acos_version('ACOS: 7.0.2, build 1'))

    def test_expected_name_is_unique_and_stable_across_retries(self):
        when = datetime(2026, 10, 1, 20, 30, tzinfo=timezone.utc)
        self.assertEqual('SPEEDFIBER-CGN-BOX1_20261001203000-exec-177.tar.gz',
                         expected_filename('SPEEDFIBER-CGN-BOX1', when, 177))
        self.assertNotEqual(expected_filename('CGN', when, 177), expected_filename('CGN', when, 178))
        with self.assertRaises(ValueError):
            expected_filename('../', when, 1)

    def test_binary_archive_analysis_is_informational_and_never_extracts(self):
        stream = io.BytesIO()
        with tarfile.open(fileobj=stream, mode='w:gz') as archive:
            content = b'opaque binary data\x00\xff'
            member = tarfile.TarInfo('backup-system.bin')
            member.size = len(content)
            archive.addfile(member, io.BytesIO(content))
        result = analyze_system_archive(stream.getvalue())
        self.assertEqual('recognized', result.status)
        self.assertEqual('tar.gz', result.metadata['format'])
        self.assertEqual('warning', analyze_system_archive(b'\x1f\x8bcorrupted').status)
        self.assertEqual('unknown', analyze_system_archive(b'PK\x03\x04').status)
        self.assertIn('backup', A10AcosDriver.capabilities)

    def test_probe_registration_does_not_enable_backup_dispatch(self):
        self.assertIsInstance(registry.resolve('A10 Networks', 'network', 'a10_system'), A10AcosDriver)
        with self.assertRaises(BackupError) as failure:
            registry.resolve('A10 Networks', 'network', 'ssh_pull')
        self.assertEqual('UNSUPPORTED_POLICY', failure.exception.code)

    def test_p14_command_uses_distinct_protocol_and_no_password(self):
        version = '4.1.4-GR1-P14, build 42'
        name = 'SPEEDFIBER-CGN-BOX1_20261001203000-exec-177.tar.gz'
        scp = backup_system_command(version, 'scp', '192.0.2.10', 'a10backup', name, 'management')
        self.assertEqual(f'backup system use-mgmt-port scp://a10backup@192.0.2.10/{name}', scp)
        self.assertNotIn('secret', scp)
        self.assertEqual(f'backup system sftp://a10backup@192.0.2.10/{name}',
                         backup_system_command(version, 'sftp', '192.0.2.10', 'a10backup', name))
        self.assertEqual(f'backup system sftp://a10backup@192.0.2.10/incoming/{name}',
                         backup_system_command(version, 'sftp', '192.0.2.10', 'a10backup', name,
                                               directory='incoming'))
        self.assertEqual(f'backup system ftp://a10backup@192.0.2.10:21/{name}',
                         backup_system_command(version, 'ftp', '192.0.2.10', 'a10backup', name, port=21))
        for kwargs in [dict(version='7.0.2, build 1'), dict(username='a;rm'),
                       dict(filename='../bad.tar.gz'), dict(host='bad/host'),
                       dict(method='scp', port=22)]:
            arguments = dict(version=version, method='scp', host='192.0.2.10', username='a10backup', filename=name)
            arguments.update(kwargs)
            with self.assertRaises(ValueError):
                backup_system_command(**arguments)


if __name__ == '__main__':
    unittest.main()
