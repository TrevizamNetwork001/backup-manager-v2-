"""Bounded, sequential local Artisan transport; business rules stay in PHP."""
import json
import os
import select
import subprocess
import time
import threading

class ArtisanTransportError(RuntimeError):
    """Unavailable control plane; preserve the receiver sidecar for retry."""


class ThreadSessions:
    """One independent transport per caller thread, closed after workers join."""
    def __init__(self, artisan, env=None):
        self.artisan = artisan
        self.env = env
        self.local = threading.local()
        self.sessions = []
        self.lock = threading.Lock()

    def __enter__(self):
        return self

    def command(self, *args):
        if not hasattr(self.local, 'session'):
            with self.lock:
                self.local.session = ArtisanSession(self.artisan, env=self.env)
                self.sessions.append(self.local.session)
        return self.local.session.command(*args)

    def close(self):
        for session in self.sessions:
            session.close()

    def __exit__(self, *args):
        self.close()


class ArtisanSession:
    def __init__(self, artisan, timeout=45, env=None):
        self.artisan = artisan
        self.timeout = timeout
        self.env = env
        self.process = None
        self.buffer = b''
        self.requests = 0

    def __enter__(self):
        return self

    def close(self):
        if self.process is not None:
            self.process.stdin.close()
            try:
                self.process.wait(timeout=1)
            except subprocess.TimeoutExpired:
                self.process.kill()
                self.process.wait()
            self.process.stdout.close()
            self.process = None
        self.buffer = b''

    def __exit__(self, *args):
        self.close()

    def command(self, *args):
        if self.requests >= 1000:
            self.close()
            self.requests = 0
        try:
            if self.process is None:
                self.process = subprocess.Popen(self.artisan + ['engine:session'],
                    stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, env=self.env)
            request = json.dumps({'command': str(args[0]), 'args': list(map(str, args[1:]))}).encode() + b'\n'
            if len(request) > 16384:
                raise ValueError('Receiver request too large')
            self.process.stdin.write(request)
            self.process.stdin.flush()
            self.requests += 1
            deadline = time.monotonic() + self.timeout
            while b'\n' not in self.buffer:
                remaining = deadline - time.monotonic()
                if remaining <= 0 or not select.select([self.process.stdout], [], [], remaining)[0]:
                    raise TimeoutError()
                block = os.read(self.process.stdout.fileno(), 65536)
                if not block:
                    raise EOFError()
                self.buffer += block
                if len(self.buffer) > 16 * 1024 * 1024:
                    raise ValueError('Receiver response too large')
            line, self.buffer = self.buffer.split(b'\n', 1)
            response = json.loads(line)
            if response.get('ok') is not True or not isinstance(response.get('output'), str):
                raise ArtisanTransportError('ENGINE_FAILED')
            return response['output'].encode()
        except ArtisanTransportError:
            # A rejected request does not poison the session or replay a commit.
            raise
        except Exception as error:
            self.close()
            # Never replay: completion may have committed before transport failed.
            raise ArtisanTransportError('ENGINE_FAILED') from error
