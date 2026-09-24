"""Common contract every backup driver implements.

A driver only implements the capabilities it actually supports (declared in
`capabilities`); the registry/dispatcher never calls a method a driver didn't
declare. See docs/ENGINE_DRIVERS.md for the full rationale and how to add a
new driver.
"""

from abc import ABC

from errors import BackupError

PROBE = 'probe'
BACKUP = 'backup'
ANALYZE = 'analyze'
RECEIVED_PAYLOAD = 'received_payload'
RESTORE_FUTURE = 'restore_future'


class DriverAuthError(BackupError):
    """Credentials were rejected by the remote device."""


class DriverTimeoutError(BackupError):
    """The device did not respond within the configured timeout."""


class DriverCommandError(BackupError):
    """The device rejected or failed to execute the requested command."""


class DriverProtocolError(BackupError):
    """Transport-level negotiation or protocol handling failed."""


class DriverValidationError(BackupError):
    """The received payload failed integrity/content validation."""


class BackupDriver(ABC):
    name = 'unknown'
    vendor = 'unknown'
    transport = 'unknown'
    capabilities = frozenset()

    def probe(self, context):
        """Read-only connectivity/identity check. Must never change device
        configuration. Returns a `results.ProbeResult`."""
        raise NotImplementedError(f'{self.name} does not support probe()')

    def backup(self, context, secret=None):
        """Perform the actual backup. Returns a `results.BackupResult`."""
        raise NotImplementedError(f'{self.name} does not support backup()')

    def analyze(self, payload, context=None):
        """Best-effort, informational content analysis. Must never be used
        to decide whether a transport-valid backup is retained — see
        docs/ENGINE_DRIVERS.md ("backup first, parser later"). Returns a
        `results.AnalysisResult`."""
        raise NotImplementedError(f'{self.name} does not support analyze()')
