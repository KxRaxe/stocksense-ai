<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Replenishment\RecommendationDecisions;
use App\Services\Reporting\AuditPresenter;
use App\Services\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(testToday()->setTime(8, 0));

    $this->owner = User::factory()->owner()->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->manager()->create(['name' => 'Pat Manager']);

    Activity::query()->delete();
});

/**
 * Writes an entry as the app would.
 *
 * @param  array<string, mixed>  $properties
 */
function logged(string $area, string $description, ?User $by = null, array $properties = [], ?Model $on = null, ?string $at = null): Activity
{
    $activity = activity($area)->causedBy($by)->withProperties($properties);

    if ($on !== null) {
        $activity->performedOn($on);
    }

    $entry = $activity->log($description);

    if ($at !== null) {
        $entry->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    return $entry->fresh();
}

describe('who may see it', function () {
    it('sends guests to log in', function () {
        $this->get(route('audit-log.index'))->assertRedirect(route('login'));
    });

    it('is for the Owner only', function () {
        $this->actingAs($this->owner)->get(route('audit-log.index'))->assertOk();
        $this->actingAs($this->manager)->get(route('audit-log.index'))->assertForbidden();
        $this->actingAs(User::factory()->inventoryStaff()->create())->get(route('audit-log.index'))->assertForbidden();
    });

    it('can only be looked at: there is nothing to change or delete an entry with', function () {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains((string) $route->getName(), 'audit'));

        expect($routes->map(fn ($route) => [$route->methods()[0], $route->getName()])->values()->all())->toBe([['GET', 'audit-log.index']]);
    });
});

describe('the list', function () {
    it('shows the newest first, with who, what and when', function () {
        logged('sales', 'Sale deleted', $this->manager, [], at: '2026-10-01 09:00:00');
        logged('forecasts', 'Weekly forecast started', null, ['scheduled' => true], at: '2026-10-03 02:00:00');

        $this->actingAs($this->owner)->get(route('audit-log.index'))
            ->assertInertia(fn ($page) => $page
                ->component('audit-log/index')
                ->where('entries.total', 2)
                ->where('entries.data.0', fn ($entry) => $entry['description'] === 'Weekly forecast started'
                    && $entry['who'] === null
                    && $entry['area'] === 'forecasts'
                    && $entry['area_label'] === 'Forecasts'
                    && $entry['subject'] === null
                    && str_starts_with($entry['created_at'], '2026-10-03'))
                ->where('entries.data.1', fn ($entry) => $entry['description'] === 'Sale deleted' && $entry['who'] === 'Pat Manager' && $entry['area_label'] === 'Sales'));
    });

    it('breaks ties with the newest entry first', function () {
        foreach (['First', 'Second', 'Third'] as $description) {
            logged('sales', $description, $this->owner, at: '2026-10-01 09:00:00');
        }

        $this->actingAs($this->owner)->get(route('audit-log.index'))
            ->assertInertia(fn ($page) => $page->where('entries.data', fn ($entries) => $entries->pluck('description')->all() === ['Third', 'Second', 'First']));
    });

    it('pages through a long log', function () {
        foreach (range(1, 30) as $n) {
            logged('sales', "Entry {$n}", $this->owner);
        }

        $this->actingAs($this->owner)->get(route('audit-log.index'))
            ->assertInertia(fn ($page) => $page->has('entries.data', 25)->where('entries.total', 30)->where('entries.last_page', 2));

        $this->actingAs($this->owner)->get(route('audit-log.index', ['page' => 2]))
            ->assertInertia(fn ($page) => $page->has('entries.data', 5));
    });

    it('offers the areas that have entries, with names', function () {
        logged('sales', 'a', $this->owner);
        logged('settings', 'b', $this->owner);
        logged('settings', 'c', $this->owner);
        logged('mystery', 'd', $this->owner);

        $this->actingAs($this->owner)->get(route('audit-log.index'))
            ->assertInertia(fn ($page) => $page->where('areas', [
                ['value' => 'mystery', 'label' => 'Mystery'],
                ['value' => 'sales', 'label' => 'Sales'],
                ['value' => 'settings', 'label' => 'System settings'],
            ]));
    });

    it('offers only people who have done something', function () {
        User::factory()->inventoryStaff()->create(['name' => 'Quiet Staff']);
        logged('sales', 'a', $this->manager);
        logged('sales', 'b', $this->owner);
        logged('forecasts', 'by the system', null);

        $this->actingAs($this->owner)->get(route('audit-log.index'))
            ->assertInertia(fn ($page) => $page->where('users', fn ($users) => $users->pluck('name')->all() === ['Olive Owner', 'Pat Manager']));
    });
});

describe('filters', function () {
    beforeEach(function () {
        logged('sales', 'Sale deleted', $this->manager, at: '2026-09-10 10:00:00');
        logged('settings', 'Changed the system settings', $this->owner, at: '2026-09-20 10:00:00');
        logged('reports', 'Exported the sales report as an Excel file', $this->owner, at: '2026-10-01 10:00:00');
        logged('forecasts', 'Weekly forecast started', null, at: '2026-10-02 02:00:00');
    });

    $descriptions = fn ($page) => $page->where('entries.data', fn ($entries) => $entries->pluck('description')->all());

    it('narrows to an area', function () {
        $this->actingAs($this->owner)->get(route('audit-log.index', ['area' => 'settings']))
            ->assertInertia(fn ($page) => $page->where('filters.area', 'settings')->where('entries.total', 1)->where('entries.data.0.description', 'Changed the system settings'));
    });

    it('narrows to a person', function () {
        $this->actingAs($this->owner)->get(route('audit-log.index', ['user' => $this->owner->id]))
            ->assertInertia(fn ($page) => $page->where('entries.total', 2)->where('filters.user', $this->owner->id));
    });

    it('narrows to a period, including the first and last day', function () {
        $this->actingAs($this->owner)->get(route('audit-log.index', ['from' => '2026-09-20', 'to' => '2026-10-01']))
            ->assertInertia(fn ($page) => $page->where('entries.data', fn ($entries) => $entries->pluck('description')->all() === [
                'Exported the sales report as an Excel file',
                'Changed the system settings',
            ]));
    });

    it('can start or end the period at one end only', function () {
        $this->actingAs($this->owner)->get(route('audit-log.index', ['from' => '2026-10-01']))
            ->assertInertia(fn ($page) => $page->where('entries.total', 2));

        $this->actingAs($this->owner)->get(route('audit-log.index', ['to' => '2026-09-10']))
            ->assertInertia(fn ($page) => $page->where('entries.total', 1)->where('entries.data.0.description', 'Sale deleted'));
    });

    it('finds a word in what happened, whatever its case', function () {
        $this->actingAs($this->owner)->get(route('audit-log.index', ['search' => 'EXPORTED']))
            ->assertInertia(fn ($page) => $page->where('entries.total', 1)->where('filters.search', 'EXPORTED'));
    });

    it('takes % and _ as themselves, not as wildcards', function () {
        logged('sales', 'Gave 50% off', $this->owner);

        $this->actingAs($this->owner)->get(route('audit-log.index', ['search' => '50%']))
            ->assertInertia(fn ($page) => $page->where('entries.total', 1));

        $this->actingAs($this->owner)->get(route('audit-log.index', ['search' => '%']))
            ->assertInertia(fn ($page) => $page->where('entries.total', 1));

        $this->actingAs($this->owner)->get(route('audit-log.index', ['search' => '_']))
            ->assertInertia(fn ($page) => $page->where('entries.total', 0));
    });

    it('combines them', function () {
        $this->actingAs($this->owner)->get(route('audit-log.index', ['user' => $this->owner->id, 'from' => '2026-09-30', 'search' => 'report']))
            ->assertInertia(fn ($page) => $page->where('entries.total', 1)->where('entries.data.0.area', 'reports'));
    });

    it('ignores what it cannot understand instead of failing', function (array $query) {
        $this->actingAs($this->owner)->get(route('audit-log.index', $query))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('entries.total', 4));
    })->with([
        'a date that is not one' => [['from' => 'yesterday']],
        'a person that is not a number' => [['user' => 'abc']],
        'an area that is far too long' => [['area' => str_repeat('x', 300)]],
        'a search that is far too long' => [['search' => str_repeat('x', 300)]],
    ]);

    it('shows nothing, not an error, for an area nobody has used', function () {
        $this->actingAs($this->owner)->get(route('audit-log.index', ['area' => 'nonsense']))
            ->assertInertia(fn ($page) => $page->where('entries.total', 0));
    });
});

describe('what each entry says happened', function () {
    it('names the thing it was done to', function () {
        $product = Product::factory()->for(Category::factory()->create())->create(['name' => 'Nails']);
        logged('catalog', 'Archived', $this->owner, on: $product);

        $this->actingAs($this->owner)->get(route('audit-log.index'))
            ->assertInertia(fn ($page) => $page->where('entries.data.0.subject', 'Product: Nails'));
    });

    it('keeps the number when the thing has since been deleted', function () {
        $product = Product::factory()->for(Category::factory()->create())->create(['name' => 'Nails']);
        logged('catalog', 'Archived', $this->owner, on: $product);
        $id = $product->id;
        DB::table('products')->where('id', $id)->delete();

        $this->actingAs($this->owner)->get(route('audit-log.index'))
            ->assertInertia(fn ($page) => $page->where('entries.data.0.subject', "Product #{$id}"));
    });

    it('lists what changed, from and to', function () {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['sku' => 'HW-001', 'name' => 'Old name']);
        Activity::query()->delete();

        $this->actingAs($this->manager);
        $product->update(['name' => 'New name']);

        $this->actingAs($this->owner)->get(route('audit-log.index'))
            ->assertInertia(fn ($page) => $page->where('entries.data.0.details', fn ($details) => collect($details)->contains(['label' => 'Name', 'from' => 'Old name', 'to' => 'New name'])));
    });

    it('lists a new thing\'s values with nothing before them', function () {
        $this->actingAs($this->manager)->post(route('categories.store'), ['name' => 'Toys', 'description' => '', 'service_level' => 92]);

        $this->actingAs($this->owner)->get(route('audit-log.index'))
            ->assertInertia(fn ($page) => $page->where('entries.data.0.details', fn ($details) => collect($details)->contains(['label' => 'Name', 'from' => null, 'to' => 'Toys'])));
    });

    it('says which settings were changed, by name, from and to', function () {
        app(Settings::class)->update([...app(Settings::class)->all(), 'review_days' => 14, 'forecast_schedule' => false], $this->owner);

        $this->actingAs($this->owner)->get(route('audit-log.index', ['area' => 'settings']))
            ->assertInertia(fn ($page) => $page
                ->where('entries.data.0.description', 'Changed the system settings')
                ->where('entries.data.0.who', 'Olive Owner')
                ->where('entries.data.0.details', fn ($details) => collect($details)->contains(['label' => 'Review period', 'from' => '7', 'to' => '14'])
                    && collect($details)->contains(['label' => 'Refresh forecasts automatically', 'from' => 'Yes', 'to' => 'No'])));
    });

    it('records an export with what was exported', function () {
        smallShop();

        $this->actingAs($this->owner)->get(route('reports.export', ['sales', 'xlsx']));

        $this->actingAs($this->owner)->get(route('audit-log.index', ['area' => 'reports']))
            ->assertInertia(fn ($page) => $page->where('entries.data.0.description', 'Exported the sales report as an Excel file')
                ->where('entries.data.0.details', fn ($details) => collect($details)->contains(['label' => 'Report', 'from' => null, 'to' => 'sales'])
                    && collect($details)->contains(['label' => 'Rows', 'from' => null, 'to' => '2'])));
    });
});

describe('the presenter', function () {
    it('names every area the app writes to, and makes up a name for the rest', function () {
        expect(AuditPresenter::areaLabel('auth'))->toBe('Sign-ins')
            ->and(AuditPresenter::areaLabel('catalog'))->toBe('Products and categories')
            ->and(AuditPresenter::areaLabel('replenishment'))->toBe('Recommendations')
            ->and(AuditPresenter::areaLabel(null))->toBe('Other')
            ->and(AuditPresenter::areaLabel('default'))->toBe('Other')
            ->and(AuditPresenter::areaLabel('stock_counts'))->toBe('Stock Counts');
    });

    it('never shows anything that looks like a secret', function () {
        $entry = logged('users', 'Updated', $this->owner, [
            'password' => 'hunter2',
            'new_password_hash' => 'x',
            'api_token' => 'abc',
            'secret_answer' => 'blue',
            'encryption_key' => 'k',
            'two_factor_secret' => 'z',
            'recovery_codes' => 'r',
            'name' => 'Visible',
        ]);

        $details = app(AuditPresenter::class)->present($entry)['details'];

        expect($details)->toBe([['label' => 'Name', 'from' => null, 'to' => 'Visible']]);
    });

    it('keeps a long value to a line', function () {
        $entry = logged('sales', 'Imported', $this->owner, ['note' => str_repeat('x', 500)]);

        $to = app(AuditPresenter::class)->present($entry)['details'][0]['to'];

        expect(mb_strlen($to))->toBe(120)->and($to)->toEndWith('…');
    });

    it('writes yes and no, lists and nothing sensibly', function () {
        $entry = logged('forecasts', 'Started', null, ['scheduled' => true, 'manual' => false, 'filters' => ['from' => '2026-09-01'], 'nothing' => null]);

        expect(app(AuditPresenter::class)->present($entry)['details'])->toBe([
            ['label' => 'Scheduled', 'from' => null, 'to' => 'Yes'],
            ['label' => 'Manual', 'from' => null, 'to' => 'No'],
            ['label' => 'Filters', 'from' => null, 'to' => 'from: 2026-09-01'],
            ['label' => 'Nothing', 'from' => null, 'to' => null],
        ]);
    });

    it('leaves out the numbers that identify a row, which mean nothing to a person', function () {
        $entry = logged('sales', 'Sale deleted', $this->owner, ['sale_id' => 41, 'product_id' => 3, 'sku' => 'F-1', 'quantity' => 2]);

        expect(collect(app(AuditPresenter::class)->present($entry)['details'])->pluck('label')->all())->toBe(['Sku', 'Quantity']);
    });

    it('writes a list as a list and a map as its pairs', function () {
        $entry = logged('reports', 'Exported', $this->owner, [
            'filters' => ['from' => '2026-09-01', 'to' => '2026-09-30', 'category' => 3],
            'tags' => ['a', 'b'],
            'flags' => ['ok' => true, 'late' => false],
            'nested' => ['deeper' => ['x' => 1]],
        ]);

        $details = collect(app(AuditPresenter::class)->present($entry)['details'])->pluck('to', 'label');

        expect($details['Filters'])->toBe('from: 2026-09-01, to: 2026-09-30, category: 3')
            ->and($details['Tags'])->toBe('a, b')
            ->and($details['Flags'])->toBe('ok: Yes, late: No')
            ->and($details['Nested'])->toBe('{"deeper":{"x":1}}');
    });

    it('names the product a recommendation was about', function () {
        smallShop();
        app(RecommendationDecisions::class)->accept(soapRecommendation(), $this->owner);

        $this->actingAs($this->owner)->get(route('audit-log.index', ['area' => 'replenishment']))
            ->assertInertia(fn ($page) => $page->where('entries.data.0.subject', 'Recommendation for Soap'));
    });

    it('has no details for an entry with no properties', function () {
        expect(app(AuditPresenter::class)->present(logged('auth', 'Signed in', $this->owner))['details'])->toBe([]);
    });
});
