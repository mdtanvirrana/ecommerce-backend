<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\OrderCancelledMail;
use App\Mail\OrderConfirmedMail;
use App\Mail\OrderStatusChangedMail;
use App\Mail\PaymentSucceededMail;
use App\Mail\RefundProcessedMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Staff;
use App\Services\StripeGatewayInterface;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoCommerceSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeStripeGateway;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    $this->seed([RbacSeeder::class, CatalogSeeder::class, DemoCommerceSeeder::class]);
    $this->fakeStripe = new FakeStripeGateway;
    $this->app->instance(StripeGatewayInterface::class, $this->fakeStripe);
    config(['services.stripe.webhook_secret' => 'whsec_test_secret']);
});

it('completes full secure Stripe webhook checkout flow, deducts stock once, handles duplicates, and sends emails', function (): void {
    $customer = Customer::query()->where('email', 'customer@shopco.local')->firstOrFail();
    $token = $customer->createToken('customer-auth', ['customer'])->plainTextToken;

    $product = Product::query()->where('slug', 'one-life-graphic-t-shirt')->firstOrFail();
    $initialStock = $product->stock_quantity;

    $this->withToken($token)->postJson('/api/v1/cart/items', [
        'product_id' => $product->id,
        'quantity' => 2,
        'selected_color' => 'Olive',
        'selected_size' => 'Large',
    ])->assertCreated();

    $checkout = $this->withToken($token)->postJson('/api/v1/checkout', [
        'shipping_full_name' => 'Sarah Miller',
        'shipping_email' => $customer->email,
        'shipping_phone' => '+1-555-0201',
        'shipping_address' => '742 Evergreen Terrace',
        'shipping_city' => 'New York',
        'shipping_postal_code' => '10001',
    ])->assertCreated();

    $orderId = $checkout->json('data.order.id');
    $piId = $checkout->json('data.payment.stripe_payment_intent_id');

    // Stock MUST NOT be decremented before verified Stripe payment
    expect($product->fresh()->stock_quantity)->toBe($initialStock);

    // Invalid webhook signature MUST be rejected
    $payload = json_encode([
        'id' => 'evt_test_001',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => $piId]],
    ]);
    $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 't=0,v1=forged_signature',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertStatus(400);

    expect($product->fresh()->stock_quantity)->toBe($initialStock);

    // Mark PaymentIntent succeeded on Stripe side and deliver valid webhook
    $this->fakeStripe->markIntentSucceeded($piId);

    $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $this->fakeStripe->validSignature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk()->assertJsonPath('data.status', 'processed');

    $order = Order::query()->findOrFail($orderId);
    expect($order->status)->toBe(OrderStatus::Paid);
    expect($order->payment_status)->toBe(PaymentStatus::Succeeded);
    expect($product->fresh()->stock_quantity)->toBe($initialStock - 2);

    // Duplicate webhook MUST be idempotent and NOT deduct stock a second time
    $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $this->fakeStripe->validSignature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk()->assertJsonPath('data.duplicate', true);

    expect($product->fresh()->stock_quantity)->toBe($initialStock - 2);

    Mail::assertQueued(OrderConfirmedMail::class, 1);
    Mail::assertQueued(PaymentSucceededMail::class, 1);
});

it('does not decrement stock or mark order paid when Stripe payment fails', function (): void {
    $customer = Customer::query()->where('email', 'customer@shopco.local')->firstOrFail();
    $token = $customer->createToken('customer-auth', ['customer'])->plainTextToken;

    $product = Product::query()->where('slug', 'checkered-shirt')->firstOrFail();
    $initialStock = $product->stock_quantity;

    $this->withToken($token)->postJson('/api/v1/cart/items', [
        'product_id' => $product->id,
        'quantity' => 1,
    ])->assertCreated();

    $checkout = $this->withToken($token)->postJson('/api/v1/checkout', [
        'shipping_full_name' => 'Sarah Miller',
        'shipping_email' => $customer->email,
        'shipping_phone' => '+1-555-0201',
        'shipping_address' => '742 Evergreen Terrace',
        'shipping_city' => 'New York',
        'shipping_postal_code' => '10001',
    ])->assertCreated();

    $orderId = $checkout->json('data.order.id');
    $piId = $checkout->json('data.payment.stripe_payment_intent_id');

    $this->fakeStripe->markIntentFailed($piId);

    $payload = json_encode([
        'id' => 'evt_test_fail_001',
        'type' => 'payment_intent.payment_failed',
        'data' => [
            'object' => [
                'id' => $piId,
                'last_payment_error' => ['code' => 'card_declined', 'message' => 'Insufficient funds'],
            ],
        ],
    ]);

    $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $this->fakeStripe->validSignature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    $order = Order::query()->findOrFail($orderId);
    expect($order->status)->toBe(OrderStatus::Pending);
    expect($order->payment_status)->toBe(PaymentStatus::Failed);
    expect($product->fresh()->stock_quantity)->toBe($initialStock);
});

it('prevents overselling when two customers race for the last unit and refunds the second safely', function (): void {
    $c1 = Customer::query()->where('email', 'customer@shopco.local')->firstOrFail();
    $c2 = Customer::query()->where('email', 'alex@example.com')->firstOrFail();
    $t1 = $c1->createToken('customer-auth', ['customer'])->plainTextToken;
    $t2 = $c2->createToken('customer-auth', ['customer'])->plainTextToken;

    $product = Product::query()->where('slug', 'compression-training-tank')->firstOrFail();
    $product->update(['stock_quantity' => 1]);

    // Both customers add the last unit and initiate checkout concurrently
    auth()->forgetGuards();
    $this->withToken($t1)->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
    auth()->forgetGuards();
    $this->withToken($t2)->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertCreated();

    auth()->forgetGuards();
    $co1 = $this->withToken($t1)->postJson('/api/v1/checkout', [
        'shipping_full_name' => 'C1',
        'shipping_email' => $c1->email,
        'shipping_phone' => '+1-555-1111',
        'shipping_address' => '1 Main St',
        'shipping_city' => 'NYC',
        'shipping_postal_code' => '10001',
    ])->assertCreated();

    auth()->forgetGuards();
    $co2 = $this->withToken($t2)->postJson('/api/v1/checkout', [
        'shipping_full_name' => 'C2',
        'shipping_email' => $c2->email,
        'shipping_phone' => '+1-555-2222',
        'shipping_address' => '2 Main St',
        'shipping_city' => 'NYC',
        'shipping_postal_code' => '10001',
    ])->assertCreated();

    $pi1 = $co1->json('data.payment.stripe_payment_intent_id');
    $pi2 = $co2->json('data.payment.stripe_payment_intent_id');
    $this->fakeStripe->markIntentSucceeded($pi1);
    $this->fakeStripe->markIntentSucceeded($pi2);

    // Customer 1 payment confirms first -> succeeds and stock drops 1 -> 0
    auth()->forgetGuards();
    $this->withToken($t1)->postJson("/api/v1/orders/{$co1->json('data.order.id')}/confirm-payment")->assertOk();
    expect($product->fresh()->stock_quantity)->toBe(0);
    expect(Order::findOrFail($co1->json('data.order.id'))->status)->toBe(OrderStatus::Paid);

    // Customer 2 payment confirms second -> stock is 0, NEVER negative, auto-refunded & cancelled
    auth()->forgetGuards();
    $this->withToken($t2)->postJson("/api/v1/orders/{$co2->json('data.order.id')}/confirm-payment")->assertOk();
    $order2 = Order::findOrFail($co2->json('data.order.id'));
    expect($product->fresh()->stock_quantity)->toBe(0);
    expect($order2->status)->toBe(OrderStatus::Cancelled);
    expect($order2->payment_status)->toBe(PaymentStatus::Refunded);
    expect(count($this->fakeStripe->refunds))->toBe(1);
    Mail::assertQueued(RefundProcessedMail::class, 1);
});

it('restores stock strictly once on cancellation, validates lifecycle transitions, and enforces IDOR protection', function (): void {
    $c1 = Customer::query()->where('email', 'customer@shopco.local')->firstOrFail();
    $c2 = Customer::query()->where('email', 'alex@example.com')->firstOrFail();
    $t1 = $c1->createToken('customer-auth', ['customer'])->plainTextToken;
    $t2 = $c2->createToken('customer-auth', ['customer'])->plainTextToken;

    $admin = Staff::query()->where('email', 'orders@shopco.local')->firstOrFail();
    $adminToken = $admin->createToken('admin-auth', ['staff'])->plainTextToken;

    $product = Product::query()->where('slug', 'faded-skinny-jeans')->firstOrFail();
    $initialStock = $product->stock_quantity;

    $this->withToken($t1)->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 3])->assertCreated();
    $co = $this->withToken($t1)->postJson('/api/v1/checkout', [
        'shipping_full_name' => 'Sarah',
        'shipping_email' => $c1->email,
        'shipping_phone' => '+1-555-0201',
        'shipping_address' => '742 Evergreen Terrace',
        'shipping_city' => 'New York',
        'shipping_postal_code' => '10001',
    ])->assertCreated();

    $orderId = $co->json('data.order.id');
    $piId = $co->json('data.payment.stripe_payment_intent_id');

    // IDOR: Customer 2 CANNOT view or confirm Customer 1's order
    auth()->forgetGuards();
    $this->withToken($t2)->getJson("/api/v1/orders/{$orderId}")->assertForbidden();
    $this->withToken($t2)->postJson("/api/v1/orders/{$orderId}/confirm-payment")->assertForbidden();

    // Unpaid pending order cannot jump to shipped or delivered (409)
    $this->withToken($adminToken)->patchJson("/api/v1/admin/orders/{$orderId}/status", [
        'status' => 'shipped',
    ])->assertStatus(409);

    // Customer 1 confirms paid order -> stock drops by 3
    $this->fakeStripe->markIntentSucceeded($piId);
    auth()->forgetGuards();
    $this->withToken($t1)->postJson("/api/v1/orders/{$orderId}/confirm-payment")->assertOk();
    expect($product->fresh()->stock_quantity)->toBe($initialStock - 3);

    // Backward jump paid -> pending rejected (409)
    $this->withToken($adminToken)->patchJson("/api/v1/admin/orders/{$orderId}/status", [
        'status' => 'pending',
    ])->assertStatus(409);

    // Valid forward transitions paid -> processing -> shipped succeed and queue status mail (without double-deducting stock)
    $this->withToken($adminToken)->patchJson("/api/v1/admin/orders/{$orderId}/status", [
        'status' => 'processing',
    ])->assertOk()->assertJsonPath('data.status', 'processing');

    $this->withToken($adminToken)->patchJson("/api/v1/admin/orders/{$orderId}/status", [
        'status' => 'shipped',
    ])->assertOk()->assertJsonPath('data.status', 'shipped');

    expect($product->fresh()->stock_quantity)->toBe($initialStock - 3);
    Mail::assertQueued(OrderStatusChangedMail::class, 2);

    // Cancel paid/shipped order -> restores stock back to initialStock + queues cancel & refund mail
    $this->withToken($adminToken)->postJson("/api/v1/admin/orders/{$orderId}/cancel", [
        'reason' => 'Customer requested cancellation',
    ])->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect($product->fresh()->stock_quantity)->toBe($initialStock);
    Mail::assertQueued(OrderCancelledMail::class, 1);
    Mail::assertQueued(RefundProcessedMail::class, 1);

    // Second cancel attempt & post-cancel jump to delivered rejected (409) and stock is NOT restored twice
    $this->withToken($adminToken)->postJson("/api/v1/admin/orders/{$orderId}/cancel")->assertStatus(409);
    $this->withToken($adminToken)->patchJson("/api/v1/admin/orders/{$orderId}/status", [
        'status' => 'delivered',
    ])->assertStatus(409);
    expect($product->fresh()->stock_quantity)->toBe($initialStock);
});

it('allows authorized staff to update order payment status with atomic stock deduction, guardrails, and refund syncing', function (): void {
    Mail::fake();

    $customerToken = $this->postJson('/api/v1/login', [
        'email' => 'customer@shopco.local',
        'password' => 'Customer@123!',
    ])->json('data.token');

    $adminToken = $this->postJson('/api/v1/admin/login', [
        'email' => 'orders@shopco.local',
        'password' => 'Staff@123!',
    ])->json('data.token');

    $supportToken = $this->postJson('/api/v1/admin/login', [
        'email' => 'support@shopco.local',
        'password' => 'Staff@123!',
    ])->json('data.token');

    $product = Product::query()->where('stock_quantity', '>=', 5)->firstOrFail();
    $initialStock = $product->stock_quantity;

    // Customer creates a pending order
    auth()->forgetGuards();
    $this->withToken($customerToken)->postJson('/api/v1/cart/items', [
        'product_id' => $product->id,
        'quantity' => 2,
    ])->assertCreated();

    $co = $this->withToken($customerToken)->postJson('/api/v1/checkout', [
        'shipping_full_name' => 'Sarah Miller',
        'shipping_email' => 'customer@shopco.local',
        'shipping_phone' => '+1-555-0201',
        'shipping_address' => '210 Fifth Ave',
        'shipping_city' => 'New York',
        'shipping_postal_code' => '10010',
    ])->assertCreated();

    $orderId = $co->json('data.order.id');

    // Support role lacks orders.update_status -> 403
    auth()->forgetGuards();
    $this->withToken($supportToken)->patchJson("/api/v1/admin/orders/{$orderId}/payment-status", [
        'payment_status' => 'succeeded',
    ])->assertForbidden();

    // Cannot refund an unpaid pending order -> 409
    auth()->forgetGuards();
    $this->withToken($adminToken)->patchJson("/api/v1/admin/orders/{$orderId}/payment-status", [
        'payment_status' => 'refunded',
    ])->assertStatus(409);

    // Mark payment succeeded -> deducts stock atomically & advances order status pending -> paid
    $paidRes = $this->withToken($adminToken)->patchJson("/api/v1/admin/orders/{$orderId}/payment-status", [
        'payment_status' => 'succeeded',
        'note' => 'Verified offline bank settlement',
    ]);

    $paidRes->assertOk()
        ->assertJsonPath('data.payment_status', 'succeeded')
        ->assertJsonPath('data.status', 'paid');

    expect($product->fresh()->stock_quantity)->toBe($initialStock - 2);
    Mail::assertQueued(PaymentSucceededMail::class, 1);
    Mail::assertQueued(OrderConfirmedMail::class, 1);

    // Paid order can transition directly to shipped and delivered (stock remains deducted strictly once)
    $this->withToken($adminToken)->patchJson("/api/v1/admin/orders/{$orderId}/status", [
        'status' => 'shipped',
    ])->assertOk()->assertJsonPath('data.status', 'shipped');

    $this->withToken($adminToken)->patchJson("/api/v1/admin/orders/{$orderId}/status", [
        'status' => 'delivered',
    ])->assertOk()->assertJsonPath('data.status', 'delivered');

    expect($product->fresh()->stock_quantity)->toBe($initialStock - 2);

    // Delivered order is terminal: cannot cancel or go back to pending
    $this->withToken($adminToken)->postJson("/api/v1/admin/orders/{$orderId}/cancel")->assertStatus(409);
    $this->withToken($adminToken)->patchJson("/api/v1/admin/orders/{$orderId}/status", [
        'status' => 'pending',
    ])->assertStatus(409);

    // Mark payment refunded -> syncs order & payment record + queues refund email
    $refundRes = $this->withToken($adminToken)->patchJson("/api/v1/admin/orders/{$orderId}/payment-status", [
        'payment_status' => 'refunded',
        'note' => 'Manual refund issued by ops',
    ]);

    $refundRes->assertOk()
        ->assertJsonPath('data.payment_status', 'refunded');

    Mail::assertQueued(RefundProcessedMail::class, 1);
});

it('rejects checkout and pre-payment validation with clear messages when stock changes between add-to-cart and payment', function (): void {
    $c1 = Customer::query()->where('email', 'customer@shopco.local')->firstOrFail();
    $c2 = Customer::query()->where('email', 'alex@example.com')->firstOrFail();
    $t1 = $c1->createToken('customer-auth', ['customer'])->plainTextToken;
    $t2 = $c2->createToken('customer-auth', ['customer'])->plainTextToken;

    $admin = Staff::query()->where('email', 'orders@shopco.local')->firstOrFail();
    $adminToken = $admin->createToken('admin-auth', ['staff'])->plainTextToken;

    $product = Product::query()->where('slug', 'compression-training-tank')->firstOrFail();
    $product->update(['stock_quantity' => 5]);

    // 1. Customer adds 3 units while stock is 5
    auth()->forgetGuards();
    $this->withToken($t1)->postJson('/api/v1/cart/items', [
        'product_id' => $product->id,
        'quantity' => 3,
    ])->assertCreated();

    // 2. Stock changes to 2 BEFORE checkout -> checkout rejects with 409 & clear message, stock untouched
    $product->update(['stock_quantity' => 2]);
    $ordersBefore = Order::query()->count();

    auth()->forgetGuards();
    $this->withToken($t1)->postJson('/api/v1/checkout', [
        'shipping_full_name' => 'Sarah Miller',
        'shipping_email' => $c1->email,
        'shipping_phone' => '+1-555-0201',
        'shipping_address' => '742 Evergreen Terrace',
        'shipping_city' => 'New York',
        'shipping_postal_code' => '10001',
    ])
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertSee('Insufficient stock for');

    expect(Order::query()->count())->toBe($ordersBefore);
    expect($product->fresh()->stock_quantity)->toBe(2);

    // 3. Stock returns to 3 -> checkout creates pending order WITHOUT deducting stock yet
    $product->update(['stock_quantity' => 3]);
    auth()->forgetGuards();
    $checkout = $this->withToken($t1)->postJson('/api/v1/checkout', [
        'shipping_full_name' => 'Sarah Miller',
        'shipping_email' => $c1->email,
        'shipping_phone' => '+1-555-0201',
        'shipping_address' => '742 Evergreen Terrace',
        'shipping_city' => 'New York',
        'shipping_postal_code' => '10001',
    ])->assertCreated();

    $orderId = $checkout->json('data.order.id');
    expect($product->fresh()->stock_quantity)->toBe(3);

    // Pre-payment stock validation passes while stock >= 3, and enforces IDOR (403 for other customer)
    auth()->forgetGuards();
    $this->withToken($t2)->postJson("/api/v1/orders/{$orderId}/validate-stock")->assertForbidden();
    auth()->forgetGuards();
    $this->withToken($t1)->postJson("/api/v1/orders/{$orderId}/validate-stock")
        ->assertOk()
        ->assertJsonPath('data.stock_available', true);

    // 4. Stock drops to 1 BETWEEN checkout and clicking Pay -> pre-payment validation rejects with 409 + clear message
    $product->update(['stock_quantity' => 1]);
    auth()->forgetGuards();
    $this->withToken($t1)->postJson("/api/v1/orders/{$orderId}/validate-stock")
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertSee('Order rejected: insufficient stock for');

    // 5. Cancelling an UNPAID pending order does NOT inflate stock (remains 1)
    auth()->forgetGuards();
    $this->withToken($adminToken)->postJson("/api/v1/admin/orders/{$orderId}/cancel", [
        'reason' => 'Stock no longer available before payment',
    ])->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect($product->fresh()->stock_quantity)->toBe(1);
});
