import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import ftp_acl_host


class DeferredFtpTest(unittest.TestCase):
    def test_empty_initial_list_is_valid_and_open_access_is_rejected(self):
        self.assertEqual(ftp_acl_host.validate({'cidrs': [], 'revision': 0}),
                         {'cidrs': [], 'revision': 0})
        with self.assertRaises(ValueError):
            ftp_acl_host.validate({'cidrs': ['0.0.0.0/0'], 'revision': 1})

    def test_empty_initial_list_keeps_ftp_offline(self):
        with tempfile.TemporaryDirectory() as temporary:
            marker = Path(temporary, 'deferred')
            marker.touch()
            with patch.object(ftp_acl_host, 'DEFER_MARKER', marker), \
                 patch.object(ftp_acl_host, 'LOCK', Path(temporary, 'lock')), \
                 patch.object(ftp_acl_host, 'validate_host_settings'), \
                 patch.object(ftp_acl_host.os, 'geteuid', return_value=0), \
                 patch.object(ftp_acl_host.os, 'chdir'), \
                 patch.object(ftp_acl_host, 'requested', return_value=({'cidrs': [], 'revision': 0}, False)), \
                 patch.object(ftp_acl_host, 'apply') as apply, \
                 patch.object(ftp_acl_host, 'command') as command:
                ftp_acl_host.main()

            apply.assert_not_called()
            command.assert_called_once_with('docker', 'compose', 'ps', '--services', '--status', 'running')
            self.assertTrue(marker.exists())

    def test_pending_configuration_stops_ftp_if_it_was_started(self):
        with tempfile.TemporaryDirectory() as temporary:
            marker = Path(temporary, 'deferred')
            marker.touch()
            with patch.object(ftp_acl_host, 'DEFER_MARKER', marker), \
                 patch.object(ftp_acl_host, 'LOCK', Path(temporary, 'lock')), \
                 patch.object(ftp_acl_host, 'validate_host_settings'), \
                 patch.object(ftp_acl_host.os, 'geteuid', return_value=0), \
                 patch.object(ftp_acl_host.os, 'chdir'), \
                 patch.object(ftp_acl_host, 'requested', return_value=({'cidrs': [], 'revision': 0}, False)), \
                 patch.object(ftp_acl_host, 'apply') as apply, \
                 patch.object(ftp_acl_host, 'command', side_effect=['ftp\n', '']) as command:
                ftp_acl_host.main()

            apply.assert_not_called()
            self.assertEqual(command.call_args_list[1].args, ('docker', 'compose', 'stop', 'ftp'))

    def test_web_save_applies_firewall_before_starting_ftp(self):
        with tempfile.TemporaryDirectory() as temporary:
            marker = Path(temporary, 'deferred')
            marker.touch()
            events = []
            data = {'cidrs': ['192.0.2.0/24'], 'revision': 1}
            with patch.object(ftp_acl_host, 'DEFER_MARKER', marker), \
                 patch.object(ftp_acl_host, 'LOCK', Path(temporary, 'lock')), \
                 patch.object(ftp_acl_host, 'validate_host_settings'), \
                 patch.object(ftp_acl_host.os, 'geteuid', return_value=0), \
                 patch.object(ftp_acl_host.os, 'chdir'), \
                 patch.object(ftp_acl_host, 'requested', return_value=(data, True)), \
                 patch.object(ftp_acl_host, 'apply', side_effect=lambda _: events.append('firewall')), \
                 patch.object(ftp_acl_host, 'persist', side_effect=lambda _: events.append('persist')), \
                 patch.object(ftp_acl_host, 'command', side_effect=lambda *args, **kwargs: events.append(args)):
                ftp_acl_host.main()

            self.assertEqual(events[0], 'firewall')
            self.assertEqual(events[1][:5], ('docker', 'compose', 'up', '-d', '--build'))
            self.assertEqual(events[2], 'persist')
            self.assertEqual(events[3][:5], ('docker', 'compose', 'exec', '-T', 'app'))
            self.assertFalse(marker.exists())


if __name__ == '__main__':
    unittest.main()
