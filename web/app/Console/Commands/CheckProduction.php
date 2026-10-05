<?php

namespace App\Console\Commands;

use App\Services\Security\ProductionCheck;
use Illuminate\Console\Command;

class CheckProduction extends Command
{
    protected $signature = 'app:check
        {--strict : Exit with an error when anything must be fixed, for use at start-up}';

    protected $description = 'Check the configuration for what must not go to production unchanged';

    public function handle(ProductionCheck $check): int
    {
        $results = $check->run();

        foreach ($results as $result) {
            $mark = match ($result['status']) {
                ProductionCheck::PASS => '<fg=green>PASS</>',
                ProductionCheck::WARN => '<fg=yellow>WARN</>',
                default => '<fg=red>FAIL</>',
            };

            $this->line("  {$mark}  {$result['check']}");

            if ($result['advice'] !== '') {
                $this->line("        {$result['advice']}");
            }
        }

        $failures = collect($results)->where('status', ProductionCheck::FAIL)->count();
        $warnings = collect($results)->where('status', ProductionCheck::WARN)->count();

        $this->newLine();
        $this->line($failures === 0
            ? "No failures, {$warnings} ".($warnings === 1 ? 'warning' : 'warnings').'.'
            : "{$failures} must be fixed before this goes to production ({$warnings} ".($warnings === 1 ? 'warning' : 'warnings').' besides).');

        return $failures > 0 && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
