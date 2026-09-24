import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from errors import BackupError, RETRYABLE_CODES, is_retryable
from results import AnalysisResult, BackupResult, ProbeResult
from contexts import BackupContext, ProbeContext
from driver_base import ANALYZE, BACKUP, PROBE, RECEIVED_PAYLOAD, BackupDriver
from registry import DriverRegistry
import drivers.mikrotik_ssh as mikrotik_ssh
import drivers.huawei_vrp_ssh as huawei_vrp_ssh
from drivers.mikrotik_ssh import MikroTikRouterOsSshDriver
from drivers.huawei_vrp_ssh import HuaweiVrpSshDriver
from drivers.huawei_olt_ftp import HuaweiOltFtpReceivedDriver


FIXTURE_MA5800 = (Path(__file__).parent / 'fixtures' / 'ma5800_ftp.cfg').read_text()


class DriverRegistryTests(unittest.TestCase):
    def setUp(self):
        self.registry = DriverRegistry()
        self.mikrotik = MikroTikRouterOsSshDriver()
        self.registry.register(('mikrotik', 'network', 'ssh_pull'), self.mikrotik)

    def test_resolves_registered_driver_case_insensitively(self):
        self.assertIs(self.registry.resolve('MiKroTik', 'Network', 'SSH_Pull'), self.mikrotik)

    def test_unknown_vendor_raises_unsupported_vendor(self):
        with self.assertRaises(BackupError) as ctx:
            self.registry.resolve('cisco', 'network', 'ssh_pull')
        self.assertEqual('UNSUPPORTED_VENDOR', ctx.exception.code)

    def test_known_vendor_wrong_combination_raises_unsupported_policy(self):
        with self.assertRaises(BackupError) as ctx:
            self.registry.resolve('mikrotik', 'olt', 'ftp_push')
        self.assertEqual('UNSUPPORTED_POLICY', ctx.exception.code)

    def test_capabilities_reflect_registered_driver(self):
        self.assertEqual(frozenset({PROBE, BACKUP, ANALYZE}),
                         self.registry.capabilities('mikrotik', 'network', 'ssh_pull'))


class ResultSerializationTests(unittest.TestCase):
    def test_backup_result_to_dict_never_includes_payload(self):
        result = BackupResult(success=True, code='ok', payload=b'super-secret-config', size_bytes=20)
        as_dict = result.to_dict()
        self.assertNotIn('payload', as_dict)
        self.assertEqual(20, as_dict['size_bytes'])

    def test_backup_result_nests_analysis(self):
        analysis = AnalysisResult(status='recognized', vendor='huawei', hostname='OLT-1')
        result = BackupResult(success=True, analysis=analysis)
        self.assertEqual('recognized', result.to_dict()['analysis']['status'])
        self.assertEqual('OLT-1', result.to_dict()['analysis']['hostname'])

    def test_probe_result_serializes(self):
        result = ProbeResult(success=False, code='SSH_AUTH_FAILED', latency_ms=12.3)
        self.assertEqual({'success': False, 'code': 'SSH_AUTH_FAILED', 'message': '',
                          'latency_ms': 12.3, 'remote_banner': None, 'metadata': {}}, result.to_dict())


class RetryableTests(unittest.TestCase):
    def test_known_retryable_codes(self):
        for code in ['SSH_TIMEOUT', 'SSH_CONNECTION_REFUSED', 'SSH_CONNECT_FAILED', 'STORAGE_FAILED']:
            self.assertTrue(is_retryable(code), code)

    def test_known_non_retryable_codes(self):
        for code in ['SSH_AUTH_FAILED', 'UNSUPPORTED_VENDOR', 'CREDENTIAL_INVALID', 'ARTIFACT_INVALID']:
            self.assertFalse(is_retryable(code), code)

    def test_unknown_code_defaults_to_non_retryable(self):
        self.assertFalse(is_retryable('SOMETHING_NEW_NOBODY_REGISTERED'))

    def test_retryable_set_has_no_overlap_with_backup_result_default(self):
        self.assertNotIn('ok', RETRYABLE_CODES)


class BaseDriverContractTests(unittest.TestCase):
    def test_undeclared_capability_raises_not_implemented(self):
        class BareDriver(BackupDriver):
            name = 'bare'

        driver = BareDriver()
        with self.assertRaises(NotImplementedError):
            driver.probe(None)
        with self.assertRaises(NotImplementedError):
            driver.backup(None)
        with self.assertRaises(NotImplementedError):
            driver.analyze(b'', None)


class MikroTikDriverTests(unittest.TestCase):
    def setUp(self):
        self.driver = MikroTikRouterOsSshDriver()
        self.context = BackupContext(execution_id=1, device_id=2, host='192.0.2.1', port=22,
                                     username='backup', vendor='mikrotik', platform='network', method='ssh_pull')

    def test_capabilities(self):
        self.assertEqual(frozenset({PROBE, BACKUP, ANALYZE}), self.driver.capabilities)

    def test_backup_success_wraps_payload_in_result(self):
        data = b'# RouterOS\n/interface bridge\nadd name=br1\n'
        with patch.object(mikrotik_ssh, 'export_config', return_value=data):
            result = self.driver.backup(self.context, secret='x', observe=lambda *a: None)
        self.assertTrue(result.success)
        self.assertEqual(data, result.payload)
        self.assertEqual(len(data), result.size_bytes)
        self.assertEqual('ssh', result.transport)

    def test_backup_failure_returns_structured_result_not_exception(self):
        with patch.object(mikrotik_ssh, 'export_config', side_effect=BackupError('SSH_AUTH_FAILED')):
            result = self.driver.backup(self.context, secret='x', observe=lambda *a: None)
        self.assertFalse(result.success)
        self.assertEqual('SSH_AUTH_FAILED', result.code)
        self.assertFalse(result.retryable)

    def test_backup_timeout_is_marked_retryable(self):
        with patch.object(mikrotik_ssh, 'export_config', side_effect=BackupError('SSH_TIMEOUT')):
            result = self.driver.backup(self.context, secret='x', observe=lambda *a: None)
        self.assertTrue(result.retryable)

    def test_backup_forwards_cancel_check_and_cancelled_is_never_retried(self):
        # The poll-loop-level check that cancel_check is actually consulted
        # lives in test_engine.py (against a fake paramiko channel); this
        # confirms the driver forwards it and classifies the outcome.
        marker = lambda: True  # noqa: E731 — identity-compared below, not called
        with patch.object(mikrotik_ssh, 'export_config', side_effect=BackupError('CANCELLED')) as export:
            result = self.driver.backup(self.context, secret='x', observe=lambda *a: None, cancel_check=marker)
        export.assert_called_once()
        self.assertIs(marker, export.call_args.args[-1])
        self.assertFalse(result.success)
        self.assertEqual('CANCELLED', result.code)
        self.assertFalse(result.retryable)

    def test_probe_success_reports_latency_without_running_a_command(self):
        with patch.object(mikrotik_ssh, 'probe_connection', return_value=42.0) as probe:
            result = self.driver.probe(ProbeContext(device_id=2, host='192.0.2.1', port=22,
                                                     username='backup', vendor='mikrotik'))
        self.assertTrue(result.success)
        self.assertEqual(42.0, result.latency_ms)
        probe.assert_called_once()

    def test_probe_failure_returns_result_not_exception(self):
        with patch.object(mikrotik_ssh, 'probe_connection', side_effect=BackupError('SSH_CONNECTION_REFUSED')):
            result = self.driver.probe(ProbeContext(device_id=2, host='192.0.2.1', port=22,
                                                     username='backup', vendor='mikrotik'))
        self.assertFalse(result.success)
        self.assertEqual('SSH_CONNECTION_REFUSED', result.code)

    def test_analyze_recognizes_export_header(self):
        result = self.driver.analyze(b'# RouterOS export\n/interface bridge\n')
        self.assertEqual('recognized', result.status)

    def test_analyze_unknown_for_non_export_content(self):
        result = self.driver.analyze(b'not a routeros export at all')
        self.assertEqual('unknown', result.status)

    def test_analyze_flags_embedded_user_password_as_warning(self):
        # Lesson from V1's RouterOSExportValidator: exports can legitimately
        # carry plaintext secrets — flag it, never block storage over it.
        data = b'# RouterOS export\n/user add name=admin password=SuperSecret123 group=full\n'
        result = self.driver.analyze(data)
        self.assertEqual('recognized', result.status)
        self.assertIn('contains_user_password', result.warnings)

    def test_analyze_flags_embedded_private_key(self):
        data = b'# RouterOS export\n-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBAK...\n-----END RSA PRIVATE KEY-----\n'
        result = self.driver.analyze(data)
        self.assertIn('contains_private_key', result.warnings)

    def test_analyze_clean_export_has_no_warnings(self):
        data = b'# RouterOS export\n/interface bridge\nadd name=br1\n'
        result = self.driver.analyze(data)
        self.assertEqual([], result.warnings)


class HuaweiVrpDriverTests(unittest.TestCase):
    def setUp(self):
        self.driver = HuaweiVrpSshDriver()
        self.context = BackupContext(execution_id=1, device_id=2, host='192.0.2.2', port=22,
                                     username='backup', vendor='huawei', platform='network', method='ssh_pull')

    def test_capabilities(self):
        self.assertEqual(frozenset({PROBE, BACKUP, ANALYZE}), self.driver.capabilities)

    def test_backup_success_wraps_payload(self):
        data = b'#\nsysname Lab\n#\n'
        with patch.object(huawei_vrp_ssh, 'export_config', return_value=data):
            result = self.driver.backup(self.context, secret='x', observe=lambda *a: None)
        self.assertTrue(result.success)
        self.assertEqual(data, result.payload)

    def test_backup_cli_failure_returns_structured_result(self):
        with patch.object(huawei_vrp_ssh, 'export_config', side_effect=BackupError('HUAWEI_EXPORT_FAILED')):
            result = self.driver.backup(self.context, secret='x', observe=lambda *a: None)
        self.assertFalse(result.success)
        self.assertEqual('HUAWEI_EXPORT_FAILED', result.code)

    def test_analyze_recognizes_sysname(self):
        result = self.driver.analyze(b'#\nsysname Lab-Router\n#\ninterface Vlanif1\n')
        self.assertEqual('recognized', result.status)
        self.assertEqual('Lab-Router', result.hostname)

    def test_analyze_unknown_without_sysname(self):
        result = self.driver.analyze(b'#\ninterface Vlanif1\n#\n')
        self.assertEqual('unknown', result.status)

    def test_analyze_unknown_for_binary_content(self):
        result = self.driver.analyze(b'\xff\xfe\x00\x01binary-garbage')
        self.assertEqual('unknown', result.status)


class HuaweiOltFtpReceivedDriverTests(unittest.TestCase):
    def setUp(self):
        self.driver = HuaweiOltFtpReceivedDriver()

    def test_capabilities_have_no_active_probe_or_backup(self):
        self.assertEqual(frozenset({ANALYZE, RECEIVED_PAYLOAD}), self.driver.capabilities)
        with self.assertRaises(NotImplementedError):
            self.driver.probe(None)
        with self.assertRaises(NotImplementedError):
            self.driver.backup(None)

    def test_r21_style_config_with_sysname_is_recognized(self):
        result = self.driver.analyze(FIXTURE_MA5800.encode('utf-8'))
        self.assertEqual('recognized', result.status)

    def test_r19_style_config_without_sysname_is_warning(self):
        without_sysname = '\n'.join(
            line for line in FIXTURE_MA5800.splitlines() if not line.strip().lower().startswith('sysname')
        )
        result = self.driver.analyze(without_sysname.encode('utf-8'))
        self.assertEqual('warning', result.status)

    def test_unknown_intact_content_is_still_analyzable_without_error(self):
        result = self.driver.analyze(b'totally unrelated vendor dump, never seen before')
        self.assertEqual('unknown', result.status)
        # Never raises — an unrecognized format is informational, not a rejection.


if __name__ == '__main__':
    unittest.main()
