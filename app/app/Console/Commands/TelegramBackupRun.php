<?php

namespace App\Console\Commands;

use App\Services\TelegramBackupCopy;
use Illuminate\Console\Command;

class TelegramBackupRun extends Command
{
    protected $signature = 'telegram-backup:run';

    protected $description = 'Enfileira os backups novos e envia as cópias pendentes ao Telegram';

    public function handle(TelegramBackupCopy $copy): int
    {
        $this->line(json_encode($copy->run()));

        return self::SUCCESS;
    }
}
