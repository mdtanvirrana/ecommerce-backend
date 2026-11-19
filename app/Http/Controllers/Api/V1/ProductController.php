<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 9), 1), 48);

        $query = Product::query()
            ->with(['category.parent', 'images'])
            ->where('is_active', true);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($categoryParam = trim((string) $request->query('category', ''))) {
            $category = is_numeric($categoryParam)
                ? Category::query()->whereKey((int) $categoryParam)->first()
                : Category::query()->where('slug', $categoryParam)->first();

            if ($category !== null) {
                $query->whereIn('category_id', $category->descendantAndSelfIds());
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->filled('min_price') && is_numeric($request->query('min_price'))) {
            $minPrice = (float) $request->query('min_price');
            $query->whereRaw('COALESCE(discount_price, regular_price) >= ?', [$minPrice]);
        }

        if ($request->filled('max_price') && is_numeric($request->query('max_price'))) {
            $maxPrice = (float) $request->query('max_price');
            $query->whereRaw('COALESCE(discount_price, regular_price) <= ?', [$maxPrice]);
        }

        if ($color = trim((string) $request->query('color', ''))) {
            $query->whereJsonContains('colors', $color);
        }

        if ($size = trim((string) $request->query('size', ''))) {
            $query->whereJsonContains('sizes', $size);
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        if ($request->boolean('new_arrivals')) {
            $query->where('is_new_arrival', true);
        }

        if ($request->boolean('top_selling')) {
            $query->where('is_top_selling', true);
        }

        if ($request->boolean('on_sale')) {
            $query->whereNotNull('discount_price')
                ->whereColumn('discount_price', '<', 'regular_price');
        }

        if ($request->boolean('in_stock')) {
            $query->where('stock_quantity', '>', 0);
        }

        $sort = (string) $request->query('sort', 'newest');
        match ($sort) {
            'price_asc' => $query->orderByRaw('COALESCE(discount_price, regular_price) ASC'),
            'price_desc' => $query->orderByRaw('COALESCE(discount_price, regular_price) DESC'),
            'popular' => $query->orderByDesc('is_top_selling')->orderByDesc('rating')->orderByDesc('reviews_count'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        $paginator = $query->paginate($perPage);

        return ApiResponse::paginated(
            $paginator,
            ProductResource::collection($paginator->items()),
            'Products retrieved successfully.'
        );
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::query()
            ->with(['category.parent', 'images'])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if ($product === null) {
            return ApiResponse::error('Product not found.', 404);
        }

        // Fetch related products in the same category or parent category family.
        $relatedCategoryIds = $product->category?->parent !== null
            ? $product->category->parent->descendantAndSelfIds()
            : [$product->category_id];

        $related = Product::query()
            ->with(['category', 'images'])
            ->where('is_active', true)
            ->where('id', '!=', $product->id)
            ->whereIn('category_id', $relatedCategoryIds)
            ->orderByDesc('is_top_selling')
            ->limit(4)
            ->get();

        if ($related->count() < 4) {
            $extra = Product::query()
                ->with(['category', 'images'])
                ->where('is_active', true)
                ->whereNotIn('id', array_merge([$product->id], $related->pluck('id')->all()))
                ->orderByDesc('rating')
                ->limit(4 - $related->count())
                ->get();
            $related = $related->concat($extra);
        }

        return ApiResponse::success([
            'product' => new ProductResource($product),
            'related_products' => ProductResource::collection($related),
        ], 'Product retrieved successfully.');
    }
}
