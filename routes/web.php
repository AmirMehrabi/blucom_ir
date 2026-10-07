<?php

use App\Http\Controllers\Admin\AdminDidController;
use App\Http\Controllers\Admin\AdminInboundSetupController;
use App\Http\Controllers\Admin\AdminLineSetupController;
use App\Http\Controllers\Admin\AdminNumberSetupController;
use App\Http\Controllers\Admin\CustomerConnectionReviewController;
use App\Http\Controllers\Admin\CustomerManagementController;
use App\Http\Controllers\Admin\NumberInventoryController;
use App\Http\Controllers\Admin\OrderFulfillmentController;
use App\Http\Controllers\Admin\PaymentGatewayController;
use App\Http\Controllers\Admin\PaymentReconciliationController;
use App\Http\Controllers\Admin\ReservationController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CallHistoryController;
use App\Http\Controllers\CallQueueController;
use App\Http\Controllers\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Customer\CheckoutController;
use App\Http\Controllers\Customer\LineController;
use App\Http\Controllers\Customer\LineSetupWizardController;
use App\Http\Controllers\Customer\PaymentController;
use App\Http\Controllers\Customer\RegistrationController;
use App\Http\Controllers\Customer\SetupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FreeSwitch\XmlController;
use App\Http\Controllers\InboundRouteController;
use App\Http\Controllers\IvrMenuController;
use App\Http\Controllers\LiveOverviewController;
use App\Http\Controllers\Marketing\PlansController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OutboundRouteController;
use App\Http\Controllers\QueueAvailabilityController;
use App\Http\Controllers\RecordingController;
use App\Http\Controllers\RecordingSettingsController;
use App\Http\Controllers\SipExtensionController;
use App\Http\Controllers\SipGatewayController;
use App\Http\Middleware\AuthenticateFreeSwitch;
use App\Http\Middleware\EnsureActivePortalAccount;
use App\Http\Middleware\EnsurePortalDomain;
use App\Http\Middleware\RedactPaymentSecrets;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

// Register the dedicated customer login before the internal portal routes.
// Shared configuration pages use the host-selected guard; admin middleware only accepts User.
Route::domain(config('portal.customer_domain'))->group(function () {
    Route::get('/', fn () => auth('customer')->check()
        ? redirect(auth('customer')->user()->homePath()) : redirect()->route('customer.login'))->name('customer.home');
    Route::get('/login', fn () => auth('customer')->check()
        ? redirect(auth('customer')->user()->homePath()) : view('auth', ['customerPortal' => true]))->name('customer.login');
    Route::get('/register', fn () => auth('customer')->check()
        ? redirect(auth('customer')->user()->homePath())
        : view('auth', ['customerPortal' => true, 'registration' => true]))->name('customer.register');
    Route::post('/auth/register/request', [RegistrationController::class, 'requestOtp'])->middleware('throttle:registration-request')->name('customer.register.request');
    Route::post('/auth/register/verify', [RegistrationController::class, 'verify'])->middleware('throttle:registration-verify')->name('customer.register.verify');
    Route::post('/auth/otp/request', [CustomerAuthController::class, 'requestOtp'])->middleware('throttle:otp-request')->name('customer.otp.request');
    Route::post('/auth/otp/verify', [CustomerAuthController::class, 'verify'])->middleware('throttle:otp-verify')->name('customer.otp.verify');
    Route::post('/auth/logout', [CustomerAuthController::class, 'logout'])->middleware('auth:customer')->name('customer.logout');
    Route::get('/auth/me', [CustomerAuthController::class, 'me'])->middleware('auth:customer')->name('customer.me');
    Route::middleware('auth:customer')->group(function () {
        Route::get('/numbers', [CheckoutController::class, 'index'])->name('customer.numbers.index');
        Route::get('/numbers/{offer}/checkout', [CheckoutController::class, 'review'])->whereNumber('offer')->name('customer.numbers.review');
        Route::get('/orders', [CheckoutController::class, 'orders'])->name('customer.orders.index');
        Route::post('/orders', [CheckoutController::class, 'reserve'])->middleware('throttle:20,1')->name('customer.orders.reserve');
        Route::get('/orders/{order}', [CheckoutController::class, 'show'])->name('customer.orders.show');
        Route::post('/orders/{order}/cancel', [CheckoutController::class, 'cancel'])->middleware('throttle:20,1')->name('customer.orders.cancel');
    });
    Route::middleware(['auth:customer', 'permission:'.Permissions::LINES_VIEW])->prefix('lines')->name('customer.lines.')->group(function () {
        Route::get('/', [LineController::class, 'index'])->name('index');
        Route::get('/{number}', [LineController::class, 'show'])->name('show');
        Route::post('/{number}/answer', [LineController::class, 'answer'])->name('answer');
        Route::post('/{number}/phones', [LineController::class, 'phones'])->name('phones');
        Route::post('/{number}/outbound', [LineController::class, 'outbound'])->name('outbound');
        Route::put('/{number}/phones/{extension}', [LineController::class, 'updatePhone'])->name('phone.update');
        Route::post('/{number}/phones/{extension}/reset', [LineController::class, 'resetPhone'])->name('phone.reset');
    });
    Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'initiate'])->middleware(['auth:customer', 'throttle:20,1'])->name('customer.payments.initiate');
    Route::get('/payments/{attempt}', [PaymentController::class, 'show'])->middleware('auth:customer')->name('customer.payments.show');
    Route::post('/payments/mellat/callback/{attempt}', [PaymentController::class, 'callback'])
        ->middleware([RedactPaymentSecrets::class, 'throttle:120,1'])->withoutMiddleware(EnsureActivePortalAccount::class)->name('customer.payments.callback');
    Route::match(['get', 'post'], '/payments/zibal/callback/{attempt}', [PaymentController::class, 'zibalCallback'])
        ->middleware([RedactPaymentSecrets::class, 'throttle:120,1'])->withoutMiddleware(EnsureActivePortalAccount::class)->name('customer.payments.zibal-callback');
});

Route::domain(config('portal.public_domain'))->group(function () {
    Route::view('/', 'welcome')->name('home');
    Route::get('/plans', PlansController::class)->name('plans');
    Route::view('/contact', 'marketing.contact')->name('contact');
    Route::get('/login', fn () => redirect()->route('customer.login'));
    Route::get('/register', fn () => redirect()->route('customer.register'));
    Route::get('/admin/{path?}', fn () => redirect()->away('https://'.config('portal.admin_domain').request()->getRequestUri()))
        ->where('path', '.*');
    Route::get('/{panel}/{path?}', fn () => redirect()->away('https://'.config('portal.admin_domain').request()->getRequestUri()))
        ->where(['panel' => 'users|sip-gateways|sip-extensions|inbound-routes|outbound-routes', 'path' => '.*']);
});

Route::domain(config('portal.admin_domain'))->group(function () {
    Route::get('/', fn () => auth('web')->check()
        ? redirect(auth('web')->user()->homePath()) : redirect()->route('login'))->name('admin.home');
    Route::get('/login', fn () => auth('web')->check()
        ? redirect(auth('web')->user()->homePath()) : view('auth'))->name('login');
    Route::post('/auth/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp-request')->name('admin.otp.request');
    Route::post('/auth/otp/verify', [AuthController::class, 'verify'])->middleware('throttle:otp-verify')->name('admin.otp.verify');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:web')->name('logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:web');
});

// Marketing pages have one canonical public URL, even when followed from a portal.
Route::middleware(EnsurePortalDomain::class)->group(function () {
    Route::get('/plans', fn () => redirect()->route('plans'));
    Route::get('/contact', fn () => redirect()->route('contact'));
});

// Shared pages keep their existing names and use the host-selected guard.
// The domain middleware runs before authentication and model binding.
Route::middleware(EnsurePortalDomain::class)->group(function () {
    Route::middleware('auth')->prefix('notifications')->name('notifications.')->group(function () {
        Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::post('/{notification}/read', [NotificationController::class, 'markRead'])->name('read');
    });

    Route::get('/access-denied', fn () => view('access-denied'))->middleware('auth')->name('access-denied');
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware(['auth', 'permission:'.Permissions::DASHBOARD_VIEW])->name('dashboard');
    Route::post('/dashboard/preference', [DashboardController::class, 'preference'])
        ->middleware(['auth', 'permission:'.Permissions::DASHBOARD_VIEW])->name('dashboard.preference');
    Route::get('/calls', [CallHistoryController::class, 'index'])
        ->middleware(['auth', 'permission:'.Permissions::CALLS_VIEW])->name('calls.index');
    Route::get('/calls/{callRecord}', [CallHistoryController::class, 'show'])
        ->middleware(['auth', 'permission:'.Permissions::CALLS_VIEW])->name('calls.show');
    Route::middleware('auth')->prefix('recordings')->name('recordings.')->group(function () {
        Route::get('/', [RecordingController::class, 'index'])->middleware('permission:'.Permissions::RECORDINGS_VIEW)->name('index');
        Route::get('/settings', [RecordingSettingsController::class, 'index'])->middleware('permission:'.Permissions::RECORDINGS_MANAGE)->name('settings');
        Route::get('/numbers/{number}', [RecordingSettingsController::class, 'edit'])->middleware('permission:'.Permissions::RECORDINGS_MANAGE)->name('numbers.edit');
        Route::get('/numbers/{number}/announcement', [RecordingSettingsController::class, 'announcement'])->middleware('permission:'.Permissions::RECORDINGS_MANAGE)->name('announcement');
        Route::put('/numbers/{number}', [RecordingSettingsController::class, 'update'])->middleware('permission:'.Permissions::RECORDINGS_MANAGE)->name('numbers.update');
        Route::post('/numbers/{number}/retention', [RecordingSettingsController::class, 'retention'])->middleware('permission:'.Permissions::RECORDINGS_MANAGE)->name('retention');
        Route::put('/storage/{tenant}', [RecordingSettingsController::class, 'storage'])->middleware('permission:'.Permissions::RECORDINGS_MANAGE)->name('storage');
        Route::get('/{recording}/audio', [RecordingController::class, 'audio'])->middleware('permission:'.Permissions::RECORDINGS_VIEW)->name('audio');
        Route::get('/{recording}/download', [RecordingController::class, 'audio'])->middleware('permission:'.Permissions::RECORDINGS_DOWNLOAD)->name('download');
        Route::delete('/{recording}', [RecordingController::class, 'destroy'])->middleware('permission:'.Permissions::RECORDINGS_DELETE)->name('destroy');
    });

    Route::middleware(['auth', 'permission:'.Permissions::LIVE_VIEW])->group(function () {
        Route::get('/live', [LiveOverviewController::class, 'index'])->name('live.index');
        Route::get('/live/state', [LiveOverviewController::class, 'state'])->name('live.state');
    });
    Route::get('/availability', [QueueAvailabilityController::class, 'index'])->middleware(['auth', 'permission:'.Permissions::QUEUES_WORK])->name('availability.index');
    Route::post('/availability', [QueueAvailabilityController::class, 'update'])->middleware(['auth', 'permission:'.Permissions::QUEUES_WORK])->name('availability.update');

    Route::middleware(['auth', 'permission:'.Permissions::PHONES_MANAGE])->prefix('menus')->name('ivr-menus.')->group(function () {
        Route::get('/', [IvrMenuController::class, 'index'])->name('index');
        Route::post('/', [IvrMenuController::class, 'store'])->name('store');
        Route::get('/{menu}/edit', [IvrMenuController::class, 'edit'])->name('edit');
        Route::put('/{menu}', [IvrMenuController::class, 'update'])->name('update');
        Route::post('/{menu}/publish', [IvrMenuController::class, 'publish'])->name('publish');
        Route::post('/{menu}/restore', [IvrMenuController::class, 'restore'])->name('restore');
        Route::delete('/{menu}', [IvrMenuController::class, 'destroy'])->name('destroy');
        Route::get('/{menu}/audio/{version}', [IvrMenuController::class, 'audio'])->name('audio');
    });

    Route::resource('teams', CallQueueController::class)
        ->middleware(['auth', 'permission:'.Permissions::PHONES_MANAGE])
        ->only(['index', 'store', 'update', 'destroy'])->parameters(['teams' => 'queue']);

    Route::domain(config('portal.admin_domain'))->middleware(['auth:web', 'admin:admin'])->group(function () {
        Route::get('/admin/settings/payment-gateways', [PaymentGatewayController::class, 'index'])->name('admin.payment-gateways.index');
        Route::put('/admin/settings/payment-gateways/{provider}', [PaymentGatewayController::class, 'update'])->middleware(RedactPaymentSecrets::class)->name('admin.payment-gateways.update');
        Route::put('/admin/settings/payment-gateways/{provider}/active', [PaymentGatewayController::class, 'activate'])->name('admin.payment-gateways.activate');
        Route::get('/admin/fulfillment', [OrderFulfillmentController::class, 'index'])->name('admin.fulfillment.index');
        Route::get('/admin/reservations', [ReservationController::class, 'index'])->name('admin.reservations.index');
        Route::post('/admin/reservations/{order}/cancel', [ReservationController::class, 'cancel'])->middleware('throttle:20,1')->name('admin.reservations.cancel');
        Route::post('/admin/fulfillment/{order}/repair', [OrderFulfillmentController::class, 'repair'])->middleware('throttle:20,1')->name('admin.fulfillment.repair');
        Route::get('/admin/payments', [PaymentReconciliationController::class, 'index'])->name('admin.payments.index');
        Route::post('/admin/payments/{attempt}/reconcile', [PaymentReconciliationController::class, 'reconcile'])->middleware('throttle:20,1')->name('admin.payments.reconcile');
        Route::post('/admin/payments/{attempt}/reverse', [PaymentReconciliationController::class, 'reverse'])->middleware('throttle:20,1')->name('admin.payments.reverse');
        Route::post('/admin/inventory', [NumberInventoryController::class, 'store'])->name('admin.inventory.store');
        Route::get('/admin/inventory/{number}', [NumberInventoryController::class, 'show'])->name('admin.inventory.show');
        Route::put('/admin/inventory/{number}', [NumberInventoryController::class, 'update'])->name('admin.inventory.update');
        Route::post('/admin/inventory/{number}/review', [NumberInventoryController::class, 'review'])->name('admin.inventory.review');
        Route::post('/admin/inventory/{number}/publish', [NumberInventoryController::class, 'publish'])->name('admin.inventory.publish');
        Route::post('/admin/inventory/{number}/withdraw', [NumberInventoryController::class, 'withdraw'])->name('admin.inventory.withdraw');
        Route::post('/admin/inventory/{number}/transition', [NumberInventoryController::class, 'transition'])->name('admin.inventory.transition');
        Route::get('/admin/plans', [PlanController::class, 'index'])->name('admin.plans.index');
        Route::post('/admin/plans', [PlanController::class, 'store'])->name('admin.plans.store');
        Route::get('/admin/plans/create', [PlanController::class, 'create'])->name('admin.plans.create');
        Route::get('/admin/plans/{plan}', [PlanController::class, 'show'])->name('admin.plans.show');
        Route::get('/admin/plan-versions/{version}/review', [PlanController::class, 'review'])->name('admin.plans.review');
        Route::post('/admin/plans/{plan}/versions', [PlanController::class, 'version'])->name('admin.plans.version');
        Route::put('/admin/plan-versions/{version}', [PlanController::class, 'editDraft'])->name('admin.plans.edit-draft');
        Route::post('/admin/plan-versions/{version}/publish', [PlanController::class, 'publish'])->name('admin.plans.publish');
        Route::post('/admin/plans/{plan}/archive', [PlanController::class, 'archive'])->name('admin.plans.archive');

        Route::get('/admin/customers', [CustomerManagementController::class, 'index'])->name('admin.customers.index');
        Route::post('/admin/customers', [CustomerManagementController::class, 'store'])->name('admin.customers.store');
        Route::put('/admin/customer-businesses/{tenant}', [CustomerManagementController::class, 'updateBusiness'])->name('admin.customers.business');
        Route::put('/admin/customers/{customer}', [CustomerManagementController::class, 'update'])->name('admin.customers.update');
        Route::get('/admin', fn () => redirect()->route('dashboard'))->name('admin');
        Route::get('/admin/quick-setup', [AdminLineSetupController::class, 'index'])->name('admin.setup.index');
        Route::post('/admin/quick-setup', [AdminLineSetupController::class, 'store'])->name('admin.setup.store');
        Route::get('/admin/quick-setup/{setup}', [AdminLineSetupController::class, 'show'])->name('admin.setup.show');
        Route::put('/admin/quick-setup/{setup}', [AdminLineSetupController::class, 'update'])->name('admin.setup.update');
        Route::post('/admin/quick-setup/{setup}/finish', [AdminLineSetupController::class, 'finish'])->name('admin.setup.finish');
        Route::delete('/admin/quick-setup/{setup}', [AdminLineSetupController::class, 'destroy'])->name('admin.setup.destroy');
        Route::get('/admin/time-conditions', [AdminInboundSetupController::class, 'index'])->name('admin.time-conditions.index');
        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
        Route::get('sip-extensions/create', [SipExtensionController::class, 'create'])->name('sip-extensions.create');
        Route::get('sip-extensions/{sip_extension}/edit', [SipExtensionController::class, 'edit'])->name('sip-extensions.edit');
        Route::resource('sip-extensions', SipExtensionController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['sip-extensions' => 'sip_extension']);
        Route::get('sip-gateways/create', [SipGatewayController::class, 'create'])->name('sip-gateways.create');
        Route::get('sip-gateways/{sip_gateway}/edit', [SipGatewayController::class, 'edit'])->name('sip-gateways.edit');
        Route::patch('sip-gateways/{sip_gateway}/status', [SipGatewayController::class, 'status'])->name('sip-gateways.status');
        Route::resource('sip-gateways', SipGatewayController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['sip-gateways' => 'sip_gateway']);
        Route::resource('inbound-routes', InboundRouteController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['inbound-routes' => 'inbound_route']);
        Route::get('outbound-routes/create', [OutboundRouteController::class, 'create'])->name('outbound-routes.create');
        Route::get('outbound-routes/{outbound_route}/edit', [OutboundRouteController::class, 'edit'])->name('outbound-routes.edit');
        Route::resource('outbound-routes', OutboundRouteController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['outbound-routes' => 'outbound_route']);

        Route::get('/admin/sip-numbers', [AdminDidController::class, 'index'])->name('admin.sip-numbers.index');
        Route::post('/admin/sip-numbers', [AdminDidController::class, 'store'])->name('admin.sip-numbers.store');
        Route::get('/admin/sip-numbers/{sip_number}/setup', [AdminNumberSetupController::class, 'show'])->name('admin.sip-numbers.setup');
        Route::get('/admin/sip-numbers/{sip_number}/inbound', [AdminInboundSetupController::class, 'edit'])->name('admin.sip-numbers.inbound');
        Route::put('/admin/sip-numbers/{sip_number}/inbound', [AdminInboundSetupController::class, 'update'])->name('admin.sip-numbers.inbound.update');
        Route::post('/admin/sip-numbers/{sip_number}/preview', [AdminInboundSetupController::class, 'preview'])->name('admin.sip-numbers.preview');
        Route::get('/admin/sip-numbers/{sip_number}/announcement', [AdminInboundSetupController::class, 'announcement'])->name('admin.sip-numbers.announcement');
        Route::put('/admin/sip-numbers/{sip_number}/gateway', [AdminNumberSetupController::class, 'updateGateway'])->name('admin.sip-numbers.gateway');
        Route::put('/admin/sip-numbers/{sip_number}', [AdminDidController::class, 'update'])->name('admin.sip-numbers.update');
        Route::delete('/admin/sip-numbers/{sip_number}', [AdminDidController::class, 'destroy'])->name('admin.sip-numbers.destroy');
        Route::get('/admin/customer-connections', [CustomerConnectionReviewController::class, 'index'])->name('admin.customer-connections.index');
        Route::post('/admin/customer-connections/gateways/{gateway}/approve', [CustomerConnectionReviewController::class, 'approveGateway'])->name('admin.customer-connections.gateways.approve');
        Route::post('/admin/customer-connections/gateways/{gateway}/reject', [CustomerConnectionReviewController::class, 'rejectGateway'])->name('admin.customer-connections.gateways.reject');
        Route::post('/admin/customer-connections/numbers/{number}/approve', [CustomerConnectionReviewController::class, 'approveNumber'])->name('admin.customer-connections.numbers.approve');
        Route::post('/admin/customer-connections/numbers/{number}/reject', [CustomerConnectionReviewController::class, 'rejectNumber'])->name('admin.customer-connections.numbers.reject');
    });

    Route::middleware('auth')->prefix('setup')->name('customer.setup.')->group(function () {
        Route::get('/wizard', [LineSetupWizardController::class, 'show'])->name('wizard');
        Route::post('/wizard/answer', [LineSetupWizardController::class, 'chooseAnswer'])->name('wizard.answer');
        Route::post('/wizard/gateway', [LineSetupWizardController::class, 'chooseGateway'])->name('wizard.gateway');
        Route::post('/wizard/number', [LineSetupWizardController::class, 'chooseNumber'])->name('wizard.number');
        Route::post('/wizard/phone', [LineSetupWizardController::class, 'createPhone'])->name('wizard.phone');
        Route::post('/wizard/outbound/{extension}', [LineSetupWizardController::class, 'setOutbound'])->name('wizard.outbound');
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
            Route::get('/answer/{number}/announcement', [SetupController::class, 'announcement'])->name('answer.announcement');
            Route::get('/phone/{extension}', [SetupController::class, 'phone'])->name('phone');
            Route::post('/phone/{extension}/reset', [SetupController::class, 'resetPhonePassword'])->name('phone.reset');
        });
        Route::get('/lines', [SetupController::class, 'lines'])->middleware('permission:'.Permissions::LINES_VIEW)->name('lines');
    });

});

Route::post('/internal/freeswitch/xml', XmlController::class)
    ->middleware([AuthenticateFreeSwitch::class, 'throttle:freeswitch-xml'])
    ->name('freeswitch.xml');
