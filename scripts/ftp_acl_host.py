#!/usr/bin/env python3
"""Apply the panel's IPv4 FTP source list to Docker's forwarding path."""

import fcntl
import ipaddress
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path


PROJECT = Path('/opt/backup-manager-v2')
CONFIG = Path('/etc/backup-manager-v2/ftp-acl.json')
LOCK = Path('/run/backup-manager-ftp-acl.lock')
INTERFACE = 'ens192'
DESTINATION = '45.239.157.250'
PORTS = '21,30000:30009'
CHAINS = ('BM_FTP_A', 'BM_FTP_B')


def command(*args, timeout=20):
    return subprocess.run(args, check=True, capture_output=True, text=True, timeout=timeout).stdout


def validate(data):
    cidrs = data.get('cidrs')
    revision = data.get('revision')
    if not isinstance(cidrs, list) or not 1 <= len(cidrs) <= 32 or not isinstance(revision, int) or revision < 0:
        raise ValueError('invalid_acl')
    networks = []
    for item in cidrs:
        if not isinstance(item, str):
            raise ValueError('invalid_acl')
        network = ipaddress.ip_network(item, strict=True)
        if network.version != 4 or network.prefixlen < 8:
            raise ValueError('invalid_acl')
        networks.append(str(network))
    return {'cidrs': list(dict.fromkeys(networks)), 'revision': revision}


def requested():
    try:
        output = command('docker', 'compose', 'exec', '-T', 'app', 'php', 'artisan', 'ftp:acl-export', timeout=25)
        panel = validate(json.loads(output))
        if panel['revision'] > 0:
            return panel, True
    except (subprocess.SubprocessError, ValueError, KeyError, json.JSONDecodeError):
        pass
    return validate(json.loads(CONFIG.read_text())), False


def iptables(*args):
    return command('/usr/sbin/iptables', '-w', '5', *args)


def apply(cidrs):
    existing = iptables('-S', 'DOCKER-USER')
    active = next((name for name in CHAINS if f'-A DOCKER-USER -j {name}' in existing), None)
    replacement = CHAINS[1] if active == CHAINS[0] else CHAINS[0]
    for name in CHAINS:
        try:
            iptables('-N', name)
        except subprocess.CalledProcessError:
            pass
    iptables('-F', replacement)
    match = ('-i', INTERFACE, '-p', 'tcp', '-m', 'multiport', '--dports', PORTS,
             '-m', 'conntrack', '--ctorigdst', DESTINATION)
    for cidr in cidrs:
        iptables('-A', replacement, *match, '-s', cidr, '-j', 'RETURN')
    iptables('-A', replacement, *match, '-j', 'REJECT')
    iptables('-I', 'DOCKER-USER', '1', '-j', replacement)
    if active:
        iptables('-D', 'DOCKER-USER', '-j', active)
        iptables('-F', active)


def persist(data):
    CONFIG.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix='.ftp-acl-', dir=CONFIG.parent)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, 'w') as target:
            json.dump(data, target, separators=(',', ':'))
            target.flush()
            os.fsync(target.fileno())
        os.replace(temporary, CONFIG)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def main():
    if os.geteuid() != 0:
        raise SystemExit('root_required')
    with LOCK.open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        os.chdir(PROJECT)
        data, from_panel = requested()
        apply(data['cidrs'])
        if from_panel:
            persist(data)
            command('docker', 'compose', 'exec', '-T', 'app', 'php', 'artisan', 'ftp:acl-applied',
                    str(data['revision']), timeout=25)
        print(f"ftp_acl_applied revision={data['revision']} networks={len(data['cidrs'])}")


if __name__ == '__main__':
    main()
