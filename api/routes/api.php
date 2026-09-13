<?php

use App\Http\Controllers\Api\V1\Admin\AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\AdminDeviceController;
use App\Http\Controllers\Api\V1\Admin\AdminFamilyController;
use App\Http\Controllers\Api\V1\Admin\AdminInventoryController;
use App\Http\Controllers\Api\V1\Admin\AdminProductCatalogController;
use App\Http\Controllers\Api\V1\Admin\AdminReportController;
use App\Http\Controllers\Api\V1\Admin\AdminSaleController;
use App\Http\Controllers\Api\V1\Admin\AdminShiftController;
use App\Http\Controllers\Api\V1\Admin\AdminStockMovementController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\BranchController;
use App\Http\Controllers\Api\V1\Admin\CommunicationSettingsController;
use App\Http\Controllers\Api\V1\Admin\InventoryDispositionController;
use App\Http\Controllers\Api\V1\Admin\VacancyController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\CommunityAlertController;
use App\Http\Controllers\Api\V1\CustomerService\CustomerServiceController;
use App\Http\Controllers\Api\V1\CustomerService\CustomerServiceDeviceController;
use App\Http\Controllers\Api\V1\CustomerService\CustomerServiceSaleController;
use App\Http\Controllers\Api\V1\CustomerService\ShiftController;
use App\Http\Controllers\Api\V1\DeviceCategoryController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DeviceLocationController;
use App\Http\Controllers\Api\V1\FamilyController;
use App\Http\Controllers\Api\V1\FamilyMemberController;
use App\Http\Controllers\Api\V1\GeofenceController;
use App\Http\Controllers\Api\V1\Iot\IotLocationController;
use App\Http\Controllers\Api\V1\IotModuleController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PublicBranchController;
use App\Http\Controllers\Api\V1\PublicVacancyController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\TheftReportController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WarrantyClaimController;
use App\Http\Controllers\Api\V1\WarrantyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group with prefix "api/v1".
|
*/

// Health check
Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'Mufasah Device Tracking API is running',
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');

// Public receipt access (signed URL required)
Route::get('/sales/{sale}/receipt', [AdminSaleController::class, 'publicReceipt'])
    ->name('sales.public-receipt');

// =============================================================================
// PUBLIC ROUTES (No authentication required)
// =============================================================================

Route::prefix('auth')->name('auth.')->group(function () {
    // Registration & Login
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:registration')
        ->name('register');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login');

    // Password Reset
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])
        ->middleware('throttle:password-reset')
        ->name('password.email');

    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:password-reset')
        ->name('password.update');

    // Email Verification (signed URL from email)
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('verification.verify');
});

Route::get('/public/branches', [PublicBranchController::class, 'index'])
    ->name('public.branches.index');
Route::get('/public/vacancies', [PublicVacancyController::class, 'index'])
    ->name('public.vacancies.index');

// =============================================================================
// PROTECTED ROUTES (Authentication required)
// =============================================================================

// 'not.suspended' must run after 'auth:sanctum' so the resolved user is available;
// it rejects any request made by a suspended account, even with a valid token.
Route::middleware(['auth:sanctum', 'not.suspended'])->group(function () {

    // -------------------------------------------------------------------------
    // Auth Routes
    // -------------------------------------------------------------------------
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('refresh');

        // Email Verification
        Route::post('/email/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:6,1')
            ->name('verification.resend');

        Route::get('/email/status', [EmailVerificationController::class, 'status'])
            ->name('verification.status');
    });

    // -------------------------------------------------------------------------
    // User Profile Routes
    // -------------------------------------------------------------------------
    Route::prefix('user')->name('user.')->group(function () {
        Route::get('/', [UserController::class, 'show'])->name('show');
        Route::put('/', [UserController::class, 'update'])->name('update');
        Route::put('/password', [UserController::class, 'changePassword'])->name('password');
        Route::post('/avatar', [UserController::class, 'uploadAvatar'])->name('avatar.upload');
        Route::delete('/avatar', [UserController::class, 'deleteAvatar'])->name('avatar.delete');
    });

    // -------------------------------------------------------------------------
    // Family Routes
    // -------------------------------------------------------------------------
    Route::prefix('families')->name('families.')->group(function () {
        Route::get('/', [FamilyController::class, 'index'])->name('index');
        Route::post('/', [FamilyController::class, 'store'])->name('store');
        Route::post('/join', [FamilyController::class, 'join'])->name('join');
        Route::get('/invitations', [FamilyController::class, 'pendingInvitations'])->name('invitations');
        Route::post('/invitations/{invitation}/respond', [FamilyController::class, 'respondToInvitation'])->name('invitations.respond');

        Route::prefix('{family}')->group(function () {
            Route::get('/', [FamilyController::class, 'show'])->name('show');
            Route::put('/', [FamilyController::class, 'update'])->name('update');
            Route::delete('/', [FamilyController::class, 'destroy'])->name('destroy');
            Route::post('/leave', [FamilyController::class, 'leave'])->name('leave');
            Route::post('/regenerate-code', [FamilyController::class, 'regenerateCode'])->name('regenerate-code');

            // Family Members
            Route::prefix('members')->name('members.')->group(function () {
                Route::get('/', [FamilyMemberController::class, 'index'])->name('index');
                Route::post('/invite', [FamilyMemberController::class, 'invite'])->name('invite');
                Route::put('/{member}', [FamilyMemberController::class, 'update'])->name('update');
                Route::delete('/{member}', [FamilyMemberController::class, 'destroy'])->name('destroy');
                Route::post('/{member}/approve', [FamilyMemberController::class, 'approve'])->name('approve');
                Route::post('/{member}/transfer-ownership', [FamilyMemberController::class, 'transferOwnership'])->name('transfer-ownership');
            });
        });
    });

    // -------------------------------------------------------------------------
    // Device Category Routes (Public read access)
    // -------------------------------------------------------------------------
    Route::get('/device-categories', [DeviceCategoryController::class, 'index'])
        ->name('device-categories.index');
    Route::get('/device-categories/{deviceCategory}', [DeviceCategoryController::class, 'show'])
        ->name('device-categories.show');

    // -------------------------------------------------------------------------
    // Device Routes
    // -------------------------------------------------------------------------
    Route::apiResource('devices', DeviceController::class);

    Route::prefix('devices/{device}')->name('devices.')->group(function () {
        // Status update
        Route::put('/status', [DeviceController::class, 'updateStatus'])->name('status');

        // Photo management
        Route::post('/photos', [DeviceController::class, 'uploadPhotos'])->name('photos.upload');
        Route::delete('/photos', [DeviceController::class, 'deletePhoto'])->name('photos.delete');

        // Ownership transfer
        Route::post('/transfer', [DeviceController::class, 'transfer'])->name('transfer');
        Route::get('/ownership-history', [DeviceController::class, 'ownershipHistory'])->name('ownership-history');

        // IoT Module linking
        Route::post('/link-module', [DeviceController::class, 'linkModule'])->name('link-module');
        Route::delete('/unlink-module', [DeviceController::class, 'unlinkModule'])->name('unlink-module');

        // Location routes
        Route::get('/locations', [DeviceLocationController::class, 'index'])->name('locations.index');
        Route::get('/locations/latest', [DeviceLocationController::class, 'latest'])->name('locations.latest');
        Route::post('/locations', [DeviceLocationController::class, 'storeManual'])->name('locations.store');

        // Location analytics routes
        Route::get('/locations/statistics', [DeviceLocationController::class, 'statistics'])->name('locations.statistics');
        Route::get('/locations/heatmap', [DeviceLocationController::class, 'heatmap'])->name('locations.heatmap');
        Route::get('/locations/track', [DeviceLocationController::class, 'track'])->name('locations.track');
    });

    // -------------------------------------------------------------------------
    // Geofence Routes
    // -------------------------------------------------------------------------
    Route::prefix('devices/{device}/geofences')->name('devices.geofences.')->group(function () {
        Route::get('/', [GeofenceController::class, 'index'])->name('index');
    });

    Route::prefix('geofences')->name('geofences.')->group(function () {
        Route::get('/', [GeofenceController::class, 'list'])->name('list');
        Route::post('/', [GeofenceController::class, 'store'])->name('store');
        Route::get('/{geofence}', [GeofenceController::class, 'show'])->name('show');
        Route::put('/{geofence}', [GeofenceController::class, 'update'])->name('update');
        Route::delete('/{geofence}', [GeofenceController::class, 'destroy'])->name('destroy');
        Route::post('/{geofence}/toggle', [GeofenceController::class, 'toggle'])->name('toggle');
        Route::get('/{geofence}/events', [GeofenceController::class, 'events'])->name('events');
    });

    // -------------------------------------------------------------------------
    // Theft Report Routes
    // -------------------------------------------------------------------------
    Route::apiResource('theft-reports', TheftReportController::class);

    Route::prefix('theft-reports/{theft_report}')->name('theft-reports.')->group(function () {
        // Status management
        Route::post('/status', [TheftReportController::class, 'updateStatus'])->name('status');

        // Assignment (admin only handled by request)
        Route::post('/assign', [TheftReportController::class, 'assign'])->name('assign');

        // Evidence management
        Route::post('/evidence', [TheftReportController::class, 'addEvidence'])->name('evidence.add');
        Route::delete('/evidence', [TheftReportController::class, 'removeEvidence'])->name('evidence.remove');

        // Status updates/history
        Route::get('/updates', [TheftReportController::class, 'updates'])->name('updates');
    });

    // -------------------------------------------------------------------------
    // Warranty Routes
    // -------------------------------------------------------------------------
    Route::get('/devices/{device}/warranty', [WarrantyController::class, 'forDevice'])
        ->name('devices.warranty');

    Route::apiResource('warranties', WarrantyController::class)->except(['store']);
    Route::post('/warranties/{warranty}/void', [WarrantyController::class, 'void'])
        ->name('warranties.void');

    // Warranty Claims
    Route::get('/warranties/{warranty}/claims', [WarrantyClaimController::class, 'forWarranty'])
        ->name('warranties.claims');
    Route::post('/warranties/{warranty}/claims', [WarrantyClaimController::class, 'store'])
        ->name('warranty-claims.store');

    Route::prefix('warranty-claims/{warranty_claim}')->name('warranty-claims.')->group(function () {
        Route::get('/', [WarrantyClaimController::class, 'show'])->name('show');
        Route::delete('/', [WarrantyClaimController::class, 'destroy'])->name('destroy');
        Route::post('/process', [WarrantyClaimController::class, 'process'])->name('process');
        Route::post('/evidence', [WarrantyClaimController::class, 'addEvidence'])->name('evidence');
    });

    // -------------------------------------------------------------------------
    // Subscription Plan Routes (Public listing for authenticated users)
    // -------------------------------------------------------------------------
    Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index'])
        ->name('subscription-plans.index');
    Route::get('/subscription-plans/{subscriptionPlan}', [SubscriptionPlanController::class, 'show'])
        ->name('subscription-plans.show');

    // -------------------------------------------------------------------------
    // Subscription Routes
    // -------------------------------------------------------------------------
    Route::prefix('subscriptions')->name('subscriptions.')->group(function () {
        Route::get('/current', [SubscriptionController::class, 'current'])->name('current');
        Route::post('/subscribe', [SubscriptionController::class, 'subscribe'])->name('subscribe');
        Route::get('/{subscription}', [SubscriptionController::class, 'show'])->name('show');
        Route::post('/{subscription}/cancel', [SubscriptionController::class, 'cancel'])->name('cancel');
        Route::post('/{subscription}/change-plan', [SubscriptionController::class, 'changePlan'])->name('change-plan');
        Route::post('/{subscription}/toggle-auto-renew', [SubscriptionController::class, 'toggleAutoRenew'])->name('toggle-auto-renew');
    });

    // -------------------------------------------------------------------------
    // Payment Routes
    // -------------------------------------------------------------------------
    Route::prefix('payments')->name('payments.')->group(function () {
        Route::get('/my-payments', [PaymentController::class, 'myPayments'])->name('my-payments');
        Route::get('/{payment}', [PaymentController::class, 'show'])->name('show');
        Route::post('/{payment}/verify', [PaymentController::class, 'verify'])->name('verify');
    });

    // -------------------------------------------------------------------------
    // Notification Routes
    // -------------------------------------------------------------------------
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead'])->name('read-all');
        Route::delete('/delete-all', [NotificationController::class, 'destroyAll'])->name('delete-all');
        Route::get('/{notification}', [NotificationController::class, 'show'])->name('show');
        Route::post('/{notification}/read', [NotificationController::class, 'markAsRead'])->name('read');
        Route::delete('/{notification}', [NotificationController::class, 'destroy'])->name('destroy');
    });

    // -------------------------------------------------------------------------
    // Community Recovery Network Routes
    // -------------------------------------------------------------------------
    Route::prefix('community')->name('community.')->group(function () {
        // Public alerts feed (active alerts nearby)
        Route::get('/alerts', [CommunityAlertController::class, 'index'])->name('alerts.index');
        Route::get('/alerts/my', [CommunityAlertController::class, 'myAlerts'])->name('alerts.my');
        Route::post('/alerts', [CommunityAlertController::class, 'store'])->name('alerts.store');

        Route::prefix('alerts/{communityAlert}')->name('alerts.')->group(function () {
            Route::get('/', [CommunityAlertController::class, 'show'])->name('show');
            Route::put('/', [CommunityAlertController::class, 'update'])->name('update');
            Route::delete('/', [CommunityAlertController::class, 'destroy'])->name('destroy');

            // Alert status management
            Route::post('/pause', [CommunityAlertController::class, 'pause'])->name('pause');
            Route::post('/resume', [CommunityAlertController::class, 'resume'])->name('resume');
            Route::post('/resolve', [CommunityAlertController::class, 'resolve'])->name('resolve');

            // Bounty management
            Route::post('/bounty', [CommunityAlertController::class, 'setBounty'])->name('bounty');

            // Engagement
            Route::post('/share', [CommunityAlertController::class, 'recordShare'])->name('share');

            // Tips for this alert
            Route::get('/tips', [CommunityAlertController::class, 'tips'])->name('tips.index');
            Route::post('/tips', [CommunityAlertController::class, 'submitTip'])->name('tips.store');

            // Claims for this alert
            Route::get('/claims', [CommunityAlertController::class, 'claims'])->name('claims.index');
            Route::post('/claims', [CommunityAlertController::class, 'submitClaim'])->name('claims.store');
        });

        // Tip management
        Route::prefix('tips/{communityTip}')->name('tips.')->group(function () {
            Route::post('/verify', [CommunityAlertController::class, 'verifyTip'])->name('verify');
            Route::post('/reject', [CommunityAlertController::class, 'rejectTip'])->name('reject');
        });

        // Claim management
        Route::prefix('claims/{bountyClaim}')->name('claims.')->group(function () {
            Route::post('/approve', [CommunityAlertController::class, 'approveClaim'])->name('approve');
            Route::post('/reject', [CommunityAlertController::class, 'rejectClaim'])->name('reject');
            Route::post('/paid', [CommunityAlertController::class, 'markClaimPaid'])->name('paid');
        });

        // Leaderboard & Heroes
        Route::get('/leaderboard', [CommunityAlertController::class, 'leaderboard'])->name('leaderboard');
        Route::get('/hero/me', [CommunityAlertController::class, 'myHeroProfile'])->name('hero.me');

        // Community Statistics
        Route::get('/stats', [CommunityAlertController::class, 'stats'])->name('stats');

        // Success Stories
        Route::get('/stories', [CommunityAlertController::class, 'successStories'])->name('stories.index');
        Route::get('/stories/{successStory}', [CommunityAlertController::class, 'showSuccessStory'])->name('stories.show');
        Route::post('/stories/{successStory}/like', [CommunityAlertController::class, 'toggleLike'])->name('stories.like');
    });

    // -------------------------------------------------------------------------
    // Admin Routes (Requires admin role)
    // -------------------------------------------------------------------------
    Route::middleware(['role:super_admin|store_admin'])->prefix('admin')->name('admin.')->group(function () {
        // Dashboard
        Route::prefix('dashboard')->name('dashboard.')->group(function () {
            Route::get('/', [AdminDashboardController::class, 'index'])->name('index');
            Route::get('/users', [AdminDashboardController::class, 'users'])->name('users');
            Route::get('/devices', [AdminDashboardController::class, 'devices'])->name('devices');
            Route::get('/revenue', [AdminDashboardController::class, 'revenue'])->name('revenue');
            Route::get('/theft-reports', [AdminDashboardController::class, 'theftReports'])->name('theft-reports');
            Route::get('/subscriptions', [AdminDashboardController::class, 'subscriptions'])->name('subscriptions');
            Route::get('/support', [AdminDashboardController::class, 'support'])->name('support');
        });

        // User management
        Route::apiResource('users', AdminUserController::class);
        Route::prefix('users/{user}')->name('users.')->group(function () {
            Route::post('/assign-role', [AdminUserController::class, 'assignRole'])->name('assign-role');
            Route::post('/suspend', [AdminUserController::class, 'suspend'])->name('suspend');
            Route::post('/unsuspend', [AdminUserController::class, 'unsuspend'])->name('unsuspend');
            Route::post('/verify-email', [AdminUserController::class, 'verifyEmail'])->name('verify-email');
        });
        Route::get('/roles', [AdminUserController::class, 'roles'])->name('roles');

        // Branch management
        Route::get('/branch-options', [BranchController::class, 'options'])->name('branches.options');
        Route::apiResource('branches', BranchController::class);
        Route::prefix('branches/{branch}')->name('branches.')->group(function () {
            Route::get('/stats', [BranchController::class, 'stats'])->name('stats');
        });

        // Device management (admin view of all devices)
        Route::get('/devices', [AdminDeviceController::class, 'index'])->name('devices.index');
        Route::get('/devices/{device}', [AdminDeviceController::class, 'show'])->name('devices.show');
        Route::put('/devices/{device}', [AdminDeviceController::class, 'update'])->name('devices.update');
        Route::put('/devices/{device}/status', [AdminDeviceController::class, 'updateStatus'])->name('devices.status');
        Route::delete('/devices/{device}', [AdminDeviceController::class, 'destroy'])->name('devices.destroy');
        Route::post('/devices/bulk-delete/request', [InventoryDispositionController::class, 'requestBulkDeviceDeletion'])
            ->middleware(['permission:delete-devices', 'throttle:6,1'])
            ->name('devices.bulk-delete.request');
        Route::post('/devices/{device}/delete/request', [InventoryDispositionController::class, 'requestDeviceDeletion'])
            ->middleware(['permission:delete-devices', 'throttle:6,1'])
            ->name('devices.delete.request');

        Route::prefix('action-challenges')->name('action-challenges.')->group(function () {
            Route::post('/{challenge}/reveal', [InventoryDispositionController::class, 'reveal'])
                ->middleware('throttle:5,1')
                ->name('reveal');
            Route::post('/{challenge}/verify', [InventoryDispositionController::class, 'verify'])
                ->middleware('throttle:10,1')
                ->name('verify');
            Route::post('/{challenge}/resend', [InventoryDispositionController::class, 'resend'])
                ->middleware('throttle:3,1')
                ->name('resend');
        });

        // Device Category management
        Route::get('/device-categories', [DeviceCategoryController::class, 'index'])
            ->name('device-categories.index');
        Route::post('/device-categories', [DeviceCategoryController::class, 'store'])
            ->middleware('role:super_admin')
            ->name('device-categories.store');
        Route::put('/device-categories/{deviceCategory}', [DeviceCategoryController::class, 'update'])
            ->middleware('role:super_admin')
            ->name('device-categories.update');
        Route::delete('/device-categories/{deviceCategory}', [DeviceCategoryController::class, 'destroy'])
            ->middleware('role:super_admin')
            ->name('device-categories.destroy');

        // IoT Module management
        Route::apiResource('iot-modules', IotModuleController::class);

        Route::prefix('iot-modules/{iot_module}')->name('iot-modules.')->group(function () {
            Route::post('/regenerate-token', [IotModuleController::class, 'regenerateToken'])
                ->name('regenerate-token');
            Route::post('/activate', [IotModuleController::class, 'activate'])
                ->name('activate');
            Route::post('/deactivate', [IotModuleController::class, 'deactivate'])
                ->name('deactivate');
        });

        // Warranty management
        Route::post('/warranties', [WarrantyController::class, 'store'])
            ->name('warranties.store');
        Route::get('/warranty-claims', [WarrantyClaimController::class, 'index'])
            ->name('warranty-claims.index');

        // Subscription management (Admin)
        Route::get('/subscriptions', [SubscriptionController::class, 'index'])
            ->name('subscriptions.index');
        Route::post('/subscriptions/{subscription}/suspend', [SubscriptionController::class, 'suspend'])
            ->name('subscriptions.suspend');
        Route::post('/subscriptions/{subscription}/reactivate', [SubscriptionController::class, 'reactivate'])
            ->name('subscriptions.reactivate');

        // Payment management (Admin)
        Route::get('/payments', [PaymentController::class, 'index'])
            ->name('payments.index');
        Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund'])
            ->name('payments.refund');

        // Reports
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/theft', [AdminReportController::class, 'theft'])->name('theft');
            Route::get('/revenue', [AdminReportController::class, 'revenue'])->name('revenue');
            Route::get('/devices', [AdminReportController::class, 'devices'])->name('devices');
            Route::get('/stock', [AdminReportController::class, 'stock'])->name('stock');
            Route::get('/subscriptions', [AdminReportController::class, 'subscriptions'])->name('subscriptions');
            Route::get('/users', [AdminReportController::class, 'users'])->name('users');
        });

        // Inventory management
        Route::prefix('inventory')->name('inventory.')->group(function () {
            // Read endpoints - accessible to all admin roles
            Route::get('/', [AdminInventoryController::class, 'index'])->name('index');
            Route::get('/stats', [AdminInventoryController::class, 'stats'])->name('stats');
            Route::get('/grouped', [AdminInventoryController::class, 'grouped'])->name('grouped');
            Route::get('/product-options', [AdminInventoryController::class, 'productOptions'])->name('product-options');
            Route::get('/receipts', [AdminInventoryController::class, 'receipts'])->name('receipts');
            Route::get('/receipts/{receipt}', [AdminInventoryController::class, 'showReceipt'])->name('receipts.show');
            Route::get('/batches', [AdminInventoryController::class, 'batches'])->name('batches');
            Route::get('/batches/{batch}', [AdminInventoryController::class, 'showBatch'])->name('batches.show');

            // Write endpoints - require explicit manage-inventory permission
            Route::middleware(['permission:manage-inventory'])->group(function () {
                Route::post('/', [AdminInventoryController::class, 'store'])->name('store');
                Route::post('/bulk', [AdminInventoryController::class, 'bulkStore'])->name('bulk');
                Route::post('/receipts', [AdminInventoryController::class, 'storeReceipt'])->name('receipts.store');
                Route::put('/batches/{batch}/selling-price', [AdminInventoryController::class, 'updateBatchSellingPrice'])->name('batches.selling-price');
                Route::put('/{device}/status', [AdminInventoryController::class, 'updateStatus'])->name('status');
                Route::post('/{device}/faulty', [AdminInventoryController::class, 'markFaulty'])->name('faulty');
                Route::post('/{device}/administrative-sale/request', [InventoryDispositionController::class, 'requestAdministrativeSale'])
                    ->middleware('throttle:6,1')
                    ->name('administrative-sale.request');
            });
        });

        // Stock movement ledger
        Route::prefix('stock-movements')->name('stock-movements.')->middleware('permission:view-stock-movements')->group(function () {
            Route::get('/', [AdminStockMovementController::class, 'index'])->name('index');
            Route::get('/summary', [AdminStockMovementController::class, 'summary'])->name('summary');
            Route::middleware('permission:manage-stock-movements')->group(function () {
                Route::get('/transfer-requests', [\App\Http\Controllers\Api\V1\Admin\StockTransferRequestController::class, 'index'])->name('transfer-requests.index');
                Route::get('/transfer-requests/{transferRequest}', [\App\Http\Controllers\Api\V1\Admin\StockTransferRequestController::class, 'show'])->name('transfer-requests.show');
                Route::post('/transfer-requests', [\App\Http\Controllers\Api\V1\Admin\StockTransferRequestController::class, 'store'])->middleware('permission:request-stock-transfers')->name('transfer-requests.store');
                Route::post('/transfer-requests/{transferRequest}/approve', [\App\Http\Controllers\Api\V1\Admin\StockTransferRequestController::class, 'approve'])->middleware('permission:approve-stock-transfers')->name('transfer-requests.approve');
                Route::post('/transfer-requests/{transferRequest}/reject', [\App\Http\Controllers\Api\V1\Admin\StockTransferRequestController::class, 'reject'])->middleware('permission:approve-stock-transfers')->name('transfer-requests.reject');
                Route::post('/transfer-requests/{transferRequest}/cancel', [\App\Http\Controllers\Api\V1\Admin\StockTransferRequestController::class, 'cancel'])->middleware('permission:request-stock-transfers')->name('transfer-requests.cancel');
                Route::post('/adjustments', [AdminStockMovementController::class, 'adjust'])->name('adjustments.store');
            });
            Route::get('/{stockMovement}', [AdminStockMovementController::class, 'show'])->name('show');
        });

        // Product Catalog management (Brands & Models)
        Route::prefix('catalog')->name('catalog.')->group(function () {
            // Catalog overview for dropdowns
            Route::get('/overview', [AdminProductCatalogController::class, 'overview'])->name('overview');
            Route::get('/categories/{categoryId}/brands', [AdminProductCatalogController::class, 'brandsByCategory'])->name('categories.brands');
            Route::get('/brands/{brandId}/models', [AdminProductCatalogController::class, 'modelsByBrand'])->name('brands.models');

            // Brands CRUD
            Route::get('/brands', [AdminProductCatalogController::class, 'brandIndex'])->name('brands.index');
            Route::post('/brands', [AdminProductCatalogController::class, 'brandStore'])->middleware('role:super_admin')->name('brands.store');
            Route::get('/brands/{brand}', [AdminProductCatalogController::class, 'brandShow'])->name('brands.show');
            Route::put('/brands/{brand}', [AdminProductCatalogController::class, 'brandUpdate'])->middleware('role:super_admin')->name('brands.update');
            Route::delete('/brands/{brand}', [AdminProductCatalogController::class, 'brandDestroy'])->middleware('role:super_admin')->name('brands.destroy');

            // Product Models CRUD
            Route::get('/models', [AdminProductCatalogController::class, 'modelIndex'])->name('models.index');
            Route::post('/models', [AdminProductCatalogController::class, 'modelStore'])->middleware('role:super_admin')->name('models.store');
            Route::get('/models/{productModel}', [AdminProductCatalogController::class, 'modelShow'])->name('models.show');
            Route::put('/models/{productModel}', [AdminProductCatalogController::class, 'modelUpdate'])->middleware('role:super_admin')->name('models.update');
            Route::delete('/models/{productModel}', [AdminProductCatalogController::class, 'modelDestroy'])->middleware('role:super_admin')->name('models.destroy');
        });

        // Sales management (POS)
        Route::prefix('sales')->name('sales.')->group(function () {
            Route::get('/', [AdminSaleController::class, 'index'])->name('index');
            Route::post('/', [AdminSaleController::class, 'store'])->middleware('permission:manage-sales')->name('store');
            Route::get('/summary', [AdminSaleController::class, 'summary'])->name('summary');
            Route::get('/{sale}', [AdminSaleController::class, 'show'])->name('show');
            Route::get('/{sale}/receipt', [AdminSaleController::class, 'receipt'])->name('receipt');
            Route::get('/{sale}/receipt-url', [AdminSaleController::class, 'generateReceiptUrl'])->name('receipt-url');
            Route::post('/{sale}/complete', [AdminSaleController::class, 'complete'])->middleware('permission:manage-sales')->name('complete');
            Route::post('/{sale}/cancel', [AdminSaleController::class, 'cancel'])->middleware('permission:manage-sales')->name('cancel');
            Route::post('/{sale}/refund', [AdminSaleController::class, 'refund'])->middleware('permission:manage-sales')->name('refund');
            Route::delete('/{sale}', [AdminSaleController::class, 'destroy'])->middleware('permission:manage-sales')->name('destroy');
        });

        // Shift management (Admin view of all shifts)
        Route::prefix('shifts')->name('shifts.')->group(function () {
            Route::get('/', [AdminShiftController::class, 'index'])->name('index');
            Route::get('/stats', [AdminShiftController::class, 'stats'])->name('stats');
            Route::get('/{shift}', [AdminShiftController::class, 'show'])->name('show');
        });

        // Communication Settings
        Route::prefix('communications')->name('communications.')->middleware('role:super_admin')->group(function () {
            Route::get('/', [CommunicationSettingsController::class, 'index'])->name('index');
            Route::put('/', [CommunicationSettingsController::class, 'update'])->name('update');
            Route::post('/test-email', [CommunicationSettingsController::class, 'sendTestEmail'])->name('test-email');
        });

        // Careers management
        Route::apiResource('vacancies', VacancyController::class)->middleware('role:super_admin');

        // Community management (Admin)
        Route::prefix('community')->name('community.')->middleware('role:super_admin')->group(function () {
            Route::get('/alerts', [CommunityAlertController::class, 'allAlerts'])->name('alerts.index');
            Route::get('/tips', [CommunityAlertController::class, 'allTips'])->name('tips.index');
            Route::get('/claims', [CommunityAlertController::class, 'allClaims'])->name('claims.index');
            Route::get('/stories', [CommunityAlertController::class, 'allSuccessStories'])->name('stories.index');
            Route::post('/stories/{successStory}/approve', [CommunityAlertController::class, 'approveSuccessStory'])->name('stories.approve');
            Route::post('/stories/{successStory}/reject', [CommunityAlertController::class, 'rejectSuccessStory'])->name('stories.reject');
            Route::post('/stories/{successStory}/feature', [CommunityAlertController::class, 'toggleFeaturedStory'])->name('stories.feature');
        });

        // Family management (Admin manages on behalf of users)
        Route::prefix('families')->name('families.')->middleware('role:super_admin')->group(function () {
            Route::get('/', [AdminFamilyController::class, 'index'])->name('index');
            Route::get('/{family}', [AdminFamilyController::class, 'show'])->name('show');
            Route::put('/{family}', [AdminFamilyController::class, 'update'])->name('update');
            Route::delete('/{family}', [AdminFamilyController::class, 'destroy'])->name('destroy');
            Route::post('/{family}/regenerate-code', [AdminFamilyController::class, 'regenerateCode'])->name('regenerate-code');
            Route::post('/{family}/transfer-ownership', [AdminFamilyController::class, 'transferOwnership'])->name('transfer-ownership');
            // Member management
            Route::get('/{family}/members', [AdminFamilyController::class, 'members'])->name('members.index');
            Route::post('/{family}/members', [AdminFamilyController::class, 'addMember'])->name('members.store');
            Route::put('/{family}/members/{member}', [AdminFamilyController::class, 'updateMember'])->name('members.update');
            Route::delete('/{family}/members/{member}', [AdminFamilyController::class, 'removeMember'])->name('members.destroy');
        });
    });

    // -------------------------------------------------------------------------
    // Customer Service Routes (Requires customer_service role)
    // -------------------------------------------------------------------------
    Route::middleware(['role:customer_service'])->prefix('customer-service')->name('customer-service.')->group(function () {
        // Dashboard
        Route::get('/dashboard', [CustomerServiceController::class, 'dashboard'])->name('dashboard');

        // Shifts
        Route::prefix('shifts')->name('shifts.')->group(function () {
            Route::get('/current', [ShiftController::class, 'current'])->name('current');
            Route::post('/start', [ShiftController::class, 'start'])->name('start');
            Route::post('/end', [ShiftController::class, 'end'])->name('end');
            Route::post('/cancel', [ShiftController::class, 'cancel'])->name('cancel');
            Route::get('/history', [ShiftController::class, 'history'])->name('history');
            Route::get('/{shift}', [ShiftController::class, 'show'])->name('show');
        });

        // Sales (filtered by sold_by_id for current user)
        Route::prefix('sales')->name('sales.')->group(function () {
            Route::get('/', [CustomerServiceSaleController::class, 'index'])->name('index');
            Route::post('/', [AdminSaleController::class, 'store'])->name('store');
            Route::get('/{sale}', [CustomerServiceSaleController::class, 'show'])->name('show');
            Route::post('/{sale}/complete', [AdminSaleController::class, 'complete'])->name('complete');
        });

        Route::get('/inventory/product-options', [AdminInventoryController::class, 'productOptions'])->name('inventory.product-options');

        // Devices (read-only access)
        Route::prefix('devices')->name('devices.')->group(function () {
            Route::get('/', [CustomerServiceDeviceController::class, 'index'])->name('index');
            Route::get('/{device}', [CustomerServiceDeviceController::class, 'show'])->name('show');
        });

        // Customers (list of customers served by current user)
        Route::get('/customers', [CustomerServiceController::class, 'customers'])->name('customers');
        Route::post('/customers', [CustomerServiceController::class, 'storeCustomer'])->name('customers.store');

        // Warranties (stub for future implementation)
        Route::get('/warranties', [CustomerServiceController::class, 'warranties'])->name('warranties');
    });
});

// =============================================================================
// IOT MODULE ROUTES (Uses API token authentication)
// =============================================================================

Route::prefix('iot')->name('iot.')->middleware('iot.auth')->group(function () {
    // Location update endpoint for IoT devices
    Route::post('/location', [IotLocationController::class, 'store'])
        ->name('location.store');
    Route::post('/locations/batch', [IotLocationController::class, 'storeBatch'])
        ->name('location.batch');
    Route::post('/heartbeat', [IotLocationController::class, 'heartbeat'])
        ->name('heartbeat');
});

// =============================================================================
// WEBHOOK ROUTES (No authentication - verified by signature)
// =============================================================================

Route::prefix('webhooks')->name('webhooks.')->group(function () {
    Route::post('/paychangu', [PaymentController::class, 'webhook'])
        ->name('paychangu');
});

// Alias for the webhook route (used in PayChanguService)
Route::post('/payments/webhook', [PaymentController::class, 'webhook'])
    ->name('api.v1.payments.webhook');
