<?php

use App\Enums\CouponType;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Staff;
use App\Services\StripeGatewayInterface;
use App\Support\CatalogCache;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoCommerceSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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

it('supports category image upload and dynamic product reviews with exact count sync and admin moderation', function (): void {
    Storage::fake('public');

    $admin = Staff::query()->where('email', 'superadmin@shopco.local')->firstOrFail();
    $adminToken = $admin->createToken('admin-auth', ['staff'])->plainTextToken;

    // Create category with image upload
    $catRes = $this->withToken($adminToken)->post('/api/v1/admin/categories', [
        'name' => 'Streetwear Capsule',
        'description' => 'Limited drops',
        'image' => UploadedFile::fake()->image('streetwear.jpg', 400, 300),
    ], ['Accept' => 'application/json'])->assertCreated();

    expect($catRes->json('data.image_url'))->not->toBeNull();

    // Customer submits review -> exact reviews_count synced
    $customer = Customer::query()->where('email', 'customer@shopco.local')->firstOrFail();
    $custToken = $customer->createToken('customer-auth', ['customer'])->plainTextToken;
    $product = Product::query()->where('slug', 't-shirt-with-tape-details')->firstOrFail();

    auth()->forgetGuards();
    $reviewRes = $this->withToken($custToken)->postJson("/api/v1/products/{$product->slug}/reviews", [
        'rating' => 5,
        'comment' => 'Amazing heavyweight cotton tee with great fit and clean tape detail!',
    ])->assertCreated();

    $reviewId = $reviewRes->json('data.id');

    $listRes = $this->getJson("/api/v1/products/{$product->slug}/reviews?per_page=3")->assertOk();
    expect($listRes->json('meta.total'))->toBe(1);
    expect($product->fresh()->reviews_count)->toBe(1);

    // Admin lists, features, and deletes review -> count returns to 0
    auth()->forgetGuards();
    $this->withToken($adminToken)->getJson('/api/v1/admin/reviews')
        ->assertOk()
        ->assertJsonPath('data.summary.total_reviews', 1);

    $this->withToken($adminToken)->patchJson("/api/v1/admin/reviews/{$reviewId}", [
        'is_featured' => true,
        'is_approved' => true,
    ])->assertOk()->assertJsonPath('data.is_featured', true);

    $this->getJson('/api/v1/reviews/featured')->assertOk()->assertJsonCount(1, 'data');

    auth()->forgetGuards();
    $this->withToken($adminToken)->deleteJson("/api/v1/admin/reviews/{$reviewId}")->assertOk();
    expect($product->fresh()->reviews_count)->toBe(0);
});

it('preserves clean nested arrays when serving public catalog endpoints from file cache', function (): void {
    config()->set('cache.default', 'file');
    Cache::setDefaultDriver('file');
    CatalogCache::invalidate();

    // First call populates file cache; second call reads unserialized payload from file cache
    foreach ([1, 2] as $attempt) {
        $cats = $this->getJson('/api/v1/categories')->assertOk();
        expect($cats->getContent())->not->toContain('__PHP_Incomplete_Class');
        expect($cats->json('data.0.children'))->toBeArray()->not->toBeEmpty();

        $prods = $this->getJson('/api/v1/products?per_page=2')->assertOk();
        expect($prods->getContent())->not->toContain('__PHP_Incomplete_Class');
        expect($prods->json('data.0.images'))->toBeArray();
        expect($prods->json('data.0.category.slug'))->toBeString();

        $slug = (string) $prods->json('data.0.slug');
        $detail = $this->getJson("/api/v1/products/{$slug}")->assertOk();
        expect($detail->getContent())->not->toContain('__PHP_Incomplete_Class');
        expect($detail->json('data.product.images'))->toBeArray();
        expect($detail->json('data.related_products'))->toBeArray();

        $reviews = $this->getJson('/api/v1/reviews/featured')->assertOk();
        expect($reviews->getContent())->not->toContain('__PHP_Incomplete_Class');
        expect($reviews->json('data'))->toBeArray();
    }
});
