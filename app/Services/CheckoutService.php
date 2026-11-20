<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckoutService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CouponService $couponService,
        private readonly ShippingCalculator $shippingCalculator,
        private readonly OrderNumberGenerator $orderNumberGenerator,
        private readonly StripeGatewayInterface $stripeGateway,
    ) {}

    /**
     * Validate cart, re-fetch authoritative product prices & stock from DB,
     * compute exact totals, create a pending order + payment record (NO stock deduction yet),
     * and create a Stripe PaymentIntent.
     *
     * @param  array{
     *   shipping_full_name: string,
     *   shipping_email: string,
     *   shipping_phone: string,
     *   shipping_address: string,
     *   shipping_city: string,
     *   shipping_postal_code: string,
     *   delivery_instructions?: ?string,
     *   coupon_code?: ?string
     * }  $data
     * @return array{order: Order, payment: Payment, client_secret: string, publishable_key: string}
     */
    public function checkout(Customer $customer, array $data): array
    {
        $cart = $this->cartService->getOrCreateCart($customer)->load(['items', 'coupon']);

        if ($cart->items->isEmpty()) {
            throw new DomainException('Your cart is empty.', 422);
        }

        // Aggregate quantities by product_id in ascending lock order to prevent deadlocks.
        $requestedTotals = [];
        foreach ($cart->items as $item) {
            $pid = (int) $item->product_id;
            $requestedTotals[$pid] = ($requestedTotals[$pid] ?? 0) + (int) $item->quantity;
        }
        ksort($requestedTotals);

        $currency = (string) config('services.stripe.currency', 'usd');

        // Atomically lock product rows, verify live stock & prices, apply coupons, and create pending order (stock NOT deducted until paid).
        [$order, $payment] = DB::transaction(function () use (
            $customer,
            $cart,
            $data,
            $requestedTotals,
            $currency
        ): array {
            $products = Product::query()
                ->whereIn('id', array_keys($requestedTotals))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotal = '0.00';
            $lineSnapshots = [];

            foreach ($cart->items as $item) {
                /** @var Product|null $product */
                $product = $products->get((int) $item->product_id);

                if ($product === null || ! $product->is_active) {
                    throw new DomainException('One or more items in your cart are no longer available.', 409);
                }

                $totalForProduct = $requestedTotals[$product->id];
                if ($product->stock_quantity < $totalForProduct) {
                    $detail = $product->stock_quantity <= 0
                        ? "{$product->name} is now out of stock. Please remove it from your cart before checking out."
                        : "Insufficient stock for {$product->name} (requested {$totalForProduct}, available {$product->stock_quantity}). Please adjust your cart quantity.";

                    throw new DomainException(
                        $detail,
                        409,
                        ['stock' => [$detail]]
                    );
                }

                $unitPrice = $product->effectivePrice();
                $lineTotal = Money::mul($unitPrice, $item->quantity);
                $subtotal = Money::add($subtotal, $lineTotal);

                $lineSnapshots[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'selected_color' => $item->selected_color !== '' ? $item->selected_color : null,
                    'selected_size' => $item->selected_size !== '' ? $item->selected_size : null,
                    'unit_price' => $unitPrice,
                    'quantity' => $item->quantity,
                    'line_total' => $lineTotal,
                ];
            }

            // Determine coupon code from explicit checkout input or cart's attached coupon.
            $couponCode = isset($data['coupon_code']) && trim((string) $data['coupon_code']) !== ''
                ? trim((string) $data['coupon_code'])
                : $cart->coupon?->code;

            $couponId = null;
            $appliedCouponCode = null;
            $discountAmount = '0.00';

            if ($couponCode !== null) {
                $validatedCoupon = $this->couponService->validateAndCalculate($couponCode, $subtotal, $customer);
                $couponId = $validatedCoupon['coupon']->id;
                $appliedCouponCode = $validatedCoupon['coupon']->code;
                $discountAmount = $validatedCoupon['discount_amount'];
            }

            $afterDiscount = Money::max('0.00', Money::sub($subtotal, $discountAmount));
            $shippingAmount = $this->shippingCalculator->calculate($afterDiscount);
            $totalAmount = Money::add($afterDiscount, $shippingAmount);

            if (Money::compare($totalAmount, '0.50') < 0) {
                throw new DomainException('Order total must be at least $0.50 for Stripe card payment.', 422);
            }

            $orderNumber = $this->orderNumberGenerator->next();

            /** @var Order $order */
            $order = Order::query()->create([
                'order_number' => $orderNumber,
                'user_id' => $customer->id,
                'coupon_id' => $couponId,
                'coupon_code' => $appliedCouponCode,
                'status' => OrderStatus::Pending,
                'payment_status' => PaymentStatus::Pending,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'shipping_amount' => $shippingAmount,
                'total_amount' => $totalAmount,
                'currency' => $currency,
                'shipping_full_name' => $data['shipping_full_name'],
                'shipping_email' => $data['shipping_email'],
                'shipping_phone' => $data['shipping_phone'],
                'shipping_address' => $data['shipping_address'],
                'shipping_city' => $data['shipping_city'],
                'shipping_postal_code' => $data['shipping_postal_code'],
                'delivery_instructions' => $data['delivery_instructions'] ?? null,
            ]);

            foreach ($lineSnapshots as $line) {
                $order->items()->create($line);
            }

            /** @var Payment $payment */
            $payment = $order->payments()->create([
                'provider' => 'stripe',
                'amount' => $totalAmount,
                'currency' => $currency,
                'status' => PaymentStatus::Pending,
            ]);

            return [$order, $payment];
        });

        try {
            $intent = $this->stripeGateway->createPaymentIntent(
                amountInCents: Money::toCents($order->total_amount),
                currency: $order->currency,
                metadata: [
                    'order_id' => (string) $order->id,
                    'order_number' => $order->order_number,
                    'customer_id' => (string) $customer->id,
                ],
                idempotencyKey: "checkout-order-{$order->id}-payment-{$payment->id}",
            );
        } catch (\Throwable $e) {
            Log::error('Stripe PaymentIntent creation failed during checkout.', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            $payment->update([
                'status' => PaymentStatus::Failed,
                'failure_message' => $e->getMessage(),
            ]);
            $order->update(['payment_status' => PaymentStatus::Failed]);

            if ($e instanceof DomainException) {
                throw $e;
            }

            throw new DomainException('Unable to initialize Stripe payment. Please try again.', 502);
        }

        $payment->update([
            'stripe_payment_intent_id' => $intent['id'],
            'metadata' => ['stripe_status' => $intent['status']],
        ]);

        return [
            'order' => $order->fresh(['items', 'latestPayment']),
            'payment' => $payment->fresh(),
            'client_secret' => $intent['client_secret'],
            'publishable_key' => (string) config('services.stripe.key'),
        ];
    }

    /**
     * Re-verify live inventory inside a DB transaction with row locks immediately before
     * submitting card payment to Stripe, rejecting oversells with a clear message before charging.
     */
    public function validateStockForPayment(Customer $customer, Order $order): void
    {
        if ((int) $order->user_id !== (int) $customer->id) {
            throw new DomainException('Order not found.', 404);
        }

        DB::transaction(function () use ($order): void {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status !== OrderStatus::Pending) {
                throw new DomainException("Order {$lockedOrder->order_number} is no longer pending payment.", 409);
            }

            if ($lockedOrder->payment_status === PaymentStatus::Succeeded) {
                throw new DomainException('This order has already been paid.', 409);
            }

            $items = $lockedOrder->items()->orderBy('product_id')->get();
            if ($items->isEmpty()) {
                throw new DomainException('Order contains no items to fulfill.', 422);
            }

            $requiredByProduct = [];
            $namesByProduct = [];
            foreach ($items as $item) {
                $pid = (int) $item->product_id;
                $requiredByProduct[$pid] = ($requiredByProduct[$pid] ?? 0) + (int) $item->quantity;
                $namesByProduct[$pid] = $item->product_name;
            }
            ksort($requiredByProduct);

            $products = Product::query()
                ->whereIn('id', array_keys($requiredByProduct))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($requiredByProduct as $productId => $qtyNeeded) {
                /** @var Product|null $product */
                $product = $products->get($productId);
                $label = $product?->name ?? $namesByProduct[$productId];

                if ($product === null || ! $product->is_active) {
                    throw new DomainException(
                        "{$label} is no longer available. Your order cannot be completed.",
                        409,
                        ['stock' => ["{$label} is no longer available."]]
                    );
                }

                if ($product->stock_quantity < $qtyNeeded) {
                    $detail = $product->stock_quantity <= 0
                        ? "Order rejected: {$label} is now out of stock. Please update your cart and try again."
                        : "Order rejected: insufficient stock for {$label} (requested {$qtyNeeded}, available {$product->stock_quantity}). Please update your cart and try again.";

                    throw new DomainException(
                        $detail,
                        409,
                        ['stock' => [$detail]]
                    );
                }
            }
        });
    }

    /**
     * Create a fresh Stripe PaymentIntent for retrying a pending/failed order.
     *
     * @return array{order: Order, payment: Payment, client_secret: string, publishable_key: string}
     */
    public function retryPayment(Customer $customer, Order $order): array
    {
        if ((int) $order->user_id !== (int) $customer->id) {
            throw new DomainException('Order not found.', 404);
        }

        // Atomically re-validate live stock with row locks and create the retry payment record.
        /** @var Payment $payment */
        $payment = DB::transaction(function () use ($order): Payment {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status !== OrderStatus::Pending) {
                throw new DomainException('Only pending orders can retry payment.', 409);
            }

            if ($lockedOrder->payment_status === PaymentStatus::Succeeded) {
                throw new DomainException('This order has already been paid.', 409);
            }

            $items = $lockedOrder->items()->orderBy('product_id')->get();
            $requiredByProduct = [];
            $namesByProduct = [];
            foreach ($items as $item) {
                $pid = (int) $item->product_id;
                $requiredByProduct[$pid] = ($requiredByProduct[$pid] ?? 0) + (int) $item->quantity;
                $namesByProduct[$pid] = $item->product_name;
            }
            ksort($requiredByProduct);

            $products = Product::query()
                ->whereIn('id', array_keys($requiredByProduct))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($requiredByProduct as $productId => $qtyNeeded) {
                /** @var Product|null $product */
                $product = $products->get($productId);
                $label = $product?->name ?? $namesByProduct[$productId];

                if ($product === null || ! $product->is_active || $product->stock_quantity < $qtyNeeded) {
                    $available = $product?->stock_quantity ?? 0;
                    throw new DomainException(
                        "Insufficient stock for {$label} to retry payment (requested {$qtyNeeded}, available {$available}).",
                        409,
                        ['stock' => ["Insufficient stock for {$label}."]]
                    );
                }
            }

            return $lockedOrder->payments()->create([
                'provider' => 'stripe',
                'amount' => $lockedOrder->total_amount,
                'currency' => $lockedOrder->currency,
                'status' => PaymentStatus::Pending,
            ]);
        });

        try {
            $intent = $this->stripeGateway->createPaymentIntent(
                amountInCents: Money::toCents($order->total_amount),
                currency: $order->currency,
                metadata: [
                    'order_id' => (string) $order->id,
                    'order_number' => $order->order_number,
                    'customer_id' => (string) $customer->id,
                    'retry_payment_id' => (string) $payment->id,
                ],
                idempotencyKey: "retry-order-{$order->id}-payment-{$payment->id}",
            );
        } catch (\Throwable $e) {
            Log::error('Stripe PaymentIntent creation failed during payment retry.', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            $payment->update([
                'status' => PaymentStatus::Failed,
                'failure_message' => $e->getMessage(),
            ]);
            $order->update(['payment_status' => PaymentStatus::Failed]);

            if ($e instanceof DomainException) {
                throw $e;
            }

            throw new DomainException('Unable to initialize Stripe payment. Please try again.', 502);
        }

        $payment->update([
            'stripe_payment_intent_id' => $intent['id'],
            'metadata' => ['stripe_status' => $intent['status']],
        ]);
        $order->update(['payment_status' => PaymentStatus::Pending]);

        return [
            'order' => $order->fresh(['items', 'latestPayment']),
            'payment' => $payment->fresh(),
            'client_secret' => $intent['client_secret'],
            'publishable_key' => (string) config('services.stripe.key'),
        ];
    }
}
