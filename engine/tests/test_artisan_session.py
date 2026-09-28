import json
import sys
import unittest
import threading
from concurrent.futures import ThreadPoolExecutor

from artisan_session import ArtisanSession, ArtisanTransportError, ThreadSessions


class ArtisanSessionTest(unittest.TestCase):
    def test_thread_sessions_isolate_children_and_close_them_after_join(self):
        code = "import sys,json,os\nfor line in sys.stdin:\n print(json.dumps({'ok':True,'output':str(os.getpid())}),flush=True)"
        barrier = threading.Barrier(2)
        with ThreadSessions([sys.executable, '-u', '-c', code]) as sessions:
            def request(_):
                barrier.wait(timeout=2)
                first = sessions.command('ftp:accounts')
                return first, sessions.command('ftp:accounts')
            with ThreadPoolExecutor(max_workers=2) as pool:
                results = list(pool.map(request, range(2)))
            children = [session.process for session in sessions.sessions]
            self.assertEqual(2, len(children))
            self.assertTrue(all(first == second for first, second in results))
            self.assertNotEqual(results[0][0], results[1][0])
        self.assertTrue(all(child.poll() is not None for child in children))

    def session(self, code, timeout=2):
        # The trailing engine:session argument is ignored by this test child.
        return ArtisanSession([sys.executable, '-u', '-c', code], timeout=timeout)

    def test_reuses_one_child_and_preserves_argument_boundaries(self):
        code = "import sys,json\nfor line in sys.stdin:\n r=json.loads(line);print(json.dumps({'ok':True,'output':json.dumps(r['args'])}),flush=True)"
        with self.session(code) as session:
            args = ['a space', '--option', 'quote\"', 'line\nbreak']
            self.assertEqual(args, json.loads(session.command('ftp:receipt', *args)))
            process = session.process
            self.assertEqual([], json.loads(session.command('ftp:accounts')))
            self.assertIs(process, session.process)
        self.assertIsNotNone(process.poll())

    def test_request_error_does_not_replay_or_poison_next_request(self):
        code = "import sys,json\nfor i,line in enumerate(sys.stdin):\n print(json.dumps({'ok':False} if i==0 else {'ok':True,'output':'fresh'}),flush=True)"
        with self.session(code) as session:
            with self.assertRaises(ArtisanTransportError):
                session.command('engine:complete', 1)
            process = session.process
            self.assertEqual(b'fresh', session.command('ftp:accounts'))
            self.assertIs(process, session.process)
            self.assertEqual(2, session.requests)

    def test_timeout_kills_child_without_replaying(self):
        with self.session('import time;time.sleep(10)', timeout=.05) as session:
            with self.assertRaises(ArtisanTransportError):
                session.command('ftp:accounts')
            self.assertIsNone(session.process)
            self.assertEqual(1, session.requests)

    def test_eof_and_malformed_response_fail_closed(self):
        for code in ['pass', "print('not json',flush=True)", "print('{}',flush=True)"]:
            with self.subTest(code=code), self.session(code) as session:
                with self.assertRaises(ArtisanTransportError):
                    session.command('ftp:accounts')

    def test_session_recycles_after_bounded_number_of_requests(self):
        code = "import sys,json\nfor line in sys.stdin:\n print(json.dumps({'ok':True,'output':'ok'}),flush=True)"
        with self.session(code) as session:
            session.command('ftp:accounts')
            first = session.process
            session.requests = 1000
            session.command('ftp:accounts')
            self.assertIsNot(first, session.process)
            self.assertIsNotNone(first.poll())
            self.assertEqual(1, session.requests)
