<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('flat')) {
            $categories = Category::query()
                ->with('parent')
                ->withCount('products')
                ->orderBy('parent_id')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            return ApiResponse::success(CategoryResource::collection($categories), 'Categories retrieved successfully.');
        }

        $categories = Category::query()
            ->whereNull('parent_id')
            ->withCount('products')
            ->with(['children' => fn ($q) => $q->withCount('products')->orderBy('sort_order')->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(CategoryResource::collection($categories), 'Categories retrieved successfully.');
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = ! empty($data['slug'])
            ? Str::slug((string) $data['slug'])
            : $this->uniqueSlug((string) $data['name']);

        $category = Category::query()->create($data);

        return ApiResponse::success(
            new CategoryResource($category->load(['parent', 'children'])),
            'Category created successfully.',
            201
        );
    }

    public function show(Category $category): JsonResponse
    {
        $category->load(['parent', 'children'])->loadCount('products');

        return ApiResponse::success(new CategoryResource($category), 'Category retrieved successfully.');
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('parent_id', $data)) {
            $candidateParentId = $data['parent_id'] !== null ? (int) $data['parent_id'] : null;
            if ($category->wouldCreateCycle($candidateParentId)) {
                throw new DomainException(
                    'Invalid parent category: circular parent-child relationship is not allowed.',
                    422,
                    ['parent_id' => ['Circular parent-child relationship is not allowed.']]
                );
            }
        }

        if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
            $data['slug'] = Str::slug((string) $data['slug']);
        }

        $category->update($data);

        return ApiResponse::success(
            new CategoryResource($category->fresh(['parent', 'children'])),
            'Category updated successfully.'
        );
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->children()->exists()) {
            throw new DomainException('Cannot delete a category that still has child subcategories.', 409);
        }

        if ($category->products()->exists()) {
            throw new DomainException('Cannot delete a category that still has assigned products.', 409);
        }

        $category->delete();

        return ApiResponse::success(null, 'Category deleted successfully.');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;

        while (Category::query()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
