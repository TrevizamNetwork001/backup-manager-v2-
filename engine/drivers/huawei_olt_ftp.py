"""Huawei OLT FTP Push capability. This driver never connects to the OLT."""

import os

from ftp_incoming import receive


def collect_config(job, complete):
    receive(os.environ['BACKUP_FTP_ROOT'], int(job['device_id']), job['ftp_filename'],
            os.environ['BACKUP_STORAGE_ROOT'], job['relative_path'],
            int(os.environ.get('BACKUP_FTP_RECEIVE_TIMEOUT_SECONDS', '180')),
            int(os.environ.get('BACKUP_FTP_STABLE_SECONDS', '5')), complete)
