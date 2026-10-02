<?php

namespace App\Services\Replenishment;

use App\Enums\RiskLevel;
use App\Models\ForecastRun;
use App\Models\Recommendation;
use Illuminate\Support\Collection;

/**
 * What a round of generating recommendations did.
 */
final class GenerationResult
{
    /**
     * @param  ForecastRun|null  $run  The forecast it was based on; null if there was none yet
     * @param  int  $created  New recommendations opened
     * @param  int  $updated  Existing open recommendations refreshed
     * @param  int  $expired  Open recommendations that stopped applying
     * @param  int  $snoozed  Products left alone because their last recommendation was dismissed recently
     * @param  array<string, int>  $byRisk  Products assessed at each risk level, by the level's value
     * @param  Collection<int, Recommendation>  $critical  The open recommendations that are critical right now
     */
    public function __construct(
        public readonly ?ForecastRun $run,
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $expired = 0,
        public readonly int $snoozed = 0,
        public readonly array $byRisk = [],
        public readonly Collection $critical = new Collection,
    ) {}

    public function assessed(): int
    {
        return array_sum($this->byRisk);
    }

    public function count(RiskLevel $risk): int
    {
        return $this->byRisk[$risk->value] ?? 0;
    }
}
