<?php

namespace App\Console\Commands;

use App\Services\InstanceTimezone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;

class EngineSession extends Command
{
    protected $signature = 'engine:session';

    protected $description = 'Execute bounded local engine and receiver requests over stdin/stdout';

    private const COMMANDS = ['ftp:expected', 'ftp:accounts', 'ftp:receive', 'ftp:receipt', 'engine:claim', 'engine:complete', 'engine:fail', 'engine:observe-host-key', 'engine:cancel-ack', 'engine:wait'];

    private ?\PDO $listener = null;

    public function handle(): int
    {
        for ($count = 0; $count < 1000 && ($line = fgets(STDIN, 16386)) !== false; $count++) {
            if (strlen($line) > 16384 || ! str_ends_with($line, "\n")) {
                return self::FAILURE;
            }
            try {
                $request = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                $response = $this->dispatch($request);
            } catch (\Throwable $exception) {
                $response = ['ok' => false];
            }
            fwrite(STDOUT, json_encode($response, JSON_THROW_ON_ERROR)."\n");
            fflush(STDOUT);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{command: string, args: list<string>}  $request
     * @return array{ok: bool, output?: string}
     */
    public function dispatch(array $request): array
    {
        if (! in_array($request['command'] ?? null, self::COMMANDS, true) ||
            ! is_array($request['args'] ?? null) || ! array_is_list($request['args'])) {
            throw new \InvalidArgumentException('Invalid receiver request.');
        }
        foreach ($request['args'] as $argument) {
            if (! is_string($argument)) {
                throw new \InvalidArgumentException('Invalid receiver argument.');
            }
        }
        if ($request['command'] === 'engine:wait') {
            if (count($request['args']) !== 1 || ! ctype_digit($request['args'][0]) ||
                (int) $request['args'][0] < 1 || (int) $request['args'][0] > 5000) {
                throw new \InvalidArgumentException('Invalid wait timeout.');
            }
            $this->listen();
            $milliseconds = (int) $request['args'][0];
            if ($this->listener) {
                $this->listener->pgsqlGetNotify(\PDO::FETCH_ASSOC, $milliseconds);
            } else {
                usleep($milliseconds * 1000);
            }

            return ['ok' => true, 'output' => 'null'];
        }
        $command = Artisan::all()[$request['command']];
        $arguments = array_values(array_diff(array_keys($command->getDefinition()->getArguments()), ['command']));
        if (count($arguments) !== count($request['args'])) {
            throw new \InvalidArgumentException('Invalid receiver arguments.');
        }
        if ($request['command'] === 'engine:claim') {
            $this->listen();
            // Subscribe and drain old hints before reading the authoritative queue.
            for ($count = 0; $this->listener && $count < 32; $count++) {
                if ($this->listener->pgsqlGetNotify(\PDO::FETCH_ASSOC, 0) === false) {
                    break;
                }
            }
        }
        clearstatcache(true);
        app()->forgetInstance(InstanceTimezone::class);
        $output = new BufferedOutput;
        $code = Artisan::call($request['command'], array_combine($arguments, $request['args']), $output);

        return $code === 0 ? ['ok' => true, 'output' => $output->fetch()] : ['ok' => false];
    }

    private function listen(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            $this->listener = null;

            return;
        }
        $pdo = $connection->getPdo();
        if ($pdo !== $this->listener) {
            $pdo->exec('LISTEN backup_engine_queue');
            $this->listener = $pdo;
        }
    }
}
