<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Support\ApiResponse;
use App\Support\CatalogCache;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $payload = CatalogCache::remember('categories:index', 120, function (): array {
            $categories = Category::query()
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
                ->with([
                    'children' => fn ($q) => $q
                        ->where('is_active', true)
                        ->withCount(['products' => fn ($p) => $p->where('is_active', true)])
                        ->orderBy('sort_order')
                        ->orderBy('name'),
                ])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            return CategoryResource::collection($categories)->resolve();
        });

        return ApiResponse::success(
            $payload,
            'Categories retrieved successfully.'
        )->header('Cache-Control', 'public, max-age=30, stale-while-revalidate=60');
    }

    public function show(string $slug): JsonResponse
    {
        $payload = CatalogCache::remember("categories:show:{$slug}", 120, function () use ($slug): ?array {
            $category = Category::query()
                ->where('slug', $slug)
                ->where('is_active', true)
                ->with([
                    'parent',
                    'children' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('name'),
                ])
                ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
                ->first();

            return $category !== null ? (new CategoryResource($category))->resolve() : null;
        });

        if ($payload === null) {
            return ApiResponse::error('Category not found.', 404);
        }

        return ApiResponse::success(
            $payload,
            'Category retrieved successfully.'
        )->header('Cache-Control', 'public, max-age=30, stale-while-revalidate=60');
    }
}
