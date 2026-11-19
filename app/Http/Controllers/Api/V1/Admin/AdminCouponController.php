<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCouponRequest;
use App\Http\Requests\Admin\UpdateCouponRequest;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);
        $query = Coupon::query();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where('code', 'like', "%{$search}%");
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        return ApiResponse::paginated(
            $paginator,
            CouponResource::collection($paginator->items()),
            'Coupons retrieved successfully.'
        );
    }

    public function store(StoreCouponRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['code'] = strtoupper(trim((string) $data['code']));
        $data['minimum_order_amount'] = $data['minimum_order_amount'] ?? '0.00';

        $coupon = Coupon::query()->create($data);

        return ApiResponse::success(new CouponResource($coupon), 'Coupon created successfully.', 201);
    }

    public function show(Coupon $coupon): JsonResponse
    {
        return ApiResponse::success(new CouponResource($coupon), 'Coupon retrieved successfully.');
    }

    public function update(UpdateCouponRequest $request, Coupon $coupon): JsonResponse
    {
        $data = $request->validated();
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim((string) $data['code']));
        }

        $coupon->update($data);

        return ApiResponse::success(new CouponResource($coupon->fresh()), 'Coupon updated successfully.');
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        $coupon->delete();

        return ApiResponse::success(null, 'Coupon deleted successfully.');
    }
}
