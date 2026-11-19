<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Staff;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        $query = Order::query()
            ->with(['user', 'latestPayment'])
            ->withCount('items');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('shipping_full_name', 'like', "%{$search}%")
                    ->orWhere('shipping_email', 'like', "%{$search}%");
            });
        }

        if ($status = trim((string) $request->query('status', ''))) {
            $query->where('status', $status);
        }

        if ($paymentStatus = trim((string) $request->query('payment_status', ''))) {
            $query->where('payment_status', $paymentStatus);
        }

        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return ApiResponse::paginated(
            $paginator,
            OrderResource::collection($paginator->items()),
            'Orders retrieved successfully.'
        );
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(['user', 'items.product.images', 'payments', 'latestPayment']);

        return ApiResponse::success(new OrderResource($order), 'Order retrieved successfully.');
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('admin');
        $nextStatus = OrderStatus::from((string) $request->validated('status'));

        $updated = $this->orderService->updateStatus(
            order: $order,
            newStatus: $nextStatus,
            actor: $staff,
            reason: $request->validated('reason'),
        );

        return ApiResponse::success(new OrderResource($updated), 'Order status updated successfully.');
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var Staff $staff */
        $staff = $request->user('admin');
        $cancelled = $this->orderService->cancel($order, $staff, $data['reason'] ?? null);

        return ApiResponse::success(new OrderResource($cancelled), 'Order cancelled successfully.');
    }
}
