import unittest
from unittest.mock import patch

from mikrotik_ftp_integrate import install_and_test, quote, script_source, source_quote, test_source


class MikroTikFtpIntegrateTest(unittest.TestCase):
    def setUp(self):
        self.data = {
            'device_id': 1,
            'device_name': 'MK-BORDA-01',
            'ftp_host': '192.0.2.80',
            'ftp_username': 'mkbackup',
            'ftp_secret': 'pa$$"word\\end',
            'schedule_time': '23:00',
        }

    def test_routeros_secret_is_escaped_in_installer_and_test(self):
        expected = quote(self.data['ftp_secret'])
        for contents in (script_source(self.data, 6), test_source(self.data, 6, 'bm-test')):
            with self.subTest(contents=contents[:30]):
                self.assertIn(':local bmPassword ' + expected, contents)
                self.assertNotIn(':local bmPassword "pa$$', contents)
        quoted = source_quote(script_source(self.data, 6))
        self.assertNotIn('\n', quoted)
        self.assertIn('\\r\\n', quoted)
        self.assertIn('backup-manager-v2-ftp device=1', quoted)

    def test_routeros_6_export_is_text_only(self):
        source = script_source(self.data, 6)
        self.assertIn('/export compact file=$bmBase', source)
        self.assertIn('MK-BORDA-01-backup-', source)
        self.assertIn('backup-manager-v2-ftp naming=2', source)
        self.assertIn('mode=ftp upload=yes', source)
        self.assertNotIn('/system backup save', source)
        self.assertNotIn('.backup', source)

    def test_old_routeros_version_or_legacy_objects_block_installation(self):
        with patch('mikrotik_ftp_integrate.run', return_value='6.42.1'), \
                patch('mikrotik_ftp_integrate.import_source') as install:
            with self.assertRaisesRegex(RuntimeError, 'ROUTEROS_VERSION_UNSUPPORTED'):
                install_and_test(object(), self.data)
            install.assert_not_called()

        with patch('mikrotik_ftp_integrate.run', return_value='6.45.7'), \
                patch('mikrotik_ftp_integrate.count', side_effect=[1, 0]), \
                patch('mikrotik_ftp_integrate.import_source') as install:
            with self.assertRaisesRegex(RuntimeError, 'LEGACY_INTEGRATION_PRESENT'):
                install_and_test(object(), self.data)
            install.assert_not_called()


if __name__ == '__main__':
    unittest.main()
