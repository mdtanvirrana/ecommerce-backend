<?php

use App\Enums\CouponType;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Staff;
use App\Services\StripeGatewayInterface;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoCommerceSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeStripeGateway;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    $this->seed([RbacSeeder::class, CatalogSeeder::class, DemoCommerceSeeder::class]);
    $this->fakeStripe = new FakeStripeGateway;
    $this->app->instance(StripeGatewayInterface::class, $this->fakeStripe);
});

it('returns live database statistics and 30-day chart on admin dashboard', function (): void {
    $superAdmin = Staff::query()->where('email', 'superadmin@shopco.local')->firstOrFail();
    $token = $superAdmin->createToken('admin-auth', ['staff'])->plainTextToken;

    $res = $this->withToken($token)->getJson('/api/v1/admin/dashboard')->assertOk();

    expect($res->json('data.stats.total_customers'))->toBe(Customer::query()->count());
    expect($res->json('data.stats.total_orders'))->toBeGreaterThanOrEqual(8);
    expect($res->json('data.sales_chart'))->toHaveCount(30);
    expect(count($res->json('data.recent_orders')))->toBeGreaterThan(0);
});

it('supports product multi-image upload, stock adjustment audit trail, and image deletion', function (): void {
    Storage::fake('public');

    $productMgr = Staff::query()->where('email', 'products@shopco.local')->firstOrFail();
    $token = $productMgr->createToken('admin-auth', ['staff'])->plainTextToken;
    $category = Category::query()->where('slug', 't-shirts')->firstOrFail();

    $create = $this->withToken($token)->post('/api/v1/admin/products', [
        'category_id' => $category->id,
        'name' => 'Studio Cotton Tee',
        'sku' => 'SHOP-STUDIO-999',
        'regular_price' => 110,
        'discount_price' => 90,
        'stock_quantity' => 12,
        'images' => [
            UploadedFile::fake()->image('front.jpg', 300, 300),
            UploadedFile::fake()->image('back.png', 300, 300),
        ],
    ], ['Accept' => 'application/json'])->assertCreated();

    $productId = $create->json('data.id');
    expect($create->json('data.images'))->toHaveCount(2);

    $this->withToken($token)->patchJson("/api/v1/admin/products/{$productId}/stock", [
        'stock_quantity' => 25,
        'notes' => 'Restocked from warehouse',
    ])->assertOk()->assertJsonPath('data.stock_quantity', 25);

    expect(
        InventoryTransaction::query()
            ->where('product_id', $productId)
            ->where('stock_before', 12)
            ->where('stock_after', 25)
            ->exists()
    )->toBeTrue();

    $firstImageId = $create->json('data.images.0.id');
    $this->withToken($token)->deleteJson("/api/v1/admin/products/{$productId}/images/{$firstImageId}")
        ->assertOk()
        ->assertJsonCount(1, 'data.images');
});

it('merges guest cart items into authenticated customer cart and enforces per-customer coupon limits', function (): void {
    $customer = Customer::query()->where('email', 'customer@shopco.local')->firstOrFail();
    $token = $customer->createToken('customer-auth', ['customer'])->plainTextToken;
    $product = Product::query()->where('slug', 't-shirt-with-tape-details')->firstOrFail();

    // Merge guest cart
    $this->withToken($token)->postJson('/api/v1/cart/merge', [
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2, 'selected_color' => 'Black', 'selected_size' => 'Large'],
        ],
    ])->assertOk()->assertJsonPath('data.item_count', 2);

    $coupon = Coupon::query()->create([
        'code' => 'ONCEONLY',
        'type' => CouponType::Fixed,
        'value' => '20.00',
        'minimum_order_amount' => '50.00',
        'usage_limit' => 10,
        'per_customer_usage_limit' => 1,
        'is_active' => true,
    ]);

    // First order succeeds using ONCEONLY
    $co = $this->withToken($token)->postJson('/api/v1/checkout', [
        'shipping_full_name' => 'Sarah',
        'shipping_email' => $customer->email,
        'shipping_phone' => '+1-555-0201',
        'shipping_address' => '742 Evergreen Terrace',
        'shipping_city' => 'NYC',
        'shipping_postal_code' => '10001',
        'coupon_code' => $coupon->code,
    ])->assertCreated();

    $piId = $co->json('data.payment.stripe_payment_intent_id');
    $this->fakeStripe->markIntentSucceeded($piId);
    $this->withToken($token)->postJson("/api/v1/orders/{$co->json('data.order.id')}/confirm-payment")->assertOk();

    // Second attempt by same customer MUST reject ONCEONLY
    $this->withToken($token)->postJson('/api/v1/coupons/validate', [
        'code' => $coupon->code,
        'subtotal' => 200,
    ])->assertStatus(422);
});

it('rejects Stripe payment confirmation if Stripe received amount mismatches order total', function (): void {
    $customer = Customer::query()->where('email', 'customer@shopco.local')->firstOrFail();
    $token = $customer->createToken('customer-auth', ['customer'])->plainTextToken;
    $product = Product::query()->where('slug', 'checkered-shirt')->firstOrFail();
    $initialStock = $product->stock_quantity;

    $this->withToken($token)->postJson('/api/v1/cart/items', [
        'product_id' => $product->id,
        'quantity' => 1,
    ])->assertCreated();

    $co = $this->withToken($token)->postJson('/api/v1/checkout', [
        'shipping_full_name' => 'Sarah',
        'shipping_email' => $customer->email,
        'shipping_phone' => '+1-555-0201',
        'shipping_address' => '742 Evergreen Terrace',
        'shipping_city' => 'NYC',
        'shipping_postal_code' => '10001',
    ])->assertCreated();

    $piId = $co->json('data.payment.stripe_payment_intent_id');
    // Simulate tampered / underpaid amount (only 100 cents instead of real order total)
    $this->fakeStripe->markIntentSucceeded($piId, 100);

    $this->withToken($token)->postJson("/api/v1/orders/{$co->json('data.order.id')}/confirm-payment")
        ->assertStatus(409);

    expect($product->fresh()->stock_quantity)->toBe($initialStock);
});

it('throttles staff login after 5 failed attempts and validates password update', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/admin/login', [
            'email' => 'support@shopco.local',
            'password' => 'WrongPassword@1!',
        ])->assertUnauthorized();
    }

    $this->postJson('/api/v1/admin/login', [
        'email' => 'support@shopco.local',
        'password' => 'Staff@123!',
    ])->assertStatus(429);

    $admin = Staff::query()->where('email', 'admin@shopco.local')->firstOrFail();
    $token = $admin->createToken('admin-auth', ['staff'])->plainTextToken;

    $this->withToken($token)->putJson('/api/v1/admin/profile/password', [
        'current_password' => 'WrongCurrent@1!',
        'password' => 'NewStrongPass@123!',
        'password_confirmation' => 'NewStrongPass@123!',
    ])->assertStatus(422);

    $this->withToken($token)->putJson('/api/v1/admin/profile/password', [
        'current_password' => 'Admin@123!',
        'password' => 'NewStrongPass@123!',
        'password_confirmation' => 'NewStrongPass@123!',
    ])->assertOk();
});
