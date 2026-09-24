"""Central driver registry.

Resolves `(vendor, platform, method)` to a registered driver instance instead
of the if/elif-per-vendor dispatch that used to live inline in
`backup_engine.py::execute()`. Unknown vendor and unsupported
vendor+platform+method combinations raise the exact same `BackupError` codes
the engine already relied on (`UNSUPPORTED_VENDOR` / `UNSUPPORTED_POLICY`),
so callers and Laravel's error-message mapping do not need to change.
"""

from errors import BackupError, UNSUPPORTED_POLICY, UNSUPPORTED_VENDOR


class DriverRegistry:
    def __init__(self):
        self._drivers = {}

    def register(self, key, driver):
        self._drivers[self._normalize(key)] = driver
        return driver

    def resolve(self, vendor, platform, method):
        driver = self._drivers.get(self._normalize((vendor, platform, method)))
        if driver is not None:
            return driver
        if not self._vendor_known(vendor):
            raise BackupError(UNSUPPORTED_VENDOR)
        raise BackupError(UNSUPPORTED_POLICY)

    def capabilities(self, vendor, platform, method):
        return self.resolve(vendor, platform, method).capabilities

    def _vendor_known(self, vendor):
        vendor = (vendor or '').strip().casefold()
        return any(key[0] == vendor for key in self._drivers)

    @staticmethod
    def _normalize(key):
        vendor, platform, method = key
        return (
            (vendor or '').strip().casefold(),
            (platform or '').strip().casefold(),
            (method or '').strip().casefold(),
        )


registry = DriverRegistry()
