<?php

use App\Models\Customer;
use App\Models\Staff;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

it('registers a customer with strong password validation and excludes password hash', function (): void {
    $weak = $this->postJson('/api/v1/register', [
        'name' => 'Weak Pass',
        'email' => 'weak@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);
    $weak->assertStatus(422)->assertJsonPath('success', false);

    $response = $this->postJson('/api/v1/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+1-555-0199',
        'password' => 'StrongPass@123!',
        'password_confirmation' => 'StrongPass@123!',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', 'jane@example.com')
        ->assertJsonMissingPath('data.user.password')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');

    $customer = Customer::query()->where('email', 'jane@example.com')->firstOrFail();
    expect(Hash::check('StrongPass@123!', $customer->password))->toBeTrue();
});

it('authenticates customer, revokes token on logout, and throttles after 5 failed attempts', function (): void {
    $customer = Customer::query()->create([
        'name' => 'John Customer',
        'email' => 'john@example.com',
        'password' => Hash::make('Customer@123!'),
        'is_active' => true,
    ]);

    // 5 failed attempts return generic 401 message
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/login', [
            'email' => 'john@example.com',
            'password' => 'WrongPass@123!',
        ])->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');
    }

    // 6th attempt is throttled with 429
    $this->postJson('/api/v1/login', [
        'email' => 'john@example.com',
        'password' => 'Customer@123!',
    ])->assertStatus(429);

    // Fresh customer login works and token revocation works on logout
    $other = Customer::query()->create([
        'name' => 'Fresh User',
        'email' => 'fresh@example.com',
        'password' => Hash::make('Customer@123!'),
        'is_active' => true,
    ]);

    $login = $this->postJson('/api/v1/login', [
        'email' => $other->email,
        'password' => 'Customer@123!',
    ])->assertOk();

    $token = $login->json('data.token');

    $this->withToken($token)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'fresh@example.com');

    $this->withToken($token)->postJson('/api/v1/logout')->assertOk();

    // After logout, token is deleted from personal_access_tokens
    expect($other->tokens()->count())->toBe(0);
});

it('enforces strict guard separation between customer tokens and staff tokens', function (): void {
    $customer = Customer::query()->create([
        'name' => 'Shopper',
        'email' => 'shopper@example.com',
        'password' => Hash::make('Customer@123!'),
        'is_active' => true,
    ]);
    $customerToken = $customer->createToken('customer-auth', ['customer'])->plainTextToken;

    $staff = Staff::query()->where('email', 'superadmin@shopco.local')->firstOrFail();
    $staffToken = $staff->createToken('admin-auth', ['staff'])->plainTextToken;

    // Customer token CANNOT access admin routes
    $this->withToken($customerToken)->getJson('/api/v1/admin/me')->assertUnauthorized();
    $this->withToken($customerToken)->getJson('/api/v1/admin/dashboard')->assertUnauthorized();

    // Staff token CANNOT access customer routes
    $this->withToken($staffToken)->getJson('/api/v1/me')->assertUnauthorized();
    $this->withToken($staffToken)->getJson('/api/v1/cart')->assertUnauthorized();

    // Staff login via dedicated admin endpoint succeeds
    $this->postJson('/api/v1/admin/login', [
        'email' => 'superadmin@shopco.local',
        'password' => 'SuperAdmin@123!',
    ])->assertOk()
        ->assertJsonPath('data.staff.email', 'superadmin@shopco.local')
        ->assertJsonPath('data.staff.is_super_admin', true);
});

it('rejects unauthenticated requests on protected customer and admin endpoints', function (): void {
    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->getJson('/api/v1/cart')->assertUnauthorized();
    $this->getJson('/api/v1/orders')->assertUnauthorized();
    $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    $this->getJson('/api/v1/admin/products')->assertUnauthorized();
});
