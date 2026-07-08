<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreCategoryRequest;
use App\Http\Requests\Inventory\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), ['active', 'archived', 'all'], true)
            ? $request->query('status')
            : 'active';

        $categories = Category::query()
            ->withCount('items')
            ->when($status === 'active', fn ($query) => $query->whereNull('archived_at'))
            ->when($status === 'archived', fn ($query) => $query->whereNotNull('archived_at'))
            ->orderBy('name')
            ->get();

        return Inertia::render('inventory/categories', [
            'categories' => $categories,
            'filters' => ['status' => $status],
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        Category::create($request->validated());

        // TODO(Phase 15): audit inventory.category.created

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Category created.',
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($request->validated());

        // TODO(Phase 15): audit inventory.category.updated

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Category updated.',
        ]);
    }

    public function archive(Category $category): RedirectResponse
    {
        $category->update(['archived_at' => now()]);

        // TODO(Phase 15): audit inventory.category.archived

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Category archived.',
        ]);
    }

    public function restore(Category $category): RedirectResponse
    {
        $category->update(['archived_at' => null]);

        // TODO(Phase 15): audit inventory.category.restored

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Category restored.',
        ]);
    }
}
