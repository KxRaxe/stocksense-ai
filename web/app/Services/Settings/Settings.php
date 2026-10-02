<?php

namespace App\Services\Settings;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settings an Owner can change, on top of the defaults in the config files.
 *
 * Only changes are stored. They are put onto the config at start-up, and again
 * before every queued job, so the rest of the application keeps reading
 * `config()` and a long-running worker notices a change without a restart. The
 * stored values are cached, and the cache is cleared whenever they change.
 */
class Settings
{
    public const CACHE_KEY = 'settings.stored';

    /** @var array<string, mixed> What the config files and environment say, taken before anything is overridden */
    private array $defaults = [];

    /** @var array<string, true> Settings whose config value is currently overridden */
    private array $applied = [];

    public function __construct()
    {
        foreach (SettingsSchema::definitions() as $key => $definition) {
            $this->defaults[$key] = $definition->cast(config($definition->config));
        }
    }

    /**
     * Puts the stored settings onto the config. Does nothing, quietly, before
     * the database is ready (a fresh install, or while migrating).
     */
    public function apply(): void
    {
        try {
            $stored = $this->stored();
        } catch (Throwable) {
            return;
        }

        foreach (SettingsSchema::definitions() as $key => $definition) {
            if (array_key_exists($key, $stored)) {
                config([$definition->config => $definition->cast($stored[$key])]);
                $this->applied[$key] = true;
            } elseif (isset($this->applied[$key])) {
                // Reset since last time: back to the default, and only for what this overrode.
                config([$definition->config => $this->defaults[$key]]);
                unset($this->applied[$key]);
            }
        }
    }

    /**
     * What each setting is now: the stored value, or the default.
     *
     * @return array<string, int|float|bool|string>
     */
    public function all(): array
    {
        $stored = $this->stored();
        $values = [];

        foreach (SettingsSchema::definitions() as $key => $definition) {
            $values[$key] = array_key_exists($key, $stored) ? $definition->cast($stored[$key]) : $this->defaults[$key];
        }

        return $values;
    }

    /**
     * @return array<string, int|float|bool|string>
     */
    public function defaults(): array
    {
        return $this->defaults;
    }

    /**
     * Whether any setting differs from its default.
     */
    public function hasChanges(): bool
    {
        return $this->stored() !== [];
    }

    /**
     * Saves the new values, then applies them. A value equal to the default is
     * not stored, so "restore defaults" and "set to the default" are the same.
     *
     * @param  array<string, mixed>  $values  Every setting, already validated
     * @return array<string, array{old: int|float|bool|string, new: int|float|bool|string}> What changed
     */
    public function update(array $values, User $by): array
    {
        $before = $this->all();
        $changes = [];

        DB::transaction(function () use ($values, $by, $before, &$changes) {
            foreach (SettingsSchema::definitions() as $key => $definition) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }

                $value = $definition->cast($values[$key]);

                if ($value === $this->defaults[$key]) {
                    DB::table('settings')->where('key', $key)->delete();
                } else {
                    DB::table('settings')->upsert(
                        [['key' => $key, 'value' => json_encode($value), 'updated_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]],
                        ['key'],
                        ['value', 'updated_by', 'updated_at'],
                    );
                }

                if ($before[$key] !== $value) {
                    $changes[$key] = ['old' => $before[$key], 'new' => $value];
                }
            }
        });

        $this->refresh();

        if ($changes !== []) {
            activity('settings')->causedBy($by)->withProperties(['changes' => $changes])->log('Changed the system settings');
        }

        return $changes;
    }

    /**
     * Puts every setting back to its default.
     */
    public function reset(User $by): void
    {
        $changes = [];
        $before = $this->all();

        foreach ($before as $key => $value) {
            if ($value !== $this->defaults[$key]) {
                $changes[$key] = ['old' => $value, 'new' => $this->defaults[$key]];
            }
        }

        DB::table('settings')->delete();
        $this->refresh();

        if ($changes !== []) {
            activity('settings')->causedBy($by)->withProperties(['changes' => $changes])->log('Restored the default system settings');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        /** @var array<string, mixed> */
        return Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('settings')->pluck('value', 'key')
            ->map(fn ($value) => json_decode((string) $value, true))
            ->all());
    }

    private function refresh(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->apply();
    }
}
