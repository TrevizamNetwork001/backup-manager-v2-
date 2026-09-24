"""Huawei OLT FTP Push capability. This driver never connects to the OLT."""

import os

from ftp_incoming import receive
from storage import analyze_content
from driver_base import BackupDriver, ANALYZE, RECEIVED_PAYLOAD
from results import AnalysisResult


def collect_config(job, complete):
    receive(os.environ['BACKUP_FTP_ROOT'], int(job['device_id']), job['ftp_filename'],
            os.environ['BACKUP_STORAGE_ROOT'], job['relative_path'],
            int(os.environ.get('BACKUP_FTP_RECEIVE_TIMEOUT_SECONDS', '180')),
            int(os.environ.get('BACKUP_FTP_STABLE_SECONDS', '5')), complete,
            home_path=job.get('ftp_home'))


class HuaweiOltFtpReceivedDriver(BackupDriver):
    """Backup arrives spontaneously (auto-backup) or via the manual diagnostic
    path (`bm-exec-<id>.cfg`) — the engine never opens a session to the OLT
    itself, so this driver has no `probe`/`backup` capability, only
    `analyze` (delegating to the single, already-validated
    `storage.analyze_content` implementation — never a second copy of the
    MA5800 parsing logic) and `received_payload` (a marker capability so the
    registry/dispatcher knows this driver is fed a payload instead of
    fetching one)."""

    name = 'huawei_olt_ftp_received'
    vendor = 'huawei'
    transport = 'ftp'
    capabilities = frozenset({ANALYZE, RECEIVED_PAYLOAD})

    def analyze(self, payload, context=None):
        platform = context.platform if context else 'olt'
        result = analyze_content(payload, 'huawei', platform)
        return AnalysisResult(status=result['status'], vendor='huawei', platform=platform,
                              metadata={'message': result.get('message')})
