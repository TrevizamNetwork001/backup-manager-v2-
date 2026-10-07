<?php

namespace App\Console\Commands;

use App\Services\NotificationManager;
use Illuminate\Console\Command;

class NotificationsRun extends Command
{
    protected $signature = 'notifications:run';

    protected $description = 'Evaluate alert conditions and deliver queued Telegram notifications';

    public function handle(NotificationManager $manager): int
    {
        $result = $manager->run();
        $this->line(json_encode($result));

        return self::SUCCESS;
    }
}
