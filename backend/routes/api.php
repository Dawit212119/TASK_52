<?php

use App\Http\Controllers\Api\AnalyticsAuditController;
use App\Http\Controllers\Api\ContentReviewsController;
use App\Http\Controllers\Api\ImportDedupController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\RentalsController;
use App\Http\Controllers\Api\ReviewTokenController;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::get('/csrf-token', function (Request $request) {
        return response()->json([
            'csrf_token' => $request->session()->token(),
            'request_id' => $request->header('X-Request-Id') ?: (string) Str::uuid(),
        ]);
    });

    Route::post('/login', [AuthController::class, 'login'])->middleware('cookie.csrf');

    Route::middleware('auth.api')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('cookie.csrf');
        Route::get('/me', [AuthController::class, 'me'])->middleware('permission:auth.me.read');
        Route::post('/password/change', [AuthController::class, 'changePassword'])
            ->middleware(['cookie.csrf', 'permission:auth.password.change']);
    });
});

Route::middleware('auth.api')->group(function () {
    Route::prefix('master')->group(function () {
        Route::get('/{entity}', [MasterDataController::class, 'index'])->middleware('permission:master.read');
        Route::post('/{entity}', [MasterDataController::class, 'store'])->middleware('permission:master.write');
        Route::patch('/{entity}/{id}', [MasterDataController::class, 'update'])->middleware('permission:master.write');
        Route::get('/{entity}/{id}/versions', [MasterDataController::class, 'versions'])->middleware('permission:master.read');
        Route::post('/{entity}/{id}/revert', [MasterDataController::class, 'revert'])->middleware('permission:master.revert');
    });

    Route::prefix('rentals')->group(function () {
        Route::get('/assets', [RentalsController::class, 'listAssets'])->middleware('permission:rentals.read');
        Route::post('/assets', [RentalsController::class, 'createAsset'])->middleware('permission:rentals.write');
        Route::patch('/assets/{id}', [RentalsController::class, 'updateAsset'])->middleware('permission:rentals.write');
        Route::post('/checkouts', [RentalsController::class, 'checkout'])->middleware('permission:rentals.checkout');
        Route::post('/checkouts/{id}/return', [RentalsController::class, 'returnCheckout'])->middleware('permission:rentals.checkout');
        Route::get('/checkouts/{id}', [RentalsController::class, 'getCheckout'])->middleware('permission:rentals.read');
        Route::post('/assets/{id}/transfer', [RentalsController::class, 'requestTransfer'])->middleware('permission:rentals.transfer.request');
        Route::post('/transfers/{id}/approve', [RentalsController::class, 'approveTransfer'])->middleware('permission:rentals.transfer.approve');
    });

    Route::prefix('inventory')->group(function () {
        Route::get('/items', [InventoryController::class, 'items'])->middleware('permission:inventory.read');
        Route::post('/receipts', [InventoryController::class, 'receipt'])->middleware('permission:inventory.write');
        Route::post('/issues', [InventoryController::class, 'issue'])->middleware('permission:inventory.write');
        Route::post('/transfers', [InventoryController::class, 'transfer'])->middleware('permission:inventory.write');
        Route::post('/stocktakes', [InventoryController::class, 'createStocktake'])->middleware('permission:inventory.stocktake.write');
        Route::post('/stocktakes/{id}/lines', [InventoryController::class, 'addStocktakeLines'])->middleware('permission:inventory.stocktake.write');
        Route::post('/stocktakes/{id}/approve-variance', [InventoryController::class, 'approveVariance'])->middleware('permission:inventory.stocktake.approve');
        Route::put('/reservation-strategy', [InventoryController::class, 'setReservationStrategy'])->middleware('permission:inventory.write');
        Route::post('/service-orders/{id}/reserve', [InventoryController::class, 'reserveServiceOrder'])->middleware('permission:inventory.write');
        Route::post('/service-orders/{id}/close', [InventoryController::class, 'closeServiceOrder'])->middleware('permission:inventory.write');
    });

    Route::prefix('content')->group(function () {
        Route::get('/items', [ContentReviewsController::class, 'listContent'])->middleware('permission:content.read');
        Route::get('/items/{id}', [ContentReviewsController::class, 'showContent'])->middleware('permission:content.read');
        Route::post('/items', [ContentReviewsController::class, 'createContent'])->middleware('permission:content.write');
        Route::patch('/items/{id}', [ContentReviewsController::class, 'updateContent'])->middleware('permission:content.write');
        Route::post('/items/{id}/submit-approval', [ContentReviewsController::class, 'submitApproval'])->middleware('permission:content.write');
        Route::post('/items/{id}/approve', [ContentReviewsController::class, 'approve'])->middleware('permission:content.approve');
        Route::post('/items/{id}/reject', [ContentReviewsController::class, 'reject'])->middleware('permission:content.approve');
        Route::post('/items/{id}/rollback', [ContentReviewsController::class, 'rollback'])->middleware('permission:content.approve');
        Route::get('/items/{id}/versions', [ContentReviewsController::class, 'versions'])->middleware('permission:content.read');
    });

    Route::prefix('reviews')->group(function () {
        Route::post('/', [ContentReviewsController::class, 'createReview'])->middleware('permission:reviews.write');
        Route::post('/{id}/responses', [ContentReviewsController::class, 'reviewResponse'])
            ->whereNumber('id')
            ->middleware('permission:reviews.respond');
        Route::post('/{id}/appeal', [ContentReviewsController::class, 'reviewAppeal'])
            ->whereNumber('id')
            ->middleware('permission:reviews.moderate');
        Route::post('/{id}/hide', [ContentReviewsController::class, 'reviewHide'])
            ->whereNumber('id')
            ->middleware('permission:reviews.moderate');
        Route::get('/', [ContentReviewsController::class, 'listReviews'])->middleware('permission:reviews.read');
    });

    Route::prefix('analytics')->group(function () {
        Route::get('/reviews/summary', [AnalyticsAuditController::class, 'reviewSummary'])->middleware('permission:analytics.read');
        Route::get('/inventory/low-stock', [AnalyticsAuditController::class, 'lowStock'])->middleware('permission:analytics.read');
        Route::get('/rentals/overdue', [AnalyticsAuditController::class, 'overdueRentals'])->middleware('permission:analytics.read');
    });

    Route::prefix('imports')->group(function () {
        Route::post('/{entity}/validate', [ImportDedupController::class, 'validateImport'])->middleware('permission:imports.write');
        Route::post('/{entity}/commit', [ImportDedupController::class, 'commitImport'])->middleware('permission:imports.write');
        Route::post('/conflicts/{conflictId}/resolve', [ImportDedupController::class, 'resolveConflict'])->middleware('permission:imports.conflict.resolve');
        Route::get('/{import_id}', [ImportDedupController::class, 'getImportReport'])->middleware('permission:imports.read');
        Route::post('/{import_id}/rollback', [ImportDedupController::class, 'rollbackImport'])->middleware('permission:imports.write');
    });

    Route::post('/visit-tokens', [ReviewTokenController::class, 'issue'])
        ->middleware('permission:rentals.checkout');

    Route::get('/exports/{entity}', [ImportDedupController::class, 'exportEntity'])->middleware('permission:exports.read');
    Route::post('/dedup/scan', [ImportDedupController::class, 'dedupScan'])->middleware('permission:dedup.scan');
    Route::post('/dedup/merge/{groupId}', [ImportDedupController::class, 'dedupMerge'])->middleware('permission:dedup.merge');

    Route::prefix('audit')->group(function () {
        Route::get('/logs', [AnalyticsAuditController::class, 'auditLogs'])->middleware('permission:audit.read');
        Route::get('/logs/{id}', [AnalyticsAuditController::class, 'auditLogById'])->middleware('permission:audit.read');
        Route::get('/partitions', [AnalyticsAuditController::class, 'auditPartitions'])->middleware('permission:audit.read');
        Route::post('/archive', [AnalyticsAuditController::class, 'auditArchive'])->middleware('permission:audit.archive');
        Route::post('/reindex', [AnalyticsAuditController::class, 'auditReindex'])->middleware('permission:audit.reindex');
    });
});

Route::post('/reviews/public', [ContentReviewsController::class, 'createPublicReview'])->middleware('throttle:60,1');

Route::get('/health', function (Request $request) {
    return response()->json([
        'data' => [
            'service' => 'vetops-backend',
            'status' => 'ok',
            'timestamp_utc' => now()->utc()->toISOString(),
            'currency' => [
                'code' => config('vetops.currency.code'),
                'amount_format' => config('vetops.currency.amount_format'),
            ],
        ],
        'request_id' => $request->header('X-Request-Id') ?: (string) Str::uuid(),
    ]);
});
