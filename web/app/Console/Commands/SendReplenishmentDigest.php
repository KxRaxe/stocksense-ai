<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendReplenishmentDigest extends Command
{
    protected $signature = 'notifications:digest
        {--weekly : Send to those who chose a weekly digest as well, whatever day it is}';

    protected $description = 'Send the replenishment digest to those who want it: daily subscribers every day, weekly subscribers on Mondays';

    public function handle(NotificationDispatcher $notifications): int
    {
        $today = CarbonImmutable::today();
        $frequencies = ['daily'];

        if ($this->option('weekly') || $today->isMonday()) {
            $frequencies[] = 'weekly';
        }

        $sent = $notifications->digest($frequencies, $today);

        $this->components->info($sent === 0
            ? 'Nothing to send: nothing needs ordering, or nobody wants a digest today.'
            : "Digest sent to {$sent} ".($sent === 1 ? 'person' : 'people').'.');

        return self::SUCCESS;
    }
}
