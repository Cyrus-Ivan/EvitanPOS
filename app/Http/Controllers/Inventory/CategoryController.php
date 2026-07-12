<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreCategoryRequest;
use App\Http\Requests\Inventory\UpdateCategoryRequest;
use App\Models\Category;
use App\Support\Audit\AuditRecorder;
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

    public function store(StoreCategoryRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $category = Category::create($request->validated());

        $audit->record(
            action: 'inventory.category.created',
            auditable: $category,
            description: "Created category \"{$category->name}\".",
            new: ['name' => $category->name],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Category created.',
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category, AuditRecorder $audit): RedirectResponse
    {
        $oldName = $category->name;
        $category->update($request->validated());

        $audit->record(
            action: 'inventory.category.updated',
            auditable: $category,
            description: "Renamed category \"{$oldName}\" to \"{$category->name}\".",
            old: ['name' => $oldName],
            new: ['name' => $category->name],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Category updated.',
        ]);
    }

    public function archive(Category $category, AuditRecorder $audit): RedirectResponse
    {
        $category->update(['archived_at' => now()]);

        $audit->record(
            action: 'inventory.category.archived',
            auditable: $category,
            description: "Archived category \"{$category->name}\".",
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Category archived.',
        ]);
    }

    public function restore(Category $category, AuditRecorder $audit): RedirectResponse
    {
        $category->update(['archived_at' => null]);

        $audit->record(
            action: 'inventory.category.restored',
            auditable: $category,
            description: "Restored category \"{$category->name}\".",
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Category restored.',
        ]);
    }
}
