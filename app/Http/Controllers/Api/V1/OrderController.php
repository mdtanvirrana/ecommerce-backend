<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');
        $perPage = min(max((int) $request->integer('per_page', 10), 1), 50);

        $paginator = Order::query()
            ->where('user_id', $customer->id)
            ->with(['items.product.images', 'latestPayment'])
            ->withCount('items')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return ApiResponse::paginated(
            $paginator,
            OrderResource::collection($paginator->items()),
            'Orders retrieved successfully.'
        );
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        // Strict IDOR protection via OrderPolicy::view
        if ($customer->cannot('view', $order)) {
            return ApiResponse::error('You are not authorized to view this order.', 403);
        }

        $order->load(['items.product.images', 'payments', 'latestPayment']);

        return ApiResponse::success(
            new OrderResource($order),
            'Order retrieved successfully.'
        );
    }
}
