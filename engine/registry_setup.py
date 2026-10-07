"""Builds the single, shared driver registry used by the whole engine —
both the job dispatcher in `backup_engine.py` and the FTP received flow
(`ftp_incoming.py`/`ftp_spontaneous.py`, for `analyze()`) resolve drivers
through this same registry instead of each keeping its own reference.
"""

from registry import registry
from drivers.mikrotik_ssh import MikroTikRouterOsSshDriver
from drivers.huawei_vrp_ssh import HuaweiVrpSshDriver
from drivers.huawei_olt_ftp import HuaweiOltFtpReceivedDriver
from drivers.vsol_olt_ftp import VsolOltFtpReceivedDriver
from drivers.vsol_ssh import VsolOltSshDriver
from drivers.a10_acos import A10AcosDriver

registry.register(('mikrotik', 'network', 'ssh_pull'), MikroTikRouterOsSshDriver())
registry.register(('huawei', 'network', 'ssh_pull'), HuaweiVrpSshDriver())
registry.register(('huawei', 'olt', 'ftp_push'), HuaweiOltFtpReceivedDriver())
registry.register(('vsol', 'olt', 'ftp_push'), VsolOltFtpReceivedDriver())
registry.register(('vsol', 'olt', 'ssh_pull'), VsolOltSshDriver())
registry.register(('a10 networks', 'network', 'a10_system'), A10AcosDriver())

__all__ = ['registry']
