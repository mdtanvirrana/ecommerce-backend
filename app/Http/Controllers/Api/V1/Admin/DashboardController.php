<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        $todayOrders = Order::query()
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->count();

        $todayRevenueRaw = Order::query()
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->where('payment_status', PaymentStatus::Succeeded->value)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->sum('total_amount');

        $totalCustomers = Customer::query()->count();

        $lowStockCount = Product::query()
            ->where('is_active', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->count();

        $totalRevenueRaw = Order::query()
            ->where('payment_status', PaymentStatus::Succeeded->value)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->sum('total_amount');

        $totalOrders = Order::query()->count();

        // 30-day daily sales & orders series directly from DB
        $startDate = now()->subDays(29)->startOfDay();
        $dailyRows = Order::query()
            ->select([
                DB::raw('DATE(created_at) as sale_date'),
                DB::raw('COUNT(*) as orders_count'),
                DB::raw("SUM(CASE WHEN payment_status = 'succeeded' AND status != 'cancelled' THEN total_amount ELSE 0 END) as revenue"),
            ])
            ->where('created_at', '>=', $startDate)
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get()
            ->keyBy('sale_date');

        $salesChart = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = Carbon::now()->subDays($i)->format('Y-m-d');
            $row = $dailyRows->get($day);
            $salesChart[] = [
                'date' => $day,
                'label' => Carbon::parse($day)->format('M d'),
                'orders' => $row ? (int) $row->orders_count : 0,
                'revenue' => $row ? (float) Money::normalize((string) $row->revenue) : 0.0,
            ];
        }

        $recentOrders = Order::query()
            ->with(['user', 'latestPayment'])
            ->withCount('items')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $lowStockProducts = Product::query()
            ->with(['category', 'images'])
            ->where('is_active', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->orderBy('stock_quantity')
            ->limit(8)
            ->get()
            ->map(fn (Product $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'stock_quantity' => $p->stock_quantity,
                'low_stock_threshold' => $p->low_stock_threshold,
            ]);

        return ApiResponse::success([
            'stats' => [
                'today_orders' => $todayOrders,
                'today_revenue' => Money::normalize((string) $todayRevenueRaw),
                'total_customers' => $totalCustomers,
                'low_stock_products' => $lowStockCount,
                'total_orders' => $totalOrders,
                'total_revenue' => Money::normalize((string) $totalRevenueRaw),
            ],
            'sales_chart' => $salesChart,
            'recent_orders' => OrderResource::collection($recentOrders),
            'low_stock_items' => $lowStockProducts,
        ], 'Dashboard statistics retrieved successfully.');
    }
}
