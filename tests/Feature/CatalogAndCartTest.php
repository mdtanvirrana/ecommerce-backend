<?php

use App\Enums\CouponType;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Staff;
use App\Services\StripeGatewayInterface;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoCommerceSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeStripeGateway;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RbacSeeder::class, CatalogSeeder::class, DemoCommerceSeeder::class]);
    $this->fakeStripe = new FakeStripeGateway;
    $this->app->instance(StripeGatewayInterface::class, $this->fakeStripe);
});

it('supports product listing, search, category parent/child filtering, sorting, and server-side pagination', function (): void {
    $this->getJson('/api/v1/products?per_page=6&page=1')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.per_page', 6)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonCount(6, 'data');

    // Search
    $search = $this->getJson('/api/v1/products?search=One+Life')
        ->assertOk();
    expect($search->json('data.0.slug'))->toBe('one-life-graphic-t-shirt');

    // Parent category filtering includes child categories (Casual -> T-shirts, Jeans, Shorts, Hoodies)
    $casual = $this->getJson('/api/v1/products?category=casual&per_page=50')->assertOk();
    expect(count($casual->json('data')))->toBeGreaterThanOrEqual(10);

    // Price sorting ascending
    $sorted = $this->getJson('/api/v1/products?sort=price_asc&per_page=5')->assertOk();
    $prices = array_map(fn ($p) => (float) $p['effective_price'], $sorted->json('data'));
    $expected = $prices;
    sort($expected);
    expect($prices)->toBe($expected);
});

it('prevents adding out-of-stock or excessive quantity products to cart', function (): void {
    $customer = Customer::query()->firstOrFail();
    $token = $customer->createToken('customer-auth', ['customer'])->plainTextToken;

    $outOfStock = Product::query()->where('stock_quantity', 0)->firstOrFail();
    $this->withToken($token)->postJson('/api/v1/cart/items', [
        'product_id' => $outOfStock->id,
        'quantity' => 1,
    ])->assertStatus(409);

    $inStock = Product::query()->where('stock_quantity', '>', 0)->firstOrFail();
    $this->withToken($token)->postJson('/api/v1/cart/items', [
        'product_id' => $inStock->id,
        'quantity' => $inStock->stock_quantity + 5,
    ])->assertStatus(409);
});

it('validates coupons strictly on backend and rejects invalid, expired, minimum-order, and exhausted coupons', function (): void {
    $customer = Customer::query()->firstOrFail();
    $token = $customer->createToken('customer-auth', ['customer'])->plainTextToken;

    // Invalid
    $this->withToken($token)->postJson('/api/v1/coupons/validate', [
        'code' => 'DOESNOTEXIST',
        'subtotal' => 200,
    ])->assertStatus(422);

    // Expired
    $this->withToken($token)->postJson('/api/v1/coupons/validate', [
        'code' => 'EXPIRED10',
        'subtotal' => 200,
    ])->assertStatus(422);

    // Below minimum order ($150 for SAVE25)
    $this->withToken($token)->postJson('/api/v1/coupons/validate', [
        'code' => 'SAVE25',
        'subtotal' => 100,
    ])->assertStatus(422);

    // Exhausted usage limit
    Coupon::query()->create([
        'code' => 'MAXEDOUT',
        'type' => CouponType::Fixed,
        'value' => '10.00',
        'minimum_order_amount' => '10.00',
        'usage_limit' => 5,
        'used_count' => 5,
        'is_active' => true,
    ]);
    $this->withToken($token)->postJson('/api/v1/coupons/validate', [
        'code' => 'MAXEDOUT',
        'subtotal' => 200,
    ])->assertStatus(422);

    // Valid WELCOME20 (20% off $200 = $40)
    $this->withToken($token)->postJson('/api/v1/coupons/validate', [
        'code' => 'WELCOME20',
        'subtotal' => 200,
    ])->assertOk()
        ->assertJsonPath('data.discount_amount', '40.00')
        ->assertJsonPath('data.final_subtotal', '160.00');
});

it('ignores tampered frontend prices/totals during checkout and calculates strictly from MySQL', function (): void {
    $customer = Customer::query()->firstOrFail();
    $token = $customer->createToken('customer-auth', ['customer'])->plainTextToken;

    $product = Product::query()->where('slug', 't-shirt-with-tape-details')->firstOrFail(); // $120.00

    $this->withToken($token)->postJson('/api/v1/cart/items', [
        'product_id' => $product->id,
        'quantity' => 2,
    ])->assertCreated();

    // Attacker tries to inject $1.00 total, $999 discount, $0 shipping
    $checkout = $this->withToken($token)->postJson('/api/v1/checkout', [
        'shipping_full_name' => 'Attacker Test',
        'shipping_email' => $customer->email,
        'shipping_phone' => '+1-555-9999',
        'shipping_address' => '123 Hack St',
        'shipping_city' => 'Austin',
        'shipping_postal_code' => '73301',
        'subtotal' => 1.00,
        'discount_amount' => 239.00,
        'shipping_amount' => 0.00,
        'total_amount' => 1.00,
    ])->assertCreated();

    // Real subtotal = 2 * $120.00 = $240.00, shipping = $15.00 (< $250 threshold), total = $255.00
    $checkout->assertJsonPath('data.order.subtotal', '240.00')
        ->assertJsonPath('data.order.discount_amount', '0.00')
        ->assertJsonPath('data.order.shipping_amount', '15.00')
        ->assertJsonPath('data.order.total_amount', '255.00');
});

it('prevents circular parent-child category relationships and duplicate SKUs', function (): void {
    $superAdmin = Staff::query()->where('email', 'superadmin@shopco.local')->firstOrFail();
    $token = $superAdmin->createToken('admin-auth', ['staff'])->plainTextToken;

    $casual = Category::query()->where('slug', 'casual')->firstOrFail();
    $tshirts = Category::query()->where('slug', 't-shirts')->firstOrFail();

    // Attempting to make parent Casual a child of its own child T-shirts -> 422
    $this->withToken($token)->putJson("/api/v1/admin/categories/{$casual->id}", [
        'parent_id' => $tshirts->id,
    ])->assertStatus(422);

    // Duplicate SKU rejected with 422
    $this->withToken($token)->postJson('/api/v1/admin/products', [
        'category_id' => $tshirts->id,
        'name' => 'Dup Product',
        'sku' => 'SHOP-TS-001',
        'regular_price' => 50,
        'stock_quantity' => 10,
    ])->assertStatus(422)->assertJsonValidationErrors(['sku']);
});
