<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function featured(): JsonResponse
    {
        $reviews = Review::query()
            ->with('product')
            ->where('is_approved', true)
            ->where('is_featured', true)
            ->orderByDesc('created_at')
            ->limit(12)
            ->get();

        return ApiResponse::success(
            ReviewResource::collection($reviews),
            'Featured reviews retrieved successfully.'
        );
    }

    public function index(Request $request, string $slug): JsonResponse
    {
        $product = Product::query()->where('slug', $slug)->where('is_active', true)->first();

        if ($product === null) {
            return ApiResponse::error('Product not found.', 404);
        }

        $perPage = min(max((int) $request->integer('per_page', 6), 1), 50);
        $sort = (string) $request->query('sort', 'latest');

        $query = Review::query()
            ->where('product_id', $product->id)
            ->where('is_approved', true);

        match ($sort) {
            'highest' => $query->orderByDesc('rating')->orderByDesc('created_at'),
            'lowest' => $query->orderBy('rating')->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        $paginator = $query->paginate($perPage);

        return ApiResponse::paginated(
            $paginator,
            ReviewResource::collection($paginator->items()),
            'Product reviews retrieved successfully.'
        );
    }

    public function store(StoreReviewRequest $request, string $slug): JsonResponse
    {
        $product = Product::query()->where('slug', $slug)->where('is_active', true)->first();

        if ($product === null) {
            return ApiResponse::error('Product not found.', 404);
        }

        /** @var Customer $customer */
        $customer = $request->user('customer');
        $data = $request->validated();

        $verifiedPurchase = Order::query()
            ->where('user_id', $customer->id)
            ->where('payment_status', PaymentStatus::Succeeded->value)
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
            ->exists();

        $review = Review::query()->create([
            'product_id' => $product->id,
            'user_id' => $customer->id,
            'reviewer_name' => $customer->name,
            'rating' => (int) $data['rating'],
            'comment' => trim((string) $data['comment']),
            'is_verified_purchase' => $verifiedPurchase,
            'is_approved' => true,
            'is_featured' => false,
        ]);

        Review::syncProductRating($product->id);

        return ApiResponse::success(
            new ReviewResource($review->load('product')),
            'Thank you! Your review has been published.',
            201
        );
    }
}
