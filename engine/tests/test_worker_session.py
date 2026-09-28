import subprocess
import threading
import unittest
from concurrent.futures import ThreadPoolExecutor
from contextlib import ExitStack
from unittest.mock import patch

import backup_engine


class WorkerSessionTest(unittest.TestCase):
    def test_worker_reuses_reporting_session_but_secret_uses_private_fd(self):
        observed = []

        def execute(job):
            backup_engine.command('engine:complete', job['id'], 'synthetic.cfg', 'worker')
            backup_engine.command('engine:secret', job['id'], 'worker',
                                  env={'ENGINE_SECRET_FD': '9'}, pass_fds=(9,))
            observed.append(backup_engine.WORKER_SESSION.session)

        with patch.object(backup_engine, 'execute', side_effect=execute), \
                patch.object(backup_engine, 'ArtisanSession') as factory, \
                patch.object(backup_engine.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, b'')) as run:
            with ExitStack() as stack, ThreadPoolExecutor(max_workers=1) as pool:
                lock = threading.Lock()
                for job_id in (1, 2):
                    pool.submit(backup_engine.execute_with_session, {'id': job_id}, stack, lock).result(timeout=2)
            factory.assert_called_once_with(backup_engine.ARTISAN)
            self.assertIs(observed[0], observed[1])
            self.assertEqual(2, observed[0].command.call_count)
            self.assertEqual(2, run.call_count)
            for call in run.call_args_list:
                self.assertEqual('engine:secret', call.args[0][-3])
                self.assertEqual((9,), call.kwargs['pass_fds'])
                self.assertEqual({'ENGINE_SECRET_FD': '9'}, call.kwargs['env'])
            factory.return_value.__exit__.assert_called_once()
