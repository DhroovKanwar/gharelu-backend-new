<?php

use App\Http\Controllers\Api\V1\Admin\AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\AdminCustomCakeRequestController;
use App\Http\Controllers\Api\V1\Admin\AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\AdminEnquiryController;
use App\Http\Controllers\Api\V1\Admin\AdminNewsletterSubscriberController;
use App\Http\Controllers\Api\V1\Admin\AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\AdminProductController;
use App\Http\Controllers\Api\V1\Admin\AdminProductImageController;
use App\Http\Controllers\Api\V1\Admin\AdminProductSizeController;
use App\Http\Controllers\Api\V1\Admin\Auth\AdminAuthController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - v1
|--------------------------------------------------------------------------
|
| Legacy paths the frontend already references (`/api/hero-config`,
| `/api/custom-cake/*`, `/api/newsletter/*`, `/api/enquiries`) still belong
| to a later phase. `/api/payments/*` is added below as a thin alias
| pointing at the same /v1 controller (Phase 6), exactly as promised when
| this comment was first written in Phase 4 — the frontend's
| paymentService.js already references these exact bare paths.
*/

Route::prefix('v1')->group(function () {

    // Public catalog
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/featured', [ProductController::class, 'featured']);
    Route::get('/products/bestsellers', [ProductController::class, 'bestsellers']);
    Route::get('/products/{slug}', [ProductController::class, 'show']);
    Route::get('/categories', [CategoryController::class, 'index']);

    // Cart price preview — public, read-only, same pricing engine as
    // order creation (OrderService::calculateCart), so it can never drift
    // from what an actual checkout would charge.
    Route::post('/cart/validate', [CartController::class, 'validateCart']);

    // Orders — store() is public (guest checkout); it optionally attaches
    // the authenticated user itself via $request->user('sanctum') rather
    // than requiring the auth:sanctum middleware, so guest checkout is
    // never blocked. index()/show() require authentication.
    Route::post('/orders', [OrderController::class, 'store'])
        ->middleware('throttle:orders');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order_number}', [OrderController::class, 'show']);
    });

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:auth');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:auth');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });

    // Payments — create-order/verify are public (guest checkout); ownership
    // for registered-user orders is enforced inside PaymentService, same
    // pattern as order creation. The webhook is server-to-server (Razorpay
    // calling us), so it carries no auth middleware and verifies its own
    // HMAC signature instead.
    Route::prefix('payments')->group(function () {
        Route::post('/create-order', [PaymentController::class, 'createOrder'])
            ->middleware('throttle:payments');
        Route::post('/verify', [PaymentController::class, 'verify'])
            ->middleware('throttle:payments');
        Route::post('/webhook', [PaymentController::class, 'webhook']);
    });

    /*
    |----------------------------------------------------------------------
    | Admin — Phase 7
    |----------------------------------------------------------------------
    |
    | Reuses the existing `users` table + Sanctum guard: an admin is just a
    | User row with a non-null `role`. Every route below (other than login)
    | requires auth:sanctum AND the `admin.role` middleware, which checks
    | the authenticated user's `role` column directly — so a normal
    | customer token can never pass, regardless of which endpoint issued it.
    */
    Route::prefix('admin')->group(function () {
        Route::post('/auth/login', [AdminAuthController::class, 'login'])
            ->middleware('throttle:admin-auth');

        Route::middleware('auth:sanctum')->group(function () {
            // Accessible to every admin role.
            Route::middleware('admin.role:super_admin,manager,staff')->group(function () {
                Route::post('/auth/logout', [AdminAuthController::class, 'logout']);
                Route::get('/auth/me', [AdminAuthController::class, 'me']);

                // Orders — staff can view and manage (update status).
                Route::get('/orders', [AdminOrderController::class, 'index']);
                Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
                Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus']);

                // Custom cake requests — staff: view only.
                Route::get('/custom-cake-requests', [AdminCustomCakeRequestController::class, 'index']);
                Route::get('/custom-cake-requests/{customCakeRequest}', [AdminCustomCakeRequestController::class, 'show']);

                // Enquiries — staff: view only.
                Route::get('/enquiries', [AdminEnquiryController::class, 'index']);
                Route::get('/enquiries/{enquiry}', [AdminEnquiryController::class, 'show']);
            });

            // manager + super_admin only.
            Route::middleware('admin.role:super_admin,manager')->group(function () {
                Route::get('/dashboard', [AdminDashboardController::class, 'index']);

                Route::apiResource('products', AdminProductController::class);
                Route::post('/products/{product}/images', [AdminProductImageController::class, 'store']);
                Route::patch('/products/{product}/images/reorder', [AdminProductImageController::class, 'reorder']);
                Route::delete('/products/{product}/images/{image}', [AdminProductImageController::class, 'destroy']);

                Route::post('/products/{product}/sizes', [AdminProductSizeController::class, 'store']);
                Route::patch('/products/{product}/sizes/reorder', [AdminProductSizeController::class, 'reorder']);
                Route::patch('/products/{product}/sizes/{size}', [AdminProductSizeController::class, 'update']);
                Route::delete('/products/{product}/sizes/{size}', [AdminProductSizeController::class, 'destroy']);

                Route::apiResource('categories', AdminCategoryController::class);

                Route::patch('/custom-cake-requests/{customCakeRequest}/status', [AdminCustomCakeRequestController::class, 'updateStatus']);
                Route::patch('/enquiries/{enquiry}/status', [AdminEnquiryController::class, 'updateStatus']);

                Route::get('/newsletter-subscribers', [AdminNewsletterSubscriberController::class, 'index']);
            });
        });
    });
});

/*
|--------------------------------------------------------------------------
| Legacy compatibility aliases
|--------------------------------------------------------------------------
|
| The frontend's src/services/paymentService.js already references these
| exact bare paths (no /v1 segment). Same controller, same behaviour —
| this just avoids ever having to touch the frontend for it.
*/
Route::prefix('payments')->group(function () {
    Route::post('/create-order', [PaymentController::class, 'createOrder'])
        ->middleware('throttle:payments');
    Route::post('/verify', [PaymentController::class, 'verify'])
        ->middleware('throttle:payments');
});
