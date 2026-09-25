<?php

use App\Http\Controllers\Admin\AdminDidController;
use App\Http\Controllers\Admin\CustomerConnectionReviewController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Customer\SetupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FreeSwitch\XmlController;
use App\Http\Controllers\InboundRouteController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OutboundRouteController;
use App\Http\Controllers\SipExtensionController;
use App\Http\Controllers\SipGatewayController;
use App\Http\Middleware\AuthenticateFreeSwitch;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/login', fn () => auth()->check()
    ? redirect(auth()->user()->homePath())
    : view('auth'))->name('login');

Route::post('/auth/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp-request');
Route::post('/auth/otp/verify', [AuthController::class, 'verify'])->middleware('throttle:otp-verify');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth');
Route::middleware('auth')->prefix('notifications')->name('notifications.')->group(function () {
    Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
    Route::post('/{notification}/read', [NotificationController::class, 'markRead'])->name('read');
});

Route::get('/', fn () => auth()->check()
    ? redirect(auth()->user()->homePath())
    : redirect()->route('login'))->name('home');

Route::get('/access-denied', fn () => view('access-denied'))->middleware('auth')->name('access-denied');
Route::get('/dashboard', function (Request $request, DashboardController $controller) {
    return $request->user()->isAdmin() ? $controller->admin() : $controller->customer($request);
})->middleware(['auth', 'permission:'.Permissions::DASHBOARD_VIEW])->name('dashboard');

Route::middleware(['auth', 'admin:admin'])->group(function () {
    Route::get('/admin', fn () => redirect()->route('dashboard'))->name('admin');
    Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
    Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
    Route::resource('sip-extensions', SipExtensionController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['sip-extensions' => 'sip_extension']);
    Route::resource('sip-gateways', SipGatewayController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['sip-gateways' => 'sip_gateway']);
    Route::resource('inbound-routes', InboundRouteController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['inbound-routes' => 'inbound_route']);
    Route::resource('outbound-routes', OutboundRouteController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['outbound-routes' => 'outbound_route']);

    Route::get('/admin/sip-numbers', [AdminDidController::class, 'index'])->name('admin.sip-numbers.index');
    Route::post('/admin/sip-numbers', [AdminDidController::class, 'store'])->name('admin.sip-numbers.store');
    Route::put('/admin/sip-numbers/{sip_number}', [AdminDidController::class, 'update'])->name('admin.sip-numbers.update');
    Route::delete('/admin/sip-numbers/{sip_number}', [AdminDidController::class, 'destroy'])->name('admin.sip-numbers.destroy');
    Route::get('/admin/customer-connections', [CustomerConnectionReviewController::class, 'index'])->name('admin.customer-connections.index');
    Route::post('/admin/customer-connections/gateways/{gateway}/approve', [CustomerConnectionReviewController::class, 'approveGateway'])->name('admin.customer-connections.gateways.approve');
    Route::post('/admin/customer-connections/gateways/{gateway}/reject', [CustomerConnectionReviewController::class, 'rejectGateway'])->name('admin.customer-connections.gateways.reject');
    Route::post('/admin/customer-connections/numbers/{number}/approve', [CustomerConnectionReviewController::class, 'approveNumber'])->name('admin.customer-connections.numbers.approve');
    Route::post('/admin/customer-connections/numbers/{number}/reject', [CustomerConnectionReviewController::class, 'rejectNumber'])->name('admin.customer-connections.numbers.reject');
});

Route::middleware('auth')->prefix('setup')->name('customer.setup.')->group(function () {
    Route::middleware('permission:'.Permissions::PROVIDERS_MANAGE)->group(function () {
        Route::get('/provider', [SetupController::class, 'provider'])->name('provider');
        Route::post('/providers', [SetupController::class, 'storeProvider'])->name('providers.store');
        Route::put('/providers/{gateway}', [SetupController::class, 'updateProvider'])->name('providers.update');
    });
    Route::middleware('permission:'.Permissions::NUMBERS_MANAGE)->group(function () {
        Route::get('/number', [SetupController::class, 'number'])->name('number');
        Route::post('/numbers', [SetupController::class, 'storeNumber'])->name('numbers.store');
        Route::get('/numbers/{number}/edit', [SetupController::class, 'editNumber'])->name('numbers.edit');
        Route::put('/numbers/{number}', [SetupController::class, 'updateNumber'])->name('numbers.update');
    });
    Route::middleware('permission:'.Permissions::PHONES_MANAGE)->group(function () {
        Route::get('/answer/{number}', [SetupController::class, 'answer'])->name('answer');
        Route::post('/answer/{number}', [SetupController::class, 'storeAnswer'])->name('answer.store');
        Route::get('/phone/{extension}', [SetupController::class, 'phone'])->name('phone');
        Route::post('/phone/{extension}/reset', [SetupController::class, 'resetPhonePassword'])->name('phone.reset');
    });
    Route::get('/lines', [SetupController::class, 'lines'])->middleware('permission:'.Permissions::LINES_VIEW)->name('lines');
});

Route::post('/internal/freeswitch/xml', XmlController::class)
    ->middleware([AuthenticateFreeSwitch::class, 'throttle:freeswitch-xml'])
    ->name('freeswitch.xml');
