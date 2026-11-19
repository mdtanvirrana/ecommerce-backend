<?php

namespace Database\Seeders;

use App\Enums\CouponType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderSequence;
use App\Models\Product;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoCommerceSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Sample Coupons (2 active + 1 expired for validation testing)
        $welcome20 = Coupon::query()->updateOrCreate(
            ['code' => 'WELCOME20'],
            [
                'type' => CouponType::Percentage,
                'value' => '20.00',
                'minimum_order_amount' => '50.00',
                'maximum_discount_amount' => '100.00',
                'usage_limit' => 200,
                'per_customer_usage_limit' => 2,
                'used_count' => 2,
                'starts_at' => now()->subDays(30),
                'expires_at' => now()->addMonths(6),
                'is_active' => true,
            ]
        );

        Coupon::query()->updateOrCreate(
            ['code' => 'SAVE25'],
            [
                'type' => CouponType::Fixed,
                'value' => '25.00',
                'minimum_order_amount' => '150.00',
                'maximum_discount_amount' => null,
                'usage_limit' => 100,
                'per_customer_usage_limit' => 1,
                'used_count' => 1,
                'starts_at' => now()->subDays(10),
                'expires_at' => now()->addMonths(3),
                'is_active' => true,
            ]
        );

        Coupon::query()->updateOrCreate(
            ['code' => 'EXPIRED10'],
            [
                'type' => CouponType::Percentage,
                'value' => '10.00',
                'minimum_order_amount' => '20.00',
                'maximum_discount_amount' => '50.00',
                'usage_limit' => 50,
                'per_customer_usage_limit' => 1,
                'used_count' => 0,
                'starts_at' => now()->subMonths(3),
                'expires_at' => now()->subDays(10),
                'is_active' => true,
            ]
        );

        // 2. Sample Customers
        $customersData = [
            ['name' => 'Sarah Miller', 'email' => 'customer@shopco.local', 'phone' => '+1-555-0201'],
            ['name' => 'Alex Klein', 'email' => 'alex@example.com', 'phone' => '+1-555-0202'],
            ['name' => 'James Lin', 'email' => 'james@example.com', 'phone' => '+1-555-0203'],
            ['name' => 'Olivia Parker', 'email' => 'olivia@example.com', 'phone' => '+1-555-0204'],
        ];

        $customers = [];
        foreach ($customersData as $c) {
            $customers[] = Customer::query()->updateOrCreate(
                ['email' => $c['email']],
                [
                    'name' => $c['name'],
                    'phone' => $c['phone'],
                    'password' => Hash::make('Customer@123!'),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );
        }

        // 3. Seed realistic orders across statuses & dates if none exist yet
        if (Order::query()->exists()) {
            return;
        }

        $products = Product::query()->where('stock_quantity', '>', 5)->orderBy('id')->get();
        if ($products->count() < 6) {
            return;
        }

        $year = (int) now()->format('Y');
        $seq = 1;

        $sampleOrders = [
            ['customer_idx' => 0, 'status' => OrderStatus::Delivered, 'payment' => PaymentStatus::Succeeded, 'days_ago' => 18, 'items' => [[0, 1], [1, 1]], 'coupon' => $welcome20],
            ['customer_idx' => 1, 'status' => OrderStatus::Delivered, 'payment' => PaymentStatus::Succeeded, 'days_ago' => 14, 'items' => [[4, 2]], 'coupon' => null],
            ['customer_idx' => 2, 'status' => OrderStatus::Shipped, 'payment' => PaymentStatus::Succeeded, 'days_ago' => 9, 'items' => [[8, 1], [5, 1]], 'coupon' => null],
            ['customer_idx' => 3, 'status' => OrderStatus::Processing, 'payment' => PaymentStatus::Succeeded, 'days_ago' => 5, 'items' => [[2, 1], [3, 2]], 'coupon' => $welcome20],
            ['customer_idx' => 0, 'status' => OrderStatus::Paid, 'payment' => PaymentStatus::Succeeded, 'days_ago' => 2, 'items' => [[8, 1], [6, 1]], 'coupon' => null],
            ['customer_idx' => 1, 'status' => OrderStatus::Paid, 'payment' => PaymentStatus::Succeeded, 'days_ago' => 0, 'items' => [[7, 1], [10, 1]], 'coupon' => null],
            ['customer_idx' => 2, 'status' => OrderStatus::Pending, 'payment' => PaymentStatus::Pending, 'days_ago' => 0, 'items' => [[9, 1]], 'coupon' => null],
            ['customer_idx' => 3, 'status' => OrderStatus::Cancelled, 'payment' => PaymentStatus::Refunded, 'days_ago' => 7, 'items' => [[11, 1]], 'coupon' => null],
        ];

        foreach ($sampleOrders as $spec) {
            $customer = $customers[$spec['customer_idx']];
            $createdAt = Carbon::now()->subDays($spec['days_ago'])->setTime(10 + $seq, 15);
            $orderNumber = sprintf('ORD-%d-%06d', $year, $seq++);

            $subtotal = '0.00';
            $lines = [];
            foreach ($spec['items'] as [$pIdx, $qty]) {
                $p = $products[$pIdx % $products->count()];
                $unit = $p->effectivePrice();
                $lineTotal = Money::mul($unit, $qty);
                $subtotal = Money::add($subtotal, $lineTotal);
                $lines[] = [
                    'product_id' => $p->id,
                    'product_name' => $p->name,
                    'product_sku' => $p->sku,
                    'selected_color' => is_array($p->colors) && count($p->colors) > 0 ? $p->colors[0] : 'Black',
                    'selected_size' => is_array($p->sizes) && count($p->sizes) > 0 ? $p->sizes[1] ?? $p->sizes[0] : 'Large',
                    'unit_price' => $unit,
                    'quantity' => $qty,
                    'line_total' => $lineTotal,
                ];
            }

            $discount = '0.00';
            $couponId = null;
            $couponCode = null;
            if ($spec['coupon'] instanceof Coupon) {
                $couponId = $spec['coupon']->id;
                $couponCode = $spec['coupon']->code;
                $discount = Money::min('100.00', Money::percentage($subtotal, '20.00'));
            }

            $afterDiscount = Money::max('0.00', Money::sub($subtotal, $discount));
            $shipping = Money::compare($afterDiscount, '250.00') >= 0 ? '0.00' : '15.00';
            $total = Money::add($afterDiscount, $shipping);

            $isPaidOrBeyond = in_array($spec['status'], [OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered], true);

            $order = Order::query()->create([
                'order_number' => $orderNumber,
                'user_id' => $customer->id,
                'coupon_id' => $couponId,
                'coupon_code' => $couponCode,
                'status' => $spec['status'],
                'payment_status' => $spec['payment'],
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'shipping_amount' => $shipping,
                'total_amount' => $total,
                'currency' => 'usd',
                'shipping_full_name' => $customer->name,
                'shipping_email' => $customer->email,
                'shipping_phone' => $customer->phone ?? '+1-555-0299',
                'shipping_address' => '742 Evergreen Terrace, Apt 4B',
                'shipping_city' => 'New York',
                'shipping_postal_code' => '10001',
                'delivery_instructions' => 'Leave at front reception desk.',
                'stock_deducted_at' => $isPaidOrBeyond || $spec['status'] === OrderStatus::Cancelled ? $createdAt : null,
                'stock_restored_at' => $spec['status'] === OrderStatus::Cancelled ? $createdAt->copy()->addHours(2) : null,
                'paid_at' => $isPaidOrBeyond ? $createdAt : null,
                'cancelled_at' => $spec['status'] === OrderStatus::Cancelled ? $createdAt->copy()->addHours(2) : null,
                'cancellation_reason' => $spec['status'] === OrderStatus::Cancelled ? 'Customer requested cancellation before dispatch.' : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            foreach ($lines as $line) {
                $order->items()->create($line);
            }

            $order->payments()->create([
                'provider' => 'stripe',
                'stripe_payment_intent_id' => 'pi_seed_'.strtolower(str_replace('-', '_', $orderNumber)),
                'stripe_charge_id' => $isPaidOrBeyond ? 'ch_seed_'.strtolower(str_replace('-', '_', $orderNumber)) : null,
                'stripe_refund_id' => $spec['payment'] === PaymentStatus::Refunded ? 're_seed_'.strtolower(str_replace('-', '_', $orderNumber)) : null,
                'amount' => $total,
                'currency' => 'usd',
                'status' => $spec['payment'],
                'verified_at' => $isPaidOrBeyond ? $createdAt : null,
                'refunded_at' => $spec['payment'] === PaymentStatus::Refunded ? $createdAt->copy()->addHours(2) : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        OrderSequence::query()->updateOrCreate(
            ['year' => $year],
            ['last_number' => $seq - 1]
        );
    }
}
