<?php

use App\Http\Controllers\Api\V1\Admin\AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\AdminCouponController;
use App\Http\Controllers\Api\V1\Admin\AdminCustomerController;
use App\Http\Controllers\Api\V1\Admin\AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\AdminProductController;
use App\Http\Controllers\Api\V1\Admin\AdminReviewController;
use App\Http\Controllers\Api\V1\Admin\AdminRoleController;
use App\Http\Controllers\Api\V1\Admin\AdminStaffController;
use App\Http\Controllers\Api\V1\Admin\AdminTwoFactorController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\CouponController;
use App\Http\Controllers\Api\V1\CustomerAuthController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Public Storefront Catalog & Reviews
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{slug}', [ProductController::class, 'show']);
    Route::get('/products/{slug}/reviews', [ReviewController::class, 'index']);
    Route::get('/reviews/featured', [ReviewController::class, 'featured']);
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{slug}', [CategoryController::class, 'show']);

    // Public / Optional-auth Coupon Validation
    Route::post('/coupons/validate', [CouponController::class, 'validateCode']);

    // Stripe Webhook (signature-verified, no auth middleware)
    Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);

    // Customer Auth (public)
    Route::post('/register', [CustomerAuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [CustomerAuthController::class, 'login']);
    Route::get('/email/verify/{id}/{hash}', [CustomerAuthController::class, 'verifyEmail'])->middleware('throttle:15,1');
    Route::post('/password/forgot', [CustomerAuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/password/reset', [CustomerAuthController::class, 'resetPassword'])->middleware('throttle:5,1');

    // Customer Authenticated Routes (separate customer Sanctum guard)
    Route::middleware(['auth:customer', 'active.account:customer'])->group(function (): void {
        Route::post('/logout', [CustomerAuthController::class, 'logout']);
        Route::get('/me', [CustomerAuthController::class, 'me']);
        Route::patch('/me', [CustomerAuthController::class, 'updateProfile']);
        Route::post('/email/verification-notification', [CustomerAuthController::class, 'resendVerificationEmail'])
            ->middleware('throttle:6,1');

        // Shopping Cart (persistent MySQL)
        Route::get('/cart', [CartController::class, 'show']);
        Route::post('/cart/items', [CartController::class, 'addItem']);
        Route::patch('/cart/items/{id}', [CartController::class, 'updateItem']);
        Route::delete('/cart/items/{id}', [CartController::class, 'removeItem']);
        Route::post('/cart/merge', [CartController::class, 'merge']);
        Route::post('/cart/coupon', [CartController::class, 'applyCoupon']);
        Route::delete('/cart/coupon', [CartController::class, 'removeCoupon']);

        // Checkout & Payments
        Route::post('/checkout', [CheckoutController::class, 'store']);
        Route::post('/orders/{order}/retry-payment', [CheckoutController::class, 'retryPayment']);
        Route::post('/orders/{order}/confirm-payment', [CheckoutController::class, 'confirmPayment']);

        // Customer Orders (strictly scoped to owner via OrderPolicy)
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);

        // Customer Product Review Submission
        Route::post('/products/{slug}/reviews', [ReviewController::class, 'store']);
    });

    // Admin / Staff Auth (public)
    Route::prefix('admin')->group(function (): void {
        Route::post('/login', [AdminAuthController::class, 'login']);
        Route::post('/two-factor/challenge', [AdminTwoFactorController::class, 'challenge'])->middleware('throttle:10,1');
        Route::post('/password/forgot', [AdminAuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
        Route::post('/password/reset', [AdminAuthController::class, 'resetPassword'])->middleware('throttle:5,1');

        // Admin / Staff Protected Routes (separate admin Sanctum guard)
        Route::middleware(['auth:admin', 'active.account:staff'])->group(function (): void {
            Route::post('/logout', [AdminAuthController::class, 'logout']);
            Route::get('/me', [AdminAuthController::class, 'me']);
            Route::patch('/profile', [AdminAuthController::class, 'updateProfile']);
            Route::put('/profile/password', [AdminAuthController::class, 'updatePassword']);
            Route::post('/two-factor/setup', [AdminTwoFactorController::class, 'setup']);
            Route::post('/two-factor/confirm', [AdminTwoFactorController::class, 'confirm']);
            Route::post('/two-factor/disable', [AdminTwoFactorController::class, 'disable']);
            Route::post('/two-factor/recovery-codes', [AdminTwoFactorController::class, 'regenerateRecoveryCodes']);

            // Dashboard
            Route::get('/dashboard', [DashboardController::class, 'index'])
                ->middleware('permission:reports.view');

            // Products Management
            Route::get('/products', [AdminProductController::class, 'index'])
                ->middleware('permission:products.view');
            Route::post('/products', [AdminProductController::class, 'store'])
                ->middleware('permission:products.create');
            Route::get('/products/{product}', [AdminProductController::class, 'show'])
                ->middleware('permission:products.view');
            Route::match(['put', 'patch', 'post'], '/products/{product}', [AdminProductController::class, 'update'])
                ->middleware('permission:products.edit');
            Route::patch('/products/{product}/stock', [AdminProductController::class, 'updateStock'])
                ->middleware('permission:products.update_stock');
            Route::delete('/products/{product}/images/{image}', [AdminProductController::class, 'destroyImage'])
                ->middleware('permission:products.edit');
            Route::delete('/products/{product}', [AdminProductController::class, 'destroy'])
                ->middleware('permission:products.delete');

            // Categories Management
            Route::get('/categories', [AdminCategoryController::class, 'index'])
                ->middleware('permission:categories.view');
            Route::post('/categories', [AdminCategoryController::class, 'store'])
                ->middleware('permission:categories.create');
            Route::get('/categories/{category}', [AdminCategoryController::class, 'show'])
                ->middleware('permission:categories.view');
            Route::match(['put', 'patch', 'post'], '/categories/{category}', [AdminCategoryController::class, 'update'])
                ->middleware('permission:categories.edit');
            Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy'])
                ->middleware('permission:categories.delete');

            // Reviews & Ratings Management
            Route::get('/reviews', [AdminReviewController::class, 'index'])
                ->middleware('permission:reviews.view');
            Route::patch('/reviews/{review}', [AdminReviewController::class, 'update'])
                ->middleware('permission:reviews.manage');
            Route::delete('/reviews/{review}', [AdminReviewController::class, 'destroy'])
                ->middleware('permission:reviews.delete');

            // Orders Management
            Route::get('/orders', [AdminOrderController::class, 'index'])
                ->middleware('permission:orders.view');
            Route::get('/orders/{order}', [AdminOrderController::class, 'show'])
                ->middleware('permission:orders.view');
            Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus'])
                ->middleware('permission:orders.update_status');
            Route::post('/orders/{order}/cancel', [AdminOrderController::class, 'cancel'])
                ->middleware('permission:orders.cancel');

            // Coupons Management
            Route::get('/coupons', [AdminCouponController::class, 'index'])
                ->middleware('permission:coupons.view|coupons.manage');
            Route::post('/coupons', [AdminCouponController::class, 'store'])
                ->middleware('permission:coupons.create|coupons.manage');
            Route::get('/coupons/{coupon}', [AdminCouponController::class, 'show'])
                ->middleware('permission:coupons.view|coupons.manage');
            Route::put('/coupons/{coupon}', [AdminCouponController::class, 'update'])
                ->middleware('permission:coupons.edit|coupons.manage');
            Route::delete('/coupons/{coupon}', [AdminCouponController::class, 'destroy'])
                ->middleware('permission:coupons.delete|coupons.manage');

            // Customers Management
            Route::get('/customers', [AdminCustomerController::class, 'index'])
                ->middleware('permission:customers.view');
            Route::get('/customers/{customer}', [AdminCustomerController::class, 'show'])
                ->middleware('permission:customers.view|customers.view_orders');

            // Staff Management
            Route::get('/staff', [AdminStaffController::class, 'index'])
                ->middleware('permission:staff.view');
            Route::post('/staff', [AdminStaffController::class, 'store'])
                ->middleware('permission:staff.create');
            Route::get('/staff/{staff}', [AdminStaffController::class, 'show'])
                ->middleware('permission:staff.view');
            Route::put('/staff/{staff}', [AdminStaffController::class, 'update'])
                ->middleware('permission:staff.edit');
            Route::delete('/staff/{staff}', [AdminStaffController::class, 'destroy'])
                ->middleware('permission:staff.delete');

            // Roles & Permissions Management
            Route::get('/roles', [AdminRoleController::class, 'index'])
                ->middleware('permission:roles.view');
            Route::post('/roles', [AdminRoleController::class, 'store'])
                ->middleware('permission:roles.create');
            Route::get('/roles/{role}', [AdminRoleController::class, 'show'])
                ->middleware('permission:roles.view');
            Route::put('/roles/{role}', [AdminRoleController::class, 'update'])
                ->middleware('permission:roles.edit');
            Route::delete('/roles/{role}', [AdminRoleController::class, 'destroy'])
                ->middleware('permission:roles.delete');
            Route::get('/permissions', [AdminRoleController::class, 'permissions'])
                ->middleware('permission:roles.view|permissions.assign');
            Route::put('/roles/{role}/permissions', [AdminRoleController::class, 'syncPermissions'])
                ->middleware('permission:permissions.assign');
        });
    });
});
