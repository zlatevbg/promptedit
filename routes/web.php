<?php

use App\Enums\Ability;
use App\Http\Controllers\Admin\SpendReportController;
use App\Http\Controllers\CreditPurchaseController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Middleware\VerifyPixelforgeSignature;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware(ThrottleRequests::with(maxAttempts: 20))
        ->name('generations.store');
    Route::post('credit-purchases', [CreditPurchaseController::class, 'store'])
        ->middleware(ThrottleRequests::with(maxAttempts: 10))
        ->name('credit-purchases.store');

    Route::get('admin/spend-report', SpendReportController::class)
        ->can(Ability::ViewAdminReport)
        ->name('admin.spend-report');
});

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware(VerifyPixelforgeSignature::class)
    ->name('webhooks.pixelforge');

require __DIR__.'/settings.php';
