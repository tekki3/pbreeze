<?php
declare(strict_types=1);

use PBreeze\Application;
use PBreeze\Scheduler\Schedule;

return static function (Schedule $schedule, Application $app): void {
    // Register trusted handlers explicitly; never put executable PHP in queue records.
    // $schedule->command('mail:work', ['50'])->everyMinute()->withoutOverlapping();
};
