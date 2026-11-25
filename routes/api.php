<?php

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\OtpAuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordAuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\SocialAuthController;
use App\Http\Controllers\Api\V1\BillerController;
use App\Http\Controllers\Api\V1\BusinessAuditController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\Collections;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

/*
| Portal API (/api/v1). Portal users authenticate with Sanctum Bearer tokens.
| Merchant API keys (np_live_… with HMAC) arrive in P1 on their own guard.
*/
Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('meta', MetaController::class)->name('meta');

    // ── Authentication ───────────────────────────────────────────────
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', [PasswordAuthController::class, 'register'])->name('register');
            Route::post('login', [PasswordAuthController::class, 'login'])->name('login');
            Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->name('password.forgot');
            Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.reset');
            Route::post('two-factor', [OtpAuthController::class, 'twoFactor'])->name('two-factor');
            Route::post('otp/verify', [OtpAuthController::class, 'verify'])->name('otp.verify');
            Route::post('oauth/exchange', [SocialAuthController::class, 'exchange'])->name('oauth.exchange');
        });

        Route::post('otp/request', [OtpAuthController::class, 'request'])->middleware('throttle:otp')->name('otp.request');

        Route::get('oauth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
            ->whereIn('provider', ['google', 'github'])->name('oauth.redirect');
        Route::get('oauth/{provider}/callback', [SocialAuthController::class, 'callback'])
            ->whereIn('provider', ['google', 'github'])->name('oauth.callback');

        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [PasswordAuthController::class, 'logout'])->name('logout');
            Route::post('logout-all', [PasswordAuthController::class, 'logoutEverywhere'])->name('logout-all');
            Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
                ->middleware('throttle:6,1')->name('verification.send');
            Route::post('oauth/{provider}/link', [SocialAuthController::class, 'linkUrl'])
                ->whereIn('provider', ['google', 'github'])->name('oauth.link');
        });
    });

    // Provider callbacks (signature-verified, no user session).
    Route::post('webhooks/{provider}', WebhookController::class)
        ->whereIn('provider', ['mock', 'snippe', 'azampay'])->middleware('throttle:webhooks')->name('webhooks');

    // Bank billers (CRDB standard bills). One URL serves verification and payment;
    // /verify and /payment are aliases for banks that want two URLs.
    Route::prefix('billers/crdb')->name('billers.crdb')->middleware(['biller.source:crdb', 'throttle:webhooks'])->group(function () {
        Route::post('/', [BillerController::class, 'crdb']);
        Route::post('verify', [BillerController::class, 'crdb'])->name('.verify');
        Route::post('payment', [BillerController::class, 'crdb'])->name('.payment');
    });

    // Bank billers (NMB Biller Standard Integration API): its own credentials and format.
    Route::prefix('billers/nmb')->name('billers.nmb')->middleware(['biller.source:nmb', 'nmb.token', 'throttle:webhooks'])->group(function () {
        Route::post('/', [BillerController::class, 'nmb']);
        Route::post('inquiry', [BillerController::class, 'nmb'])->name('.inquiry');
        Route::post('payment-notification', [BillerController::class, 'nmb'])->name('.payment');
    });

    // Public invitation preview (the invite page shows it before sign-in).
    Route::get('invitations/{token}', [StaffController::class, 'preview'])->middleware('throttle:30,1')->name('invitations.preview');

    Route::middleware('auth:sanctum')->group(function () {
        // ── Profile ──────────────────────────────────────────────────
        Route::prefix('me')->name('me.')->controller(ProfileController::class)->group(function () {
            Route::get('/', 'show')->name('show');
            Route::patch('/', 'update')->name('update');
            Route::put('password', 'password')->name('password');
            Route::post('phone', 'requestPhone')->middleware('throttle:otp')->name('phone.request');
            Route::post('phone/verify', 'verifyPhone')->middleware('throttle:auth')->name('phone.verify');
            Route::put('two-factor', 'twoFactor')->name('two-factor');
            Route::delete('social/{provider}', 'unlink')->whereIn('provider', ['google', 'github'])->name('social.unlink');
            Route::get('sessions', 'sessions')->name('sessions');
            Route::delete('sessions/{token}', 'revokeSession')->whereNumber('token')->name('sessions.revoke');
        });

        Route::post('invitations/{token}/accept', [StaffController::class, 'accept'])->name('invitations.accept');

        // ── Businesses ───────────────────────────────────────────────
        Route::get('businesses', [BusinessController::class, 'index'])->name('businesses.index');
        Route::post('businesses', [BusinessController::class, 'store'])->middleware('throttle:10,60')->name('businesses.store');

        Route::prefix('businesses/{business}')->name('businesses.')->scopeBindings()->group(function () {
            Route::get('/', [BusinessController::class, 'show'])->name('show');
            Route::patch('/', [BusinessController::class, 'update'])->name('update');
            Route::post('logo', [BusinessController::class, 'logo'])->name('logo');

            Route::get('kyc', [KycController::class, 'overview'])->name('kyc');
            Route::post('kyc/documents', [KycController::class, 'upload'])->name('kyc.documents.store');
            Route::get('kyc/documents/{document}', [KycController::class, 'download'])->name('kyc.documents.download');
            Route::delete('kyc/documents/{document}', [KycController::class, 'destroy'])->name('kyc.documents.destroy');
            Route::post('kyc/submit', [KycController::class, 'submit'])->middleware('two-factor')->name('kyc.submit');
            Route::post('sender-id', [KycController::class, 'requestSenderId'])->name('sender-id');

            Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
            Route::patch('staff/{member}', [StaffController::class, 'update'])->name('staff.update');
            Route::delete('staff/{member}', [StaffController::class, 'destroy'])->name('staff.destroy');
            Route::get('invitations', [StaffController::class, 'invitations'])->name('invitations.index');
            Route::post('invitations', [StaffController::class, 'invite'])->name('invitations.store');
            Route::delete('invitations/{invitation}', [StaffController::class, 'revoke'])->name('invitations.revoke');

            Route::get('audit-logs', BusinessAuditController::class)->name('audit');

            // ── Collections (P1) ──────────────────────────────────────
            Route::get('groups', [Collections\GroupController::class, 'index'])->name('groups.index');
            Route::post('groups', [Collections\GroupController::class, 'store'])->name('groups.store');
            Route::patch('groups/{group}', [Collections\GroupController::class, 'update'])->name('groups.update');
            Route::delete('groups/{group}', [Collections\GroupController::class, 'destroy'])->name('groups.destroy');
            Route::post('customers/move-group', [Collections\GroupController::class, 'move'])->name('customers.move-group');

            Route::get('customers', [Collections\CustomerController::class, 'index'])->name('customers.index');
            Route::post('customers', [Collections\CustomerController::class, 'store'])->name('customers.store');
            Route::get('customers/{customer}', [Collections\CustomerController::class, 'show'])->name('customers.show');
            Route::patch('customers/{customer}', [Collections\CustomerController::class, 'update'])->name('customers.update');

            Route::get('invoices', [Collections\InvoiceController::class, 'index'])->name('invoices.index');
            Route::post('invoices', [Collections\InvoiceController::class, 'store'])->name('invoices.store');
            Route::post('invoices/bulk', [Collections\InvoiceController::class, 'bulk'])->middleware('throttle:20,1')->name('invoices.bulk');
            Route::get('invoice-batches', [Collections\InvoiceController::class, 'batches'])->name('invoice-batches.index');
            Route::get('invoices/{invoice}', [Collections\InvoiceController::class, 'show'])->name('invoices.show');
            Route::post('invoices/{invoice}/void', [Collections\InvoiceController::class, 'void'])->name('invoices.void');
            Route::post('invoices/{invoice}/apply-credit', [Collections\InvoiceController::class, 'applyCredit'])->name('invoices.apply-credit');

            Route::get('payment-intents', [Collections\PaymentIntentController::class, 'index'])->name('payment-intents.index');
            Route::post('payment-intents', [Collections\PaymentIntentController::class, 'store'])->middleware('throttle:60,1')->name('payment-intents.store');
            Route::get('payment-intents/{paymentIntent}', [Collections\PaymentIntentController::class, 'show'])->name('payment-intents.show');
            Route::post('payment-intents/{paymentIntent}/cancel', [Collections\PaymentIntentController::class, 'cancel'])->name('payment-intents.cancel');
            Route::post('payment-intents/{paymentIntent}/retry', [Collections\PaymentIntentController::class, 'retry'])->name('payment-intents.retry');
            Route::post('payment-intents/{paymentIntent}/resend', [Collections\PaymentIntentController::class, 'resend'])->middleware('throttle:6,1')->name('payment-intents.resend');
            Route::post('payment-intents/{paymentIntent}/simulate', [Collections\PaymentIntentController::class, 'simulate'])->name('payment-intents.simulate');

            Route::get('payments', [Collections\PaymentController::class, 'index'])->name('payments.index');
            Route::post('payments', [Collections\PaymentController::class, 'store'])->name('payments.store');
            Route::get('payments/{payment}', [Collections\PaymentController::class, 'show'])->name('payments.show');
            Route::post('payments/{payment}/void', [Collections\PaymentController::class, 'void'])->name('payments.void');
            Route::get('settlements', [Collections\PaymentController::class, 'settlements'])->name('settlements.index');
            Route::get('balance', [Collections\PaymentController::class, 'balance'])->name('balance');
            Route::get('summary', [Collections\PaymentController::class, 'summary'])->name('summary');
            Route::get('reports/{report}/export', [Collections\ReportController::class, 'export'])
                ->whereIn('report', array_keys(Collections\ReportController::REPORTS))->middleware('throttle:20,1')->name('reports.export');
        });

        // ── NGUZO back-office ────────────────────────────────────────
        Route::prefix('admin')->name('admin.')->middleware('platform.admin')->group(function () {
            Route::get('dashboard', Admin\DashboardController::class)->name('dashboard');
            Route::get('businesses', [Admin\BusinessReviewController::class, 'index'])->name('businesses.index');

            Route::prefix('businesses/{business}')->name('businesses.')->scopeBindings()
                ->controller(Admin\BusinessReviewController::class)->group(function () {
                    Route::get('/', 'show')->name('show');
                    Route::get('documents/{document}', 'downloadDocument')->name('documents.download');

                    // Decisions need two-step verification (plan §7).
                    Route::middleware('two-factor')->group(function () {
                        Route::post('recommend', 'recommend')->name('recommend');
                        Route::post('decide', 'decide')->name('decide');
                        Route::post('suspend', 'suspend')->name('suspend');
                        Route::post('reinstate', 'reinstate')->name('reinstate');
                        Route::patch('documents/{document}', 'reviewDocument')->name('documents.review');
                        Route::patch('sender-ids/{senderId}', 'senderIdStatus')->name('sender-ids.status');
                    });
                });

            Route::get('users', [Admin\UserController::class, 'index'])->name('users.index');
            Route::get('users/{user}', [Admin\UserController::class, 'show'])->name('users.show');
            Route::get('audit-logs', Admin\AuditLogController::class)->name('audit');
        });
    });
});
