<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\Catalog\CategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Product categories. Everyone who can see the catalog can list them; changing
 * them needs `catalog.manage` (see routes/web.php).
 */
class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('categories/index', [
            'categories' => Category::query()
                ->withCount('products')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => $this->present($category))
                ->all(),
            'can' => [
                'manage' => $request->user()->hasPermissionTo(Permission::ManageCatalog),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('categories/create');
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        Category::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Category created.']);

        return to_route('categories.index');
    }

    public function edit(Category $category): Response
    {
        return Inertia::render('categories/edit', [
            'category' => $this->present($category->loadCount('products')),
        ]);
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Category updated.']);

        return to_route('categories.index');
    }

    public function destroy(Category $category): RedirectResponse
    {
        // Products keep pointing at their category even when archived, so any
        // product, active or not, blocks deletion.
        if ($category->products()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'This category still has products, including archived ones. Move them to another category first.',
            ]);
        }

        $category->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Category deleted.']);

        return to_route('categories.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'service_level' => (float) $category->service_level,
            'products_count' => $category->products_count ?? 0,
        ];
    }
}
