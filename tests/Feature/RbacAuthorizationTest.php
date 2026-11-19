<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RbacSeeder::class, CatalogSeeder::class]);
});

function staffTokenFor(string $email): string
{
    $staff = Staff::query()->where('email', $email)->firstOrFail();

    return $staff->createToken('admin-auth', ['staff'])->plainTextToken;
}

it('enforces granular database-driven RBAC permissions across roles', function (): void {
    $superAdminToken = staffTokenFor('superadmin@shopco.local');
    $productMgrToken = staffTokenFor('products@shopco.local');
    $orderMgrToken = staffTokenFor('orders@shopco.local');
    $supportToken = staffTokenFor('support@shopco.local');

    // Product Manager can view products & categories, but CANNOT view/modify orders or staff
    auth()->forgetGuards();
    $this->withToken($productMgrToken)->getJson('/api/v1/admin/products')->assertOk();
    $this->withToken($productMgrToken)->getJson('/api/v1/admin/categories')->assertOk();
    $this->withToken($productMgrToken)->getJson('/api/v1/admin/orders')->assertForbidden();
    $this->withToken($productMgrToken)->getJson('/api/v1/admin/staff')->assertForbidden();

    // Order Manager can view orders & customers, but CANNOT create products or manage roles
    auth()->forgetGuards();
    $this->withToken($orderMgrToken)->getJson('/api/v1/admin/orders')->assertOk();
    $this->withToken($orderMgrToken)->getJson('/api/v1/admin/customers')->assertOk();
    $this->withToken($orderMgrToken)->postJson('/api/v1/admin/products', [])->assertForbidden();
    $this->withToken($orderMgrToken)->getJson('/api/v1/admin/roles')->assertForbidden();

    // Support has read-only orders/customers/products, CANNOT delete or create
    auth()->forgetGuards();
    $this->withToken($supportToken)->getJson('/api/v1/admin/orders')->assertOk();
    $this->withToken($supportToken)->getJson('/api/v1/admin/customers')->assertOk();
    $this->withToken($supportToken)->getJson('/api/v1/admin/dashboard')->assertForbidden();
    $this->withToken($supportToken)->postJson('/api/v1/admin/coupons', [])->assertForbidden();

    // Super Admin can access everything including dashboard, staff, and roles
    auth()->forgetGuards();
    $this->withToken($superAdminToken)->getJson('/api/v1/admin/dashboard')->assertOk();
    $this->withToken($superAdminToken)->getJson('/api/v1/admin/staff')->assertOk();
    $this->withToken($superAdminToken)->getJson('/api/v1/admin/roles')->assertOk();
});

it('protects Super Admin account and Super Admin role from deletion or privilege removal', function (): void {
    $superAdmin = Staff::query()->where('email', 'superadmin@shopco.local')->firstOrFail();
    $superAdminRole = Role::query()->where('slug', Role::SUPER_ADMIN_SLUG)->firstOrFail();
    $superAdminToken = $superAdmin->createToken('admin-auth', ['staff'])->plainTextToken;

    // Even Super Admin cannot delete Super Admin account or role
    $this->withToken($superAdminToken)->deleteJson("/api/v1/admin/staff/{$superAdmin->id}")->assertForbidden();
    $this->withToken($superAdminToken)->deleteJson("/api/v1/admin/roles/{$superAdminRole->id}")->assertForbidden();
    $this->withToken($superAdminToken)->putJson("/api/v1/admin/roles/{$superAdminRole->id}/permissions", [
        'permission_ids' => [],
    ])->assertForbidden();

    // A non-Super-Admin granted staff.edit/staff.delete STILL cannot modify or delete Super Admin
    $admin = Staff::query()->where('email', 'admin@shopco.local')->firstOrFail();
    $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
    $extraPerms = Permission::query()->whereIn('name', ['staff.edit', 'staff.delete', 'permissions.assign'])->pluck('id')->all();
    $adminRole->permissions()->syncWithoutDetaching($extraPerms);
    $adminToken = $admin->createToken('admin-auth', ['staff'])->plainTextToken;

    auth()->forgetGuards();
    $this->withToken($adminToken)->putJson("/api/v1/admin/staff/{$superAdmin->id}", [
        'name' => 'Hijacked',
        'role_ids' => [$adminRole->id],
    ])->assertForbidden();

    $this->withToken($adminToken)->deleteJson("/api/v1/admin/staff/{$superAdmin->id}")->assertForbidden();
});

it('allows Super Admin to create custom role, assign permissions, and assign to staff', function (): void {
    $superAdminToken = staffTokenFor('superadmin@shopco.local');
    $couponViewId = Permission::query()->where('name', 'coupons.view')->value('id');

    $created = $this->withToken($superAdminToken)->postJson('/api/v1/admin/roles', [
        'name' => 'Marketing Specialist',
        'description' => 'Views coupons only',
        'permission_ids' => [$couponViewId],
    ])->assertCreated();

    $roleId = $created->json('data.id');

    $staffRes = $this->withToken($superAdminToken)->postJson('/api/v1/admin/staff', [
        'name' => 'Marketer',
        'email' => 'marketer@shopco.local',
        'password' => 'StrongPass@123!',
        'role_ids' => [$roleId],
    ])->assertCreated();

    $marketerToken = staffTokenFor('marketer@shopco.local');
    auth()->forgetGuards();
    $this->withToken($marketerToken)->getJson('/api/v1/admin/coupons')->assertOk();
    $this->withToken($marketerToken)->getJson('/api/v1/admin/orders')->assertForbidden();
});
