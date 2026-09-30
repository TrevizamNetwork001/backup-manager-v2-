"""VSOL OLT FTP Push capability. This driver never connects to the OLT —
the operator runs `write` + `copy startup-config ftp://...` manually on its
SSH console (see app/resources/views/devices/edit.blade.php, step 4/5 of the
Huawei/VSOL FTP wizard). Registered separately from huawei_olt_ftp.py only
because the registry keys on vendor; `collect_config` there is fully generic
(driven by job dict fields, no Huawei-specific logic) and is reused as-is by
backup_engine.py for any RECEIVED_PAYLOAD-capable driver, VSOL included.
"""

from storage import analyze_content
from driver_base import BackupDriver, ANALYZE, RECEIVED_PAYLOAD
from results import AnalysisResult


class VsolOltFtpReceivedDriver(BackupDriver):
    """No VSOL-specific content recognition exists yet (storage.analyze_content
    only knows the Huawei MA5800 format) — analyze() always reports 'unknown'
    for VSOL, which is purely informational and never blocks storage or the
    checksum-based validation that actually decides success."""

    name = 'vsol_olt_ftp_received'
    vendor = 'vsol'
    transport = 'ftp'
    capabilities = frozenset({ANALYZE, RECEIVED_PAYLOAD})

    def analyze(self, payload, context=None):
        platform = context.platform if context else 'olt'
        result = analyze_content(payload, 'vsol', platform)
        return AnalysisResult(status=result['status'], vendor='vsol', platform=platform,
                              metadata={'message': result.get('message')})
