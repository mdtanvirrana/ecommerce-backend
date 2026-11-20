<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Support\ApiResponse;
use App\Support\CatalogCache;
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

        $stats = Review::query()
            ->selectRaw('COUNT(*) as total_reviews')
            ->selectRaw('SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) as approved_reviews')
            ->selectRaw('SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) as pending_reviews')
            ->selectRaw('SUM(CASE WHEN is_featured = 1 AND is_approved = 1 THEN 1 ELSE 0 END) as featured_reviews')
            ->selectRaw('AVG(CASE WHEN is_approved = 1 THEN rating END) as average_rating')
            ->first();

        $summary = [
            'total_reviews' => (int) ($stats->total_reviews ?? 0),
            'approved_reviews' => (int) ($stats->approved_reviews ?? 0),
            'pending_reviews' => (int) ($stats->pending_reviews ?? 0),
            'featured_reviews' => (int) ($stats->featured_reviews ?? 0),
            'average_rating' => number_format((float) ($stats->average_rating ?? 0), 2, '.', ''),
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
        CatalogCache::invalidate();

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
        CatalogCache::invalidate();

        return ApiResponse::success(null, 'Review deleted successfully.');
    }
}
