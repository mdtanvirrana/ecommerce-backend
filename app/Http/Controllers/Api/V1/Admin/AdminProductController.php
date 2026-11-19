<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Staff;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminProductController extends Controller
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        $query = Product::query()->with(['category.parent', 'images']);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($categoryId = $request->query('category_id')) {
            $cat = Category::query()->find((int) $categoryId);
            if ($cat !== null) {
                $query->whereIn('category_id', $cat->descendantAndSelfIds());
            }
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->boolean('low_stock')) {
            $query->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
        }

        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return ApiResponse::paginated(
            $paginator,
            ProductResource::collection($paginator->items()),
            'Products retrieved successfully.'
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = ! empty($data['slug'])
            ? Str::slug((string) $data['slug'])
            : $this->uniqueSlug((string) $data['name']);
        $data['sku'] = strtoupper(trim((string) $data['sku']));

        $uploadedFiles = $request->file('images', []);
        unset($data['images']);

        /** @var Product $product */
        $product = DB::transaction(function () use ($data, $uploadedFiles): Product {
            /** @var Product $created */
            $created = Product::query()->create($data);
            $this->attachUploadedImages($created, is_array($uploadedFiles) ? $uploadedFiles : [$uploadedFiles], true);

            return $created->fresh(['category.parent', 'images']);
        });

        return ApiResponse::success(new ProductResource($product), 'Product created successfully.', 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(['category.parent', 'images']);

        return ApiResponse::success(new ProductResource($product), 'Product retrieved successfully.');
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
            $data['slug'] = Str::slug((string) $data['slug']);
        }
        if (isset($data['sku'])) {
            $data['sku'] = strtoupper(trim((string) $data['sku']));
        }

        $uploadedFiles = $request->file('images', []);
        unset($data['images']);

        /** @var Product $updated */
        $updated = DB::transaction(function () use ($product, $data, $uploadedFiles): Product {
            $product->update($data);

            if (! empty($uploadedFiles)) {
                $hasPrimary = $product->images()->where('is_primary', true)->exists();
                $this->attachUploadedImages(
                    $product,
                    is_array($uploadedFiles) ? $uploadedFiles : [$uploadedFiles],
                    ! $hasPrimary
                );
            }

            return $product->fresh(['category.parent', 'images']);
        });

        return ApiResponse::success(new ProductResource($updated), 'Product updated successfully.');
    }

    public function updateStock(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var Staff $staff */
        $staff = $request->user('admin');
        $updated = $this->inventoryService->adjustStock(
            product: $product,
            newStockQuantity: (int) $data['stock_quantity'],
            actorId: $staff->id,
            notes: (string) ($data['notes'] ?? 'Updated by staff'),
        );

        return ApiResponse::success(
            new ProductResource($updated->load(['category.parent', 'images'])),
            'Product stock updated successfully.'
        );
    }

    public function destroyImage(Product $product, ProductImage $image): JsonResponse
    {
        if ((int) $image->product_id !== (int) $product->id) {
            return ApiResponse::error('Image does not belong to this product.', 404);
        }

        if (! str_starts_with($image->path, 'http') && Storage::disk('public')->exists($image->path)) {
            Storage::disk('public')->delete($image->path);
        }

        $wasPrimary = $image->is_primary;
        $image->delete();

        if ($wasPrimary) {
            $next = $product->images()->orderBy('sort_order')->first();
            $next?->update(['is_primary' => true]);
        }

        return ApiResponse::success(
            new ProductResource($product->fresh(['category.parent', 'images'])),
            'Product image deleted.'
        );
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return ApiResponse::success(null, 'Product deleted successfully.');
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function attachUploadedImages(Product $product, array $files, bool $markFirstPrimary): void
    {
        $order = (int) ($product->images()->max('sort_order') ?? -1) + 1;

        foreach ($files as $idx => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $storedPath = $file->store('products', 'public');
            $product->images()->create([
                'path' => $storedPath,
                'alt_text' => $product->name,
                'is_primary' => $markFirstPrimary && $idx === 0,
                'sort_order' => $order++,
            ]);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;

        while (Product::query()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
