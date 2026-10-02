<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateSystemSettingsRequest;
use App\Services\Settings\Settings;
use App\Services\Settings\SettingsSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop-wide settings: how stock advice is worked out and when things run.
 * Owner only (`settings.manage`, see routes/web.php). Not to be confused with
 * the settings every person has for their own account.
 */
class SystemSettingsController extends Controller
{
    public function __construct(private readonly Settings $settings) {}

    public function edit(): Response
    {
        $values = $this->settings->all();
        $defaults = $this->settings->defaults();
        $groups = [];

        foreach (SettingsSchema::definitions() as $key => $definition) {
            $groups[$definition->group][] = [
                'key' => $key,
                'label' => $definition->label,
                'help' => $definition->help,
                'type' => $definition->type,
                'unit' => $definition->unit,
                'min' => $definition->min,
                'max' => $definition->max,
                'choices' => array_map(
                    fn (int|string $value, string $label) => ['value' => $value, 'label' => $label],
                    array_keys($definition->choices),
                    array_values($definition->choices),
                ),
                'value' => $values[$key],
                'default' => $defaults[$key],
                'is_default' => $values[$key] === $defaults[$key],
            ];
        }

        return Inertia::render('system-settings/edit', [
            'groups' => array_map(fn (string $name, array $settings) => ['name' => $name, 'settings' => $settings], array_keys($groups), array_values($groups)),
            'has_changes' => $this->settings->hasChanges(),
            'time_zone' => (string) config('app.timezone'),
        ]);
    }

    public function update(UpdateSystemSettingsRequest $request): RedirectResponse
    {
        /** @var array<string, mixed> $values */
        $values = $request->validated('values');

        $changes = $this->settings->update($values, $request->user());

        Inertia::flash('toast', $changes === []
            ? ['type' => 'info', 'message' => 'Nothing was changed.']
            : ['type' => 'success', 'message' => 'Settings saved. They apply from now on.']);

        return back();
    }

    public function reset(Request $request): RedirectResponse
    {
        $this->settings->reset($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'The default settings are back.']);

        return back();
    }
}
