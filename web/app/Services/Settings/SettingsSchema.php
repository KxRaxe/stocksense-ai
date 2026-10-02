<?php

namespace App\Services\Settings;

/**
 * Everything an Owner can change, in the order the settings page shows it.
 * Each one overrides a value from the config files, which stay the defaults.
 */
final class SettingsSchema
{
    public const STOCK = 'Stock advice';

    public const FORECASTS = 'Forecasts';

    public const SCHEDULE = 'Schedule';

    /** Laravel's numbering: 0 is Sunday. */
    public const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        0 => 'Sunday',
    ];

    /**
     * @return array<string, SettingDefinition> Keyed by setting key
     */
    public static function definitions(): array
    {
        $definitions = [
            new SettingDefinition(
                'default_service_level', self::STOCK, 'Default service level',
                'The target for new categories: the share of the time a product should be in stock when someone wants it. Higher means more safety stock. Existing categories keep their own.',
                SettingDefinition::NUMBER, 'replenishment.default_service_level', 50, 99.9, '%',
            ),
            new SettingDefinition(
                'review_days', self::STOCK, 'Review period',
                'How often you place orders. Safety stock covers the lead time plus this gap, so ordering less often means holding more.',
                SettingDefinition::INTEGER, 'replenishment.review_days', 1, 60, 'days',
            ),
            new SettingDefinition(
                'overstock_days', self::STOCK, 'Overstock threshold',
                'A product with more than this many days of sales on hand is flagged as overstocked.',
                SettingDefinition::INTEGER, 'replenishment.overstock_days', 14, 730, 'days',
            ),
            new SettingDefinition(
                'snooze_days', self::STOCK, 'Dismissed advice stays hidden for',
                'After a recommendation is dismissed, the product is left out of the recommendations for this long.',
                SettingDefinition::INTEGER, 'replenishment.snooze_days', 1, 60, 'days',
            ),
            new SettingDefinition(
                'stale_forecast_days', self::STOCK, 'Warn when the forecast is older than',
                'The recommendations page and the digest email warn that the advice may be out of date.',
                SettingDefinition::INTEGER, 'replenishment.stale_forecast_days', 3, 90, 'days',
            ),

            new SettingDefinition(
                'horizon_week', self::FORECASTS, 'Weekly forecast looks ahead',
                'How many weeks a weekly forecast covers.',
                SettingDefinition::INTEGER, 'forecasting.horizons.week', 1, 26, 'weeks',
            ),
            new SettingDefinition(
                'horizon_month', self::FORECASTS, 'Monthly forecast looks ahead',
                'How many months a monthly forecast covers.',
                SettingDefinition::INTEGER, 'forecasting.horizons.month', 1, 12, 'months',
            ),
            new SettingDefinition(
                'forecast_schedule', self::SCHEDULE, 'Refresh forecasts automatically',
                'Retrain the forecasting model and make new forecasts on the schedule below. Forecasts can always be started by hand.',
                SettingDefinition::BOOLEAN, 'forecasting.schedule.enabled',
            ),
            new SettingDefinition(
                'forecast_weekly_day', self::SCHEDULE, 'Weekly forecast runs on',
                'The day the weekly forecast is refreshed.',
                SettingDefinition::CHOICE, 'forecasting.schedule.weekly.day', choices: self::WEEKDAYS,
            ),
            new SettingDefinition(
                'forecast_weekly_time', self::SCHEDULE, 'Weekly forecast runs at',
                'Time of day, in the shop\'s time zone (Asia/Manila).',
                SettingDefinition::TIME, 'forecasting.schedule.weekly.time',
            ),
            new SettingDefinition(
                'forecast_monthly_day', self::SCHEDULE, 'Monthly forecast runs on day',
                'The day of the month the monthly forecast is refreshed (1 to 28, so every month has it).',
                SettingDefinition::INTEGER, 'forecasting.schedule.monthly.day', 1, 28,
            ),
            new SettingDefinition(
                'forecast_monthly_time', self::SCHEDULE, 'Monthly forecast runs at',
                'Time of day, in the shop\'s time zone.',
                SettingDefinition::TIME, 'forecasting.schedule.monthly.time',
            ),
            new SettingDefinition(
                'advice_schedule', self::SCHEDULE, 'Refresh advice and send digests automatically',
                'Recalculate the recommendations every day and send the replenishment digest. Both can always be done by hand.',
                SettingDefinition::BOOLEAN, 'replenishment.schedule.enabled',
            ),
            new SettingDefinition(
                'advice_time', self::SCHEDULE, 'Recommendations are refreshed at',
                'Time of day, in the shop\'s time zone.',
                SettingDefinition::TIME, 'replenishment.schedule.generate',
            ),
            new SettingDefinition(
                'digest_time', self::SCHEDULE, 'Digest emails are sent at',
                'Must be after the recommendations are refreshed, so the digest is up to date.',
                SettingDefinition::TIME, 'replenishment.schedule.digest',
            ),
        ];

        $keyed = [];

        foreach ($definitions as $definition) {
            $keyed[$definition->key] = $definition;
        }

        return $keyed;
    }
}
