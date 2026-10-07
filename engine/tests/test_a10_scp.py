import io
import os
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from a10_scp import ScpRejected, receive_archive, requested_filename


NAME = 'CGNAT-A10_20261002110000-exec-177.tar.gz'


class ScpChannel:
    def __init__(self, content):
        self.input = io.BytesIO(content)
        self.output = bytearray()

    def read(self, size):
        return self.input.read(min(size, 3))

    def write(self, content):
        self.output.extend(content)


class A10ScpTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        os.chmod(self.directory.name, 0o700)

    def tearDown(self):
        self.directory.cleanup()

    def channel(self, payload=b'archive'):
        return ScpChannel(f'C0600 {len(payload)} {NAME}\n'.encode() + payload + b'\0')

    def test_single_archive_is_published_after_complete_transfer(self):
        channel = self.channel()
        target = receive_archive(channel, self.directory.name, NAME)
        self.assertEqual(b'archive', target.read_bytes())
        self.assertEqual(0o600, target.stat().st_mode & 0o777)
        self.assertEqual(b'\0\0\0', channel.output)
        self.assertEqual(NAME, requested_filename(f'scp -t /{NAME}'))

    def test_incomplete_or_wrong_transfer_does_not_publish(self):
        for content in [f'C0600 7 {NAME}\nabc'.encode(),
                        f'C0600 3 WRONG.tar.gz\nabc\0'.encode(),
                        f'C0600 3 {NAME}\nabcX'.encode(),
                        b'C0600 3 ' + NAME.encode() + b'\xff\nabc\0',
                        f'C0755 3 {NAME}\nabc\0'.encode(),
                        f'C0600 67108865 {NAME}\n'.encode(),
                        f'D0700 0 {NAME}\n'.encode()]:
            with self.subTest(content=content[:35]), self.assertRaises(ScpRejected):
                receive_archive(ScpChannel(content), self.directory.name, NAME)
            self.assertFalse((Path(self.directory.name) / NAME).exists())
            self.assertEqual([], list(Path(self.directory.name).iterdir()))

    def test_rejects_duplicate_and_unsafe_command(self):
        receive_archive(self.channel(), self.directory.name, NAME)
        with self.assertRaises(ScpRejected):
            receive_archive(self.channel(b'other'), self.directory.name, NAME)
        self.assertEqual(b'archive', (Path(self.directory.name) / NAME).read_bytes())
        for command in [f'scp -t ../{NAME}', f'scp -t /tmp/{NAME}',
                        f'scp -t /{NAME}; id', f'scp -r -t /{NAME}']:
            with self.subTest(command=command), self.assertRaises(ScpRejected):
                requested_filename(command)

    def test_rejects_public_or_symlink_inbox(self):
        os.chmod(self.directory.name, 0o755)
        with self.assertRaises(ScpRejected):
            receive_archive(self.channel(), self.directory.name, NAME)
        os.chmod(self.directory.name, 0o700)
        link = Path(self.directory.name) / 'link'
        link.symlink_to(self.directory.name)
        with self.assertRaises(ScpRejected):
            receive_archive(self.channel(), link, NAME)
