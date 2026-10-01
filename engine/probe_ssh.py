"""One-shot SSH credential probe called by the web app over stdin/stdout."""

import json
import sys

from contexts import ProbeContext
from driver_base import PROBE
from errors import BackupError
from drivers.huawei_olt_ssh_probe import probe_authentication as probe_huawei_olt_authentication
from registry_setup import registry


def main():
    observed = {}
    try:
        data = json.load(sys.stdin)
        def observe(algorithm, fingerprint):
            observed.update(algorithm=algorithm, fingerprint=fingerprint)

        context = ProbeContext(
            device_id=int(data['device_id']), host=data['host'], port=int(data['port']),
            username=data['username'], vendor=data['vendor'], platform=data['platform'],
            ssh_host_key_algorithm=data.get('ssh_host_key_algorithm'),
            ssh_host_key_fingerprint=data.get('ssh_host_key_fingerprint'),
            metadata={'__secret': data['secret'], '__observe': observe},
        )
        if data['vendor'].strip().casefold() == 'huawei' and data['platform'].strip().casefold() == 'olt':
            # OLT backups remain FTP push; this checks SSH access only.
            result = probe_huawei_olt_authentication(context)
        else:
            driver = registry.resolve(data['vendor'].strip().casefold(), data['platform'], 'ssh_pull')
            if PROBE not in driver.capabilities:
                raise ValueError('unsupported_driver')
            result = driver.probe(context)
        output = {'success': result.success, 'code': result.code,
                  'latency_ms': result.latency_ms, 'observed': observed}
    except BackupError as error:
        output = {'success': False, 'code': error.code, 'observed': observed}
    except Exception:
        output = {'success': False, 'code': 'ENGINE_FAILED', 'observed': observed}
    sys.stdout.write(json.dumps(output))


if __name__ == '__main__':
    main()
