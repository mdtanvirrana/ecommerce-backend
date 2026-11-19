<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $permissionsByGroup = [
            'products' => [
                'products.view' => 'View products catalog in admin',
                'products.create' => 'Create new products',
                'products.edit' => 'Edit existing products and images',
                'products.delete' => 'Delete products',
                'products.update_stock' => 'Adjust product inventory stock levels',
            ],
            'categories' => [
                'categories.view' => 'View categories tree',
                'categories.create' => 'Create categories',
                'categories.edit' => 'Edit categories',
                'categories.delete' => 'Delete categories',
            ],
            'orders' => [
                'orders.view' => 'View customer orders and payment details',
                'orders.update_status' => 'Update order fulfillment statuses',
                'orders.cancel' => 'Cancel orders and restore stock',
            ],
            'coupons' => [
                'coupons.view' => 'View discount coupons',
                'coupons.create' => 'Create discount coupons',
                'coupons.edit' => 'Edit discount coupons',
                'coupons.delete' => 'Delete discount coupons',
                'coupons.manage' => 'Full management access over coupons',
            ],
            'customers' => [
                'customers.view' => 'View customer directory',
                'customers.view_orders' => 'View individual customer order history',
            ],
            'reports' => [
                'reports.view' => 'View dashboard analytics and sales reports',
                'reports.export' => 'Export sales and order reports',
            ],
            'staff' => [
                'staff.view' => 'View staff users list',
                'staff.create' => 'Create staff accounts',
                'staff.edit' => 'Edit staff accounts and assign roles',
                'staff.delete' => 'Delete staff accounts',
            ],
            'roles' => [
                'roles.view' => 'View roles and permissions matrix',
                'roles.create' => 'Create custom roles',
                'roles.edit' => 'Edit custom roles',
                'roles.delete' => 'Delete custom roles',
                'permissions.assign' => 'Assign permissions to roles',
            ],
        ];

        $permissionMap = [];
        foreach ($permissionsByGroup as $group => $items) {
            foreach ($items as $name => $description) {
                $permission = Permission::query()->updateOrCreate(
                    ['name' => $name],
                    ['group' => $group, 'description' => $description]
                );
                $permissionMap[$name] = $permission->id;
            }
        }

        $roleDefinitions = [
            Role::SUPER_ADMIN_SLUG => [
                'name' => 'Super Admin',
                'description' => 'Unrestricted system administrator with all permissions.',
                'permissions' => array_keys($permissionMap),
            ],
            'admin' => [
                'name' => 'Admin',
                'description' => 'Store administrator managing catalog, orders, coupons, customers, and reports.',
                'permissions' => [
                    'products.view', 'products.create', 'products.edit', 'products.delete', 'products.update_stock',
                    'categories.view', 'categories.create', 'categories.edit', 'categories.delete',
                    'orders.view', 'orders.update_status', 'orders.cancel',
                    'coupons.view', 'coupons.create', 'coupons.edit', 'coupons.delete', 'coupons.manage',
                    'customers.view', 'customers.view_orders',
                    'reports.view', 'reports.export',
                    'staff.view', 'roles.view',
                ],
            ],
            'product-manager' => [
                'name' => 'Product Manager',
                'description' => 'Manages products, categories, pricing, and inventory stock.',
                'permissions' => [
                    'products.view', 'products.create', 'products.edit', 'products.delete', 'products.update_stock',
                    'categories.view', 'categories.create', 'categories.edit', 'categories.delete',
                    'reports.view',
                ],
            ],
            'order-manager' => [
                'name' => 'Order Manager',
                'description' => 'Manages order fulfillment, status transitions, cancellations, and customer lookups.',
                'permissions' => [
                    'orders.view', 'orders.update_status', 'orders.cancel',
                    'customers.view', 'customers.view_orders',
                    'reports.view',
                ],
            ],
            'support' => [
                'name' => 'Support',
                'description' => 'Read-only access to orders, customers, products, categories, and coupons.',
                'permissions' => [
                    'orders.view',
                    'customers.view', 'customers.view_orders',
                    'products.view', 'categories.view', 'coupons.view',
                ],
            ],
        ];

        $roles = [];
        foreach ($roleDefinitions as $slug => $def) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'is_system' => true,
                ]
            );

            $permIds = array_map(fn (string $p): int => $permissionMap[$p], $def['permissions']);
            $role->permissions()->sync($permIds);
            $roles[$slug] = $role;
        }

        // Seed default Super Admin and role-specific demo staff accounts
        $staffAccounts = [
            [
                'name' => 'Super Administrator',
                'email' => 'superadmin@shopco.local',
                'phone' => '+1-555-0100',
                'password' => 'SuperAdmin@123!',
                'roles' => [Role::SUPER_ADMIN_SLUG],
            ],
            [
                'name' => 'Store Admin',
                'email' => 'admin@shopco.local',
                'phone' => '+1-555-0101',
                'password' => 'Admin@123!',
                'roles' => ['admin'],
            ],
            [
                'name' => 'Maya Product Lead',
                'email' => 'products@shopco.local',
                'phone' => '+1-555-0102',
                'password' => 'Staff@123!',
                'roles' => ['product-manager'],
            ],
            [
                'name' => 'Omar Order Ops',
                'email' => 'orders@shopco.local',
                'phone' => '+1-555-0103',
                'password' => 'Staff@123!',
                'roles' => ['order-manager'],
            ],
            [
                'name' => 'Nadia Support',
                'email' => 'support@shopco.local',
                'phone' => '+1-555-0104',
                'password' => 'Staff@123!',
                'roles' => ['support'],
            ],
        ];

        foreach ($staffAccounts as $acct) {
            $staff = Staff::query()->updateOrCreate(
                ['email' => $acct['email']],
                [
                    'name' => $acct['name'],
                    'phone' => $acct['phone'],
                    'password' => Hash::make($acct['password']),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $roleIds = array_map(fn (string $slug): int => $roles[$slug]->id, $acct['roles']);
            $staff->roles()->sync($roleIds);
        }
    }
}
