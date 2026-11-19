<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        $query = Customer::query()
            ->withCount('orders')
            ->withSum([
                'orders as total_spent' => fn (Builder $q) => $q
                    ->where('payment_status', PaymentStatus::Succeeded->value)
                    ->where('status', '!=', OrderStatus::Cancelled->value),
            ], 'total_amount');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        return ApiResponse::paginated(
            $paginator,
            CustomerResource::collection($paginator->items()),
            'Customers retrieved successfully.'
        );
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer->loadCount('orders');

        $totalSpentRaw = Order::query()
            ->where('user_id', $customer->id)
            ->where('payment_status', PaymentStatus::Succeeded->value)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->sum('total_amount');

        $orders = Order::query()
            ->where('user_id', $customer->id)
            ->with(['latestPayment'])
            ->withCount('items')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success([
            'customer' => new CustomerResource($customer),
            'total_orders' => $orders->count(),
            'total_spending' => Money::normalize((string) $totalSpentRaw),
            'orders' => OrderResource::collection($orders),
        ], 'Customer details retrieved successfully.');
    }
}
