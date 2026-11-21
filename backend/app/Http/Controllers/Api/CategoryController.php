<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);
        if ($perPage <= 0) {
            $perPage = 20;
        }

        $categories = Category::withCount('products')->orderBy('name')->paginate($perPage);

        return response()->json($categories);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create($request->validated());

        return response()->json([
            'message' => 'Category created successfully.',
            'data' => $category,
        ], 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category->update($request->validated());

        return response()->json([
            'message' => 'Category updated successfully.',
            'data' => $category->fresh(),
        ]);
    }

    public function destroy(Category $category): JsonResponse
    {
        // Check for products including trashed
        $productCount = $category->products()->withTrashed()->count();

        if ($productCount > 0) {
            return response()->json([
                'message' => 'Cannot delete category. It has associated products.',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully.',
        ]);
    }
}
