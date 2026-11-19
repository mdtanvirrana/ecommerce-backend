<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        $query = Review::query()->with(['product', 'user']);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('reviewer_name', 'like', "%{$search}%")
                    ->orWhere('comment', 'like', "%{$search}%")
                    ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('rating') && is_numeric($request->query('rating'))) {
            $query->where('rating', (int) $request->query('rating'));
        }

        if ($request->filled('is_approved')) {
            $query->where('is_approved', $request->boolean('is_approved'));
        }

        if ($request->filled('is_featured')) {
            $query->where('is_featured', $request->boolean('is_featured'));
        }

        if ($request->filled('product_id') && is_numeric($request->query('product_id'))) {
            $query->where('product_id', (int) $request->query('product_id'));
        }

        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        $summary = [
            'total_reviews' => Review::query()->count(),
            'approved_reviews' => Review::query()->where('is_approved', true)->count(),
            'pending_reviews' => Review::query()->where('is_approved', false)->count(),
            'featured_reviews' => Review::query()->where('is_featured', true)->where('is_approved', true)->count(),
            'average_rating' => number_format((float) (Review::query()->where('is_approved', true)->avg('rating') ?? 0), 2, '.', ''),
        ];

        return ApiResponse::success(
            data: [
                'reviews' => ReviewResource::collection($paginator->items()),
                'summary' => $summary,
            ],
            message: 'Reviews retrieved successfully.',
            meta: [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        );
    }

    public function update(Request $request, Review $review): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'comment' => ['sometimes', 'string', 'min:5', 'max:2000'],
            'is_approved' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_verified_purchase' => ['sometimes', 'boolean'],
        ]);

        $review->update($data);
        Review::syncProductRating($review->product_id);

        return ApiResponse::success(
            new ReviewResource($review->fresh(['product'])),
            'Review updated successfully.'
        );
    }

    public function destroy(Review $review): JsonResponse
    {
        $productId = $review->product_id;
        $review->delete();
        Review::syncProductRating($productId);

        return ApiResponse::success(null, 'Review deleted successfully.');
    }
}
