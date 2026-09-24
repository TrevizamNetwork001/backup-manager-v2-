"""Structured results every driver returns instead of raw bytes/exceptions
crossing module boundaries. See docs/ENGINE_DRIVERS.md.

`to_dict()` on each result is used for structured logging only — it never
includes `payload` (the raw backup content) or any secret.
"""

from dataclasses import dataclass, field
from typing import Optional


@dataclass
class AnalysisResult:
    status: str  # 'recognized' | 'warning' | 'unknown' — informational only,
    # never a reason to reject a transport-valid backup (see docs).
    vendor: Optional[str] = None
    platform: Optional[str] = None
    model: Optional[str] = None
    version: Optional[str] = None
    hostname: Optional[str] = None
    warnings: list = field(default_factory=list)
    metadata: dict = field(default_factory=dict)

    def to_dict(self):
        return {
            'status': self.status, 'vendor': self.vendor, 'platform': self.platform,
            'model': self.model, 'version': self.version, 'hostname': self.hostname,
            'warnings': list(self.warnings), 'metadata': dict(self.metadata),
        }


@dataclass
class ProbeResult:
    success: bool
    code: str = 'ok'
    message: str = ''
    latency_ms: Optional[float] = None
    remote_banner: Optional[str] = None
    metadata: dict = field(default_factory=dict)

    def to_dict(self):
        return {
            'success': self.success, 'code': self.code, 'message': self.message,
            'latency_ms': self.latency_ms, 'remote_banner': self.remote_banner,
            'metadata': dict(self.metadata),
        }


@dataclass
class BackupResult:
    success: bool
    code: str = 'ok'
    message: str = ''
    transport: Optional[str] = None
    payload: Optional[bytes] = None
    size_bytes: Optional[int] = None
    sha256: Optional[str] = None
    filename_hint: Optional[str] = None
    warnings: list = field(default_factory=list)
    metadata: dict = field(default_factory=dict)
    analysis: Optional[AnalysisResult] = None
    retryable: bool = False

    def to_dict(self):
        return {
            'success': self.success, 'code': self.code, 'message': self.message,
            'transport': self.transport, 'size_bytes': self.size_bytes, 'sha256': self.sha256,
            'filename_hint': self.filename_hint, 'warnings': list(self.warnings),
            'metadata': dict(self.metadata),
            'analysis': self.analysis.to_dict() if self.analysis else None,
            'retryable': self.retryable,
        }
