<?php

namespace App\Console\Commands;

use App\Services\InstanceTimezone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

class EngineSession extends Command
{
    protected $signature = 'engine:session';

    protected $description = 'Execute bounded local engine and receiver requests over stdin/stdout';

    private const COMMANDS = ['ftp:expected', 'ftp:accounts', 'ftp:receive', 'ftp:receipt', 'engine:claim', 'engine:complete', 'engine:fail'];

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
        $command = Artisan::all()[$request['command']];
        $arguments = array_values(array_diff(array_keys($command->getDefinition()->getArguments()), ['command']));
        if (count($arguments) !== count($request['args'])) {
            throw new \InvalidArgumentException('Invalid receiver arguments.');
        }
        foreach ($request['args'] as $argument) {
            if (! is_string($argument)) {
                throw new \InvalidArgumentException('Invalid receiver argument.');
            }
        }
        clearstatcache(true);
        app()->forgetInstance(InstanceTimezone::class);
        $output = new BufferedOutput;
        $code = Artisan::call($request['command'], array_combine($arguments, $request['args']), $output);

        return $code === 0 ? ['ok' => true, 'output' => $output->fetch()] : ['ok' => false];
    }
}
