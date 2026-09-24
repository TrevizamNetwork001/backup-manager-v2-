"""Standardized context objects passed into driver methods.

Deliberately carry only what a driver needs to do its job — never the
resolved secret (passed as a short-lived explicit argument, `del`-eted right
after use, exactly like the existing `execute()`/`export_config()` code
already does) and never anything that would be unsafe to log via repr().
"""

from dataclasses import dataclass, field
from typing import Optional


@dataclass
class ProbeContext:
    device_id: int
    host: str
    port: int
    username: str
    vendor: str
    platform: Optional[str] = None
    timeout: int = 10
    ssh_host_key_algorithm: Optional[str] = None
    ssh_host_key_fingerprint: Optional[str] = None
    metadata: dict = field(default_factory=dict)


@dataclass
class BackupContext:
    execution_id: int
    device_id: int
    host: str
    port: int
    username: str
    vendor: str
    platform: Optional[str] = None
    method: Optional[str] = None
    policy_id: Optional[int] = None
    timeout: int = 30
    ssh_host_key_algorithm: Optional[str] = None
    ssh_host_key_fingerprint: Optional[str] = None
    temp_dir: Optional[str] = None
    metadata: dict = field(default_factory=dict)
